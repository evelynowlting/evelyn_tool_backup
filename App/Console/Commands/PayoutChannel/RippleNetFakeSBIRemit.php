<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\RippleNetStateEnum;
use App\Events\PayoutGatewayStatusUpdateEvent;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RippleNetFakeSBIRemit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rn-payout:fake-sbiremit {--mode=lock} {--amount=100000}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This robot acts as SBI Remit to lock payments from OwlPay';

    /**
     * Configurations.
     */
    protected $xc_intermediary_url = 'https://c7puaurctfcb.xctest.i.ripple.com';
    protected $xc_intermediary_user = 'payment_user_client';
    protected $xc_intermediary_user_secret = 'L57SH81vdZaBLIPfWiNNM8iTsPy9TDFd';
    protected $xc_sender_rn_address = 'test.twn.owlting';
    protected $xc_intermediary_rn_address = 'test.cloud.owltingtestpeer';
    protected $xc_alias_account = 'alias_jpy_sbiremit';
    protected $xc_connected_account = 'conct_jpy_owlting';

    protected $intermediary_token_file = 'intermediary_token.json';
    protected $my_timezone = 'Asia/Taipei';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    private function _getAuthToken($force = false)
    {
        if (file_exists($this->intermediary_token_file)) {
            $auth_token = json_decode(file_get_contents($this->intermediary_token_file));
            $auth_token->expires = Carbon::parse($auth_token->expires)->setTimezone($this->my_timezone);
        } else {
            $auth_token = null;
        }

        if (!$force and $auth_token and Carbon::now($this->my_timezone)->lessThan($auth_token->expires)) {
            $this->info('Reuse token available until '.$auth_token->expires->format('H:i:s'));

            return $auth_token->token;
        }

        try {
            $response = Http::withBasicAuth($this->xc_intermediary_user, $this->xc_intermediary_user_secret)
                ->asForm()
                ->post($this->xc_intermediary_url.'/oauth/token', ['grant_type' => 'client_credentials']);
            $status = $response->getStatusCode();
            if (200 != $status) {
                $this->error("Error requesting auth token: status=$status");
                throw new RequestException($response);
            }
        } catch (\Exception $exception) {
            $this->error('Error creating auth token: message='.$exception->getMessage());
            throw $exception;
        }

        $token = $response['access_token'];
        $expires = Carbon::now($this->my_timezone)->addSeconds($response['expires_in'] - 30);
        file_put_contents($this->intermediary_token_file, json_encode(['token' => $token, 'expires' => $expires]));

        $this->info('Get new token available until '.$expires->format('H:i:s'));

        return $token;
    }

    private function _getAcceptedPayments()
    {
        try {
            $response = Http::withToken($this->_getAuthToken())
                ->get($this->xc_intermediary_url.'/v4/payments', [
                    'sending_host' => $this->xc_sender_rn_address,
                    'connector_role' => 'INTERMEDIARY',
                    'states' => 'ACCEPTED',
                    'payment_type' => 'REGULAR',
                    'without_labels' => 'LOCK_PROCESSING',
                    'sort_field' => 'CREATED_AT',
                    'sort_direction' => 'ASC',
                    'size' => '100',
                    // 'page' => '0',
                ]);
            $status = $response->getStatusCode();
            if (200 != $status) {
                Log::error("Error getting payments: status=$status");
                throw new RequestException($response);
            }
        } catch (\Exception $exception) {
            $this->error('Error getting payments: message='.$exception->getMessage());
            throw $exception;
        }

        $accepted_payments = [];
        foreach ($response['content'] as $payment) {
            $error = false;
            foreach ($payment['user_info'] as $user_info) {
                $failed = $user_info['failed'] ?? null;
                $json = $failed[0]['json'] ?? null;
                $code = $json['code'] ?? null;
                if (!empty($code)) {
                    $error = true;
                    break;
                }
            }
            if ($error) {
                continue;
            }

            $accepted_payments[] = $payment['payment_id'];
        }

        return $accepted_payments;
    }

    private function _lockPayment($payment_id)
    {
        try {
            $response = Http::withToken($this->_getAuthToken())
                ->post($this->xc_intermediary_url.'/v4/payments/'.$payment_id.'/lock', ['internal_id' => '']);
            $status = $response->getStatusCode();
            if (200 != $status) {
                $this->error("Error locking payment: status=$status");
                throw new RequestException($response);
            }
        } catch (\Exception $exception) {
            $this->error('Error locking payment: message='.$exception->getMessage());
            throw $exception;
        }

        $this->info("Locked $payment_id");
    }

    private function _checkBalance()
    {
        try {
            $response = Http::withToken($this->_getAuthToken())
                ->get($this->xc_intermediary_url.'/v4/monitor/balances');

            $status = $response->getStatusCode();
            if (200 != $status) {
                $this->error("Error trasferring amount: status=$status");
                throw new RequestException($response);
            }
        } catch (\Exception $exception) {
            $this->error('Error trasferring amount: message='.$exception->getMessage());
            throw $exception;
        }

        $result = [];
        $currency_balances = $response['local_balance']['currency_balances'];
        foreach ($currency_balances as $currency_balance) {
            foreach ($currency_balance as $account_balances) {
                if (is_array($account_balances)) {
                    foreach ($account_balances as $balance) {
                        if ('conct_jpy_owlting' == $balance['account_name']) {
                            $result['conct_jpy_owlting'] = $balance['balance'];
                        }
                    }
                }
            }
        }

        $this->info('Balance: '.implode('', $result));

        return 0;
    }

    private function _prefund($amount)
    {
        try {
            $timestr = Carbon::now($this->my_timezone)->format('YmdHis');
            $response = Http::withOptions(['verify' => false])
                ->withToken($this->_getAuthToken())
                ->post($this->xc_intermediary_url.'/v4/transfers/execute', [
                    'sender_address' => "{$this->xc_alias_account}@{$this->xc_intermediary_rn_address}",
                    'receiver_address' => "{$this->xc_connected_account}@{$this->xc_intermediary_rn_address}",
                    'amount' => $amount,
                    'end_to_end_id' => "opp_{$timestr}",
                    'internal_id' => "opt_{$timestr}",
                ]);
            $status = $response->getStatusCode();
            if (200 != $status) {
                $this->error("Error trasferring amount: status=$status");
                throw new RequestException($response);
            }
        } catch (\Exception $exception) {
            $this->error('Error trasferring amount: message='.$exception->getMessage());
            throw $exception;
        }

        $this->info("Trasferred $amount");
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if ('lock' == $this->option('mode')) {
            $count = 0;
            $accepted_payments = $this->_getAcceptedPayments();

            if (count($accepted_payments) <= 0) {
                Log::info('No accepted payments');

                return 0;
            }

            foreach ($accepted_payments as $payment) {
                $this->_lockPayment($payment);
                // event(new PayoutGatewayStatusUpdateEvent($payment, RippleNetStateEnum::STATE_LOCKED));
                ++$count;
            }
            Log::info('# of Locked Payments: '.$count);
        } elseif ('prefund' == $this->option('mode')) {
            $this->_prefund($this->option('amount'));
        } elseif ('check_balance' == $this->option('mode')) {
            $balance = $this->_checkBalance();

            return $balance;
        }

        return 0;
    }
}
