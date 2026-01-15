<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\PayoutStatusEnum;
use App\Enums\RippleNetStateEnum;
use App\Events\PayoutGatewayStatusUpdateEvent;
use App\Services\Payout\RippleNetKYCService;
use App\Services\Payout\RippleNetPayoutService;
use App\Services\PayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RippleNetPayoutSettlementStaff extends Command
{
    protected $signature = 'rn-payout:settlement {--mode=settle} {--amount=1000}';
    protected $description = 'This command line tool is to execute and finalize settlement.';
    /**
     * @var PayoutService
     */
    private $payoutService;
    /**
     * @var RippleNetPayoutService
     */
    private $rnPayoutService;
    /**
     * @var RippleNetKYCService
     */
    private $kycService;

    public function __construct(PayoutService $payoutService,
        RippleNetKYCService $kycService,
        RippleNetPayoutService $rnPayoutService)
    {
        parent::__construct();

        $this->rnPayoutService = $rnPayoutService;
        $this->payoutService = $payoutService;
        $this->kycService = $kycService;
    }

    public function handle()
    {
        if ('create' == $this->option('mode')) {
            $fake_user_info = $this->kycService->getKYCInfo();
            $quote_id = $this->rnPayoutService->createQuoteCollection('JPY', $this->option('amount'));
            $this->info('Created payment: '.$this->rnPayoutService->acceptQuote($quote_id, $fake_user_info));
        } elseif ('settle' == $this->option('mode')) {
            $locked_payments = $this->rnPayoutService->getPaymentsByConditions(RippleNetStateEnum::STATE_LOCKED);

            if (count($locked_payments) <= 0) {
                Log::info('No locked payments');

                return;
            }

            $settled = 0;
            foreach ($locked_payments as $payment) {
                $this->rnPayoutService->settlePayment($payment['payment_id']);

                // Skip those payments with state settlement declined.
                if (RippleNetStateEnum::STATE_SETTLEMENT_DECLINED == $payment['payment_state']) {
                    // event(new PayoutGatewayStatusUpdateEvent($payment['payment_id'], RippleNetStateEnum::STATE_SETTLEMENT_DECLINED));
                    continue;
                }
                $this->info('Settled payment: '.$payment['payment_id']);
                // event(new PayoutGatewayStatusUpdateEvent($payment['payment_id'], RippleNetStateEnum::STATE_EXECUTED));
                ++$settled;
            }

            if ($settled > 0) {
                Log::info('# of Settled Payments: '.$settled);
            }
        } elseif ('finalize' == $this->option('mode')) {
            $completed_payments = $this->rnPayoutService->getPaymentsByConditions(RippleNetStateEnum::STATE_COMPLETED, RippleNetStateEnum::LABEL_COMPLETED_PROCESSING);

            if (count($completed_payments) <= 0) {
                Log::info('No locked payments');

                return;
            }

            $completed = 0;
            foreach ($completed_payments as $payment) {
                $this->rnPayoutService->labelPayment($payment['payment_id'], RippleNetStateEnum::LABEL_COMPLETED_PROCESSING);
                $this->info('Completed payment: '.$payment['payment_id']);
                // event(new PayoutGatewayStatusUpdateEvent($payment['payment_id'], RippleNetStateEnum::STATE_COMPLETED));

                $payout = $this->payoutService->getPayoutByPaymentUUId($payment['payment_id']);

                $application = $payout->application;

                if (empty($payout)) {
                    _owlPayLog('payout_finish_by_gateway_failed', [
                        'external_payment_uuid' => $payment['payment_id'],
                    ], 'payout_gateway', 'error');
                } else {
                    $this->info('Payout succeed event');

                    $this->payoutService->updateStatus($payout, PayoutStatusEnum::STATUS_FINISH);
                }
                ++$completed;
            }

            if ($completed > 0) {
                Log::info('# of completed Payments: '.$completed);
            }
        } elseif ('failed_handle' == $this->option('mode')) {
            $failed_payments = $this->rnPayoutService->getPaymentsByConditions(RippleNetStateEnum::STATE_FAILED);

            $count = 0;

            foreach ($failed_payments as $payment) {
                // event(new PayoutGatewayStatusUpdateEvent($payment['payment_id'], RippleNetStateEnum::STATE_FAILED));
                ++$count;
            }

            if ($count > 0) {
                Log::info('# of failed Payments: '.$count);
            }
        }

        return 0;
    }
}
