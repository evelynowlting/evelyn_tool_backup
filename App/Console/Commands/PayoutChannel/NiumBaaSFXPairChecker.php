<?php

namespace App\Console\Commands\PayoutChannel;

use App\Services\Payout\NiumBaaSPayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class NiumBaaSFXPairChecker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium-baas-fx-pair-check:util
                    {--mode=order_preview}
                    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nium BaaS tool for checking fx pair.';

    protected $pairsInput = [
        'PEN -> USD',
        'CLP -> USD',
        'USD -> PEN',
        'USD -> CLP',
    ];

    protected $list_main_currencies = [
        'SGD',
        'USD',
        'GBP',
        'EUR',
        'AUD',
        'HKD',
    ];

    protected $list_src_currencies = [
        'AED',
        'ARS',
        'AUD',
        'BDT',
        'BRL',
        'CAD',
        'CHF',
        'CLP',
        'CNY',
        'COP',
        'CRC',
        'DKK',
        'EUR',
        'GBP',
        'GHS',
        'HKD',
        'IDR',
        'ILS',
        'INR',
        'JPY',
        'KES',
        'KRW',
        'LKR',
        'MXN',
        'MYR',
        'NGN',
        'NPR',
        'NZD',
        'PEN',
        'PHP',
        'SAR',
        'SEK',
        'SGD',
        'THB',
        'TRY',
        'TWD',
        'TZS',
        'USD',
        'UYU',
        'VND',
        'ZAR',
    ];

    protected $list_dst_currencies = [
        // 41 currencies
        'AED',
        'ARS',
        'AUD',
        'BDT',
        'BRL',
        'CAD',
        'CHF',
        'CLP',
        'CNY',
        'COP',
        'CRC',
        'DKK',
        'EUR',
        'GBP',
        'GHS',
        'HKD',
        'IDR',
        'ILS',
        'INR',
        'JPY',
        'KES',
        'KRW',
        'LKR',
        'MXN',
        'MYR',
        'NGN',
        'NPR',
        'NZD',
        'PEN',
        'PHP',
        'SAR',
        'SEK',
        'SGD',
        'THB',
        'TRY',
        'TWD',
        'TZS',
        'USD',
        'UYU',
        'VND',
        'ZAR',
    ];

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        protected NiumBaaSPayoutService $niumBaaSPayoutService,
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
        $this->info('This tool runs ONLY on Nium BaaS Sandbox environment.');
        $mode = strtolower(trim($this->option('mode')));

        $modes = ['payout_preview', 'order_preview', 'main', 'get_exchange_rate'];
        if (!in_array($mode, $modes)) {
            $this->error('Please input correct mode.');
            $this->info('For payout_preview, please enter --mode=payout_preview');
            $this->info('For order_preview, please enter --mode=order_preview');
            $this->info('For main, please enter --mode=main');
            $this->info(string: 'For get exchange rate with nium and total rates, please enter --mode=get_exchange_rate');

            return;
        }
        $request_header = [];
        $request_header['x-api-key'] = config('payoutchannel.niumBaaS.api_key.SG');
        $request_header['x-client-name'] = 'OwlTing SG';
        $error_pair = [];

        // $this->list_src_currencies = ['LKR'];
        // $this->list_dst_currencies = ['KES'];
        if ('get_exchange_rate' == $mode) {
            $reutersRate = [
                'reuters' => [],
                'total' => [],
            ];

            foreach ($this->pairsInput as $pairs) {
                $paris = preg_match('PEN -> USD');
                $this->info("Checking exchange rate V2(reuters only) $src_currency".' -> '.$dst_currency);
                $url = "https://apisandbox.spend.nium.com/api/v2/exchangeRate?sourceCurrencyCode=$src_currency&destinationCurrencyCode=$dst_currency";
                $request = Http::/* dd()-> */ withHeaders($request_header)->timeout(10000);
                $response = $request->get($url, [
                    'sourceCurrency' => $src_currency,
                    'destinationCurrency' => $dst_currency,
                ]);
                $rst = $response->json();
                $status = $response->status();

                if (200 == $status) {
                    $reutersExchangeRate = $rst['exchangeRate'];
                    $reutersRate['reuters'] = [
                        $src_currency.' -> '.$dst_currency => $reutersExchangeRate,
                    ];
                    $this->info(" pairs $src_currency".' -> '.$dst_currency.' rate '.$reutersExchangeRate);
                }
                $this->info("Checking exchange rate V2(reuters only) $src_currency".' -> '.$dst_currency);
                $url = "https://apisandbox.spend.nium.com/api/v2/exchangeRate?sourceCurrencyCode=$src_currency&destinationCurrencyCode=$dst_currency";
                $request = Http::/* dd()-> */ withHeaders($request_header)->timeout(10000);
                $response = $request->get($url, [
                    'sourceCurrency' => $src_currency,
                    'destinationCurrency' => $dst_currency,
                ]);

                $rst = $response->json();
                $status = $response->status();
                if (200 == $status) {
                    $totalExchangeRate = $rst['fx_rate'];
                    $reutersRate['total'] = [
                        $src_currency.' -> '.$dst_currency => $totalExchangeRate,
                    ];
                    $this->info(" pairs $src_currency".' -> '.$dst_currency.' rate '.$totalExchangeRate);
                }
            }
        }

        if ('main' == $mode) {
            foreach ($this->list_src_currencies as $src_currency) {
                foreach ($this->list_dst_currencies as $dst_currency) {
                    $this->info("Checking $src_currency".' -> '.$dst_currency);
                    $url = 'https://apisandbox.spend.nium.com/api/v1/client/08eebb79-6c84-4af7-9416-7d415840d65d/customer/f6a10648-2a64-4ace-b949-beba8732a6e9/wallet/7a3d6192-1492-4bb2-97b3-ac82fddaa725/lockExchangeRate';
                    $request = Http::/* dd()-> */ withHeaders($request_header)->timeout(10000);
                    $response = $request->get($url, [
                        'sourceCurrency' => $src_currency,
                        'destinationCurrency' => $dst_currency,
                    ]);

                    $rst = $response->json();
                    $status = $response->status();

                    if (200 == $status) {
                        $this->info("No error for pair $src_currency".' -> '.$dst_currency);
                    } else {
                        $code = $rst['code'] ?? null;
                        $message = $rst['message'] ?? null;
                        echo $code.PHP_EOL;
                        // {
                        //     "status": "BAD_REQUEST",
                        //     "message": "Currency Pair not supported",
                        //     "code": "400",
                        //     "body": null
                        // }
                        $this->error("Error while selling $src_currency".' to buy '.$dst_currency.' with message '.$message);
                        $error_pair[$src_currency.' -> '.$dst_currency] = 'Status Code='.$code.' Message='.$message;
                    }
                }
            }

            if (!empty($error_pair)) {
                dd($error_pair);
            }
        }

        if ('payout_preview' == $mode) {
            foreach ($this->list_main_currencies as $src_currency) {
                foreach ($this->list_dst_currencies as $dst_currency) {
                    $this->info("Checking $src_currency".' -> '.$dst_currency);
                    $url = 'https://apisandbox.spend.nium.com/api/v1/client/08eebb79-6c84-4af7-9416-7d415840d65d/customer/f6a10648-2a64-4ace-b949-beba8732a6e9/wallet/7a3d6192-1492-4bb2-97b3-ac82fddaa725/lockExchangeRate';
                    $request = Http::/* dd()-> */ withHeaders($request_header)->timeout(10000);
                    $response = $request->get($url, [
                        'sourceCurrency' => $src_currency,
                        'destinationCurrency' => $dst_currency,
                    ]);

                    $rst = $response->json();
                    $status = $response->status();

                    if (200 == $status) {
                        $this->info("No error for pair $src_currency".' -> '.$dst_currency);
                    } else {
                        $code = $rst['code'] ?? null;
                        $message = $rst['message'] ?? null;
                        // {
                        //     "status": "BAD_REQUEST",
                        //     "message": "Currency Pair not supported",
                        //     "code": "400",
                        //     "body": null
                        // }
                        $this->error("Error while selling $src_currency".' to buy '.$dst_currency.' with message '.$message);
                        $error_pair[$src_currency.' -> '.$dst_currency] = 'Status Code='.$code.' Message='.$message;
                    }
                }
            }

            if (!empty($error_pair)) {
                dd($error_pair);
            }
        }

        if ('order_preview' == $mode) {
            foreach ($this->list_dst_currencies as $src_currency) {
                foreach ($this->list_main_currencies as $dst_currency) {
                    $this->info("Checking $src_currency".' -> '.$dst_currency);
                    $url = 'https://apisandbox.spend.nium.com/api/v1/client/08eebb79-6c84-4af7-9416-7d415840d65d/customer/f6a10648-2a64-4ace-b949-beba8732a6e9/wallet/7a3d6192-1492-4bb2-97b3-ac82fddaa725/lockExchangeRate';
                    $request = Http::/* dd()-> */ withHeaders($request_header)->timeout(10000);
                    $response = $request->get($url, [
                        'sourceCurrency' => $src_currency,
                        'destinationCurrency' => $dst_currency,
                    ]);

                    $rst = $response->json();
                    $status = $response->status();

                    if (200 == $status) {
                        $this->info("No error for pair $src_currency".' -> '.$dst_currency);
                    } else {
                        $code = $rst['code'] ?? null;
                        $message = $rst['message'] ?? null;
                        // {
                        //     "status": "BAD_REQUEST",
                        //     "message": "Currency Pair not supported",
                        //     "code": "400",
                        //     "body": null
                        // }

                        $this->error("Error while selling $src_currency".' to buy '.$dst_currency.' with message '.$message);
                        $error_pair[$src_currency.' -> '.$dst_currency] = 'Status Code='.$code.' Message='.$message;
                    }
                }
            }

            if (!empty($error_pair)) {
                dd($error_pair);
            }
        }
    }
}
