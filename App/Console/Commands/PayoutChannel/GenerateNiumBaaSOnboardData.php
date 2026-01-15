<?php

namespace App\Console\Commands\PayoutChannel;

use App\Models\BaseInformation;
use App\Services\AMLService;
use App\Services\Payout\NiumBaaSOnboardService;
use Illuminate\Console\Command;

class GenerateNiumBaaSOnboardData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium_baas:generate_onboard_data {aml_uuid}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate nium baas onboard data by aml_uuid';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private AMLService $amlService,
        private NiumBaaSOnboardService $niumBaaSOnboardService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $aml_uuid = $this->argument('aml_uuid');

        $baseInformation = BaseInformation::where('aml_uuid', $aml_uuid)->first();

        $application = $baseInformation->modelable;

        $niumBaasCustomerInfo = $application->nium_baas_customer_info;

        $this->amlService->setApplication($application);
        $userAmlDatasFromAML = $this->amlService->postUserInfo(queryIds: [$aml_uuid], idType: 'paymentMethod');

        $content = $this->niumBaaSOnboardService->mapNiumBaaSOnboardCustomerFromOwlTingAML($userAmlDatasFromAML[0], $application);

        file_put_contents('nium_baas_onboard_data_'.$aml_uuid.'_origin.json', json_encode($content, JSON_UNESCAPED_UNICODE));

        $filteredData = self::filterKey($content, filterKey: 'documentDetails');

        file_put_contents('nium_baas_onboard_data_'.$aml_uuid.'_base.json', json_encode($filteredData, JSON_UNESCAPED_UNICODE));

        // debug
        // $content = json_decode(file_get_contents("nium_baas_onboard_data_4652_origin.json"), true);

        $filteredUploadData = self::filterUploadData($content);

        $niumCustomer = $this->niumBaaSOnboardService->fetchCustomerById(nium_baas_client_info: $niumBaasCustomerInfo->nium_baas_client_info, customer_id: $niumBaasCustomerInfo->customer_id);

        $filteredUploadData = $this->mapNiumBaaSOnboardUploadDocumentByOnboardResponse($filteredUploadData, $niumCustomer);

        file_put_contents('nium_baas_onboard_data_'.$aml_uuid.'_upload_data.json', json_encode($filteredUploadData, JSON_UNESCAPED_UNICODE));

        $this->splitUploadDataToFiles($filteredUploadData, $aml_uuid);

        file_put_contents('nium_baas_onboard_data_'.$aml_uuid.'_upload_data.json', json_encode($filteredUploadData, JSON_UNESCAPED_UNICODE));

        $this->info('[NiumBaas Generate onboard data] success');
    }

    public function filterKey($array, $filterKey)
    {
        foreach ($array as $key => $value) {
            if ($key === $filterKey) {
                unset($array[$key]);
            } elseif (is_array($value)) {
                $array[$key] = self::filterKey($value, $filterKey);
            }
        }

        return $array;
    }

    public function filterUploadData($content)
    {
        $results = [];

        if (isset($content['businessDetails']['documentDetails'])) {
            $results['businessDetails'] = [
                'documentDetails' => $content['businessDetails']['documentDetails'],
            ];
        }

        if (isset($content['businessDetails']['stakeholders'])) {
            foreach ($content['businessDetails']['stakeholders'] as $key => $stakeholder) {
                if (isset($stakeholder['stakeholderDetails']['documentDetails'])) {
                    $results['businessDetails']['stakeholders'][$key]['stakeholderDetails']['firstName'] = $stakeholder['stakeholderDetails']['firstName'];
                    $results['businessDetails']['stakeholders'][$key]['stakeholderDetails']['middleName'] = $stakeholder['stakeholderDetails']['middleName'] ?? null;
                    $results['businessDetails']['stakeholders'][$key]['stakeholderDetails']['lastName'] = $stakeholder['stakeholderDetails']['lastName'];
                    $results['businessDetails']['stakeholders'][$key]['stakeholderDetails']['address'] = $stakeholder['stakeholderDetails']['address'];
                    $results['businessDetails']['stakeholders'][$key]['stakeholderDetails']['documentDetails'] = $stakeholder['stakeholderDetails']['documentDetails'];
                }
            }
        }
        if (isset($content['businessDetails']['applicantDetails']['documentDetails'])) {
            $results['businessDetails']['applicantDetails']['documentDetails'] = $content['businessDetails']['applicantDetails']['documentDetails'];
        }

        return $results;
    }

    public function mapNiumBaaSOnboardUploadDocumentByOnboardResponse($filteredUploadData, $niumCustomer)
    {
        // mapping root document
        $businessDetailsReferenceId = $niumCustomer['corporateCustomer']['businessDetails']['referenceId'];

        // mapping stakeholders
        foreach ($niumCustomer['corporateCustomer']['businessDetails']['stakeholders'] as $stakeholder) {
            if (isset($stakeholder['stakeholderDetails'])) {
                $stakeholderDetail = $stakeholder['stakeholderDetails'];
                $nameAndAddress = implode('_',
                    [
                        $stakeholderDetail['firstName'],
                        $stakeholderDetail['middleName'],
                        $stakeholderDetail['lastName'],
                        $stakeholderDetail['address']['addressLine2'],
                    ]
                );
                $stakeholderDetailReferenceIds[$nameAndAddress] = $stakeholder['referenceId'];
            }
        }
        $filteredUploadData['region'] = $niumCustomer['corporateCustomer']['complianceRegion'];

        $filteredUploadData['businessDetails']['referenceId'] = $businessDetailsReferenceId;

        foreach ($filteredUploadData['businessDetails']['stakeholders'] as $key => $stakeholder) {
            $stakeholderDetail = $stakeholder['stakeholderDetails'];
            $nameAndAddress = implode('_',
                [
                    $stakeholderDetail['firstName'],
                    $stakeholderDetail['middleName'],
                    $stakeholderDetail['lastName'],
                    $stakeholderDetail['address']['addressLine2'],
                ]
            );

            if (in_array($nameAndAddress, array_keys($stakeholderDetailReferenceIds))) {
                unset($filteredUploadData['businessDetails']['stakeholders'][$key]['stakeholderDetails']['firstName']);
                unset($filteredUploadData['businessDetails']['stakeholders'][$key]['stakeholderDetails']['middleName']);
                unset($filteredUploadData['businessDetails']['stakeholders'][$key]['stakeholderDetails']['lastName']);
                unset($filteredUploadData['businessDetails']['stakeholders'][$key]['stakeholderDetails']['address']);
                $filteredUploadData['businessDetails']['stakeholders'][$key]['referenceId'] = $stakeholderDetailReferenceIds[$nameAndAddress];
            }
        }

        // mapping applicantDetails
        if (isset($filteredUploadData['businessDetails']['applicantDetails'])) {
            $filteredUploadData['businessDetails']['applicantDetails']['referenceId'] = $niumCustomer['corporateCustomer']['businessDetails']['applicantDetails']['referenceId'];
        }

        return $filteredUploadData;
    }

    public function splitUploadDataToFiles($filteredUploadData, $aml_uuid)
    {
        $companyResult = [];
        $stakeResult = [];
        $applicantResult = [];
        // split company files
        foreach ($filteredUploadData['businessDetails']['documentDetails'] as $key => $documentDetails) {
            foreach ($documentDetails['document'] as $documentKey => $document) {
                $companyResult['region'] = $filteredUploadData['region'];
                $companyResult['businessDetails']['referenceId'] = $filteredUploadData['businessDetails']['referenceId'];
                $companyResult['businessDetails']['documentDetails'][$key]['documentType'] = $documentDetails['documentType'];
                $companyResult['businessDetails']['documentDetails'][$key]['document'][] = $document;
                $companyResult['businessDetails']['documentDetails'] = array_values($companyResult['businessDetails']['documentDetails']);
                file_put_contents('nium_baas_onboard_data_'.$aml_uuid.'_upload_data-company-'.$key.'-'.$documentKey.'.json', json_encode($companyResult, JSON_UNESCAPED_UNICODE));
                $companyResult = [];
            }
        }

        // split stakeholders files
        foreach ($filteredUploadData['businessDetails']['stakeholders'] as $key => $stakeholder) {
            // file_put_contents('stakeholders.json', json_encode($stakeholder, JSON_PRETTY_PRINT));
            foreach ($stakeholder['stakeholderDetails']['documentDetails'] as $subKey => $documentDetails) {
                foreach ($documentDetails['document'] as $documentKey => $document) {
                    $stakeResult['region'] = $filteredUploadData['region'];
                    $subKeyArray = array_values([
                        $subKey => [
                            'documentType' => $documentDetails['documentType'],
                            'documentNumber' => $documentDetails['documentNumber'],
                            'documentIssuanceCountry' => $documentDetails['documentIssuanceCountry'],
                            'documentExpiryDate' => $documentDetails['documentExpiryDate'],
                            'document' => $document,
                        ],
                    ]);
                    $stakeResult['businessDetails']['stakeholders'][] = [
                        'referenceId' => $stakeholder['referenceId'],
                        'stakeholderDetails' => [
                            'documentDetails' => $subKeyArray,
                        ],
                    ];
                    file_put_contents('nium_baas_onboard_data_'.$aml_uuid.'_upload_data-stakeholders-'.$key.'-'.$subKey.'-'.$documentKey.'.json', json_encode($stakeResult, JSON_UNESCAPED_UNICODE));
                    $stakeResult = [];
                }
            }
        }

        // split applicant files
        foreach ($filteredUploadData['businessDetails']['applicantDetails']['documentDetails'] as $key => $documentDetails) {
            foreach ($documentDetails['document'] as $documentKey => $document) {
                $applicantResult['region'] = $filteredUploadData['region'];
                $applicantResult['businessDetails']['applicantDetails']['referenceId'] = $filteredUploadData['businessDetails']['applicantDetails']['referenceId'];
                $applicantResult['businessDetails']['applicantDetails']['documentDetails'][$key]['documentType'] = $documentDetails['documentType'];
                $applicantResult['businessDetails']['applicantDetails']['documentDetails'][$key]['documentNumber'] = $documentDetails['documentNumber'];
                $applicantResult['businessDetails']['applicantDetails']['documentDetails'][$key]['documentIssuanceCountry'] = $documentDetails['documentIssuanceCountry'];
                $applicantResult['businessDetails']['applicantDetails']['documentDetails'][$key]['documentExpiryDate'] = $documentDetails['documentExpiryDate'];
                $applicantResult['businessDetails']['applicantDetails']['documentDetails'][$key]['document'][] = $document;
                $applicantResult['businessDetails']['applicantDetails']['documentDetails'] = array_values($applicantResult['businessDetails']['applicantDetails']['documentDetails']);
                file_put_contents('nium_baas_onboard_data_'.$aml_uuid.'_upload_data-applicant-'.$key.'-'.$documentKey.'.json', json_encode($applicantResult, JSON_UNESCAPED_UNICODE));
                $applicantResult = [];
            }
        }
    }
}
