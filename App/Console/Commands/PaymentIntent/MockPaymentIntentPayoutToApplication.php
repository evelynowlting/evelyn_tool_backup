<?php

namespace App\Console\Commands\PaymentIntent;

use App\Enums\PaymentIntent\MerchantInfo\AuthGatewayEnum;
use App\Events\PaymentIntent\PaymentIntentPayoutToApplicationEvent;
use App\Services\PaymentIntentService;
use Illuminate\Console\Command;

class MockPaymentIntentPayoutToApplication extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment_intent:mock-payout-to-application {--days=7}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mock a payment intent payout to application event';

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

        $this->info('[MockPaymentIntentPayoutToApplication] Search mocking payout to application for payment intents from '.$startDate.' to '.$endDate);

        $paymentIntents = $this->paymentIntentService->getSandboxAllowPayoutToApplicationPaymentIntents($startDate, $endDate);

        $this->info('[MockPaymentIntentPayoutToApplication] count: '.$paymentIntents->count());

        foreach ($paymentIntents as $paymentIntent) {
            if (AuthGatewayEnum::HARBOR == $paymentIntent->auth_gateway) {
                continue;
            }
            $this->info('[MockPaymentIntentPayoutToApplication] Mocking payout to application for payment intent: '.$paymentIntent->uuid);

            $payoutTotal = $paymentIntent->net();
            $payoutBankName = 'OwlTing Sandbox Bank';
            $payoutBankAccountName = 'OwlPay Inc';
            $payoutBankAccountNumber = '111222333444';
            $this->paymentIntentService->updatePaymentIntentAfterPayoutToApplication($paymentIntent, $payoutTotal, $payoutBankName, $payoutBankAccountName, $payoutBankAccountNumber);

            $this->info('[MockPaymentIntentPayoutToApplication] Finish mocking payout to application for payment intent: '.$paymentIntent->uuid);

            $application = $paymentIntent->application;

            event(new PaymentIntentPayoutToApplicationEvent($application, $paymentIntent));
        }
    }
}
