<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\BaseInformationTypeEnum;
use App\Enums\ErrorEnum;
use App\Enums\FeePayerEnum;
use App\Enums\PayoutChannel\CrossBorderPayoutEnum;
use App\Enums\PayoutChannel\NiumBaaSEnum;
use App\Enums\PolicyTypeEnum;
use App\Events\NiumBaaSOnBoardingSubmitEvent;
use App\Events\Payout\PayoutGatewayFeeSyncEvent;
use App\Exceptions\HttpException\NiumBaasOnboardException;
use App\Services\AMLService;
use App\Services\ApplicationService;
use App\Services\BaseInformationService;
use App\Services\Payout\NiumBaaSOnboardService;
use App\Services\Payout\NiumBaaSPayoutService;
use App\Services\PolicyService;
use Illuminate\Console\Command;

class NiumBaaSCustomerOfflineOnboardTool extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium_baas:offline-onboard
            {--mode=}
            {--application_uuid=}
            {--nium_baas_customer_id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This tool is used to complete the customer onboarding process for Nium BaaS offline.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private AmlService $amlService,
        private ApplicationService $applicationService,
        private BaseInformationService $baseInformationService,
        private NiumBaaSOnboardService $niumBaaSOnboardService,
        private NiumBaaSPayoutService $niumBaaSPayoutService,
        private PolicyService $policyService,
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
        $mode = trim($this->option('mode'));
        $applicationUuid = trim($this->option('application_uuid'));
        $niumBaasCustomerId = trim($this->option('nium_baas_customer_id'));

        $modes = [
            'complete_onboarding_process',
        ];

        if (!in_array($mode, $modes)) {
            $this->error('Please input correct mode.');

            $this->info('--mode');
            $this->info('    complete_onboarding_process: Complete customer onboarding process for Nium BaaS.');
            $this->info('==================================Parameters===================================');
            $this->info('--application_uuid=app_rwBG48NVX5yevwnr45mFozx: Pass the OwlPay application uuid');
            $this->info('--nium_baas_customer_id=1e1c9875-d7cf-4364-a614-f9cd58a8246e: Pass the Nium customer id');

            return 0;
        }

        if ('complete_onboarding_process' == $mode) {
            $application = $this->applicationService->getApplicationsByNameOrUUID($applicationUuid)->first();

            $baseInformationService = $this->baseInformationService;

            $amlService = $this->amlService;

            $niumBaaSOnboardService = $this->niumBaaSOnboardService;

            $country = $application->country_iso;

            $region = $niumBaaSOnboardService->getRegionInConfig($country);

            throw_if(is_null($region), new NiumBaasOnboardException('application onboard region not found in config', type: ErrorEnum::NIUM_ONBOARD_REGION_NOT_FOUND_IN_CONFIG, attributes: [
                'application_name' => $application->name,
                'application_uuid' => $application->uuid,
                'application_country' => $country,
            ]));

            $niumBaasClientInfo = $niumBaaSOnboardService->getNiumBaaSClientInfoByRegion($region);

            $isApplicationOnboardNiumBaas = $baseInformationService->isApplicationOnboardGateway($application, gateway: CrossBorderPayoutEnum::NIUM_BAAS);

            throw_if(!$isApplicationOnboardNiumBaas, new NiumBaasOnboardException('nium baas extension not found'));

            $baseInformation = $baseInformationService->getBaseInformationByModel(
                $application,
                BaseInformationTypeEnum::COMPANY,
                payout_gateway: CrossBorderPayoutEnum::NIUM_BAAS,
            );

            $amlService->setApplication($application);
            $userAmlDatasFromAML = $amlService->postUserInfo(
                queryIds: [$baseInformation->aml_uuid],
                idType: 'paymentMethod'
            );

            $applicationCustomerOnboardInfo = $niumBaaSOnboardService->getApplicationCustomerInfo($application);

            // $policy = $this->policyService->getPolicyByType($application, type: PolicyTypeEnum::NIUM_BAAS_ONBOARD_POLICY);
            $policy = null;

            foreach ($userAmlDatasFromAML as $userAmlDataFromAML) {
                $kybData = $niumBaaSOnboardService->mapNiumBaaSOnboardCustomerFromOwlTingAML($userAmlDataFromAML, $application);

                if (empty($applicationCustomerOnboardInfo)) {
                    $niumBaasCustomerDetails = $this->niumBaaSPayoutService->fetchCustomerDetail($niumBaasClientInfo, $niumBaasCustomerId);

                    if (isset($niumBaasCustomerDetails['customerHashId'])) {
                        $this->info('[Nium BaaS] Customer can be found in Nium side.');
                        $onboardData = $niumBaaSOnboardService->extractOnboardingInfoFromCustomerDetails($niumBaasCustomerDetails);
                    } else {
                        $this->info("[Nium BaaS] Customer does not exist on Nium's side.");
                        $onboardData = $niumBaaSOnboardService->onboardCorporateCustomer(
                            $niumBaasClientInfo,
                            $application,
                            kyb_data: $kybData
                        );
                    }

                    $region = $kybData['region'];
                    $onboardInfo = $niumBaaSOnboardService->addCustomerOnboardingInfo(
                        $region,
                        $onboardData,
                    );

                    _owlPayLog('nium_baas_add_onboard_info', [compact('region', 'onboardData')], 'nium_baas', 'info');

                    $niumBaasCustomerInfo = $niumBaaSOnboardService->addCustomerInfo(
                        $niumBaasClientInfo,
                        $application,
                        $onboardInfo['customer_id'],
                        $onboardInfo['wallet_id'],
                        swiftFeeType: $kybData['owlpayData']['swiftFeeType'] ?? FeePayerEnum::OUR,
                        purposeCode: $kybData['owlpayData']['purposeCode'] ?? 'IR01811',
                    );
                    _owlPayLog('nium_baas_add_customer_info', [], 'nium_baas', 'info');
                } else {
                    $niumBaasCustomerInfo = $applicationCustomerOnboardInfo;
                }

                // Ths implementation is duplicated with niumBaaSPayoutService->fetchCustomerDetail, but don't want to change the logic in NiumBaaSPayoutB2B, so leave it as is now and will refine it if needed in the future.
                $niumCustomer = $niumBaaSOnboardService->fetchCustomerById(nium_baas_client_info: $niumBaasCustomerInfo->nium_baas_client_info, customer_id: $niumBaasCustomerInfo->customer_id);

                if (NiumBaaSEnum::CUSTOMER_STATUS_CLEAR != $niumCustomer['status'] &&
                    NiumBaaSEnum::CPL_STATUS_COMPLETED != $niumCustomer['complianceStatus']
                ) {
                    $this->info('[Nium BaaS] Upload corporate customer document.');
                    $niumBaaSOnboardService->uploadCorporateCustomerDocument($niumCustomer, $niumBaasCustomerInfo, $kybData);
                }

                if (!empty($policy)) {
                    $niumBaaSOnboardService->acceptTermsAndCondition($niumBaasClientInfo, $niumBaasCustomerInfo, application: $application, name: $policy->source_policy_name, versionId: $policy->version);
                    _owlPayLog('nium_baas_accept_tnc', [], 'nium_baas', 'info');
                }

                if (!$baseInformation->is_gateway_valid &&
                    NiumBaaSEnum::CUSTOMER_STATUS_CLEAR == $niumCustomer['status'] &&
                    NiumBaaSEnum::CPL_STATUS_COMPLETED == $niumCustomer['complianceStatus']
                ) {
                    $this->info("[Nium BaaS] Update is_gateway_valid for application $application->id.");
                    $this->baseInformationService->updateApplicationIsGatewayValid(
                        applicationId: $application->id,
                        type: BaseInformationTypeEnum::COMPANY,
                        payoutGateway: $this->getPayoutGateway(),
                        isGatewayValid: true,
                    );

                    event(new NiumBaaSOnBoardingSubmitEvent($application, $niumBaasCustomerInfo, $region));
                    $this->info('[Nium BaaS] NiumBaaSOnBoardingSubmitEvent is sent.');
                }
            }

            event(new PayoutGatewayFeeSyncEvent($application, CrossBorderPayoutEnum::NIUM_BAAS));
            $this->info('[Nium BaaS] PayoutGatewayFeeSyncEvent is sent.');
        }

        return 0;
    }
}
