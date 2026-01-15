<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\PayoutGateway;
use App\Models\PayoutGatewaySupportCurrency;
use App\Services\PayoutGatewayService;
use Artisan;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncSupportCurrenciesToPayoutGateway extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:support_currencies_to_payout_gateway {--gateway=} {--application_id=} {--sync_fee}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'When payout_gateway_support_currencies table updated, should sync to application payout gateway';

    /** @var PayoutGatewayService */
    protected $payoutGatewayService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
        $this->payoutGatewayService = app('App\Services\PayoutGatewayService');
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $specGateway = $this->option('gateway');

        $application_id = $this->option('application_id');

        $payout_gateway_support_currencies = PayoutGatewaySupportCurrency::when(!is_null($specGateway), function ($query) use ($specGateway) {
            $query->where('gateway', $specGateway);
        })->get();

        $applications = Application::orderBy('id', 'DESC')->get();

        $gateways = $payout_gateway_support_currencies->pluck('gateway')->unique();

        $payout_gateways = PayoutGateway::join('applications', 'applications.id', '=', 'payout_gateways.application_id')
            ->whereIn('gateway', $gateways)
            ->whereNotNull('target_country')
            ->where('is_test', false)
            ->when(!empty($application_id), function ($query) use ($application_id) {
                $query->where('application_id', $application_id);
            })
            ->select([
                'applications.country_iso as country_iso',
                'payout_gateways.*',
            ])
            ->get()
            ->groupBy('gateway');

        $payout_network_configs = config('payout_network');
        $is_active = [];
        $is_allow_ask_for_payment = [];
        $enable_two_step_payout = [];

        foreach ($gateways as $gateway) {
            $this->info(sprintf('Sync payout_gateway to already onboard application: %s', $gateway));

            $support_gateway_country_currencies = $payout_gateway_support_currencies->groupBy('gateway')[$gateway];

            $this->info(sprintf('Sync %s country currencies', $support_gateway_country_currencies->count()));

            if (!isset($payout_gateways[$gateway])) {
                continue;
            }

            $application_gateways = $payout_gateways[$gateway]->groupBy('application_id');

            foreach ($application_gateways as $application_id => $application_gateway) {
                $application_current_currencies[$gateway][$application_id] = $application_gateway->map(function ($application_gateway) use (&$is_active, &$is_allow_ask_for_payment, &$enable_two_step_payout, $gateway, $application_id) {
                    if (!isset($is_active[$gateway][$application_id])) {
                        $is_active[$gateway][$application_id] = $application_gateway['is_active'];
                    }

                    if (!isset($is_allow_ask_for_payment[$gateway][$application_id])) {
                        $is_allow_ask_for_payment[$gateway][$application_id] = $application_gateway['is_allow_ask_for_payment'];
                    }

                    if (!isset($enable_two_step_payout[$gateway][$application_id])) {
                        $enable_two_step_payout[$gateway][$application_id] = $application_gateway['enable_two_step_payout'];
                    }

                    return [
                        'target_country' => $application_gateway['target_country'],
                        'source_currency' => $application_gateway['source_currency'],
                        'target_currency' => $application_gateway['target_currency'],
                        'fee_currency' => $application_gateway['fee_currency'],
                        'fee_rate' => $application_gateway['fee_rate'],
                        'fee_price' => $application_gateway['fee_price'],
                        'is_active' => $application_gateway['is_active'],
                        'is_allow_ask_for_payment' => $application_gateway['is_allow_ask_for_payment'],
                        'enable_two_step_payout' => $application_gateway['enable_two_step_payout'],
                    ];
                })->groupBy(['target_country', 'source_currency', 'target_currency'])->toArray();
            }

            $payout_network_config_key = array_flip(array_column($payout_network_configs, 'key'))[$gateway] ?? null;

            if (!is_null($payout_network_config_key)) {
                $payout_network_config = $payout_network_configs[$payout_network_config_key];
            }

            $support_gateway_country_currency_pairs = $support_gateway_country_currencies->map(function ($support_gateway_country_currency) {
                return [
                    'target_country' => $support_gateway_country_currency['country_iso'],
                    'source_currency' => $support_gateway_country_currency['source_currency'],
                    'target_currency' => $support_gateway_country_currency['target_currency'],
                    'fee_currency' => $support_gateway_country_currency['fee_currency'],
                    'fee_rate' => $support_gateway_country_currency['fee_rate'],
                    'fee_price' => $support_gateway_country_currency['fee_price'],
                ];
            })->toArray();

            foreach ($application_current_currencies[$gateway] as $application_id => $application_current_currency) {
                $insert_data = [];
                $application = $applications->find($application_id);

                if (empty($application)) {
                    $this->error('Error! application not found application_id:'.$application_id);
                    continue;
                }

                $this->info(sprintf('Sync currency pairs Application ID:%s', $application->id));

                $index = 0;
                foreach ($support_gateway_country_currency_pairs as $key => $support_gateway_country_currency_pair) {
                    $index = $key + 1;
                    $this->info(sprintf("[$index/".count($support_gateway_country_currency_pairs).'] Sync supportGatewayCountryCurrencyPairs'));
                    $is_allow_install_payout_gateway = $this->payoutGatewayService->isAllowInstallPayoutGatewayByGatewayNetworkConfig($application, $payout_network_config);

                    if (!$is_allow_install_payout_gateway) {
                        continue;
                    } else {
                        $payout_gateway_support_currency = $payout_gateway_support_currencies->filter(function ($payout_gateway_support_currency) use ($support_gateway_country_currency_pair, $gateway) {
                            return $payout_gateway_support_currency->source_currency == $support_gateway_country_currency_pair['source_currency']
                                && $payout_gateway_support_currency->target_currency == $support_gateway_country_currency_pair['target_currency']
                                && $payout_gateway_support_currency->gateway == $gateway
                                && $payout_gateway_support_currency->country_iso == $support_gateway_country_currency_pair['target_country'];
                        })->first();

                        $application_current_currency_object = $application_current_currency[$support_gateway_country_currency_pair['target_country']][$support_gateway_country_currency_pair['source_currency']][$support_gateway_country_currency_pair['target_currency']][0] ?? null;

                        $insert_data[] = [
                            'is_test' => false,
                            'application_id' => $application->id,
                            'gateway' => $gateway,
                            'target_country' => $support_gateway_country_currency_pair['target_country'],
                            'source_currency' => $support_gateway_country_currency_pair['source_currency'],
                            'target_currency' => $support_gateway_country_currency_pair['target_currency'],
                            'fee_rate' => $payout_gateway_support_currency['fee_rate'] ?? 0,
                            'fee_price' => $payout_gateway_support_currency['fee_price'] ?? 0,
                            'fee_currency' => $payout_gateway_support_currency['fee_currency'] ?? 'USD',
                            'is_active' => $application_current_currency_object['is_active'] ?? $is_active[$gateway][$application_id],
                            'is_allow_ask_for_payment' => $application_current_currency_object['is_allow_ask_for_payment'] ?? $is_allow_ask_for_payment[$gateway][$application_id],
                            'enable_two_step_payout' => $application_current_currency_object['enable_two_step_payout'] ?? $enable_two_step_payout[$gateway][$application_id],
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ];
                        $this->info(sprintf("[$index/".count($support_gateway_country_currency_pairs).'] Generate Insert Data'));
                    }
                }

                DB::beginTransaction();

                $application->payout_gateways()->where('gateway', $gateway)->forceDelete();
                if (!empty($insert_data)) {
                    $chunked = array_chunk($insert_data, 500);
                    foreach ($chunked as $chunk) {
                        PayoutGateway::insert($chunk);
                    }
                }

                DB::commit();

                $this->info('------');

                $is_sync_fee = $this->option('sync_fee');

                if ($is_sync_fee) {
                    $this->info(sprintf('Sync gateway fees Application ID:%s', $application->id));
                    Artisan::call('sync:payout_gateway_fees', [
                        '--application_uuid' => $application->uuid,
                        '--payout_gateway' => [$gateway],
                        '--is_test' => false,
                    ]);
                }
            }
        }
    }
}
