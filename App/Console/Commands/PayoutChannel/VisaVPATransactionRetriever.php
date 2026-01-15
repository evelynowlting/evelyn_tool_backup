<?php

namespace App\Console\Commands\PayoutChannel;

use Alcohol\ISO4217;
use App\Exceptions\HttpException\EmptyException;
use App\Services\Payout\Visa\VisaDataV2Service;
use App\Services\Payout\Visa\VisaVirtualAccountService;
use App\Services\Payout\Visa\VisaVPAInfoService;
use App\Services\Payout\Visa\VisaVPAReconciliationService;
use App\Services\Payout\Visa\VisaVPATransactionData;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VisaVPATransactionRetriever extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visa:get_trans_data
                            {--dry_run : Do not do any update}
                            {--skip_s3_upload : Skip uploading to S3}
                            {--start_date_offset=3 : Set start_date to N days ago}
                            {--end_date_offset=2 : Set end_date to N days ago}
                            {--http_timeout=60 : Timeout for HTTP client}
                            {--connect_timeout=30 : Timeout for connecting}
                           ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'VISA VPA transaction data retrieve';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private VisaDataV2Service $visa_data_service,
        private VisaVirtualAccountService $visa_virtual_account_service,
        private VisaVPAReconciliationService $visa_vpa_reconciliation_service,
        private VisaVPAInfoService $visa_vpa_info_service,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(
    ) {
        $start_date_offset = $this->option('start_date_offset');
        $end_date_offset = $this->option('end_date_offset');
        $start_date = Carbon::now()->subDays($start_date_offset);
        $end_date = Carbon::now()->subDays($end_date_offset);

        $dry_run = (bool) $this->option('dry_run');
        $skip_s3_upload = (bool) $this->option('skip_s3_upload');
        $http_timeout = $this->option('http_timeout');
        $connect_timeout = $this->option('connect_timeout');

        Log::info("[VisaVPATransactionRetriever] start_date={$start_date}, end_date={$end_date}");
        Log::info("[VisaVPATransactionRetriever] dry_run={$dry_run}, skip_s3_upload={$skip_s3_upload}, http_timeout={$http_timeout}, connect_timeout={$connect_timeout}");

        $application_ids = $this->visa_virtual_account_service->getApplicationIdOfUnSettle();

        if (empty($application_ids)) {
            return 0;
        }

        $vpa_info_list = $this->visa_vpa_info_service->getVpaInfoByApplicationIds($application_ids);
        $index = 0;
        foreach ($vpa_info_list as $vpa_info) {
            try {
                $transaction_data = $this->visa_data_service->getTransactionDataV2(
                    bank_id: $vpa_info->bank_id,
                    region_id: $vpa_info->region_id,
                    processor_id: $vpa_info->processor_id,
                    company_id: $vpa_info->company_id,
                    start_date: $start_date,
                    end_date: $end_date,
                    http_timeout: $http_timeout,
                    connect_timeout: $connect_timeout,
                );
            } catch (EmptyException $e) {
                Log::warning('message: '.$e->getMessage());
                continue;
            }

            // filter data and store
            if (empty($transaction_data)) {
                Log::info('[VisaVPATransactionRetriever] No transaction data.');
                continue;
            }

            if ($dry_run) {
                continue;
            }

            $time = time();
            if (!$skip_s3_upload) {
                $this->uploadTransactionDataToS3(
                    $vpa_info->application_id,
                    $time,
                    $index,
                    $transaction_data,
                );
            }

            foreach ($transaction_data as $transaction) {
                $this->updateReconciliationData(VisaVPATransactionData::fromV2TransactionData($transaction));
            }
            ++$index;
        }

        return 0;
    }

    private function uploadTransactionDataToS3(
        $application_id,
        $time,
        $index,
        $raw_transaction_data,
    ) {
        // use timestamp for file name
        $file_name = sprintf('transaction_file_v2_%s_%s_%s.json', $application_id, $time, $index);
        Log::info('[VisaVPATransactionRetriever] [uploadTransactionDataToS3] Uploading '.$file_name);
        // store transaction data into S3 for backup
        $encrypt_content = $this->visa_data_service->encryptFile(json_encode($raw_transaction_data));
        $this->visa_data_service->uploadTransactionDataToS3($encrypt_content, $file_name);
    }

    private function updateReconciliationData(VisaVPATransactionData $transaction_data)
    {
        $store_id = $this->visa_data_service->updateReconciliationData($transaction_data);
        if (!empty($store_id)) {
            $this->visa_virtual_account_service->setSettleByCardNumber($transaction_data->account_number);
            $currency_iso_code_service = new ISO4217();
            $currency = $currency_iso_code_service->getByNumeric($transaction_data->billing_currency_code);
            $total_amount = $transaction_data->billing_amount;
            // confirm data is success
            if ('' == $transaction_data->refund_number) {
                $this->visa_vpa_reconciliation_service->confirmSettlement($transaction_data->account_number, $currency['alpha3'], $total_amount);
            } else {
                $this->visa_vpa_reconciliation_service->failSettlement($transaction_data->account_number, $currency['alpha3'], $total_amount);
            }
        }
    }
}
