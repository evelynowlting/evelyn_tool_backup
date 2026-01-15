<?php

namespace App\Console\Commands;

use App\Models\Accounting;
use App\Services\AccountingService;
use App\Services\PayoutGatewayService;
use App\Services\PayoutService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AccountingPayout extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounting:payout {accounting_uuid} {--payout_gateway=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';
    private $accountingService;
    /**
     * @var PayoutService
     */
    private $payoutService;
    /**
     * @var PayoutGatewayService
     */
    private $payoutGatewayService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(AccountingService $accountingService,
        PayoutService $payoutService,
        PayoutGatewayService $payoutGatewayService)
    {
        parent::__construct();
        $this->accountingService = $accountingService;
        $this->payoutService = $payoutService;
        $this->payoutGatewayService = $payoutGatewayService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $accounting_uuid = $this->argument('accounting_uuid');

        $payout_gateway = $this->option('payout_gateway');

        DB::beginTransaction();

        $accounting = Accounting::where('uuid', $accounting_uuid)->lockForUpdate()->first();

        $application = $accounting->application;

        $is_test = $accounting->is_test;

        $payout_gateway = $this->payoutGatewayService->getPayoutGatewayByEnvironment($payout_gateway, $is_test);

        $accounting = $this->accountingService->getAccountingByApplication(
            $application,
            $accounting->uuid,
            $is_test,
            [
                'details',
            ]
        );

        $payouts = $this->payoutService->getScanPassedPayoutsByAccounting($accounting, ['sender_model', 'receiver_model']);

        $b2b_payout_gateway = $this->payoutGatewayService->getPayoutGatewayInstance($application, $payout_gateway);

        $payout_date = Carbon::today()->format($b2b_payout_gateway->getScheduleDateFormat());

        $this->payoutService->payoutB2BByPayoutGateway(
            $accounting,
            $payouts,
            $b2b_payout_gateway,
            $payout_date,
            $application,
            $is_test
        );

        $accounting->refresh();

        DB::commit();
    }
}
