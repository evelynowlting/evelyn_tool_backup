<?php

namespace App\Console\Commands\PayoutChannel;

use App\Services\Payout\NiumBaaSOnboardService;
use App\Services\Payout\NiumBaaSPayoutService;
use Illuminate\Console\Command;

class NiumBaaSBeneficiaryValidationSchemaUtil extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium-baas-ben-validation-schema:util
                {--mode=list_required}
                {--to_file=true}
                {--customer_id=31ebe55c-3ec4-4e9a-b712-a9667e03ba08}
                {--payout_method=""}
                ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nium BaaS tool for getting beneficiary validation schema.';

    protected $currency = [
        // 38 currencies
        'USD',
        'AUD',
        'NZD',
        'CAD',
        'IDR',
        'SGD',
        'HKD',
        'MYR',
        'GBP',
        'INR',
        'LKR',
        'NPR',
        'PHP',
        'THB',
        'VND',
        'KRW',
        'DKK',
        'EUR',
        'SEK',
        'TRY',
        'ARS',
        'BRL',
        'CLP',
        'COP',
        'MXN',
        'PEN',
        'UYU',
        'CNY',
        'GHS',
        'KES',
        'NGN',
        'CRC',
        'ILS',
        'JPY',
        'ZAR',
        'TWD',
    ];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        protected NiumBaaSPayoutService $niumBaaSPayoutService,
        protected NiumBaaSOnboardService $niumBaasOnboardService
    ) {
        parent::__construct();
    }

    // /**
    //  * Execute the console command.
    //  *
    //  * @return int
    //  */
    public function handle()
    {
        $modes = [
            'list_required',
            'list_method',
            'list_all',
        ];
        $mode = strtolower(trim($this->option('mode')));
        $to_file = strtolower(trim($this->option('to_file')));
        $customer_id = strtolower(trim($this->option('customer_id')));
        $payout_method = strtolower(trim($this->option('payout_method')));

        $nium_baas_customer_info = $this->niumBaaSPayoutService->getNiumBaaSCustomerInfoById($customer_id);
        $nium_baas_client_info = $nium_baas_customer_info->nium_baas_client_info;

        if ((!in_array($mode, $modes))) {
            $this->error('Please input correct mode.');
            $this->info('For listing all required validation fields, please enter --mode=list_required');
            $this->info('For listing all schema, please enter --mode=list_all');
            $this->info('For listing summary, please enter --mode=list_method');
            $this->info('For exporting result to file, please enter --to_file=true');

            return;
        }

        $total_rst = [];
        foreach ($this->currency as $c) {
            $results = $this->niumBaasOnboardService->fetchBeneficiaryValidationSchema(
                $nium_baas_client_info,
                $customer_id,
                $c,
                $payout_method);

            $summary = [];
            $currency_all = [];
            $each_method = [];
            $cnt = 0;
            foreach ($results as $r) {
                $id = substr($r['$id'], 12, -5);
                $title = $r['title'];
                $required = $r['required'];
                $currency_all[$id]['title'] = $title;
                $currency_all[$id]['required'] = $required;
                $summary[] = $title;

                $each_method[] = $r;
                $this->info(sprintf('Currency: %s, Title: %s, Required: %s ', $c, $title, implode(',', $required)));
                ++$cnt;
            }

            if ($cnt > 0) {
                $c .= '(Total method='.$cnt.')';
            }

            if ('list_required' == $mode) {
                $total_rst[$c] = $currency_all;
            } elseif ('list_all' == $mode) {
                $total_rst[$c] = $each_method;
            } elseif ('list_method' == $mode) {
                $total_rst[$c] = $summary;
            }
        }

        if ($to_file) {
            if ('list_required' == $mode) {
                $version = 'required';
            } elseif ('list_all' == $mode) {
                $version = 'detailed';
            } elseif ('list_method' == $mode) {
                $version = 'method';
            }

            $datetime = now()->timezone('Asia/Taipei')->format('Ymd_Hi');
            $file = 'nium_baas_beneficiary_validation_schema_'.$datetime.'_'.$version.'.json';
            file_put_contents($file, json_encode($total_rst));
            $this->info('=====Exporting the result to file=====');
            $this->info('File name: '.$file);
        }
    }
}
