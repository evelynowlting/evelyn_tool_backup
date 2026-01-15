<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\PayoutChannel\DomesticPayoutEnum;
use App\Enums\PayoutChannel\SinopacBankEnum;
use App\Enums\PayoutStatusEnum;
use App\Events\Payout\AccountingPayoutFailedEvent;
use App\Events\Payout\AccountingPayoutSucceedEvent;
use App\Models\Accounting;
use App\Models\Application;
use App\Services\Payout\SinopacBankInfoService;
use App\Services\Payout\SinopacBankPayoutService;
use App\Services\PayoutService;
use Carbon\Carbon;
use DB;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SinopacBankPayoutStatusChecker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sinopac:payout_status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sinopac Bank query payouts status';

    public const FILTER_STATUS_LIST = [
        PayoutStatusEnum::STATUS_FINISH,
        PayoutStatusEnum::STATUS_FAILED,
    ];

    public const IN_PROCESS = -1;
    public const SUCCESS = 1;
    public const FAILED = 0;

    private const SINOPAC_TXT_QUERY_HOUR = '16';
    private const SINOPAC_TXT_QUERY_MIN = '30';
    private const SINOPAC_TXT_QUERY_SEC = '00';

    private const SINOPAC_TIMEZONE = 'Asia/Taipei';

    /**
     * @var SinopacBankPayoutService
     */
    private $sinopac_bank_payout_service;

    private $sinopac_bank_info_service;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        SinopacBankPayoutService $sinopac_bank_payout_service,
        SinopacBankInfoService $sinopac_bank_info_service,
        PayoutService $payout_service,
    ) {
        parent::__construct();
        $this->sinopac_bank_payout_service = $sinopac_bank_payout_service;
        $this->sinopac_bank_info_service = $sinopac_bank_info_service;
        $this->payout_service = $payout_service;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // query batch
        $payouts = $this->payout_service->getPayoutsWithoutStatus(DomesticPayoutEnum::SINOPAC_BANK, self::FILTER_STATUS_LIST);

        if (0 == $payouts->count()) {
            $this->info('[SinoPac Bank Payouts] No payouts should be updated.');

            return 0;
        }

        $payouts->loadMissing(['accounting.application', 'accounting.payouts', 'application', 'receiver_model']);
        $working_accountings = $payouts->pluck('accounting')->unique('external_batch_uuid')->values();
        $working_accountings = $working_accountings->filter(function ($accounting) {
            return !empty($accounting->external_batch_uuid);
        });
        if (0 == $working_accountings->count()) {
            $this->info('No SinoPac Bank payout should be updated.');

            return 0;
        }

        foreach ($working_accountings as $working_accounting) {
            $this->info('----------------------');

            if (!empty($working_accounting->payout_date)) {
                $payout_date = Carbon::parse($working_accounting->payout_date);
            } else {
                $payout_date = Carbon::today(self::SINOPAC_TIMEZONE);
            }

            $application = $working_accounting->application;
            $query_result = $this->queryResult($working_accounting->application_id, $working_accounting->external_batch_uuid);

            switch ($query_result['status']) {
                case self::SUCCESS:
                    $message = '[Sinpac Bank Payouts] Query success accountings ID: '.$working_accounting->id;

                    Log::info($message);

                    $this->batchUpdateByRecords($application, $working_accounting, $query_result['records']);

                    break;
                case self::FAILED:
                    $message = '[Sinpac Bank Payouts] Query fail accountings ID: '.$working_accounting->id;

                    $payouts = $working_accounting->payouts;

                    Log::info($message);

                    foreach ($payouts as $payout) {
                        AccountingPayoutFailedEvent::dispatch($application, $working_accounting, $payout);
                    }
                    break;
                case self::IN_PROCESS:
                default:
                    $message = '[Sinpac Bank Payouts] Query in_process accountings ID: '.$working_accounting->id;

                    $this->batchUpdateByRecords($application, $working_accounting, $query_result['records']);

                    Log::info($message);
                    break;
            }
        }
    }

    private function queryResult($application_id, $serial_number)
    {
        if (empty($serial_number)) {
            return [
                'status' => self::FAILED,
                'records' => [],
            ];
        }

        // query payout
        $sinopac_info = $this->sinopac_bank_info_service->getSinopacInfoByApplicationId($application_id);
        $response = $this->sinopac_bank_payout_service->query($sinopac_info, $serial_number);

        // Query fail handling
        if (!isset($response->repBody->returnCode) && SinopacBankEnum::RETURN_CODE_QUERY_FAIL == $response->repBody->returnCode) {
            Log::info('[SinoPac] SinoPac Query API query fail.');

            return [
                'status' => self::FAILED,
                'records' => [],
            ];
        }

        // Query success but no data should return fail
        if (!isset($response->repBody->cases) && SinopacBankEnum::RETURN_CODE_QUERY_FAIL == $response->repBody->returnCode) {
            Log::info('[SinoPac] There is no data when API query.');

            return [
                'status' => self::FAILED,
                'records' => [],
            ];
        }

        switch ($response->repBody->returnCode) {
            case SinopacBankEnum::RETURN_CODE_SUCCESS:
                $status = self::SUCCESS;
                break;
            case SinopacBankEnum::RETURN_CODE_CREATE_PAYOUT_FAIL:
            default:
                $status = self::FAILED;
                break;
        }

        return [
            'status' => $status,
            'records' => $response->repBody->cases,
        ];
    }

    private function batchUpdateByRecords(Application $application, Accounting $accounting, $records)
    {
        $sinopac_bank_result = [
            'success' => [],
            'in_process' => [],
            'failed' => [],
        ];

        foreach ($records as $record) {
            $txn_case_no = $record->txnCaseNo;

            foreach ($record->detail as $detail) {
                $record_status_code = (string) $detail->statusCode;
                $payout_id = $detail->txSeq;

                switch ($record_status_code) {
                case SinoPacBankEnum::DETAIL_STATUS_SUCCESS:
                    // success
                    $sinopac_bank_result['success'][$payout_id] = [
                        'status_code' => $record_status_code,
                        'status_description' => (string) $detail->statusDesc,
                    ];
                    break;
                case SinoPacBankEnum::DETAIL_STATUS_API_VALIDATE_FAIL:
                case SinoPacBankEnum::DETAIL_STATUS_FAIL:
                    $sinopac_bank_result['failed'][$payout_id] = [
                        'status_code' => $record_status_code,
                        'status_description' => (string) $detail->statusDesc,
                    ];
                    break;
                case SinoPacBankEnum::DETAIL_STATUS_PROCESSING:
                case SinoPacBankEnum::DETAIL_STATUS_OTHERS:
                default:
                    Log::info('[Sinopac Bank Payouts] Query Unknown status code:'.(string) $detail->StatusCode.' and description:'.(string) $detail->StatusDesc);

                    $sinopac_bank_result['in_process'][$payout_id] = [
                        'status_code' => (string) $detail->statusCode,
                        'status_description' => (string) $detail->statusDesc,
                    ];
                    break;
            }
            }
        }

        Log::info('[SinoPac Bank Payouts] Query success payout ID list:'.implode(',', array_keys($sinopac_bank_result['success'])));
        Log::info('[SinoPac Bank Payouts] Query in_process payout ID list:'.implode(',', array_keys($sinopac_bank_result['in_process'])));
        Log::info('[SinoPac Bank Payouts] Query failed payout ID list:'.implode(',', array_keys($sinopac_bank_result['failed'])));

        if (count($sinopac_bank_result['success']) > 0 || count($sinopac_bank_result['failed']) > 0) {
            $finished_payouts = $accounting->payouts()->whereIn('id', array_keys($sinopac_bank_result['success']))->get();

            $failed_payouts = $accounting->payouts()->whereIn('id', array_keys($sinopac_bank_result['failed']))->get();

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
