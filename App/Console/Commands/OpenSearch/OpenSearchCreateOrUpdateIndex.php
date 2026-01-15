<?php

namespace App\Console\Commands\OpenSearch;

use App\Services\OpenSearch\AccountingService;
use App\Services\OpenSearch\OrderReconciliationService;
use App\Services\OpenSearch\OrderService;
use App\Services\OpenSearch\PayoutService;
use App\Services\OpenSearch\RecordService;
use Illuminate\Console\Command;

class OpenSearchCreateOrUpdateIndex extends Command
{
    protected $signature = 'opensearch:index
                            {table_name : order, order_reconciliation, accounting, payout, record}
                            {action : create - create index, and if it existed, abort thr process. update - update index setting and mapping, but part setting parameter might cause update failure. delete_and_create - delete index if it existed and create it}
                            ';

    protected $description = 'opensearch create index
                                  action option:
                                    create - default, create index, and if it already existed, abort thr process.
                                    update - update index mapping, but part setting parameter might cause update failure
                                    delete_and_create - delete index if it existed and create it
                                  ';

    public function __construct(
        private OrderService $orderService,
        private OrderReconciliationService $orderReconciliationService,
        private AccountingService $accountingService,
        private PayoutService $payoutService,
        private RecordService $recordService,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->info(date('Y-m-d H:i:s').' '.__CLASS__.' command start');
        $startTime = microtime(true);

        $tableName = $this->argument('table_name');
        $action = $this->argument('action');
        $service = $this->getService($tableName);
        $indexMapping = $service->getCurrentIndexMapping();
        $isIndexExisted = !empty($indexMapping);

        $indexName = $service->getIndexName();
        switch ($action) {
            case 'create':
                if ($isIndexExisted) {
                    $this->info("index $indexName already exist.");
                    break;
                }

                $service->createIndex();
                break;
            case 'update':
                if (!$isIndexExisted) {
                    $this->info("index $indexName not exist.");
                    break;
                }

                $service->updateIndexMapping();
                break;
            case 'delete_and_create':
                if ($isIndexExisted) {
                    $service->deleteIndex();
                }

                $service->createIndex();

                break;
            default:
                $this->error('action not support.');
        }

        $costTime = round(microtime(true) - $startTime, 4);
        $this->info(date('Y-m-d H:i:s').' '.__CLASS__.' command end.');
        $this->info(date('Y-m-d H:i:s').' '.__CLASS__.' command cost '.$costTime.' seconds');
    }

    private function getService(string $tableName)
    {
        switch ($tableName) {
            case 'order':
                return $this->orderService;
            case 'order_reconciliation':
                return $this->orderReconciliationService;
            case 'accounting':
                return $this->accountingService;
            case 'payout':
                return $this->payoutService;
            case 'record':
                return $this->recordService;
            default:
                $this->error('table name not support');
        }
    }
}
