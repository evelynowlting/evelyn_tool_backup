<?php

namespace App\Console\Commands;

use App\Services\WalletProCurrencyAccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RefreshWalletProTokens extends Command
{
    protected $signature = 'update:wallet_pro_tokens';
    protected $description = 'Update WalletPro tokens ';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        /** @var WalletProCurrencyAccountService $walletProCurrencyAccountService */
        $walletProCurrencyAccountService = app(WalletProCurrencyAccountService::class);
        $daysRemain = 7;

        $expiringAccounts = $walletProCurrencyAccountService->getExpiringTokens($daysRemain);

        $refreshSuccess = 0;
        $refreshFail = 0;
        foreach ($expiringAccounts as $account) {
            $response = $walletProCurrencyAccountService->refreshBearer($account['application_id'], $account['refresh_token']);
            if ($response['success']) {
                ++$refreshSuccess;
            } else {
                ++$refreshFail;
            }
        }
        Log::info(sprintf('[WalletPro] Tokens needed to refresh: %d, success: %d, fail: %d', count($expiringAccounts), $refreshSuccess, $refreshFail));
    }
}
