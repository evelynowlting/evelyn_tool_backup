<?php

namespace App\Console\Commands\PayoutChannel;

use App\PayoutGateways\NiumBaaSPayoutB2B;
use App\Services\NiumBaasCalculatorExchangeRateItemsService;
use App\Services\NiumBaasCalculatorTemplatesService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NiumBaasCalculatorTemplateSync extends Command
{
    protected $signature = 'nium_baas:sync_nium_calculator_template';

    protected $description = 'Sync nium baas fee segment and write is as nium_baas_calculator_template.';

    public function __construct(
        private NiumBaaSPayoutB2B $niumBaaSPayoutB2B,
        private NiumBaasCalculatorTemplatesService $niumBaasCalculatorTemplatesService,
        private NiumBaasCalculatorExchangeRateItemsService $niumBaasCalculatorExchangeRateItemsService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $feeMap = $this->niumBaaSPayoutB2B->getFeeMap();
        $niumBaasContractInformationDetails = config('payoutchannel.niumBaaS.contract_information.detail');

        $currencyToNiumBaasContractInformationDetailsMap = collect($niumBaasContractInformationDetails)->groupBy('currency')->toArray();

        $niumBaasCalculatorTemplatesBatchInsertData = [];
        $niumBaasCalculatorTemplatesBatchUpdateData = [];
        $niumBaasCalculatorExchangeRateBatchInsertData = [];
        $exchangeRateBatchInsertDataMap = [];

        $niumBaasCalculatorTemplates = $this->niumBaasCalculatorTemplatesService->getAll();
        $niumBaasCalculatorTemplateMap = $niumBaasCalculatorTemplates->keyBy(function ($niumBaasCalculatorTemplate) {
            return "$niumBaasCalculatorTemplate->name-$niumBaasCalculatorTemplate->country";
        });

        $existedTemplatedIds = $niumBaasCalculatorTemplates->pluck('id')->toArray();
        $niumBaasCalculatorTemplateExchangeRateItems = $this->niumBaasCalculatorExchangeRateItemsService->getByNiumBaasCalculatorTemplatesIds($existedTemplatedIds, ['id']);
        $existedTemplatedExchangeRateItemsIds = $niumBaasCalculatorTemplateExchangeRateItems->pluck('id')->toArray();

        /**
         * $feeMap example:
         * [
         *   'SG' => [
         *     'DEFAULT' => [
         *        'SAR' => [
         *           'FX_MARKUP' => [...],
         *           'REMIT_BANK_FEE' => [...],
         *        ],
         *        'USD' => [...],
         *        ...
         *     ],
         *     'SEGMENT 2' => [...],
         *   ],
         * ].
         */
        $now = Carbon::now();
        foreach ($feeMap as $country => $segments) {
            foreach ($segments as $segmentName => $segment) {
                // skip defaultSegment string attribute
                if ('defaultSegment' == $segmentName) {
                    continue;
                }
                $uniqueKey = "$segmentName-$country";

                // existed template
                if (isset($niumBaasCalculatorTemplateMap[$uniqueKey])) {
                    $niumBaasCalculatorTemplate = $niumBaasCalculatorTemplateMap[$uniqueKey];
                    $niumBaasCalculatorTemplatesBatchUpdateData[] = [
                        'id' => $niumBaasCalculatorTemplate->id,
                        'updated_at' => $now,
                    ];
                } else { // new template
                    $niumBaasCalculatorTemplatesBatchInsertData[] = [
                        'name' => $segmentName,
                        'country' => $country,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                $exchangeRateItems = [];

                foreach ($segment as $currency => $feeConfig) {
                    $contractDetails = $currencyToNiumBaasContractInformationDetailsMap[$currency] ?? [];
                    // one currency may contain multiple fee config (2B, 2P, P2P, wallet ..etc.)
                    foreach ($contractDetails as $contractDetail) {
                        $exchangeRateItems[] = [
                            'location' => $contractDetail['location'],
                            'currency' => $currency,
                            'fee_rate_nium' => $contractDetail['fx_fee_rate'],
                            'fee_rate_mark_up' => $feeConfig['FX_MARKUP']['feeValue'] - $contractDetail['fx_fee_rate'],
                            'fee_rate_after_mark_up' => $feeConfig['FX_MARKUP']['feeValue'],
                            'transaction_fee_nium' => $contractDetail['transaction_fee'],
                            'transaction_fee_mark_up' => $feeConfig['REMIT_BANK_FEE']['feeValue'] - $contractDetail['transaction_fee'],
                            'transaction_fee_after_mark_up' => $feeConfig['REMIT_BANK_FEE']['feeValue'],
                            'sequence' => $contractDetail['sequence'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                $exchangeRateBatchInsertDataMap[$uniqueKey] = $exchangeRateItems;
            }
        }

        try {
            DB::beginTransaction();

            // batch insert, update template
            if (!empty($niumBaasCalculatorTemplatesBatchUpdateData)) {
                $this->niumBaasCalculatorTemplatesService->batchUpdate($niumBaasCalculatorTemplatesBatchUpdateData);
            }

            if (!empty($niumBaasCalculatorTemplatesBatchInsertData)) {
                $this->niumBaasCalculatorTemplatesService->batchInsert($niumBaasCalculatorTemplatesBatchInsertData);
            }

            // delete all existed template exchange rate items
            if (!empty($existedTemplatedExchangeRateItemsIds)) {
                $this->niumBaasCalculatorExchangeRateItemsService->deleteByIds($existedTemplatedExchangeRateItemsIds);
            }

            // update nium_baas_calculator_templates_id to nium_baas_calculator_exchange_rate_items batch insert data
            $niumBaasCalculatorTemplates = $this->niumBaasCalculatorTemplatesService->getAll();
            foreach ($niumBaasCalculatorTemplates as $niumBaasCalculatorTemplate) {
                $uniqueKey = "$niumBaasCalculatorTemplate->name-$niumBaasCalculatorTemplate->country";
                if (!isset($exchangeRateBatchInsertDataMap[$uniqueKey])) {
                    $niumBaasCalculatorTemplate->delete();
                    continue;
                }

                $exchangeRateBatchInsertData = $exchangeRateBatchInsertDataMap[$uniqueKey];
                foreach ($exchangeRateBatchInsertData as $exchangeRateBatchInsertDatum) {
                    $exchangeRateBatchInsertDatum['nium_baas_calculator_templates_id'] = $niumBaasCalculatorTemplate->id;
                    $niumBaasCalculatorExchangeRateBatchInsertData[] = $exchangeRateBatchInsertDatum;
                }
            }

            $this->niumBaasCalculatorExchangeRateItemsService->batchInsert($niumBaasCalculatorExchangeRateBatchInsertData);

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            _owlPayLog('nium_baas_calculator_template_sync_failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTrace(),
            ], 'nium_baas', 'error');

            throw $e;
        }

        return Command::SUCCESS;
    }
}
