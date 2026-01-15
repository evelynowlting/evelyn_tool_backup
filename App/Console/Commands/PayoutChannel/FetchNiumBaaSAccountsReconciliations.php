<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\PayoutChannel\CrossBorderPayoutEnum;
use App\Models\Application;
use App\Services\ApplicationService;
use App\Services\Payout\NiumBaaSPayoutService;
use Illuminate\Console\Command;

class FetchNiumBaaSAccountsReconciliations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium_baas:reconciliations
                            {--app_id=all}
                            ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This tool is used for updating Nium BaaS accounts reconciliations.';

    protected $niumBaaSPayoutService;

    protected $applicationService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        NiumBaaSPayoutService $niumBaaSPayoutService,
        ApplicationService $applicationService
    ) {
        parent::__construct();
        $this->niumBaaSPayoutService = $niumBaaSPayoutService;
        $this->applicationService = $applicationService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // get related application id
        $application_id = $this->option('app_id');

        if ('all' == $application_id) {
            $applications = $this->applicationService->getApplicationsWithChannel(CrossBorderPayoutEnum::NIUM_BAAS);

            foreach ($applications as $application) {
                $this->_updateAccountsReconcileRecords($application);
            }
        } else {
            $application = Application::find($application_id);
            $this->_updateAccountsReconcileRecords($application);
        }

        return 0;
    }

    private function _updateAccountsReconcileRecords($application)
    {
        $nium_baas_customer_info = $application->nium_baas_customer_info;

        $nium_baas_client_info = $nium_baas_customer_info->nium_baas_client_info;

        $customer_id = $nium_baas_customer_info->customer_id;

        $wallet_id = $nium_baas_customer_info->wallet_id;

        $page = 0;

        $size = 100;

        do {
            // fetch accounts reconciliations
            $response = $this->niumBaaSPayoutService->fetchPayoutDetails($nium_baas_client_info, $customer_id, $wallet_id, page: $page, size: $size);

            if (empty($response)) {
                $this->error(sprintf('[Nium Payout]Error fetching accounts reconciliations with application id %s and nium baas wallet id %s',
                    $application->id,
                    $wallet_id)
                );

                return 1;
            }

            $totalPages = $response['totalPages'];
            $totalElements = $response['totalElements'];
            $currentPage = $page;
            // $recordsFiltered = $response['recordsFiltered'];
            $accounts_reconciliations = $response['content'];
            $ids = array_column($accounts_reconciliations, 'systemTraceAuditNumber');

            // dd($ids);
            $existing_counts = $this->niumBaaSPayoutService->numberOfExistingAccountsReconciliations($ids);

            // if (config('payoutchannel.niumBaaS.debug')) {
            $this->info(sprintf('[Nium BaaS Payout]application_id:%s, nium_baas_wallet_id:%s', $application->id, $wallet_id));
            $this->info("[Nium BaaS Payout]Page: $page");
            $this->info('[Nium BaaS Payout]Fetched reconciliations: '.$totalElements.' ');
            $this->info('[Nium BaaS Payout]Existing reconciliations: '.$existing_counts)."\n";
            // }

            if (0 == $existing_counts) {
                $this->info('[Nium BaaS Payout]All records need be update.');
                $this->niumBaaSPayoutService->updateOrCreateAccountsReconciliations($application, $wallet_id, $accounts_reconciliations);
            } elseif ($existing_counts == $totalElements) {
                $this->info('[Nium BaaS Payout]No record needs to be update.');
            } elseif ($existing_counts < $totalElements) {
                $this->info('[Nium BaaS Payout]Partial records update.');
                $this->niumBaaSPayoutService->updateOrCreateAccountsReconciliations($application, $wallet_id, $accounts_reconciliations);
                break;
            }

            ++$page;
        } while ($totalPages > $currentPage);
    }
}
