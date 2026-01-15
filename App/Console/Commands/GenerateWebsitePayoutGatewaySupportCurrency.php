<?php

namespace App\Console\Commands;

use App\Enums\PayoutChannel\CrossBorderPayoutEnum;
use App\Models\Application;
use App\Models\NiumBaaSCustomerInfo;
use App\Models\WebsitePayoutGatewaySupportCurrency;
use App\PayoutGateways\NiumBaaSPayoutB2B;
use App\Repositories\NiumBaasCalculatorTemplatesRepository;
use App\Repositories\NiumBaasClientInfoRepository;
use App\Repositories\WebsitePayoutGatewaySupportCurrencyRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mavinoo\Batch\BatchFacade as Batch;

class GenerateWebsitePayoutGatewaySupportCurrency extends Command
{
    protected $signature = 'generate:website_payout_gateway_support_currencies';

    protected $description = 'Generate website payout gateway support currencies';

    public function __construct(
        private WebsitePayoutGatewaySupportCurrencyRepository $websitePayoutGatewaySupportCurrencyRepository,
        private NiumBaasCalculatorTemplatesRepository $niumBaasCalculatorTemplatesRepository,
        private NiumBaasClientInfoRepository $niumBaasClientInfoRepository,
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $existedWebsitePayoutGatewaySupportCurrencies = $this->websitePayoutGatewaySupportCurrencyRepository->getWebsitePayoutGatewaySupportCurrencies([], []);

        $existedWebsitePayoutGatewaySupportCurrenciesByKey = $existedWebsitePayoutGatewaySupportCurrencies->keyBy(function ($item) {
            return $item->source_country_iso.$item->target_country_iso.$item->source_currency.$item->target_currency.$item->gateway;
        })->toArray();

        $configWebsitePayoutGateways = config('website_payout_gateway.gateway_setting_data');

        $paymentCurrencies = config('website_payout_gateway.payment_currencies');

        $insertData = [];

        $niumBaasCalculatorTemplateSG = $this->niumBaasCalculatorTemplatesRepository->getDefaultTemplateByCountry('SG');
        $niumBaasCalculatorTemplateSG->load(['niumBaasCalculatorExchangeRateItems']);
        $currencyToNiumBaaSExchangeRateItems = $niumBaasCalculatorTemplateSG->niumBaasCalculatorExchangeRateItems->groupBy('currency');

        $niumBaasClientInfo = $this->niumBaasClientInfoRepository->getByCountry('SG');
        $fakeApplication = app(Application::class);
        $fakeNiumBaasCustomerInfo = app(NiumBaaSCustomerInfo::class, ['customer_id' => 'fake', 'wallet_id' => 'fake']);
        $fakeNiumBaasCustomerInfo->nium_baas_client_info = $niumBaasClientInfo;
        $fakeApplication->nium_baas_customer_info = $fakeNiumBaasCustomerInfo;
        $niumBaasPayoutGatewayInstance = app(NiumBaaSPayoutB2B::class, ['application' => $fakeApplication]);

        Log::info('website payout gateway mapping data start');

        foreach ($configWebsitePayoutGateways as $configWebsitePayoutGateway) {
            $sourceCurrencies = $configWebsitePayoutGateway['source_currencies'];

            $feePrices = $configWebsitePayoutGateway['fee_prices'];

            $sourceCountries = $configWebsitePayoutGateway['source_countries'];

            $targetCountries = $configWebsitePayoutGateway['target_countries'];

            $gateway = $configWebsitePayoutGateway['gateway'];

            $feeCurrency = $configWebsitePayoutGateway['fee_currency'];

            $allowedLocalCurrency = $configWebsitePayoutGateway['allowed_local_currency'] ?? [];

            $isCrossBoardGateway = CrossBorderPayoutEnum::isValid($gateway);

            foreach ($sourceCountries as $sourceCountry) {
                foreach ($targetCountries as $targetCountry => $currency) {
                    foreach ($sourceCurrencies as $sourceCurrency) {
                        $targetCurrencies = $this->getTargetCurrencies($currency, $gateway);

                        foreach ($targetCurrencies as $targetCurrency) {
                            $isLocalCurrency = $niumBaasPayoutGatewayInstance->isCalculatorLocalCurrency(country: $targetCountry, currency: $targetCurrency);
                            $isAllowCurrency = false;

                            // cross board payout might not support local currency
                            if ($isCrossBoardGateway && $isLocalCurrency && !in_array($currency, $allowedLocalCurrency)) {
                                continue;
                            }

                            // 目前可以支援的幣別
                            if (in_array($sourceCurrency, $paymentCurrencies) ||
                                ('TW' == $targetCountry && 'TWD' == $sourceCurrency && 'TWD' == $targetCurrency)) {
                                $isAllowCurrency = true;
                            }

                            if (CrossBorderPayoutEnum::VISA_VPA == $gateway) {
                                $fee_rate = 0.35;
                                $sourceCurrency = $targetCurrency;
                                $isAllowCurrency = true;
                            }

                            $feePrice = $feePrices[$currency] ?? 0;
                            $feeExchangeRateAfterMarkUp = 0;
                            if (CrossBorderPayoutEnum::NIUM_BAAS === $gateway) {
                                $exchangeRateItem = $currencyToNiumBaaSExchangeRateItems[$targetCurrency]->first();
                                $feePrice = $isLocalCurrency ? $exchangeRateItem->transaction_fee_after_mark_up : 10;
                                // nium baas owlpay markup 對外固定 0.35 方便吸引客戶
                                $feeExchangeRateAfterMarkUp = $exchangeRateItem->fee_rate_nium + 0.35;
                            }

                            $insertIndexKey = $sourceCountry.$targetCountry.$sourceCurrency.$targetCurrency.$gateway;

                            $insertData[$insertIndexKey] = [
                                'source_country_iso' => $sourceCountry,
                                'target_country_iso' => $targetCountry,
                                'source_currency' => $sourceCurrency,
                                'target_currency' => $targetCurrency,
                                'gateway' => $gateway,
                                'fee_price' => _convertTotalToAmount($feePrice, $feeCurrency),
                                'fee_exchange_rate_mark_up' => $feeExchangeRateAfterMarkUp,
                                'fee_rate' => $fee_rate ?? 0,
                                'fee_currency' => $feeCurrency,
                                'type' => $configWebsitePayoutGateway['type'],
                                'is_allow_currency' => $isAllowCurrency,
                            ];

                            if (isset($existedWebsitePayoutGatewaySupportCurrenciesByKey[$insertIndexKey])) {
                                $insertData[$insertIndexKey]['id'] = $existedWebsitePayoutGatewaySupportCurrenciesByKey[$insertIndexKey]['id'];
                            }
                        }
                    }
                }
            }
        }

        Log::info('website payout gateway mapping data end');

        $updatedInput = array_intersect_key($insertData, $existedWebsitePayoutGatewaySupportCurrenciesByKey);
        $deletedInput = array_diff_key($existedWebsitePayoutGatewaySupportCurrenciesByKey, $insertData);
        $createdInput = array_diff_key($insertData, $updatedInput);

        if (!empty($updatedInput)) {
            $chunked = array_chunk($updatedInput, 500);
            foreach ($chunked as $chunk) {
                $websiteUpdated = Batch::update(new WebsitePayoutGatewaySupportCurrency(), array_values($chunk), 'id');

                _owlPayLog('update_success', [
                    'website_updated' => $websiteUpdated,
                ], 'system');
            }
        }

        if (!empty($createdInput)) {
            $websiteInput = array_values($createdInput);

            $websiteCreated = Batch::insert(new WebsitePayoutGatewaySupportCurrency(), array_keys($websiteInput[0]), $websiteInput);

            _owlPayLog('create_success', [
                'website_created' => $websiteCreated,
            ], 'system');
        }

        if (!empty($deletedInput)) {
            $deleteIds = array_column($deletedInput, 'id');
            $chunkDeleteIds = array_chunk($deleteIds, 500);
            foreach ($chunkDeleteIds as $deleteIds) {
                $this->websitePayoutGatewaySupportCurrencyRepository->destroy($deleteIds);
            }

            _owlPayLog('delete_success', [
                'deleted_input' => $deleteIds,
            ], 'system');
        }

        $env = config('app.env');
        Cache::pull("website_countries:$env");
        Cache::pull("website_payout_gateways:$env");

        $this->info('website payout gateway support currencies created.');
    }

    private function getTargetCurrencies(string $currency, string $gateway): array
    {
        $targetCurrencies = [$currency];
        if (CrossBorderPayoutEnum::NIUM_BAAS === $gateway) {
            $targetCurrencies = array_merge($targetCurrencies, ['USD', 'EUR', 'GBP', 'SGD', 'HKD', 'AUD', 'JPY']);
            $targetCurrencies = array_unique($targetCurrencies);
        }

        return $targetCurrencies;
    }
}
