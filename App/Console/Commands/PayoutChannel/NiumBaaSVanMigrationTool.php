<?php

namespace App\Console\Commands\PayoutChannel;

use App\Exceptions\HttpException\NiumBaasVANDetailException;
use App\Exceptions\HttpException\NiumBaasVANTagDeleteException;
use App\Exceptions\HttpException\NiumBaasVANTagUpdateException;
use App\Services\ApplicationService;
use App\Services\Payout\NiumBaaSPayoutService;
use Illuminate\Console\Command;

class NiumBaaSVanMigrationTool extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium_baas_van:migrate
        {--mode=""}
        {--client=}
        {--bank_name=}
        {--currencies=}
        {--tag_key=}
        {--tag_value=}
        {--overwrite=false}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate VANs for the existing customers.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private NiumBaaSPayoutService $niumBaaSPayoutService,
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
        $mode = strtolower(trim($this->option('mode')));
        $nium_client = strtoupper(trim($this->option('client')));
        $bank_name = strtoupper(trim($this->option('bank_name')));
        $currencies = explode(',', strtoupper(trim($this->option('currencies'))));
        $overwrite_remote = ('true' == $this->option('overwrite'));

        $modes = [
            'sync_van_to_owlpay',
            'assign_van',
            'update_van_tag', // add or update tags.
            'delete_van_tag',
        ];

        if ((!in_array($mode, $modes))) {
            $this->error('Please input correct mode.');

            $this->info('--mode');
            $this->info('    sync_van_to_owlpay: Sync fetched assigned VANs to owlpay database.');
            $this->info('    assign_van: Assign VANs to the existing customers.');
            $this->info('    update_van_tag: Add/Update VAN tags that VANs only record in owlpay database to the existing customers.');
            $this->info('    delete_van_tag: Delete VAN tags that VANs only record in owlpay database to the existing customers.');
            $this->info('==================================Parameters===================================');
            $this->info('--client=HK: Pass the client name');
            $this->info('--bank_name=DBS_HK: Pass the bank name');
            $this->info('--currencies=USD,HKD,AUD,GBP,EUR: Pass the currencies');
            $this->info('--overwrite');

            return 0;
        }

        if ('sync_van_to_owlpay' == $mode) {
            if ('' == $nium_client) {
                $this->error('[Nium BaaS VAN Migration]Please check if parameters --client is empty.');

                return 0;
            }

            // Get nium client info and list all the customer under the relevant region
            $nium_baas_client_info = $this->niumBaaSPayoutService->getNiumBaaSClientInfoByRegion($nium_client);
            $active_nium_customers = $nium_baas_client_info->nium_baas_customer_info;

            if (_isEmpty($active_nium_customers)) {
                $this->warn("[Nium BaaS VAN Migration]No active nium customers under client $nium_client");

                return 0;
            }

            $this->info("[Nium BaaS VAN Migration]sync all VAN details under client $nium_baas_client_info->name");

            $this->_syncAllVanDetails($active_nium_customers);
        }

        if ('assign_van' == $mode) {
            if ('' == $nium_client || '' == $bank_name || 0 == count($currencies)) {
                $this->error('[Nium BaaS VAN Migration]Please check if parameters --client, --bank_name, --currencies are empty.');

                return 0;
            }

            // Get nium client info and list all the customer under the relevant region
            $nium_baas_client_info = $this->niumBaaSPayoutService->getNiumBaaSClientInfoByRegion($nium_client);
            $active_nium_customers = $nium_baas_client_info->nium_baas_customer_info;

            if (empty($active_nium_customers)) {
                $this->warn("[Nium BaaS VAN Migration]No active nium customers under client $nium_client");

                return 0;
            }

            // assign VAN with new bankName and currencies
            try {
                foreach ($active_nium_customers as $nium_baas_customer) {
                    $nium_baas_customer_id = $nium_baas_customer->customer_id;
                    $nium_baas_wallet_id = $nium_baas_customer->wallet_id;
                    foreach ($currencies as $c) {
                        $this->info(sprintf('[Nium BaaS VAN Migration]Assign VAN with bank %s %s to customer %s', $bank_name, $c, $nium_baas_customer_id));
                        $van_details = $this->niumBaaSPayoutService->assignVANtoCustomer($nium_baas_client_info, $nium_baas_customer_id, $nium_baas_wallet_id, $c, $bank_name);
                        _owlPayLog('nium_baas_assign_van_to_customer', compact('van_details'), 'nium_baas', 'info');
                    }
                }
            } catch (NiumBaasVANDetailException $e) {
                $attributes = $e->getAttributes();
                _owlPayLog('nium_baas_assign_van_to_customer_failed', compact('attributes'), 'nium_baas', 'error');
            }
        }

        // 針對資料庫裡面已經有的VAN，在Nium端打上tag
        if ('update_van_tag' == $mode) {
            $tag_key = trim($this->option('tag_key'));
            $tag_value = trim($this->option('tag_value'));

            if ('' == $nium_client || '' == $bank_name || '' == $tag_key || '' == $tag_value) {
                $this->error('[Nium BaaS VAN Migration]parameters --client or --bank_name or --tag_key or --tag_value is empty.');

                return 0;
            }

            // prepare tag data
            $update_tags = [
                $tag_key => $tag_value,
            ];

            // Get nium client info and list all the customer under the relevant region
            $nium_baas_client_info = $this->niumBaaSPayoutService->getNiumBaaSClientInfoByRegion($nium_client);
            $active_nium_customers = $nium_baas_client_info->nium_baas_customer_info;

            if (empty($active_nium_customers)) {
                $this->warn("[Nium BaaS VAN Migration]No active nium customers under client $nium_client");

                return 0;
            }

            // If the overwrite option is true then update VAN tag with key and value. Otherwise, skip to update van tags.
            try {
                foreach ($active_nium_customers as $nium_baas_customer) {
                    $nium_baas_customer_id = $nium_baas_customer->customer_id;
                    $nium_baas_wallet_id = $nium_baas_customer->wallet_id;

                    $local_van_details = $this->niumBaaSPayoutService->getVANDetailsByNiumBaaSCustomerAndBankName($nium_baas_customer_id, $bank_name);
                    foreach ($local_van_details as $local_van) {
                        $currency = $local_van['currency_code'];
                        $unique_payment_id = $local_van['unique_payment_id'];
                        $tags = json_decode($local_van['tags'], true);

                        // Nium沒有針對unique_payment_id是FAILED作處理，所以如果更新到此類型的tag，API就會回HTTP 500，造成exception。因此，要對此類型的payment id過濾
                        if ('FAILED' == $unique_payment_id) {
                            $this->warn(sprintf('[Nium BaaS VAN Migration]Can not update VAN tag with payment id %s and currency %s to customer %s', $unique_payment_id, $currency, $nium_baas_customer_id));
                            continue;
                        }

                        // 判斷是否有相同的tag key
                        if (isset($tags[$tag_key]) && !$overwrite_remote) {
                            $this->info(sprintf('[Nium BaaS VAN Migration]Skip to update VAN tag %s with payment id %s %s to customer %s', $unique_payment_id, $currency, json_encode($update_tags), $nium_baas_customer_id));
                            continue;
                        }

                        $this->info(sprintf('[Nium BaaS VAN Migration]Update VAN tag %s with payment id %s %s to customer %s', json_encode($update_tags), $unique_payment_id, $currency, $nium_baas_customer_id));
                        $this->niumBaaSPayoutService->updateVANTags($nium_baas_client_info, $nium_baas_customer_id, $nium_baas_wallet_id, $currency, $unique_payment_id, $update_tags);
                    }
                }
            } catch (NiumBaasVANTagUpdateException $e) {
                $attributes = $e->getAttributes();
                _owlPayLog('nium_baas_update_van_tag_failed', compact('attributes'), 'nium_baas', 'error');
            }
        }

        // 針對資料庫裡面已經有的VAN，在Nium端刪除tag
        // Nium delete有bug，不管tag value是什麼，只要tag key相同就會被刪除。
        if ('delete_van_tag' == $mode) {
            $tag_key = trim($this->option('tag_key'));
            $tag_value = trim($this->option('tag_value'));

            if ('' == $nium_client || '' == $bank_name || '' == $tag_key || '' == $tag_value) {
                $this->error('[Nium BaaS VAN Migration]parameters --client or --bank_name or --tag_key or --tag_value is empty.');

                return 0;
            }

            // prepare tag data
            $delete_tags = [
                $tag_key => $tag_value,
            ];

            // Get nium client info and list all the customer under the relevant region
            $nium_baas_client_info = $this->niumBaaSPayoutService->getNiumBaaSClientInfoByRegion($nium_client);
            $active_nium_customers = $nium_baas_client_info->nium_baas_customer_info;

            if (empty($active_nium_customers)) {
                $this->warn("[Nium BaaS VAN Migration]No active nium customers under client $nium_client");

                return 0;
            }

            // Delete VAN tag with key and value
            try {
                foreach ($active_nium_customers as $nium_baas_customer) {
                    $nium_baas_customer_id = $nium_baas_customer->customer_id;
                    $nium_baas_wallet_id = $nium_baas_customer->wallet_id;

                    $local_van_details = $this->niumBaaSPayoutService->getVANDetailsByNiumBaaSCustomerAndBankName($nium_baas_customer_id, $bank_name);

                    try {
                        foreach ($local_van_details as $local_van) {
                            $currency = $local_van['currency_code'];
                            $unique_payment_id = $local_van['unique_payment_id'];

                            // Nium沒有針對unique_payment_id是FAILED作處理，所以如果更新到此類型的tag，API就會回HTTP 500，造成exception。因此，要對此類型的payment id過濾
                            if ('FAILED' == $unique_payment_id) {
                                $this->warn(sprintf('[Nium BaaS VAN Migration]Can not delete VAN tag with payment id %s and currency %s to customer %s', $unique_payment_id, $currency, $nium_baas_customer_id));
                                continue;
                            }

                            $this->info(sprintf('[Nium BaaS VAN Migration]Delete VAN tag %s with payment id %s %s to customer %s', json_encode($delete_tags), $unique_payment_id, $currency, $nium_baas_customer_id));
                            $this->niumBaaSPayoutService->deleteVANTags($nium_baas_client_info, $nium_baas_customer_id, $nium_baas_wallet_id, $currency, $unique_payment_id, $delete_tags);
                        }
                    } catch (NiumBaasVANTagUpdateException $e) {
                        $attributes = $e->getAttributes();
                        _owlPayLog('nium_baas_delete_van_tag_failed', compact('attributes'), 'nium_baas', 'error');
                    }
                }
            } catch (NiumBaasVANTagDeleteException $e) {
                $attributes = $e->getAttributes();
                _owlPayLog('nium_baas_delete_van_tag_failed', compact('attributes'), 'nium_baas', 'error');
            }
        }

        return 0;
    }

    private function _syncAllVanDetails($active_nium_customers)
    {
        $applications = $active_nium_customers->pluck('application_id')->toArray();
        $applications = $this->applicationService->getApplicationsByIds($applications);

        foreach ($applications as $application) {
            $nium_baas_customer_info = $application->nium_baas_customer_info;
            $nium_baas_customer_id = $nium_baas_customer_info->customer_id;
            $this->info("[Nium BaaS VAN Migration]sync VAN details for customer id $nium_baas_customer_id, application uuid $application->uuid");
            $result = $this->niumBaaSPayoutService->syncAllVanDetailsByApplication($application);

            if (0 === $result) {
                $this->warn("[Nium BaaS VAN Migration]Skip syncing VAN because no VAN has been assigned for the customer. $nium_baas_customer_id");
                continue;
            }

            $this->info('[Nium BaaS VAN Migration]Synchronize VAN with the database '.json_encode($result));
        }
    }
}
