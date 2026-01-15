<?php

namespace App\Console\Commands;

use App\Enums\AccountingStatusEnum;
use App\Enums\OrderTransferDetailStatusEnum;
use App\Models\Accounting;
use App\Models\Order;
use Illuminate\Console\Command;
use Mavinoo\Batch\BatchFacade as Batch;

class UpdatePayoutIdToOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:payout_id_to_orders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update payout_id to order ';

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
        $need_update_payouts = Accounting::leftJoin('payouts_has_accounting_details as phad', 'phad.accounting_id', '=', 'accounting.id')
            ->leftJoin('accounting_details as ad', 'ad.id', '=', 'phad.accounting_detail_id')
            ->leftJoin('orders_transfer_detail as otd', 'otd.order_transfer_id', '=', 'ad.order_transfer_id')
            ->whereIn('accounting.status', [
                AccountingStatusEnum::STATUS_WAIT_EXECUTE,
                AccountingStatusEnum::STATUS_SCHEDULED,
                AccountingStatusEnum::STATUS_IN_PROCESS,
                AccountingStatusEnum::STATUS_IN_FINISH,
            ])
            ->where('otd.status', OrderTransferDetailStatusEnum::STATUS_APPROVE)
            ->select([
                'otd.order_id as order_id',
                'phad.payout_id as payout_id',
                'otd.order_transfer_id as order_transfer_id',
                'accounting.id as accounting_id',
            ])
            ->get();

        $orders = Order::whereNotNull('accounting_id')
            ->whereNull('payout_id')
            ->get();

        $order_update_data = [];

        foreach ($orders as $order) {
            foreach ($need_update_payouts as $need_update_payout) {
                if ($order->order_transfer_id == $need_update_payout->order_transfer_id &&
                    $order->accounting_id == $need_update_payout->accounting_id) {
                    $order_update_data[$need_update_payout->order_id] = [
                        'id' => $need_update_payout->order_id,
                        'payout_id' => $need_update_payout->payout_id,
                    ];
                }
            }
        }

        if (!empty($order_update_data)) {
            $order_updated = Batch::update(new Order(), $order_update_data, 'id');

            _owlPayLog('payout_id_to_orders', [
                'order_updated' => $order_updated,
            ], 'system');

            $this->info('orders payout_id updated.');
        }

        $this->info('success.');

        return 0;
    }
}
