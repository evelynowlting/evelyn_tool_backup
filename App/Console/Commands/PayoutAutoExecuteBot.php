<?php

namespace App\Console\Commands;

use App\Enums\AccountingStatusEnum;
use App\Enums\PayoutStatusEnum;
use App\Services\AccountingService;
use App\Services\PayoutGatewayService;
use App\Services\PayoutService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PayoutAutoExecuteBot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:auto_execute_payout';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Payout auto execute bot';

    /** @var PayoutService */
    private $payoutService;

    /** @var AccountingService */
    private $accountingService;

    /** @var PayoutGatewayService */
    private $payoutGatewayService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->payoutService = app(PayoutService::class);

        $this->payoutGatewayService = app(PayoutGatewayService::class);

        $this->accountingService = app(AccountingService::class);
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $payout_gateways = config('bot.payout_auto_execute_gateways', []);

        $in_process_accountings = $this->accountingService->getInProcessAccountingsByPayoutGateways($payout_gateways);

        foreach ($in_process_accountings as $accounting) {
            _owlPayLog('bot_payout_auto_execute', [
                'payout_gateway' => $accounting->gateway,
                'accounting_uuid' => $accounting->uuid,
            ], 'system');

            $payouts = $this->payoutService->getScanPassedPayoutsByAccounting($accounting, ['sender_model', 'receiver_model']);

            $payouts->loadMissing(['application', 'accounting']);

            _owlPayLog('bot_payout_auto_execute-allow_paoyuts_count', [
                'payouts_count' => $payouts->count(),
                'payouts_items' => $this->mapPayoutsLog($payouts),
                'accounting_uuid' => $accounting->uuid,
            ], 'system');

            $payouts = $payouts->filter(function ($payout) {
                $accounting = $payout->accounting;

                return PayoutStatusEnum::STATUS_IN_PROCESS == $payout->status && AccountingStatusEnum::STATUS_IN_PROCESS == $accounting->status;
            });

            $application = $accounting->application;
            $is_test = $accounting->is_test;
            $payout_date = $accounting->payout_date;
            $apply_user = $accounting->apply;
            $b2b_payout_gateway = $this->payoutGatewayService->getPayoutGatewayInstance($application, $accounting->gateway);
            $payout_date = Carbon::parse($payout_date)->format($b2b_payout_gateway->getScheduleDateFormat());

            $this->payoutService->payoutB2BByPayoutGateway(
                $accounting,
                $payouts,
                $b2b_payout_gateway,
                $payout_date,
                $apply_user,
                $is_test
            );
        }
    }

    public function mapPayoutsLog($payouts)
    {
        return $payouts->map(function ($payout) {
            return [
                'sender_name' => $payout->sender_model->name,
                'sender_uuid' => $payout->sender_model->uuid,
                'recevier_name' => $payout->receiver_model->name,
                'recevier_uuid' => $payout->receiver_model->uuid,
                'currency' => $payout->currency,
                'total' => $payout->total,
                'external_payment_uuid' => $payout->external_payment_uuid,
                'target_currency' => $payout->target_currency,
                'target_total' => $payout->target_total,
                'aml_order_id' => $payout->aml_order_id,
                'aml_order_status' => $payout->aml_order_status,
                'uuid' => $payout->uuid,
                'status' => $payout->status,
                'external_payment',
            ];
        })->toArray();
    }
}
