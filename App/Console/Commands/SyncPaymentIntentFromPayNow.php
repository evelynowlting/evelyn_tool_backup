<?php

namespace App\Console\Commands;

use App\Enums\PaymentIntent\BankStatusEnum;
use App\Enums\PaymentIntent\MerchantInfo\AuthGatewayEnum;
use App\Enums\PaymentIntent\PayNowPaymentIntentStatusEnum;
use App\Enums\PaymentIntent\StatusEnum;
use App\Events\PaymentIntent\PaymentIntentAuthorizationCancelledEvent;
use App\Events\PaymentIntent\PaymentIntentAuthorizedEvent;
use App\Events\PaymentIntent\PaymentIntentCapturedEvent;
use App\Events\PaymentIntent\PaymentIntentFailedEvent;
use App\Events\PaymentIntent\PaymentIntentRefundedEvent;
use App\Models\PaymentIntent;
use App\Services\CustomerService;
use App\Services\PaymentIntentService;
use App\Services\PayNow\PaymentIntentService as PayNowPaymentIntentService;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SyncPaymentIntentFromPayNow extends Command
{
    public const PAYNOW_ATTRIBUTES_AUTH = 'auths';
    public const PAYNOW_ATTRIBUTES_CAPTURE = 'captures';
    public const PAYNOW_ATTRIBUTES_REVERSE = 'reverses';
    public const PAYNOW_ATTRIBUTES_REFUND = 'refunds';
    public const PAYNOW_ATTRIBUTES_VOID = 'voids';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment_intent:sync {payment_intent_uuid?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Payment Intent from PayNow';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private PaymentIntentService $paymentIntentService,
        private CustomerService $customerService,
        private PayNowPaymentIntentService $payNowPaymentIntentService,
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
        $paymentIntentUuid = $this->argument('payment_intent_uuid');

        DB::beginTransaction();

        try {
            if (empty($paymentIntentUuid)) {
                $paymentIntents = PaymentIntent::whereNotNull('payment_intents_merchant_uuid')->lockForUpdate()->get();
            } else {
                $paymentIntents = PaymentIntent::where('uuid', $paymentIntentUuid)->lockForUpdate()->get();
            }

            $count = 1;
            foreach ($paymentIntents as $key => $paymentIntent) {
                $this->info('['.$count + $key.'/'.$paymentIntents->count().'] Sync Payment Intent: '.$paymentIntent->uuid);
                $this->syncPaymentIntent($paymentIntent);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            _owlPayLog(
                'update_payment_intent_failed',
                [
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                ],
                'platform',
                'info'
            );
        }
    }

    private function syncPaymentIntent(PaymentIntent $paymentIntent)
    {
        $paymentIntentFromPayNow = $this->payNowPaymentIntentService->getPaymentIntent($paymentIntent);

        $metaDataList = $paymentIntentFromPayNow['result']['metadata'] ?? [];

        $application = $paymentIntent->application;
        _owlPayLog(
            'update_payment_intent',
            [
                'id' => $paymentIntent->id,
                'status' => "{$paymentIntent->status}->{$paymentIntentFromPayNow['result']['status']}",
            ],
            'platform',
            'debug'
        );

        // draft -> failed
        if (in_array($paymentIntent->status, [StatusEnum::CREATED, StatusEnum::CHECKING_OUT])
            && ($paymentIntentFromPayNow['result']['status'] ?? null) == 'failed') {
            try {
                $paymentIntent = $this->paymentIntentService->updatePaymentIntentAfterAuthorizedFailed($paymentIntent);
                $this->info('[Sync Payment Intent Completed] Payment Intent has been updated to '.StatusEnum::FAILED.', uuid: '.$paymentIntent->uuid);
                event(new PaymentIntentFailedEvent($application, $paymentIntent));
            } catch (\Exception $e) {
                _owlPayLog(
                    'update_payment_intent_failed',
                    [
                        'code' => $e->getCode(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'message' => $e->getMessage(),
                    ],
                    'platform',
                    'error'
                );
            }

            return;
        }

        // draft -> checking_out
        if (
            in_array($paymentIntent->status, [StatusEnum::CREATED])
            && in_array(Arr::get($paymentIntentFromPayNow, 'result.status'), ['requires_confirmation', 'pending_review', 'processing'])
        ) {
            $paymentIntent = $this->paymentIntentService->updatePaymentIntentToCheckout($paymentIntent, AuthGatewayEnum::PAYNOW);
        }

        foreach ($metaDataList as $key => $metaData) {
            if (empty($metaData)) {
                continue;
            }

            // draft -> authorized
            if (in_array($paymentIntent->status, [StatusEnum::CREATED, StatusEnum::CHECKING_OUT]) && self::PAYNOW_ATTRIBUTES_AUTH == $key) {
                $paymentIntent = $this->paymentIntentService->updatePaymentIntentAfterAuthorized($paymentIntent, Arr::first($metaData), AuthGatewayEnum::PAYNOW);
                $customerResult = $paymentIntentFromPayNow['result']['customer'];
                $this->customerService->updateCustomerAfterAuthorized(
                    customer: $paymentIntent->customer()->lockForUpdate()->first(),
                    customerResult: $customerResult
                );
                $this->info('[Sync Payment Intent Completed] Payment Intent has been updated to '.StatusEnum::AUTHORIZED.', uuid: '.$paymentIntent->uuid);
                event(new PaymentIntentAuthorizedEvent($application, $paymentIntent));
            }

            // authorized -> captured
            if (in_array($paymentIntent->status, [StatusEnum::AUTHORIZED]) && self::PAYNOW_ATTRIBUTES_CAPTURE == $key) {
                $paymentIntent = $this->paymentIntentService->updatePaymentIntentAfterSubmitCapture($paymentIntent, Arr::first($metaData));
                $this->info('[Sync Payment Intent Completed] Payment Intent has been updated to '.StatusEnum::CAPTURED.', uuid: '.$paymentIntent->uuid);
                event(new PaymentIntentCapturedEvent($application, $paymentIntent));
            }

            // authorized -> cancel
            if (in_array($paymentIntent->status, [StatusEnum::AUTHORIZED]) && self::PAYNOW_ATTRIBUTES_REVERSE == $key) {
                $reverse = Arr::first($metaData);
                $paymentIntent = $this->paymentIntentService->updatePaymentIntentAfterReverse(paymentIntent: $paymentIntent, reason: null, reversedUuid: $reverse['id'], cancelledAt: $reverse['submit_time_utc']);
                $this->info('[Sync Payment Intent Completed] Payment Intent has been updated to '.$paymentIntent->status.', uuid: '.$paymentIntent->uuid);
                event(new PaymentIntentAuthorizationCancelledEvent($application, $paymentIntent));
            }

            // captured -> bank_status='bank_in_process' -> void(Paynow)
            // status changed to cancelled or captured
            if (in_array($paymentIntent->status, [StatusEnum::CAPTURED, StatusEnum::PARTIAL_REFUND]) && in_array($paymentIntent->bank_status, [BankStatusEnum::BANK_IN_PROCESS]) && self::PAYNOW_ATTRIBUTES_VOID == $key) {
                $void = Arr::first($metaData);
                $isVoided = (PayNowPaymentIntentStatusEnum::VOID_VOIDED == $void['status']);
                $paymentIntent = $this->paymentIntentService->updatePaymentIntentAfterVoidedCapture($paymentIntent, $isVoided);
                $this->info('[Sync Payment Intent Completed] Payment Intent has been updated to '.$paymentIntent->status.', uuid: '.$paymentIntent->uuid);
                $paymentIntentsRefund = $paymentIntent->refunds()->orderBy('id', 'desc')->first();
                event(new PaymentIntentRefundedEvent($application, $paymentIntent, $paymentIntentsRefund));
            }

            // captured -> bank_status='bank_payout_finished' -> refund
            if (in_array($paymentIntent->status, [StatusEnum::CAPTURED, StatusEnum::PARTIAL_REFUND]) && in_array($paymentIntent->bank_status, [BankStatusEnum::BANK_PROCESSED]) && self::PAYNOW_ATTRIBUTES_REFUND == $key) {
                $refund = Arr::first($metaData);
                $paymentIntent = $this->paymentIntentService->updatePaymentIntentAfterRefund($paymentIntent, $refund['amount'], $refund['id']);
                $this->info('[Sync Payment Intent Completed] Payment Intent has been updated to '.$paymentIntent->status.', uuid: '.$paymentIntent->uuid);
                $paymentIntentsRefund = $paymentIntent->refunds()->orderBy('id', 'desc')->first();
                event(new PaymentIntentRefundedEvent($application, $paymentIntent, $paymentIntentsRefund));
            }
        }
    }
}
