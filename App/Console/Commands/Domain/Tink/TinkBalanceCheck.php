<?php

namespace App\Console\Commands\Domain\Tink;

use App\Console\Commands\Domain\Tink\Concerns\ShowMessage;
use Illuminate\Console\Command;
use Infrastructure\Tink\Enums\OAuth\AuthorizationType;
use Infrastructure\Tink\TinkRawClient;
use Psr\Log\LogLevel;

class TinkBalanceCheck extends Command
{
    use ShowMessage;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tink:balance_check
                            {--refresh_balance}
                            {--status_check_interval=1}
                            {tink_user_id}
                            {account_id}
                            ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tink Balance Check utility';

    private const BALANCE_REFRESH_FINISHED = 'FINISHED';

    private TinkRawClient $tinkRawClient;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
    ) {
        parent::__construct();
        $this->tinkRawClient = new TinkRawClient(
            config('payoutchannel.tink.client_id', ''),
            config('payoutchannel.tink.client_secret', ''),
            config('payoutchannel.tink.url.api_base', ''),
            config('payoutchannel.tink.url.api_data_v2_base', ''),
        );
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $tink_user_id = $this->argument('tink_user_id');
        $account_id = $this->argument('account_id');
        $status_check_interval = max(1, intval($this->option('status_check_interval')));
        $do_refresh_balance = $this->option('refresh_balance');

        // Generate client token
        $client_token = $this->generateClientToken();

        // Generate balance check authorization code
        $balance_check_authorization_code = $this->generateBalanceCheckAuthorizationCode($client_token, $tink_user_id);

        // Generate balance check user token
        $balance_check_user_token = $this->generateBalanceCheckUserToken($balance_check_authorization_code);

        if ($do_refresh_balance) {
            $refresh_id = $this->refreshBalance($balance_check_user_token, $account_id);
            $this->showMessage(LogLevel::INFO, 'Refresh ID: '.$refresh_id);

            do {
                $balance_refresh_status = $this->getBalanceRefreshStatus($balance_check_user_token, $refresh_id);
                $this->showMessage(LogLevel::INFO, 'Balance refresh status: '.$balance_refresh_status);
                sleep($status_check_interval);
            } while (self::BALANCE_REFRESH_FINISHED != $balance_refresh_status);
        }

        // Get balance
        $balance = $this->getAccountBalance($balance_check_user_token, $account_id);

        $this->showMessage(LogLevel::INFO, print_r($balance->toArray(), true));
    }

    private function generateClientToken()
    {
        $res = $this->tinkRawClient->generateClientToken();

        return $res->access_token;
    }

    private function generateBalanceCheckAuthorizationCode(string $client_token, string $user_id)
    {
        $res = $this->tinkRawClient->generateAuthorizationCode(AuthorizationType::BALANCE_CHECK, $client_token, $user_id);

        return $res->code;
    }

    private function generateBalanceCheckUserToken(string $balance_check_authorization_code)
    {
        $res = $this->tinkRawClient->generateUserToken($balance_check_authorization_code);

        return $res->access_token;
    }

    private function refreshBalance(string $balance_check_user_token, string $account_id)
    {
        $res = $this->tinkRawClient->refreshBalance($balance_check_user_token, $account_id);

        return $res->balanceRefreshId;
    }

    private function getBalanceRefreshStatus(string $balance_check_user_token, string $refresh_id)
    {
        $res = $this->tinkRawClient->getBalanceRefreshStatus($balance_check_user_token, $refresh_id);

        return $res->status;
    }

    private function getAccountBalance(string $balance_check_user_token, string $account_id)
    {
        $res = $this->tinkRawClient->getAccountBalance($balance_check_user_token, $account_id);

        return $res;
    }
}
