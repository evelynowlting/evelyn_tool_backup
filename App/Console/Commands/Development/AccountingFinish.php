<?php

namespace App\Console\Commands\Development;

use App\Enums\AccountingStatusEnum;
use App\Events\Payout\CathayAccountingPayoutSucceedEvent;
use App\Models\Accounting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AccountingFinish extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounting:finish {accounting_uuid} {--failed_order_transfer_uuids=} {--reason_code=} {--reason_message=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

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
        $accounting_uuid = $this->argument('accounting_uuid');

        $failed_order_transfer_uuids = $this->option('failed_order_transfer_uuids');

        $reason_code = $this->option('reason_code');

        $reason_message = $this->option('reason_message');

        if (!empty($failed_order_transfer_uuids) && (empty($reason_code) || empty($reason_message))) {
            $this->error('Please input reason_code & reason_message when you input failed_order_transfer_uuids (you can input any words).');

            return 0;
        }

        $failed_order_transfer_uuids = array_filter(explode(',', $failed_order_transfer_uuids));

        DB::beginTransaction();

        $accounting = Accounting::where('status', AccountingStatusEnum::STATUS_IN_PROCESS)
            ->where('uuid', $accounting_uuid)
            ->lockForUpdate()
            ->first();

        if (empty($accounting)) {
            $this->error('[ERROR] accounting not found.');

            return 0;
        }

        $application = $accounting->application;

        $failed_order_transfers = $accounting
            ->order_transfers()
            ->whereIn('orders_transfer.uuid', $failed_order_transfer_uuids)
            ->get();

        if ($failed_order_transfers->count() != count($failed_order_transfer_uuids)) {
            $this->error('[ERROR] failed_orders_transfer error.');
        }

        $failed_order_transfers_collection = $failed_order_transfers->map(function ($failed_order_transfer) use ($reason_code, $reason_message) {
            return [
                'reason_code' => $reason_code,
                'reason_message' => $reason_message,
                'order_transfer' => $failed_order_transfer,
            ];
        });

        $accounting->update([
            'status' => AccountingStatusEnum::STATUS_IN_FINISH,
        ]);

        event(
            new CathayAccountingPayoutSucceedEvent(
                $application,
                $accounting,
                [],
                $failed_order_transfers_collection
            ));

        DB::commit();

        return 0;
    }
}
