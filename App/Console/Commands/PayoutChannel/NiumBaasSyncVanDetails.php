<?php

namespace App\Console\Commands\PayoutChannel;

use App\Exceptions\HttpException\EmptyException;
use App\Exceptions\HttpException\NiumBaasVANDetailException;
use App\Models\Application;
use App\Models\NiumBaaSCustomerInfo;
use App\Services\ApplicationService;
use App\Services\Payout\NiumBaaSPayoutService;
use Illuminate\Console\Command;

class NiumBaasSyncVanDetails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium_baas:sync_van_details {--all} {--application_uuid=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(private NiumBaaSPayoutService $niumBaaSPayoutService, private ApplicationService $applicationService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $is_all = $this->option('all');
        $application_uuid = $this->option('application_uuid');

        if (!$is_all) {
            if (empty($application_uuid)) {
                $is_spec_application = $this->confirm('Do you want to choose spec application?');
            } else {
                $is_spec_application = true;
            }
        } else {
            $is_spec_application = false;
        }

        $applications = [];

        if ($is_spec_application) {
            if (!empty($application_uuid)) {
                $application = Application::where('uuid', $application_uuid)->first();
                if (null == $application) {
                    $this->printMessage('Application uuid not found', 'warn');
                }
            }

            while (empty($application)) {
                $application = $this->askApplication();
            }

            $nium_baas_customer_info = $application->nium_baas_customer_info;

            if (empty($nium_baas_customer_info)) {
                $this->printMessage('Application Nium baas customer info not exist', 'warn');

                return;
            }

            $applications[] = $application;
        } else {
            $applications = NiumBaaSCustomerInfo::where('is_active', true)->with(['application'])->get()->pluck('application')->unique();
        }

        foreach ($applications as $application) {
            $this->getAndSyncVanDetails($application);
        }
    }

    public function getAndSyncVanDetails($application)
    {
        $niumBaaSCustomerInfo = $application->nium_baas_customer_info;

        $niumBaasClientInfo = $niumBaaSCustomerInfo->nium_baas_client_info;

        $country = $application->country_iso;

        $customerId = $niumBaaSCustomerInfo->customer_id;

        $walletId = $niumBaaSCustomerInfo->wallet_id;

        $allowFundCurrencyBanks = config('payoutchannel.niumBaaS.allow_fund_currency_bank')[$country] ?? [];
        throw_if(empty($allowFundCurrencyBanks), new EmptyException('[Nium BaaS] allowFundCurrencyBanks in payoutchannel is empty'));

        $vanDetails = $this->niumBaaSPayoutService->fetchVANDetails($niumBaasClientInfo, $customerId, $walletId);

        $vanDetailsMap = [];
        foreach ($vanDetails['content'] as $vanDetail) {
            $vanDetailsMap[] = $vanDetail['currencyCode'].':'.$vanDetail['bankName'];
        }

        try {
            foreach ($allowFundCurrencyBanks as $allowFundCurrencyBank) {
                $bank = $allowFundCurrencyBank['bank_name'];
                foreach ($allowFundCurrencyBank['currencies'] as $currency) {
                    if (in_array($currency.':'.$bank, $vanDetailsMap)) {
                        continue;
                    }

                    $van_details = $this->niumBaaSPayoutService->assignVANtoCustomer($niumBaasClientInfo, $customerId, $walletId, $currency, $bank);
                    _owlPayLog('nium_baas_assign_van_to_customer', compact('van_details'), 'nium_baas', 'info');
                }
            }
        } catch (NiumBaasVANDetailException $e) {
            $attributes = $e->getAttributes();
            _owlPayLog('nium_baas_assign_van_to_customer_failed', compact('attributes'), 'nium_baas', 'error');
        }

        $this->niumBaaSPayoutService->syncAllVanDetailsByApplication($application);
        $this->niumBaaSPayoutService->syncAllWalletsByApplication($application);
        $this->niumBaaSPayoutService->syncAllReconciliationsByApplication($application);
    }

    private function printMessage($message, $level = 'info', $data = [])
    {
        $message_prefix = '[Sync Nium Baas VAN Details] ';

        $message = $message_prefix.$message;

        switch ($level) {
            case 'warn':
            case 'error':
                $this->{$level}($message);
                _owlPayLog('sync_nium_baas_van_details', $data, 'system', 'info');
                break;
            case 'info':
            default:
                $this->info($message);
                _owlPayLog('sync_nium_baas_van_details', $data, 'system', 'info');
                break;
        }
    }

    private function askApplication()
    {
        $application_options = collect();
        $select_check = false;
        $application = null;

        while (0 == $application_options->count()) {
            $application_name_or_uuid = $this->ask('what your application name or uuid?');

            $application_options = $this->applicationService
                ->getApplicationsByNameOrUUID($application_name_or_uuid);
        }

        while (null == $application && false == $select_check) {
            if ($application_options->count() > 1) {
                $options = $application_options->map(function ($application) {
                    return "$application->name ($application->uuid)";
                })->toArray();

                $choice = $this->choice('What your application?', $options);

                $application = $application_options[array_search($choice, $options)] ?? null;
            } else {
                $application = $application_options->first();
            }

            $this->table(
                ['id', 'uuid', 'name'],
                [[$application->id, $application->uuid, $application->name]]
            );

            $select_check = $this->confirm('Is application select correctly?');
        }

        if (!empty($application)) {
            return $application;
        }

        return null;
    }
}
