<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\PayoutChannel\FirstBankEnum;
use App\Enums\PayoutStatusEnum;
use App\Events\Payout\AccountingPayoutFailedEvent;
use App\Events\Payout\AccountingPayoutSucceedEvent;
use App\Exceptions\HttpException\EmptyException;
use App\Helpers\Base62Helper;
use App\Models\Accounting;
use App\Models\Application;
use App\Models\Payout;
use App\Services\Payout\FirstBankInfoService;
use App\Services\Payout\FirstBankPayoutService;
use Carbon\Carbon;
use DB;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FirstBankPayoutQueryChecker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'first_bank:payout_status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'First Bank query payouts status';

    public const FILTER_STATUS_LIST = [
        PayoutStatusEnum::STATUS_FINISH,
        PayoutStatusEnum::STATUS_FAILED,
    ];

    public const IN_PROCESS = -1;
    public const SUCCESS = 1;
    public const FAILED = 0;

    private const FCB_TXT_QUERY_HOUR = '16';
    private const FCB_TXT_QUERY_MIN = '30';
    private const FCB_TXT_QUERY_SEC = '00';

    private const FCB_TIMEZONE = 'Asia/Taipei';

    /**
     * @var FirstBankPayoutService
     */
    private $first_bank_payout_service;

    private $first_bank_info_service;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        FirstBankPayoutService $firstBankService,
        FirstBankInfoService $firstBankInfoService
    ) {
        parent::__construct();
        $this->first_bank_payout_service = $firstBankService;
        $this->first_bank_info_service = $firstBankInfoService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $payouts = $this->first_bank_payout_service->getPayoutsWithoutStatus(self::FILTER_STATUS_LIST);
        if (0 == $payouts->count()) {
            $this->info('[FirstBank Payouts] No payouts should be updated.');

            return 0;
        }

        $payouts->loadMissing(['accounting.application', 'accounting.payouts', 'application', 'receiver_model']);
        $working_accountings = $payouts->pluck('accounting')->unique('external_batch_uuid')->values();

        $working_accountings = $working_accountings->filter(function ($accounting) {
            return !empty($accounting->external_batch_uuid);
        });
        if (0 == $working_accountings->count()) {
            $this->info('No First Bank payout should be updated.');

            return 0;
        }

        foreach ($working_accountings as $working_accounting) {
            $this->info('----------------------');

            if (!empty($working_accounting->payout_date)) {
                $payout_date = Carbon::parse($working_accounting->payout_date);
            } else {
                $payout_date = Carbon::today(self::FCB_TIMEZONE);
            }

            $final_time = $payout_date->timezone(self::FCB_TIMEZONE)
                ->hour(self::FCB_TXT_QUERY_HOUR)
                ->minute(self::FCB_TXT_QUERY_MIN)
                ->second(self::FCB_TXT_QUERY_SEC);

            if (!$final_time->isPast()) {
                $this->logger('[FirstBank Payouts] First bank final check time at '.$final_time->format('c'), 'warning');
                continue;
            }

            $application = $working_accounting->application;

            $query_result = $this->queryResult($working_accounting->application_id, $working_accounting->external_batch_uuid);

            switch ($query_result['status']) {
                case self::SUCCESS:
                    $message = '[FirstBank Payouts] Query success accountings ID: '.$working_accounting->id;

                    $this->logger($message);

                    $this->batchUpdateByRecords($application, $working_accounting, $query_result['records']);

                    break;
                case self::FAILED:
                    $message = '[FirstBank Payouts] Query fail accountings ID: '.$working_accounting->id;

                    $payouts = $working_accounting->payouts;

                    $this->logger($message);

                    foreach ($payouts as $payout) {
                        AccountingPayoutFailedEvent::dispatch($application, $working_accounting, $payout);
                    }
                    break;
                case self::IN_PROCESS:
                default:
                    $message = '[FirstBank Payouts] Query in_process accountings ID: '.$working_accounting->id;

                    $this->batchUpdateByRecords($application, $working_accounting, $query_result['records']);

                    $this->logger($message);
                    break;
            }
        }
    }

    private function queryResult($application_id, $message_id)
    {
        if (empty($message_id)) {
            return [
                'status' => self::FAILED,
                'records' => [],
            ];
        }

        // query payout
        $user_info = $this->first_bank_info_service->getFirstBankInfoByApplicationId($application_id);
        $serial_number = $this->first_bank_payout_service->getSerialNumber();
        $message_serial_number = $this->first_bank_payout_service->getMessageSerialNumber($serial_number);
        $request_header = $this->first_bank_payout_service->getRequestHeader($message_serial_number);

        $response = $this->first_bank_payout_service->getQueryPayoutResult($request_header, $user_info->tax_id_no, $serial_number, $message_id);

        $result = $this->first_bank_payout_service->queryPayoutResponseXmlParser($response);
        throw_if(empty($result), new EmptyException('[FirstBank] Query API response is empty'));

        if (isset($result['status_description']) && '待放行' == $result['status_description']) {
            return [
                'status' => self::IN_PROCESS,
                'records' => $result['records'],
            ];
        }

        // handle parsing error
        if (empty($result['status_code'])) {
            throw_if(empty($result), new EmptyException('[FirstBank] Query API response format error: status is not found.'));
        }

        switch ($result['status_code']) {
            case FirstBankEnum::MD_QUERY_FINISH:
                $status = self::SUCCESS;
                break;
            case FirstBankEnum::MD_QUERY_IN_PROCESS:
                $status = self::IN_PROCESS;
                break;
            case FirstBankEnum::MD_QUERY_CHECK_ERROR:
            default:
                $status = self::FAILED;
                break;
        }

        return [
            'status' => $status,
            'records' => $result['records'],
        ];
    }

    private function logger($message, $type = 'info')
    {
        Log::$type($message);
        if ('warning' == $type) {
            $this->warn($message);
        } else {
            $this->$type($message);
        }
    }

    private function batchUpdateByRecords(Application $application, Accounting $accounting, $records)
    {
        $firstbank_result = [
            'success' => [],
            'in_process' => [],
            'failed' => [],
        ];

        foreach ($records as $record) {
            $record_status_code = (string) $record->Status->StatusCode;

            $payout_id = Base62Helper::decode((string) $record->PmtRemitRefId);

            if (empty($payout_id)) {
                $message = 'payout_id base62 decode failed';
                $this->logger($message, 'warning');
                continue;
            }

            switch ($record_status_code) {
                case FirstBankEnum::RECORD_SUCCESS:
                    // success
                    $firstbank_result['success'][$payout_id] = [
                        'status_code' => (string) $record->Status->StatusCode,
                        'status_description' => (string) $record->Status->StatusDesc,
                    ];
                    break;
                case FirstBankEnum::RECORD_FAILED:
                case FirstBankEnum::RECORD_CHECK_FAILED:
                case FirstBankEnum::RECORD_SCHEDULE_CANCELLED:
                    $firstbank_result['failed'][$payout_id] = [
                        'status_code' => (string) $record->Status->StatusCode,
                        'status_description' => (string) $record->Status->StatusDesc,
                        'payout_date' => (string) $record->Status->SettleDate,
                    ];
                    break;
                case FirstBankEnum::RECORD_IN_PROCESS:
                case FirstBankEnum::RECORD_ON_SCHEDULE:
                default:
                    $this->logger('[FirstBank Payouts] Query Unknown status code:'.(string) $record->Status->StatusCode.' and description:'.(string) $record->Status->StatusDesc);

                    $firstbank_result['in_process'][$payout_id] = [
                        'status_code' => (string) $record->Status->StatusCode,
                        'status_description' => (string) $record->Status->StatusDesc,
                        'payout_date' => (string) $record->Status->SettleDate,
                    ];
                    break;
            }
        }

        $this->logger('[FirstBank Payouts] Query success payout ID list:'.implode(',', array_keys($firstbank_result['success'])));
        $this->logger('[FirstBank Payouts] Query in_process payout ID list:'.implode(',', array_keys($firstbank_result['in_process'])));
        $this->logger('[FirstBank Payouts] Query failed payout ID list:'.implode(',', array_keys($firstbank_result['failed'])));
        if (count($firstbank_result['success']) > 0 || count($firstbank_result['failed']) > 0) {
            $finished_payouts = $accounting->payouts()->whereIn('id', array_keys($firstbank_result['success']))->get();
            $failed_payouts = $accounting->payouts()->whereIn('id', array_keys($firstbank_result['failed']))->get();

            DB::beginTransaction();
            event(new AccountingPayoutSucceedEvent(
                $application,
                $accounting,
                $finished_payouts->toArray(),
                $failed_payouts->toArray(),
            ));
            DB::commit();
        }
    }
}
