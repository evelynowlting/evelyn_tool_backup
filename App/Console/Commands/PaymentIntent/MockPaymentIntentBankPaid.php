<?php

namespace App\Console\Commands\PaymentIntent;

use App\Enums\PaymentIntent\MerchantInfo\AuthGatewayEnum;
use App\Enums\PaymentIntent\ReconciliationType;
use App\Events\PaymentIntent\PaymentIntentBankStatusUpdatedEvent;
use App\Models\FiservTransaction;
use App\Models\PaymentIntentReconciliation;
use App\Services\PaymentIntentService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class MockPaymentIntentBankPaid extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment_intent:mock-bank-paid {--days=7}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mock a payment intent bank paid event';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(private PaymentIntentService $paymentIntentService)
    {
        parent::__construct();
    }

    protected function getDaysOption(): int
    {
        return $this->option('days') ?? 1;
    }

    protected function getStartAndEndDate($days)
    {
        $endDate = now()
            ->setTimezone('UTC');

        $startDate = $endDate
            ->copy()
            ->subDays($days);

        return compact('startDate', 'endDate');
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $days = $this->getDaysOption();

        ['startDate' => $startDate, 'endDate' => $endDate] = $this->getStartAndEndDate($days);

        $this->info('[MockPaymentIntentBankPaid] Search mocking bank paid for payment intents from '.$startDate.' to '.$endDate);

        $paymentIntents = $this->paymentIntentService->getSandboxAllowBankPaidPaymentIntents($startDate, $endDate);

        $this->info('[MockPaymentIntentBankPaid] count: '.$paymentIntents->count());

        foreach ($paymentIntents as $paymentIntent) {
            $this->info('[MockPaymentIntentBankPaid] Mocking bank paid for payment intent: '.$paymentIntent->uuid);

            $mockTransaction = new FiservTransaction([
                'ITEM_NO' => Str::uuid()->toString(),
                'TRAN_STAT' => 'APPROVED',
                'TRAN_AMT' => $paymentIntent->captured_total,
                'ACC_AMT_NT' => $paymentIntent->captured_total * 0.99,
                'ACC_AMT_CH' => $paymentIntent->captured_total * 0.01,
                'ACC_CURR' => 'HKD',
                'TRAN_CURR' => 'HKD',
                'TRAN_DATE' => now()->setTimezone('UTC'),
                'MERCH_TRAN_REF' => $paymentIntent->getReconciliationId(),
            ]);

            $paymentIntentReconciliation = PaymentIntentReconciliation::query()->create([
                'country' => 'HK',
                'reference_uuid' => $mockTransaction->ITEM_NO,
                'currency' => $mockTransaction->TRAN_CURR,
                'total' => $mockTransaction->TRAN_AMT,
                'bank_paid_currency' => $mockTransaction->ACC_CURR,
                'bank_paid_total' => $mockTransaction->ACC_AMT_NT,
                'bank_paid_at' => Carbon::now(),
                'fee_currency' => $mockTransaction->ACC_CURR,
                'fee_total' => $mockTransaction->ACC_AMT_CH,
                'authorized_gateway' => AuthGatewayEnum::PAYNOW,
                'type' => ReconciliationType::PAYMENT_INTENT,
            ]);

            $paymentIntentReconciliation->paymentIntents()->attach($paymentIntent->id);

            $this->paymentIntentService->updatePaymentIntentBankStatusByFiservTransaction($paymentIntent, $mockTransaction);

            $this->info('[MockPaymentIntentBankPaid] Finish mocking bank paid for payment intent: '.$paymentIntent->uuid);

            $application = $paymentIntent->application;

            event(new PaymentIntentBankStatusUpdatedEvent($application, $paymentIntent));
        }
    }
}
