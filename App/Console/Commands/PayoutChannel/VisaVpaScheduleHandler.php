<?php

namespace App\Console\Commands\PayoutChannel;

use App\Events\Payout\AccountingPayoutFailedEvent;
use App\Events\Platform\AccountingPayoutExecutedEvent;
use App\Repositories\AccountingRepository;
use App\Services\PayoutGatewayService;
use App\Services\PayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class VisaVpaScheduleHandler extends Command
{
    /**
     * Issue a VISA VPA card.
     *
     * @var string
     */
    protected $signature = 'accounting:vpa_vpa_schedule';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'handle visa vpa payout schedule';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        public AccountingRepository $accountingRepository,
        public PayoutGatewayService $payoutGatewayService,
        public PayoutService $payoutService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // 執行排程的 accounting
        $schedule_accountings = $this->accountingRepository->getVisaScheduleAccountings();
        foreach ($schedule_accountings as $accounting) {
            $payout_gateway = $accounting->gateway;
            $application = $accounting->application;
            $today = Carbon::today($application->timezone);

            $payout_date = Carbon::parse($accounting->payout_date, $application->timezone);

            $b2b_payout_gateway = $this->payoutGatewayService->getPayoutGatewayInstance($application, $payout_gateway);

            $payouts = $this->payoutService->getScanPassedPayoutsByAccounting($accounting, ['sender_model', 'receiver_model']);

            if ($payout_date->equalTo($today)) {
                $this->payoutService->payoutB2BByPayoutGateway(
                    $accounting,
                    $payouts,
                    $b2b_payout_gateway,
                    $payout_date->format($b2b_payout_gateway->getScheduleDateFormat()),
                    $accounting->execute,
                    $accounting->is_test
                );

                event(new AccountingPayoutExecutedEvent($application, $accounting, $accounting->is_test));
            }
        }

        // 將過期的 accounting 狀態改為失敗
        $unexecuted_accountings = $this->accountingRepository->getVisaUnexecutedAccountings();
        foreach ($unexecuted_accountings as $accounting) {
            $application = $accounting->application;
            $today = Carbon::today($application->timezone);
            $payout_date = Carbon::parse($accounting->payout_date, $application->timezone);
            AccountingPayoutFailedEvent::dispatchIf(
                $payout_date->isBefore($today),
                $accounting->application,
                $accounting,
                null,
                'Payout date expired'
            );
        }

        return 0;
    }
}
