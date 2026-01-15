<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\PayoutChannel\FirstBankEnum;
use App\Enums\PayoutStatusEnum;
use App\Events\Payout\AccountingPayoutFailedEvent;
use App\Exceptions\HttpException\EmptyException;
use App\Services\Payout\FirstBankInfoService;
use App\Services\Payout\FirstBankPayoutService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FirstBankPayoutDetector extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'first_bank:payout_check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'First Bank payout create detect';

    public const FILTER_STATUS_LIST = [
        PayoutStatusEnum::STATUS_FINISH,
        PayoutStatusEnum::STATUS_FAILED,
    ];

    public const IN_PROCESS = -1;
    public const SUCCESS = 1;
    public const FAILED = 0;

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
        // query batch
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
            $payouts = $working_accounting->payouts;

            /**
             * Prevent upload payout is not in the FirstBank immediately,
             * we should should to do check after more than 6 minutes.
             */
            $is_skip_check = false;
            $now = Carbon::now();
            $diff_time = config('payoutchannel.firstBank.check_diff_time');
            foreach ($payouts as $payout) {
                if ($now->diffInMinutes($payout->external_payment_uuid_updated_at) < $diff_time) {
                    $is_skip_check = true;
                    break;
                }
            }

            if (true == $is_skip_check) {
                continue;
            }

            $application = $working_accounting->application;

            $query_result = $this->queryResult($working_accounting->application_id, $working_accounting->external_batch_uuid);

            if (self::FAILED == $query_result['status']) {
                _owlPayLog(
                    'first_bank_detector_query_accounting_failed',
                    [
                        'accounting_id' => $working_accounting->id,
                    ],
                    'first_bank');

                foreach ($payouts as $payout) {
                    AccountingPayoutFailedEvent::dispatch($application, $working_accounting, $payout);
                }
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
}
