<?php

namespace App\Console\Commands\PaymentIntent;

use App\Events\PaymentIntent\PaymentIntentCapturedEvent;
use App\Repositories\PaymentIntentRepository;
use App\Services\PaymentIntentService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

class PaymentIntentCapture extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment_intent:capture {timezone?}
                            {--is_test=}
                            {--days=30}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Capture payment-intent to Cybersource through PayNow';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        protected readonly PaymentIntentRepository $paymentIntentRepository,
        protected readonly PaymentIntentService $paymentIntentService,
    ) {
        parent::__construct();
    }

    protected function getIsTestOption(): bool
    {
        $isTest = $this->option('is_test');

        return 'true' === $isTest;
    }

    protected function getDaysOption(): int
    {
        return $this->option('days') ?? 30;
    }

    protected function getTimezone(): string
    {
        return $this->argument('timezone') ?? 'Asia/Taipei';
    }

    protected function getStartAndEndDate(Carbon $now, $days, $subMinutes)
    {
        $endDate = $now->copy()
            ->subMinutes($subMinutes)
            ->setTimezone('UTC');

        $startDate = $endDate
            ->copy()
            ->subDays($days);

        return compact('startDate', 'endDate');
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isTest = $this->getIsTestOption();

        $days = $this->getDaysOption();

        $subMinutes = $isTest
            ? Config::get('payment_intent.auto_capture_payment_minutes_for_test')
            : Config::get('payment_intent.auto_capture_payment_minutes');

        $timezone = $this->getTimezone();

        $now = now($timezone);

        $allowCaptureAt = now($timezone)->subMinutes(5);

        ['startDate' => $startDate, 'endDate' => $endDate] = $this->getStartAndEndDate($now, $days, $subMinutes);

        $this->info("pick up can capture payment-intent by {$timezone}");

        $paymentIntents = $this->paymentIntentRepository->getNotYetCapturedPaymentIntents(
            $startDate,
            $endDate,
            $allowCaptureAt,
            $isTest
        );

        $paymentIntents->each(function ($paymentIntent) {
            $merchantInfo = $paymentIntent->paymentIntentsMerchantInfo;

            if ($merchantInfo->enable_auto_capture) {
                event(new PaymentIntentCapturedEvent($paymentIntent->application, $paymentIntent));
            }
        });

        $this->info('capture payment-intent done');

        return 0;
    }
}
