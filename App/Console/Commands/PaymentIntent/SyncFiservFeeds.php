<?php

namespace App\Console\Commands\PaymentIntent;

use App\Enums\FiservFeedTypeEnum;
use App\Services\FiservFeedService;
use App\Services\FiservService;
use App\Services\PaymentIntentService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class SyncFiservFeeds extends Command
{
    protected $signature = 'sync:fiserv_feeds {--date=}';
    protected $description = 'Sync fiserv feeds';

    public function __construct(
        private FiservFeedService $fiservFeedService,
        private FiservService $fiservService,
        private PaymentIntentService $paymentIntentService,
    ) {
        parent::__construct();
    }

    private function getFiservFeeds($feedType): ?string
    {
        $s3FeedsDirectory = config('fiserv.feeds_s3_path');
        $s3FeedsPrefix = config('fiserv.feeds_prefix');
        $date = $this->option('date');
        if (!empty($date) && is_string($date)) {
            $date = Carbon::parse($date)->format('Ymd');
        } else {
            $date = Carbon::now()->format('Ymd');
        }

        $csvFileName = $s3FeedsPrefix.$feedType.'_'.$date.'.csv';
        $filePath = $s3FeedsDirectory.$csvFileName;
        if (!Storage::disk('s3')->exists($filePath)) {
            _owlPayLog('fiserv_s3_feed_not_found', ['path' => $filePath], 'system', 'error');

            return null;
        } else {
            $csvContent = Storage::disk('s3')->get($filePath);
            _owlPayLog('fiserv_s3_feed_read_success', ['path' => $filePath, 'content_length' => strlen($csvContent)], 'system', 'info');

            return $csvContent;
        }
    }

    private function fiservCsvToCollection(string $csvContent): Collection
    {
        $lines = array_reverse(explode(PHP_EOL, $csvContent));
        $header = collect(str_getcsv(array_pop($lines)));
        $lines = array_reverse($lines);
        $rows = collect($lines)->filter();
        $data = $rows->map(function ($row) use ($header) {
            return $header->combine(str_getcsv($row));
        });

        return $data;
    }

    public function handle()
    {
        $date = $this->option('date');
        if (!empty($date) && is_string($date)) {
            $date = Carbon::parse($date)->format('Ymd');
        } else {
            $date = Carbon::yesterday()->format('Ymd');
        }

        $feedTypes = FiservFeedTypeEnum::toArray();
        foreach ($feedTypes as $feedType) {
            _owlPayLog('fiserv_feed_processing', ['feed_type' => $feedType], 'system', 'info');
            $feedContent = $this->getFiservFeeds($feedType);
            if (empty($feedContent)) {
                _owlPayLog('fiserv_csv_empty', ['feed_type' => $feedType], 'system', 'error');
                continue;
            }
            $csvData = $this->fiservCsvToCollection($feedContent);
            _owlPayLog('fiserv_csv_to_collection_success', ['feed_type' => $feedType, 'row_count' => $csvData->count()], 'system', 'info');
            switch ($feedType) {
                case FiservFeedTypeEnum::FISERV_CHARGEBACK_STATUS:
                    $mapping = config('fiserv.feeds_mapping.'.FiservFeedTypeEnum::FISERV_CHARGEBACK_STATUS);
                    $batchInsertData = $this->fiservMapCsvRecordToModelData($csvData, $mapping, $feedType);
                    if (count($batchInsertData) > 0) {
                        $rowsAffected = $this->fiservFeedService->batchInsertChargebackStatus($batchInsertData->toArray());
                        _owlPayLog('fiserv_batch_insert_chargeback_status_success', ['rows_affected' => $rowsAffected], 'system', 'info');
                    }
                    break;
                case FiservFeedTypeEnum::FISERV_FUNDING_ACCOUNT:
                    $mapping = config('fiserv.feeds_mapping.'.FiservFeedTypeEnum::FISERV_FUNDING_ACCOUNT);
                    $batchInsertData = $this->fiservMapCsvRecordToModelData($csvData, $mapping, $feedType);
                    if (count($batchInsertData) > 0) {
                        $rowsAffected = $this->fiservFeedService->batchInsertFundingAccount($batchInsertData->toArray());
                        _owlPayLog('fiserv_batch_insert_funding_account_success', ['rows_affected' => $rowsAffected], 'system', 'info');
                    }
                    break;
                case FiservFeedTypeEnum::FISERV_MERCHANT_PROFILE:
                    $mapping = config('fiserv.feeds_mapping.'.FiservFeedTypeEnum::FISERV_MERCHANT_PROFILE);
                    $batchInsertData = $this->fiservMapCsvRecordToModelData($csvData, $mapping, $feedType);
                    if (count($batchInsertData) > 0) {
                        $rowsAffected = $this->fiservFeedService->batchInsertMerchantProfile($batchInsertData->toArray());
                        _owlPayLog('fiserv_batch_insert_merchant_profile_success', ['rows_affected' => $rowsAffected], 'system', 'info');
                    }
                    break;
                case FiservFeedTypeEnum::FISERV_ADDITIONAL_DATA_ADDENDUM:
                    $mapping = config('fiserv.feeds_mapping.'.FiservFeedTypeEnum::FISERV_ADDITIONAL_DATA_ADDENDUM);
                    $batchInsertData = $this->fiservMapCsvRecordToModelData($csvData, $mapping, $feedType);
                    if (count($batchInsertData) > 0) {
                        $rowsAffected = $this->fiservFeedService->batchInsertAdditionalDataAddendum($batchInsertData->toArray());
                        _owlPayLog('fiserv_batch_insert_additional_data_addendum_success', ['rows_affected' => $rowsAffected], 'system', 'info');
                    }
                    break;
                case FiservFeedTypeEnum::FISERV_TRANSACTION:
                    $mapping = config('fiserv.feeds_mapping.'.FiservFeedTypeEnum::FISERV_TRANSACTION);
                    $batchInsertData = $this->fiservMapCsvRecordToModelData($csvData, $mapping, $feedType);
                    if (count($batchInsertData) > 0) {
                        $rowsAffected = $this->fiservFeedService->batchInsertTransaction($batchInsertData->toArray());
                        _owlPayLog('fiserv_batch_insert_transaction_success', ['rows_affected' => $rowsAffected], 'system', 'info');
                    }
                    break;
                default:
                    _owlPayLog('fiserv_unknown_feed_type', ['feed_type' => $feedType], 'system', 'error');
                    throw new \Exception('Unknown feed type');
            }
        }

        $this->fiservService->getFiservTransactionsByFundingDate($date)->chunk(500)->map(function ($transactions) use ($date) {
            $this->paymentIntentService->createPaymentIntentReconciliationByFiservTransactions($date, $transactions);
        });

        Artisan::call('payment_intent:sync_status_from_fiserv_table');
    }

    private function fiservMapCsvRecordToModelData($csvData, array $mapping, string $feedType)
    {
        $result = $csvData->map(function ($csvRow) use ($mapping) {
            foreach ($mapping as $key => $value) {
                if ($csvRow->has($value)) {
                    $dbRow[$key] = $csvRow[$value];
                } else {
                    $dbRow[$key] = null;
                }
            }

            return $dbRow;
        });

        _owlPayLog('fiserv_insert_data_build_success', ['feed_type' => $feedType, 'row_count' => $result->count()], 'system', 'info');

        return $result;
    }
}
