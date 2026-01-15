<?php

namespace App\Console\Commands;

use App\Models\PaymentIntentSupportCurrency;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncPaymentIntentNetworkConfigToDatabase extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:payment_intent_network_config_to_database';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'When config/payment_intent_network.php updated, should sync to database';

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
        $paymentIntentNetworksAllowCountries = collect(config('payment_intent_network'))->pluck('allow_countries');

        foreach ($paymentIntentNetworksAllowCountries as $key => $paymentIntentNetworksAllowCountry) {
            $paymentIntentNetwork = config("payment_intent_network.$key");
            $paymentIntentGateway = $paymentIntentNetwork['key'];

            foreach ($paymentIntentNetwork['allow_countries'] as $supportPair) {
                foreach ($supportPair['support_currencies'] as $supportCurrency) {
                    $insert_data[] = [
                        'country_iso' => $supportPair['country_iso'],
                        'currency' => $supportCurrency,
                        'gateway' => $paymentIntentGateway,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ];
                }
            }
        }

        PaymentIntentSupportCurrency::truncate();
        PaymentIntentSupportCurrency::insert($insert_data);
    }
}
