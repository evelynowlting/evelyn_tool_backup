<?php

namespace App\Console\Commands;

use App\Events\Billing\BillingsWillExpiredEvent;
use App\Services\BillingService;
use Illuminate\Console\Command;

class BillingExpiredChecker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:expired_checker';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'if billing will expired, send email';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private BillingService $billingService
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
        $billings = $this->billingService->getWillExpiredBillings();

        $billings->groupBy('application_id')->each(function ($billings) {
            event(new BillingsWillExpiredEvent($billings->first()->application, $billings));
        });

        return 0;
    }
}
