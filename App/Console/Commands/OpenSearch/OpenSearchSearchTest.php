<?php

namespace App\Console\Commands\OpenSearch;

use App\Services\OpenSearch\AccountingService as OpenSearchAccountingService;
use App\Services\OpenSearch\OrderReconciliationService as OpenSearchOrderReconciliationService;
use App\Services\OpenSearch\OrderService as OpenSearchOrderService;
use App\Services\OpenSearch\PayoutService as OpenSearchPayoutService;
use App\Services\OpenSearch\RecordService as OpenSearchRecordService;
use Illuminate\Console\Command;

class OpenSearchSearchTest extends Command
{
    protected $signature = 'opensearch:search:test
                            {application_id : application id }
                            {text : text you want to search}
                            {--is_test=0 : is test mode or not}
                            {--size=5 : row size each type}
                            {--target=all : what object you want to search, can be order, order_reconciliation, accounting, payout, record, all}
                            ';

    protected $description = 'opensearch search test';

    public function __construct(
        private OpenSearchOrderService $openSearchOrderService,
        private OpenSearchOrderReconciliationService $openSearchOrderReconciliationService,
        private OpenSearchAccountingService $openSearchAccountingService,
        private OpenSearchPayoutService $openSearchPayoutService,
        private OpenSearchRecordService $openSearchRecordService,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $text = $this->argument('text');
        $applicationId = $this->argument('application_id');
        $size = $this->option('size');
        $isTest = (bool) $this->option('is_test');
        $target = $this->option('target');

        $analyzeResult = $this->openSearchOrderService->analyze($text);
        $tokens = $this->extraAnalyzeResult($analyzeResult);
        $this->info(date('Y-m-d H:i:s').' tokenized result: '.json_encode($tokens, JSON_UNESCAPED_UNICODE));

        $startTime = microtime(true);

        $orders = [];
        if (in_array($target, ['all', 'order'])) {
            $orderStartTime = microtime(true);

            $result = $this->openSearchOrderService->globalSearch(
                text: $text,
                applicationId: $applicationId,
                isTest: $isTest,
                column: ['uuid', 'application_id', 'application_order_serial', 'description'],
                size: $size,
            );

            $orderCostTime = round(microtime(true) - $orderStartTime, 4);
            $this->info(date('Y-m-d H:i:s').' search orders cost '.$orderCostTime.' seconds');

            $orders = $this->extractSearchResult($result);
        }

        $orderReconciliations = [];
        if (in_array($target, ['all', 'order_reconciliation'])) {
            $orderReconciliationStartTime = microtime(true);

            $result = $this->openSearchOrderReconciliationService->globalSearch(
                text: $text,
                applicationId: $applicationId,
                isTest: $isTest,
                column: ['uuid', 'application_id', 'uuid', 'group_uuid', 'description'],
                size: $size,
            );

            $orderReconciliationsCostTime = round(microtime(true) - $orderReconciliationStartTime, 4);
            $this->info(date('Y-m-d H:i:s').' search order_reconciliations cost '.$orderReconciliationsCostTime.' seconds');

            $orderReconciliations = $this->extractSearchResult($result);
        }

        $accountings = [];
        if (in_array($target, ['all', 'accounting'])) {
            $accountingStartTime = microtime(true);

            $result = $this->openSearchAccountingService->globalSearch(
                text: $text,
                applicationId: $applicationId,
                isTest: $isTest,
                column: ['uuid', 'application_id', 'uuid', 'description'],
                size: $size,
            );

            $accountingCostTime = round(microtime(true) - $accountingStartTime, 4);
            $this->info(date('Y-m-d H:i:s').' search accounting cost '.$accountingCostTime.' seconds');

            $accountings = $this->extractSearchResult($result);
        }

        $payouts = [];
        if (in_array($target, ['all', 'payout'])) {
            $payoutStartTime = microtime(true);
            $result = $this->openSearchPayoutService->globalSearch(
                text: $text,
                applicationId: $applicationId,
                isTest: $isTest,
                column: ['uuid', 'application_id', 'description'],
                size: $size
            );

            $payoutCostTime = round(microtime(true) - $payoutStartTime, 4);
            $this->info(date('Y-m-d H:i:s').' search payout cost '.$payoutCostTime.' seconds');
            $payouts = $this->extractSearchResult($result);
        }

        $records = [];
        if (in_array($target, ['all', 'record'])) {
            $recordStartTime = microtime(true);
            $result = $this->openSearchRecordService->globalSearch(
                text: $text,
                applicationId: $applicationId,
                isTest: $isTest,
                column: ['uuid', 'application_id', 'path', 'route_name', 'action'],
                size: $size,
            );

            $recordCostTime = round(microtime(true) - $recordStartTime, 4);
            $this->info(date('Y-m-d H:i:s').' search records cost '.$recordCostTime.' seconds');

            $records = $this->extractSearchResult($result);
        }

        $result = [
            'orders' => $orders,
            'order_reconciliations' => $orderReconciliations,
            'accountings' => $accountings,
            'payouts' => $payouts,
            'records' => $records,
        ];

        $costTime = round(microtime(true) - $startTime, 4);
        $this->info(date('Y-m-d H:i:s').' all search cost '.$costTime.' seconds');

        $this->info('result: ');
        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
    }

    private function extractSearchResult(array $result): array
    {
        $data = [];
        $items = $result['hits']['hits'];
        foreach ($items as $item) {
            $data[] = array_merge(
                ['id' => $item['_id']],
                $item['_source'],
            );
        }

        return $data;
    }

    private function extraAnalyzeResult(array $result): array
    {
        $terms = [];
        $tokens = $result['tokens'] ?? [];
        foreach ($tokens as $token) {
            $terms[] = $token['token'];
        }

        return $terms;
    }
}
