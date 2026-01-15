<?php

namespace App\Console\Commands\PayoutChannel;

use App\Mail\AdminNiumBaaSBeneficiarySchemaChangedMail;
use App\Mail\AdminNiumBaaSBeneficiarySchemaFetchErrorMail;
use App\Models\NiumBaaSClientInfo;
use App\Repositories\PayoutGatewaySupportCurrencyRepository;
use App\Services\Payout\NiumBaaSOnboardService;
use Arr;
use Illuminate\Console\Command;
use Mail;

class NiumBaaSBeneficiarySchemaDetector extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium_baas:beneficiary_schema_detector';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nium baas beneficiary schema detector';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private NiumBaaSOnboardService $niumBaaSOnboardService,
        private PayoutGatewaySupportCurrencyRepository $payoutGatewaySupportCurrencyRepository,
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
        $fetched_errors = [];

        $clientInfoList = NiumBaaSClientInfo::all();

        $contract_information = require config_path().'/fields/payout_network/nium_baas/contract_information.php';

        $currencies = $contract_information['currency_list'];

        // $list = $allowCountriesAndCurrencies->map(function($allowCountriesAndCurrency) {

        //     return [
        //         'country_iso' => $allowCountriesAndCurrency->country_iso,
        //         'target_currency' => $allowCountriesAndCurrency->target_currency,
        //     ];
        // })->unique()->groupBy('country_iso')->toArray();

        // $payment_methods = [
        //     'LOCAL',
        //     'SWIFT',
        //     'WALLET',
        //     'CARD',
        //     'PROXY',
        // ];

        $this->info(sprintf('[Nium BaaS beneficiary detector] Start checking beneficiary schema with %s currencies: %s', count($currencies), implode(',', $currencies)));

        foreach ($clientInfoList as $clientInfo) {
            $customerId = $clientInfo->nium_baas_customer_info()->first()?->customer_id;
            $region = $clientInfo->country_code;
            $this->info("[Nium BaaS beneficiary detector] Start with client $region and customer id $customerId");

            if (empty($customerId)) {
                $this->warn('[Nium BaaS beneficiary detector] '.$clientInfo->country_code.'('.$clientInfo->client_id.') region not found any customer');
                continue;
            }

            foreach ($currencies as $currency) {
                $schemaList = $this->niumBaaSOnboardService->fetchBeneficiaryValidationSchema($clientInfo, $customerId, $currency);

                // Error:
                // $schemaList =
                // [
                //     'status' => 'BAD_REQUEST',
                //     'message' => 'Unable to get validation schema',
                //     'errors' => [
                //       'Unable to get validation schema',
                //     ],
                // ];
                if (isset($schemaList['errors'])) {
                    $message = $schemaList['message'] ?? null;

                    $fetched_errors[$region][$currency] = $message;
                    $this->error(sprintf('[Nium BaaS beneficiary detector] %s %s schema NOT found, error message %s', $clientInfo->country_code, $currency, $message));

                    continue;
                }

                foreach ($schemaList as $schema) {
                    $latest = $clientInfo->nium_baas_beneficiary_schemas()->where([
                        'schema_nium_baas_id' => $schema['$id'],
                    ])->orderBy('created_at', 'DESC')->first();

                    $schema = $clientInfo->nium_baas_beneficiary_schemas()->create([
                        'currency' => $currency,
                        'schema' => $schema['$schema'],
                        'schema_nium_baas_id' => $schema['$id'],
                        'type' => $schema['type'],
                        'title' => $schema['title'],
                        'rules' => Arr::except($schema, [
                            '$schema',
                            '$id',
                            'type',
                            'title',
                        ]),
                    ]);

                    $this->info('[Nium BaaS beneficiary detector] '.$clientInfo->country_code.' '.$currency.' created, schema_nium_baas_id:'.$schema->schema_nium_baas_id);

                    if (!empty($latest)) {
                        if ($schema->rules == $latest->rules) {
                            $this->info('[Nium BaaS beneficiary detector] The rules are the same as the previous record.');
                        } else {
                            $this->error('[Nium BaaS beneficiary detector] The rules have been changed.');
                            _owlPayLog('nium_baas_beneficiary_schema_changed', [
                                'schema_nium_baas_id' => $schema['$id'],
                            ], 'nium_baas', 'error');
                            Mail::to([
                                config('owlpay_notify.owlpay_rd_email'),
                                ])->queue((new AdminNiumBaaSBeneficiarySchemaChangedMail($latest, $schema, $region))->onQueue('send-mail'));
                        }
                    }
                }
            }
        }

        if (!_isEmpty($fetched_errors)) {
            $this->error('[Nium BaaS beneficiary detector] Error list '.json_encode($fetched_errors));
            Mail::to([
                config('owlpay_notify.owlpay_rd_email'),
                ])->queue((new AdminNiumBaaSBeneficiarySchemaFetchErrorMail($fetched_errors))->onQueue('send-mail'));
        }

        return 0;
    }
}
