<?php

namespace App\Console\Commands\PayoutChannel;

use App\Exceptions\HttpException\EmptyException;
use App\Exceptions\HttpException\UnknownException;
use App\Services\Payout\Visa\VisaMLEService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

enum VCDSDateFilterType: string
{
    case TRANSACTION_DATETIME = 'transaction_datetime';
    case POSTING_DATE = 'posting_date';
}

class VisaVCDSUtil extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visa:vcds_util
                            {company_id}
                            {bank_id}
                            {processor_id}
                            {region_id}
                            {date_filter_type : transaction_datetime | posting_date: Type of date filter}
                            {start_date}
                            {end_date}
                            {--dry_run}
                            {--http_timeout=60}
                            {--connect_timeout=30}
                            {--output=}
                            {--also_log_to=}
                           ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'VISA VCDS API Utility';

    protected VisaMLEService $visa_mle_service;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
    ) {
        parent::__construct();
        $this->visa_mle_service = new VisaMLEService(
            config('payoutchannel.visa.vpa_mle_key_id'),
            config('payoutchannel.visa.vpa_mle_server_cert_path'),
            config('payoutchannel.visa.vpa_mle_private_key_path'),
        );
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(
    ) {
        $company_id = $this->argument('company_id');
        $bank_id = $this->argument('bank_id');
        $region_id = $this->argument('region_id');
        $processor_id = $this->argument('processor_id');
        $region_id = $this->argument('region_id');
        $start_date = Carbon::parse($this->argument('start_date'));
        $end_date = Carbon::parse($this->argument('end_date'));
        $dry_run = boolval($this->option('dry_run'));
        $date_filter_type = VCDSDateFilterType::from($this->argument('date_filter_type'));
        $http_timeout = intval($this->option('http_timeout'));
        $connect_timeout = intval($this->option('connect_timeout'));
        $output = $this->option('output');
        $also_log_to = $this->option('also_log_to');

        if (!empty($also_log_to)) {
            Log::getLogger()->pushHandler(new StreamHandler($also_log_to, Logger::DEBUG));
        }

        if (!empty($output) && file_exists($output)) {
            throw new \Exception("File \"{$output}\" already exists");
        }

        Log::info("[VisaVCDSUtil] date_filter_type={$date_filter_type->value}, start_date={$start_date}, end_date={$end_date}, dry_run={$dry_run}, http_timeout={$http_timeout}, connect_timeout={$connect_timeout}");
        Log::info("[VisaVCDSUtil] company_id={$company_id}, bank_id={$bank_id}, processor_id={$processor_id}, region_id={$region_id}");

        if ($dry_run) {
            return;
        }

        $result = [];

        try {
            $result['v2'] = $this->getTransactionDataV2(
                bank_id: $bank_id,
                region_id: $region_id,
                processor_id: $processor_id,
                company_id: $company_id,
                date_filter_type: $date_filter_type,
                start_date: $start_date,
                end_date: $end_date,
                http_timeout: $http_timeout,
                connect_timeout: $connect_timeout,
            );
        } catch (\Exception $e) {
            Log::error('[VisaVCDSUtil] Exception: '.$e);
        }

        if (!empty($output)) {
            file_put_contents($output, json_encode($result));
        }
    }

    public function generateTransactionAndEnhancedDataApiPayload(
        $bank_id,
        $region_id,
        $processor_id,
        $company_id,
        VCDSDateFilterType $date_filter_type,
        Carbon $start_date,
        Carbon $end_date,
        int $page_num,
        int $page_size,
    ) {
        $date_filter_name = match ($date_filter_type) {
            VCDSDateFilterType::TRANSACTION_DATETIME => 'transactionDateTime',
            VCDSDateFilterType::POSTING_DATE => 'postingDate',
        };
        $date_format = match ($date_filter_type) {
            VCDSDateFilterType::TRANSACTION_DATETIME => 'YYYY-MM-DDTHH:mm:ss',
            VCDSDateFilterType::POSTING_DATE => 'YYYY-MM-DD',
        };

        return [
            'companyId' => $company_id,
            'filters' => [
                $date_filter_name => [
                    'filterValues' => [
                        $start_date->isoFormat($date_format),
                        $end_date->isoFormat($date_format),
                    ],
                    'filterType' => 'BETWN',
                ],
            ],
            'issuerId' => $bank_id,
            'pagination' => [
                'pageNum' => $page_num,
                'pageSize' => $page_size,
            ],
            'processorId' => $processor_id,
            'regionId' => $region_id,
            'type' => 'TRAN',
        ];
    }

    public function getTransactionDataV2(
        string $bank_id,
        string $region_id,
        string $processor_id,
        string $company_id,
        VCDSDateFilterType $date_filter_type,
        Carbon $start_date,
        Carbon $end_date,
        int $page_size = 50,
        $http_timeout = 60,
        $connect_timeout = 30,
    ) {
        throw_if(empty($bank_id), new EmptyException('Empty bank_id request data when sending VISA Data API request.'));
        throw_if(empty($region_id), new EmptyException('Empty region_id request data when sending VISA Data API request.'));
        throw_if(empty($processor_id), new EmptyException('Empty processor_id request data when sending VISA Data API request.'));
        throw_if(empty($company_id), new EmptyException('Empty company_id request data when sending VISA Data API request.'));

        $page_num = 1;
        $request_paylods = [];
        $x_correlation_ids = [];
        $responses = [];
        $transactions = [];

        do {
            $request_data = $this->generateTransactionAndEnhancedDataApiPayload(
                bank_id: $bank_id,
                region_id: $region_id,
                processor_id: $processor_id,
                company_id: $company_id,
                start_date: $start_date,
                end_date: $end_date,
                page_num: $page_num,
                page_size: $page_size,
                date_filter_type: $date_filter_type,
            );
            $request_paylods[] = $request_data;
            Log::debug('[getTransactionDataV2] request_data = '.json_encode($request_data));
            $encrypted_result = $this->visa_mle_service->encryptPayload(
                json_encode($request_data),
            );
            $result = $this->doRequest(
                url: config('payoutchannel.visa.data_v2_api_url').'transaction_enhanced',
                method: 'post',
                request_data: $encrypted_result['encrypted_payload'],
                headers: $encrypted_result['extra_headers'],
                http_timeout: $http_timeout,
                connect_timeout: $connect_timeout,
            );
            $x_correlation_ids[] = $result->header('X-Correlation-ID');
            $decrypted_result = $this->visa_mle_service->decryptPayload(
                $result->object()->encData,
            );
            $result_object = json_decode($decrypted_result);
            $responses[] = $result_object;
            $result_page_num = $result_object->metadata->pageNum ?? $page_num;
            $result_page_record_count = $result_object->metadata->pageRecordCount ?? 0;

            throw_if($page_num != $result_page_num, new UnknownException("[getTransactionDataV2] API returned page number {$result_page_num} != ours {$page_num}"));

            $transactions = array_merge($transactions, $result_object->transactions ?? []);
            ++$page_num;
        } while ($result_page_record_count >= $page_size);
        Log::debug('[getTransactionDataV2] transactions='.count($transactions));

        return [
            'request_payloads' => $request_paylods,
            'x_correlation_ids' => $x_correlation_ids,
            'responses' => $responses,
            'transactions' => $transactions,
        ];
    }

    /** Handle HTTP request */
    public function doRequest(
        $url,
        $method,
        $request_data = [],
        $headers = [],
        $http_timeout = 60,
        $connect_timeout = 30,
    ) {
        throw_if(empty($request_data), new EmptyException('Empty request data when sending VISA Data API request.'));

        Log::debug("[doRequest] url = {$url}");
        $key_file = config('payoutchannel.visa.key_path');
        $cert_file = config('payoutchannel.visa.cert_path');
        $user_id = config('payoutchannel.visa.user_id');
        $password = config('payoutchannel.visa.password');
        $ca_verify = config('payoutchannel.visa.ca_verify');

        $request = Http::timeout($http_timeout)
            ->withBasicAuth($user_id, $password)
            ->withOptions([
                'cert' => $cert_file,
                'ssl_key' => $key_file,
                'verify' => $ca_verify,
                'connect_timeout' => $connect_timeout,
            ])
            ->withHeaders($headers);
        $response = $request->$method($url, $request_data);
        Log::debug('[doRequest] X-Correlation-ID: '.$response->header('X-Correlation-ID'));

        return $response;
    }
}
