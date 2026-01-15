<?php

namespace App\Console\Commands\PayoutChannel;

use App\Services\ApplicationService;
use App\Services\Payout\NiumBaaSOnboardService;
use App\Services\Payout\NiumBaaSPayoutService;
use Illuminate\Console\Command;

class NiumBaaSSyncApplication extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium_baas:sync {--application_id=} {--sync_van_details}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync nium baas wallet/reconciliation/payIn account manually.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private NiumBaaSPayoutService $niumBaaSPayoutService,
        private NiumBaaSOnboardService $niumBaaSOnboardService,
        private ApplicationService $applicationService
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
        $application_id = $this->option('application_id');

        $sync_van_details = $this->option('sync_van_details');

        if (!empty($application_id)) {
            $applications = [$application_id];
        } else {
            $niumOnboardCustomers = $this->niumBaaSOnboardService->getAllOnboardCustomers();
            $applications = $niumOnboardCustomers->pluck('application_id')->toArray();
        }

        $applications = $this->applicationService->getApplicationsByIds($applications);

        foreach ($applications as $application) {
            $country = $application->country_iso;

            $niumBaaSCustomerInfo = $application->nium_baas_customer_info;

            if ($niumBaaSCustomerInfo->is_active) {
                if ($sync_van_details) {
                    $this->niumBaaSPayoutService->assignVanDetailsByConfig($niumBaaSCustomerInfo, $country);
                }

                $this->niumBaaSPayoutService->syncAllVanDetailsByApplication($application);
                $this->niumBaaSPayoutService->syncAllWalletsByApplication($application);
                $this->niumBaaSPayoutService->syncAllReconciliationsByApplication($application);
            }
        }
    }
}
