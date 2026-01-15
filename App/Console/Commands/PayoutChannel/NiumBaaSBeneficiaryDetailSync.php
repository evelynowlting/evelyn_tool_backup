<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\BaseInformationStatusEnum;
use App\Enums\BaseInformationTypeEnum;
use App\Enums\PayoutChannel\CrossBorderPayoutEnum;
use App\Models\Vendor;
use App\Services\AMLService;
use App\Services\BaseInformationService;
use App\Services\NiumBaaSBeneficiaryInfoService;
use App\Services\NiumBaasClientInfoService;
use App\Services\Payout\NiumBaaSOnboardService;
use Illuminate\Console\Command;

class NiumBaaSBeneficiaryDetailSync extends Command
{
    protected $signature = 'nium_baas:beneficiary_detail_sync';

    protected $description = 'Sync Nium Baas Beneficiary detail which not latest';

    public function __construct(
        private AMLService $amlService,
        private BaseInformationService $baseInformationService,
        private NiumBaaSBeneficiaryInfoService $niumBaaSBeneficiaryInfoService,
        private NiumBaasClientInfoService $niumBaasClientInfoService,
        private NiumBaaSOnboardService $niumBaaSOnboardService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $niumBaasBeneficiaryInfos = $this->niumBaaSBeneficiaryInfoService->getNonLatest(limit: 200);
        $niumBaasBeneficiaryInfos->load([
            'vendor',
            'vendor.application',
            'vendor.application.nium_baas_customer_info',
        ]);

        $vendors = $niumBaasBeneficiaryInfos->pluck('vendor');
        $vendorIds = $vendors->pluck('id')->toArray();
        $vendorBaseInformations = $this->baseInformationService->getByModelIdsAndType(
            modelIds: $vendorIds,
            modelType: (new Vendor())->getMorphClass(),
            type: BaseInformationTypeEnum::VENDOR_INFORMATION,
            payoutGateway: CrossBorderPayoutEnum::NIUM_BAAS,
            status: BaseInformationStatusEnum::APPROVED,
            isEnable: true,
        );

        $vendorBaseInformationsMap = $vendorBaseInformations->keyBy(function ($vendorBaseInformation) {
            $country = _findCountryRegion($vendorBaseInformation->country) ?? $vendorBaseInformation->country;

            return $vendorBaseInformation->model_id.'-'.$country.'-'.$vendorBaseInformation->currency;
        });

        $niumBaasClientInfos = $this->niumBaasClientInfoService->getAll();
        $countryCodeToNiumBaasClientInfosMap = $niumBaasClientInfos->keyBy('country_code');

        $missingVendorBaseInfoVendors = [];
        $missingAmlDataVendors = [];
        $emptyOnboardDataVendors = [];
        $updateBeneficiaryDetailFailedVendors = [];
        $beneficiaryUpdateData = [];

        foreach ($niumBaasBeneficiaryInfos as $baasBeneficiaryInfo) {
            $vendor = $baasBeneficiaryInfo->vendor;
            $application = $vendor->application;
            $region = $this->getNiumBaasClientRegion($application->country_iso);
            $niumBaasClientInfo = $countryCodeToNiumBaasClientInfosMap[$region] ?? null;

            $destinationCountry = $baasBeneficiaryInfo->destination_country;
            $destinationCurrency = $baasBeneficiaryInfo->destination_currency;

            $country = _findCountryRegion($destinationCountry) ?? $destinationCountry;
            $key = $vendor->id.'-'.$country.'-'.$destinationCurrency;
            $vendorBaseInformation = $vendorBaseInformationsMap[$key] ?? null;
            if (is_null($vendorBaseInformation)) {
                $missingVendorBaseInfoVendors[] = [
                    'vendor_id' => $vendor->id,
                    'country' => $destinationCountry,
                    'currency' => $destinationCurrency,
                ];
                continue;
            }

            $this->amlService->setApplication($application);
            $amlDataList = $this->amlService->postUserInfo(
                queryIds: [$vendorBaseInformation->aml_uuid],
                idType: 'paymentMethod',
                amlUUIdWithCurrency: [
                    $vendorBaseInformation->aml_uuid => $vendorBaseInformation->currency,
                ]
            );

            // amlDataList should only 1 element, because params only assign 1 aml_uuid
            $amlData = array_pop($amlDataList);
            if (is_null($amlData)) {
                $missingAmlDataVendors[] = [
                    'vendor_id' => $vendor->id,
                    'country' => $destinationCountry,
                    'currency' => $destinationCurrency,
                ];
                continue;
            }

            $onboardData = $this->niumBaaSOnboardService->mapNiumBaaSOnboardBeneficiaryFromOwlTingAML($amlData, $application);
            if (empty($onboardData)) {
                $emptyOnboardDataVendors[] = [
                    'vendor_id' => $vendor->id,
                    'country' => $destinationCountry,
                    'currency' => $destinationCurrency,
                    'aml_data' => $amlData,
                ];
                continue;
            }

            $niumBaasCustomerInfo = $vendor->application->nium_baas_customer_info;
            $beneficiaryData = $this->niumBaaSOnboardService->updateBeneficiaryDetails($niumBaasClientInfo, $niumBaasCustomerInfo->customer_id, $baasBeneficiaryInfo->beneficiary_id, $onboardData);
            if (empty($beneficiaryData)) {
                $updateBeneficiaryDetailFailedVendors[] = [
                    'vendor_id' => $vendor->id,
                    'country' => $destinationCountry,
                    'currency' => $destinationCurrency,
                    'aml_data' => $amlData,
                    'beneficiary_info' => $beneficiaryData,
                ];
                continue;
            }

            $beneficiaryUpdateData[] = array_merge(
                [
                    'id' => $baasBeneficiaryInfo->id,
                    'is_sync_latest' => true,
                ],
                $this->niumBaaSOnboardService->getVendorBeneficiaryInfoUpdateAttributesByBeneficiaryData($beneficiaryData)
            );
        }

        if (!empty($missingVendorBaseInfoVendors) || !empty($missingAmlDataVendors) || !empty($emptyOnboardDataVendors) || !empty($updateBeneficiaryDetailFailedVendors)) {
            _owlPayLog('nium_baas_sync_beneficiary_detail_failed', [
                'missing_vendor_base_information_vendors' => $missingVendorBaseInfoVendors,
                'missing_vendor_aml_data_vendors' => $missingAmlDataVendors,
                'empty_onboard_data_vendors' => $emptyOnboardDataVendors,
                'update_beneficiary_info_failed_vendors' => $updateBeneficiaryDetailFailedVendors,
            ], account_type: 'nium_baas', type: 'error');
        }

        if (!empty($beneficiaryUpdateData)) {
            $this->niumBaaSBeneficiaryInfoService->batchUpdate($beneficiaryUpdateData);
        }

        $this->info('updated failed '.(count($niumBaasBeneficiaryInfos) - count($beneficiaryUpdateData)).' nium bass beneficiary detail.');
        $this->info('updated success '.count($beneficiaryUpdateData).' nium bass beneficiary detail.');

        return Command::SUCCESS;
    }

    private function getNiumBaasClientRegion(string $country): string|int
    {
        $businessRegionMap = config('payoutchannel.niumBaaS.business_region_map');
        $region = null;
        foreach ($businessRegionMap as $regionKey => $countryList) {
            if (in_array($country, $countryList)) {
                $region = $regionKey;
                break;
            }
        }

        return $region;
    }
}
