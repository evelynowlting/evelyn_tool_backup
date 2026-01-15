<?php

namespace App\Console\Commands\Development;

use App\Services\Harbor\PaymentIntentService as HarborPaymentIntentService;
use App\Services\HarborApplicationInfoService;
use Illuminate\Console\Command;

class GenerateHarborTestModeApp extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:harbor_test_mode_app';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate harbor test mode application';

    public function __construct(
        private HarborApplicationInfoService $harborApplicationInfoService,
        private HarborPaymentIntentService $harborPaymentIntentService,
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
        $harbor = $this->harborPaymentIntentService->updateOrCreateHarborTestApplicationInfo();

        $this->harborPaymentIntentService->processSubscriptionWebhook($harbor);

        $this->info("Generated Harbor app id: $harbor->id");

        return 0;
    }
}
