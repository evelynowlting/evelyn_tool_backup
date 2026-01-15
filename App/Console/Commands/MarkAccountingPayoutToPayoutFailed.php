<?php

namespace App\Console\Commands;

use App\Console\Commands\PayoutChannel\CathayPayoutStaff;
use App\Enums\AccountingStatusEnum;
use App\Enums\PayoutChannel\CathayBankEnum;
use App\Models\Accounting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarkAccountingPayoutToPayoutFailed extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounting:failed_payout {accounting_uuid} {batch_no?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';
    /**
     * @var CathayPayoutStaff
     */
    private $cathayPayoutStaff;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(CathayPayoutStaff $cathayPayoutStaff)
    {
        parent::__construct();
        $this->cathayPayoutStaff = $cathayPayoutStaff;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $failed_order_transfers = collect([]);

        $accounting_uuid = $this->argument('accounting_uuid');

        $batch_no = $this->argument('batch_no');

        $accounting = Accounting::where('uuid', $accounting_uuid)->first();

        if (empty($accounting)) {
            $this->error('accounting not found');

            return 0;
        }

        $application = $accounting->application;

        if (!empty($batch_no)) {
            $failed_payouts = $accounting->payouts()->with('accounting_details.order_transfer')
                ->where('external_payment_uuid', $batch_no)
                ->get();
        } else {
            $failed_payouts = $accounting->payouts()->with('accounting_details.order_transfer')
                ->get();
        }

        if (0 == $failed_payouts->count()) {
            $this->error('payouts not found');

            return 0;
        }

        foreach ($failed_payouts as $failed_payout) {
            $accounting_details = $failed_payout->accounting_details;
            $order_transfers = $accounting_details->pluck('order_transfer');

            foreach ($order_transfers as $order_transfer) {
                $failed_order_transfers->push([
                    'reason_code' => 'OWLPAY:MARK_FAILED_COMMAND',
                    'reason_message' => CathayBankEnum::BUILD_FAILED,
                    'order_transfer' => $order_transfer,
                ]);
            }
        }

        $status = AccountingStatusEnum::STATUS_IN_FINISH;

        try {
            DB::beginTransaction();
            $this->cathayPayoutStaff->finishAccounting(
                $application,
                $accounting,
                collect([]),
                $failed_order_transfers,
                $status,
                []
            );
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error($e->getMessage());
        }
    }
}
