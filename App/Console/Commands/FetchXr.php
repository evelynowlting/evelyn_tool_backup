<?php

namespace App\Console\Commands;

use App\Enums\ExchangeRateProviderEnum;
use App\Services\ExchangeRateService;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchXr extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'xr:fetch
        {--provider=}
        {--rebase=}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch currency exchange rates from online providers';
    /**
     * @var ExchangeRateService
     */
    private $exchangeRateService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(ExchangeRateService $exchangeRateService)
    {
        parent::__construct();

        $this->exchangeRateService = $exchangeRateService;
    }

    /**
     * Re-generate rates array with base currency replaced.
     * Limitations:
     *      1. Only work if all items have the same 'from_currency'
     *      2. The new base currency must be in the rates array.
     *
     * @param array  $rates    exchange rate array to be inserted
     * @param string $new_base 3-letter currency code
     *
     * @return array
     */
    private function _rebase($rates, $new_base)
    {
        if (!$rates or $new_base == $rates[0]['from_currency']) {
            return;
        }

        $invert_rate = 0;
        $old_base = $rates[0]['from_currency'];
        foreach ($rates as $rate) {
            if ($rate['from_currency'] != $old_base) {
                return;
            }
            if ($rate['to_currency'] == $new_base) {
                $invert_rate = 1 / $rate['rate'];
                break;
            }
        }

        if (!$invert_rate) {
            return;
        }

        $provider = $rates[0]['provider'];
        $published_at = $rates[0]['published_at'];

        /**
         * To avoid potential exponent bias of floating-point numbers,
         * we treat the loopback and invert rules separately
         * rather than relying on plain arithmetic calculation.
         */
        $rebased_rates = [[     // loopback
            'from_currency' => $new_base,
            'to_currency' => $new_base,
            'rate' => 1,
            'provider' => $provider,
            'published_at' => $published_at,
        ]];
        foreach ($rates as $rate) {
            if ($rate['from_currency'] == $rate['to_currency']) {
                continue;
            }
            if ($rate['to_currency'] == $new_base) {
                $rebased_rates[] = [    // invert
                    'from_currency' => $new_base,
                    'to_currency' => $old_base,
                    'rate' => $invert_rate,
                    'provider' => $provider,
                    'published_at' => $published_at,
                ];
            } else {
                $rebased_rates[] = [    // rebase
                    'from_currency' => $new_base,
                    'to_currency' => $rate['to_currency'],
                    'rate' => $rate['rate'] * $invert_rate,
                    'provider' => $provider,
                    'published_at' => $published_at,
                ];
            }
        }

        return $rebased_rates;
    }

    private function _readFromApiforex()
    {
        $api_key = env('XR_APIFOREX_API_KEY');
        if (!$api_key) {
            $this->error('Missing API key for Api Forex');

            return;
        }
        try {
            $response = Http::get('https://v2.api.forex/rates/latest.json', ['key' => $api_key]);
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());

            return;
        }
        if (true != $response['success']) {
            $this->error('Server reponses fail');

            return;
        }

        $rates = [];
        $base = $response['infos']['source']['base_currency'];
        $loopback = false;
        foreach ($response['rates'] as $currency => $rate) {
            $rates[] = [
                'from_currency' => $base,
                'to_currency' => $currency,
                'rate' => $rate,
                'provider' => 'apiforex',
                'published_at' => $response['infos']['timestamp'],
            ];
            if ($base == $currency) {
                $loopback = true;
            }
        }

        if (!$loopback) {
            $rates[] = [
                'from_currency' => $base,
                'to_currency' => $base,
                'rate' => 1,
                'provider' => 'apiforex',
                'published_at' => $response['infos']['timestamp'],
            ];
        }

        return $rates;
    }

    private function _readFromCurrencylayer()
    {
        $api_key = env('XR_CURRENCYLAYER_API_KEY');
        if (!$api_key) {
            $this->error('Missing API key for currencylayer');

            return;
        }

        try {
            $response = Http::get('http://api.currencylayer.com/live', ['access_key' => $api_key]);
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());

            return;
        }
        if (true != $response['success']) {
            $this->error('Server reponses fail');

            return;
        }

        $rates = [];
        $base = $response['source'];
        $published_at = new DateTime("@{$response['timestamp']}");
        foreach ($response['quotes'] as $quote => $rate) {
            $rates[] = [
                'from_currency' => $base,
                'to_currency' => substr($quote, 3),
                'rate' => $rate,
                'provider' => 'currencylayer',
                'published_at' => $published_at,
            ];
        }

        return $rates;
    }

    private function _readFromFixerio()
    {
        $api_key = env('XR_FIXERIO_API_KEY');
        if (!$api_key) {
            $this->error('Missing API key for Fixer.io');

            return 0;
        }

        try {
            $response = Http::get('http://data.fixer.io/api/latest', ['access_key' => $api_key]);
        } catch (\Exception $exception) {
            $this->error($exception->getMessage());

            return;
        }
        if (true != $response['success']) {
            $this->error('Server reponses fail');

            return;
        }

        $rates = [];
        $base = $response['base'];
        $published_at = new DateTime("@{$response['timestamp']}");
        foreach ($response['rates'] as $currency => $rate) {
            $rates[] = [
                'from_currency' => $base,
                'to_currency' => $currency,
                'rate' => $rate,
                'provider' => 'fixerio',
                'published_at' => $published_at,
            ];
        }

        return $rates;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $provider = $this->option('provider');
        if (!$provider) {
            $provider = env('XR_DEFAULT_PROVIDER', ExchangeRateProviderEnum::SEEDER);
        }

        $rates = [];

        if (ExchangeRateProviderEnum::SEEDER == $provider) {
            Artisan::call('db:seed --class=ExchangeRatesSeeder');

            return 0;
        } elseif (ExchangeRateProviderEnum::APIFOREX == $provider) {
            $rates = $this->_readFromApiforex();
        } elseif (ExchangeRateProviderEnum::CURRENCYLAYER == $provider) {
            $rates = $this->_readFromCurrencylayer();
        } elseif (ExchangeRateProviderEnum::FIXERIO == $provider) {
            $rates = $this->_readFromFixerio();
        } else {
            $this->error("Unknown provider: {$provider}");
        }

        if (!$rates) {
            Log::error(sprintf('Failed to read rates from %s', $provider));

            return 0;
        }

        Log::info(sprintf('Read %d rates from %s', count($rates), $provider));

        $rebase = $this->option('rebase');
        if ($rebase) {
            $new_rates = $this->_rebase($rates, $rebase);
            $rates = $new_rates ?? $rates;
        }

        // foreach ($rates as $rate)
        //     $this->line(sprintf('%s %s %20.4f', $rate['from_currency'], $rate['to_currency'], $rate['rate']));

        $this->exchangeRateService->updateOrCreateRates($rates);

        return 0;
    }
}
