<?php

namespace App\Console\Commands\PayoutChannel;

use App\Events\Payout\AccountingPayoutFailedEvent;
use App\Events\Vendor\VendorVisaCardExpiredEvent;
use App\Services\AccountingService;
use App\Services\Payout\Visa\VisaVirtualAccountService;
use App\Services\PayoutService;
use DateTime;
use Illuminate\Console\Command;

class VisaVpaPaymentExpireDetector extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visa:vpa_payment_detector';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect VPA payment expire, then make status to fail';

    private $visa_virtual_account_service;

    private const EXPIRE_DAYS = 2;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        VisaVirtualAccountService $visa_virtual_account_service,
        AccountingService $accounting_service,
        PayoutService $payout_service,
    ) {
        parent::__construct();
        $this->visa_virtual_account_service = $visa_virtual_account_service;
        $this->accounting_service = $accounting_service;
        $this->payout_service = $payout_service;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Retrive expire day card
        $date = new DateTime('today');
        $minus_days = sprintf('-%d days', self::EXPIRE_DAYS);
        $date->modify($minus_days);
        $expire_date = $date->format('Y-m-d');
        $error_message = '[VISA VPA] Virtual account is expired!';
        $virtual_accounts = $this->visa_virtual_account_service->getUncheckReconciliationVirtualAccount($expire_date);

        foreach ($virtual_accounts as $virtual_account) {
            $application = $virtual_account->application;

            if (empty($application)) {
                $this->error('[VISA VPA] virtual_account application not found, virtual_account_id:'.$virtual_account->id);
                continue;
            }

            $accounting = $virtual_account->payout->accounting;
            $payout = $virtual_account->payout;

            event(
                new AccountingPayoutFailedEvent(
                    $application,
                    $accounting,
                    $payout,
                    $error_message
                ));

            VendorVisaCardExpiredEvent::dispatch($payout, $virtual_account);
        }

        return 0;
    }
}
