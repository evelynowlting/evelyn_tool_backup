<?php

namespace App\Console\Commands\PaymentIntent;

use App\Enums\FiservFeedTypeEnum;
use App\Services\FiservFeedService;
use App\Services\FiservService;
use App\Services\PaymentIntentService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

class SyncFiservFeedsSftp extends Command
{
    protected $signature = 'sync:fiserv_feeds_sftp {--date=} {--file=}';
    protected $description = 'Sync fiserv feeds by sftp to their server';

    public function __construct(
        private FiservFeedService $fiservFeedService,
        private FiservService $fiservService,
        private PaymentIntentService $paymentIntentService,
    ) {
        parent::__construct();
    }

    private function connectFiservSftp(): ?SFTP
    {
        $sftpKey = PublicKeyLoader::load(file_get_contents(config('fiserv.feeds_sftp_private_key')), config('fiserv.feeds_sftp_passphrase'));
        $sftp = new SFTP(config('fiserv.feeds_sftp_host'), config('fiserv.feeds_sftp_port'));
        // fiserv use both passphase encrypted key AND password
        if (!$sftp->login(config('fiserv.feeds_sftp_username'), config('fiserv.feeds_sftp_passphrase'), $sftpKey)) {
            _owlPayLog('fiserv_sftp_login_fail', ['errors' => $sftp->getErrors()], 'system', 'error');

            return null;
        }
        _owlPayLog('fiserv_sftp_login_success', [], 'system', 'info');

        return $sftp;
    }

    private function backupFeedToS3($filePath)
    {
        $path = Storage::disk('s3')->putFileAs(config('fiserv.feeds_s3_backup_path'), new File($filePath), basename($filePath));
        if (false === $path) {
            _owlPayLog('fiserv_s3_backup_failed', ['path' => $filePath], 'system', 'error');
        } else {
            _owlPayLog('fiserv_s3_backup_success', ['path' => $filePath, 's3_path' => $path], 'system', 'info');
        }
    }

    private function getFiservFeeds($date): ?array
    {
        $file = $this->option('file');
        if (!empty($file) && is_string($file)) {
            $tarGzipFileName = $file;
        } else {
            $sftp = $this->connectFiservSftp();
            if (!$sftp) {
                return [];
            }
            $sftpFeedsDirectory = config('fiserv.feeds_sftp_path');
            $sftpFeedsPrefix = config('fiserv.feeds_prefix');
            $tarGzipFileName = $sftpFeedsPrefix.$date.'.tar.gz';
            $tarGzipFilePath = $sftpFeedsDirectory.$tarGzipFileName;
            if (!$sftp->get($tarGzipFilePath, storage_path('logs/'.$tarGzipFileName))) {
                _owlPayLog('fiserv_sftp_gzip_download_failed', [
                    'path' => $tarGzipFilePath,
                    'local_path' => storage_path('logs/'.$tarGzipFileName),
                    'errors' => $sftp->getSftpErrors(),
                ], 'system', 'error');

                return [];
            }
            _owlPayLog('fiserv_sftp_gzip_download_success', [
                'path' => $tarGzipFilePath,
                'content_length' => filesize(storage_path('logs/'.$tarGzipFileName)),
            ], 'system', 'info');
        }
        $this->backupFeedToS3(storage_path('logs/'.$tarGzipFileName));
        $gzipFile = new \PharData(storage_path('logs/'.$tarGzipFileName));
        $tarFile = $gzipFile->decompress();
        $tarFile->extractTo(storage_path('logs/fiserv_sftp/'), overwrite: true);
        unlink($tarFile->getPath());
        $csvFiles = array_diff(scandir(storage_path('logs/fiserv_sftp')), ['.', '..']);
        _owlPayLog('fiserv_sftp_csv_extract_success', ['csv_files' => $csvFiles], 'system', 'info');

        return array_values($csvFiles);
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
        $file = $this->option('file');
        if (!empty($file) && is_string($file)) {
            $tmp = explode('_', $file);
            $date = rtrim(end($tmp), '.tar.gz');
        } else {
            $date = $this->option('date');
            if (!empty($date) && is_string($date)) {
                $date = Carbon::parse($date)->format('Ymd');
            } else {
                $date = Carbon::yesterday()->format('Ymd');
            }
        }
        $fiservFeeds = $this->getFiservFeeds($date);
        if (empty($fiservFeeds)) {
            _owlPayLog('fiserv_no_csv_extracted', [], 'system', 'error');

            return;
        }

        $feedTypes = FiservFeedTypeEnum::toArray();
        foreach ($feedTypes as $feedType) {
            _owlPayLog('fiserv_feed_processing', ['feed_type' => $feedType], 'system', 'info');
            $index = array_search(config('fiserv.feeds_prefix').$feedType.'_'.$date.'.csv', $fiservFeeds);
            if (false === $index) {
                _owlPayLog('fiserv_csv_not_found', ['feed_type' => $feedType], 'system', 'error');
                continue;
            }
            $csvPath = storage_path('logs/fiserv_sftp/'.$fiservFeeds[$index]);
            $feedContent = file_get_contents($csvPath);
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
            unlink($csvPath);
        }
        unlink(storage_path('logs/'.config('fiserv.feeds_prefix').$date.'.tar.gz'));

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
