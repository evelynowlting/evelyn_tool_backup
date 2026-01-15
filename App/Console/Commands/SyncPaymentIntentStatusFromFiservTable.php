<?php

namespace App\Console\Commands;

use App\Enums\PaymentIntent\BankStatusEnum;
use App\Events\PaymentIntent\PaymentIntentBankStatusUpdatedEvent;
use App\Services\FiservService;
use App\Services\PaymentIntentService;
use App\Services\PayNow\PaymentIntentService as PayNowPaymentIntentService;
use Illuminate\Console\Command;

class SyncPaymentIntentStatusFromFiservTable extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment_intent:sync_status_from_fiserv_table';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Payment Intent status from Fiserv table to Payment Intent table';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private PaymentIntentService $paymentIntentService,
        private PayNowPaymentIntentService $payNowPaymentIntentService,
        private FiservService $fiservService
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
        $paymentIntents = $this->paymentIntentService->getPaymentIntentsByBankStatus(BankStatusEnum::BANK_IN_PROCESS);

        foreach ($paymentIntents as $paymentIntent) {
            $this->info('[SyncPaymentIntentStatusFromFiservTable] Checking PaymentIntent: '.$paymentIntent->uuid.', Captured Date: '.$paymentIntent->captured_at);

            $reconciliationId = $this->payNowPaymentIntentService->convertUuidToReconciliationId($paymentIntent->id);

            $fiservTransaction = $this->fiservService->getFiservTransactionByUuid($reconciliationId);

            if (!empty($fiservTransaction)) {
                $this->info('[SyncPaymentIntentStatusFromFiservTable] Fiserv Transaction id: '.$fiservTransaction->id);

                if ('Cleared' == $fiservTransaction->TRAN_STAT) {
                    $this->paymentIntentService->updatePaymentIntentBankStatusByFiservTransaction($paymentIntent, $fiservTransaction);

                    $application = $paymentIntent->application;

                    event(new PaymentIntentBankStatusUpdatedEvent($application, $paymentIntent));
                }
            } else {
                $this->info('[SyncPaymentIntentStatusFromFiservTable] Fiserv Transaction not found in PaymentIntent: '.$paymentIntent->uuid.', Captured Date: '.$paymentIntent->captured_at);
            }
        }

        return 0;
    }
}
