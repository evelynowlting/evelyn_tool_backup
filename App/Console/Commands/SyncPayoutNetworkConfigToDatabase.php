<?php

namespace App\Console\Commands;

use App\Enums\PayoutChannel\CrossBorderPayoutEnum;
use App\Models\PayoutGatewaySupportCurrency;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class SyncPayoutNetworkConfigToDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:payout_network_config_to_database';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'When config/payout_network.php updated, should sync to database';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
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
        $payout_networks_allow_countries = collect(config('payout_network'))->pluck('allow_countries');

        foreach ($payout_networks_allow_countries as $key => $payout_networks_allow_country) {
            $payout_network = config("payout_network.$key");
            $payout_gateway = $payout_network['key'];

            $is_allow_ask_for_payment = $payout_network['allow_ask_for_payment'];

            foreach ($payout_network['allow_order_currencies'] as $source_currency) {
                foreach ($payout_networks_allow_country as $country_currency_pairs) {
                    foreach ($country_currency_pairs['currency_pairs'] as $currency_pairs) {
                        if (CrossBorderPayoutEnum::VISA_VPA === $payout_gateway
                            && $source_currency != $currency_pairs['target_currency']) {
                            continue;
                        }

                        $insert_data[] = [
                            'country_iso' => $country_currency_pairs['country_iso'],
                            'gateway' => $payout_gateway,
                            'source_currency' => $source_currency,
                            'target_currency' => $currency_pairs['target_currency'],
                            'fee_currency' => $currency_pairs['fee_currency'] ?? 'USD',
                            'fee_price' => $currency_pairs['fee_price'] ?? 0,
                            'fee_rate' => $currency_pairs['fee_rate'] ?? 0,
                            'is_allow_ask_for_payment' => $is_allow_ask_for_payment,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ];
                    }
                }
            }
        }

        PayoutGatewaySupportCurrency::truncate();

        PayoutGatewaySupportCurrency::insert($insert_data);
        // foreach ($insert_data as $data) {
        //     PayoutGatewaySupportCurrency::firstOrCreate(
        //         Arr::only($data, ['country_iso', 'gateway', 'source_currency', 'target_currency']),
        //         Arr::only($data, ['fee_currency', 'fee_price', 'fee_rate', 'is_allow_ask_for_payment']),
        //     );
        // }
    }
}
