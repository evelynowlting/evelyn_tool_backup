<?php

namespace App\Console\Commands\OpenSearch;

use App\Services\AccountingService;
use App\Services\OpenSearch\AccountingService as OpenSearchAccountingService;
use App\Services\OpenSearch\OrderReconciliationService as OpenSearchOrderReconciliationService;
use App\Services\OpenSearch\OrderService as OpenSearchOrderService;
use App\Services\OpenSearch\PayoutService as OpenSearchPayoutService;
use App\Services\OpenSearch\RecordService as OpenSearchRecordService;
use App\Services\OrderReconciliationService;
use App\Services\OrderService;
use App\Services\PayoutService;
use App\Services\RecordService;
use Exception;
use Illuminate\Console\Command;

class OpenSearchImportDocuments extends Command
{
    protected $signature = 'opensearch:import
                            {table_name : order, order_reconciliation, accounting, payout, record}
                            {--size=5000}
                            {--start_id=0}
                            ';

    protected $description = 'opensearch import orders';

    public function __construct(
        private OrderService $orderService,
        private OpenSearchOrderService $openSearchOrderService,
        private OrderReconciliationService $orderReconciliationService,
        private OpenSearchOrderReconciliationService $openSearchOrderReconciliationService,
        private AccountingService $accountingService,
        private OpenSearchAccountingService $openSearchAccountingService,
        private PayoutService $payoutService,
        private OpenSearchPayoutService $openSearchPayoutService,
        private RecordService $recordService,
        private OpenSearchRecordService $openSearchRecordService,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $this->info(date('Y-m-d H:i:s').' '.__CLASS__.' command start');
        $startTime = microtime(true);

        $tableName = $this->argument('table_name');
        $size = (int) $this->option('size');
        $startId = (int) $this->option('start_id');

        $service = $this->getService($tableName);
        $openSearchService = $this->getOpenSearchService($tableName);

        $maxId = $service->getMaxId();
        $count = $service->getCount();

        $totalPage = floor($count / $size);
        $this->info(date('Y-m-d H:i:s')." Max Id: $maxId, total page: $totalPage, per page size: $size");
        if (0 != $count % $size) {
            ++$totalPage;
        }

        for ($i = 0; $i < $totalPage; ++$i) {
            $this->info(date('Y-m-d H:i:s').' The page: '.($i + 1)." sync Id from $startId.");

            if ($startId >= $maxId) {
                $this->info(date('Y-m-d H:i:s').' reach maxId. abort it.');
                break;
            }

            $models = $service->getDataForOpenSearchImport(startId: $startId, size: $size);

            $startId = $models->last()->id;

            $params = [];
            foreach ($models as $model) {
                $params[] = [
                    'index' => [
                        '_index' => $openSearchService->getIndexName(),
                        '_id' => $model->id,
                    ],
                ];

                $params[] = $openSearchService->getDocumentByModel($model);
            }

            $retry = 0;
            $isSuccess = false;
            while ($retry < 3 && !$isSuccess) {
                $result = $openSearchService->bulkIndex($params);
                $this->info(date('Y-m-d H:i:s').' Bulk index '.($retry + 1).' time.');
                if ($result['errors']) {
                    ++$retry;
                    $this->error('OpenSearch import error info: '.json_encode($result));
                    usleep(200);
                    continue;
                }

                $isSuccess = true;
            }

            if (!$isSuccess) {
                $errorItems = [];
                foreach ($result['items'] as $item) {
                    if (isset($item['index']['error'])) {
                        $errorItems[] = $item;
                    }
                }

                throw new Exception('OpenSearch import exception. error item: '.json_encode($errorItems));
            }
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

    private function getOpenSearchService(string $tableName)
    {
        switch ($tableName) {
            case 'order':
                return $this->openSearchOrderService;
            case 'order_reconciliation':
                return $this->openSearchOrderReconciliationService;
            case 'accounting':
                return $this->openSearchAccountingService;
            case 'payout':
                return $this->openSearchPayoutService;
            case 'record':
                return $this->openSearchRecordService;
            default:
                $this->error('table name not support');
        }
    }
}
