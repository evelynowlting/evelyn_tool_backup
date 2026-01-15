<?php

namespace App\Console\Commands\PaymentIntent;

use App\Events\PaymentIntent\PaymentIntentFinancialReviewedUpdatedEvent;
use App\Services\PaymentIntentService;
use Illuminate\Console\Command;

class MockPaymentIntentFinancialReviewed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment_intent:mock-financial-reviewed {--days=7}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mock a payment intent financial reviewed event';

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

        $this->info('[MockPaymentIntentFinancialReviewed] Search mocking financial reviewed for payment intents from '.$startDate.' to '.$endDate);

        $paymentIntents = $this->paymentIntentService->getSandboxAllowFinancialReviewedPaymentIntents($startDate, $endDate);

        $this->info('[MockPaymentIntentFinancialReviewed] count: '.$paymentIntents->count());

        foreach ($paymentIntents as $paymentIntent) {
            $this->info('[MockPaymentIntentFinancialReviewed] Mocking financial reviewed for payment intent: '.$paymentIntent->uuid);

            $this->paymentIntentService->updatePaymentIntentAfterFinancialReviewed($paymentIntent);

            $this->info('[MockPaymentIntentFinancialReviewed] Finish mocking financial reviewed for payment intent: '.$paymentIntent->uuid);

            $application = $paymentIntent->application;

            event(new PaymentIntentFinancialReviewedUpdatedEvent($application, $paymentIntent));
        }
    }
}
