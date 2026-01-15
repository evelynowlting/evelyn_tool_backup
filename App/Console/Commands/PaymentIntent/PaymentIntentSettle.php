<?php

namespace App\Console\Commands\PaymentIntent;

use App\Events\PaymentIntent\PaymentIntentAutoSettleEvent;
use App\Events\PaymentIntent\PaymentIntentSettleEvent;
use App\Repositories\PaymentIntentRepository;
use App\Repositories\PaymentIntentsMerchantInfoRepository;
use App\Services\PaymentIntentService;
use Illuminate\Console\Command;

class PaymentIntentSettle extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment_intent:settle {timezone?}
                            {--is_test=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Settle payment-intent ';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        protected readonly PaymentIntentsMerchantInfoRepository $paymentIntentsMerchantInfoRepository,
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

    protected function getTimezone(): string
    {
        return $this->argument('timezone') ?? 'Asia/Taipei';
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isTest = $this->getIsTestOption();

        $timezone = $this->getTimezone();

        $now = now($timezone);

        $this->info("pick up can settle payment-intent by {$timezone}");

        $merchantInfos = $this->paymentIntentsMerchantInfoRepository->getLocalTimeCanSettlePaymentIntentsMerchantInfos($now);
        $merchantInfos = $merchantInfos->where('enable_auto_settle', true);
        $applications = $merchantInfos->pluck('application');

        $paymentIntents = $this->paymentIntentRepository->getAllowSettlePaymentIntentsByApplications($applications, $isTest);

        $paymentIntents->each(function ($paymentIntent) {
            $merchantInfo = $paymentIntent->paymentIntentsMerchantInfo;

            if ($merchantInfo->enable_auto_settle) {
                event(new PaymentIntentSettleEvent($paymentIntent->application, $paymentIntent));
            }
        });

        $paymentIntentsGroupByApplication = $paymentIntents->groupBy('application_id');

        foreach ($paymentIntentsGroupByApplication as $paymentIntents) {
            $application = $paymentIntents->first()->application;

            event(new PaymentIntentAutoSettleEvent($application, $paymentIntents));
        }

        $this->info('settle payment-intent done');

        return 0;
    }
}
