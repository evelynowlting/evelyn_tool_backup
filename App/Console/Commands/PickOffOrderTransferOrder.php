<?php

namespace App\Console\Commands;

use App\Enums\OrderStatusEnum;
use App\Enums\OrderTransferStatusEnum;
use App\Models\Order;
use App\Models\OrderTransfer;
use App\Services\OrderReconciliationService;
use Illuminate\Console\Command;

class PickOffOrderTransferOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order_transfer:pick_off_order {order_transfer_uuid} {order_uuid}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pick off order from order transfer and update order status to '.OrderStatusEnum::PLATFORM_APPLICATION_CANCELLED;

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
        $order_transfer_uuid = $this->argument('order_transfer_uuid');

        $order_uuid = $this->argument('order_uuid');

        $order_transfer = OrderTransfer::where('uuid', $order_transfer_uuid)->first();
        if (empty($order_transfer)) {
            $this->error('The order_transfer not found');

            return 0;
        }

        $order = Order::where('uuid', $order_uuid)->first();

        if (empty($order)) {
            $this->error('Order not found');

            return 0;
        }

        if (!in_array($order_transfer->status, [OrderTransferStatusEnum::STATUS_IN_PROCESS, OrderTransferStatusEnum::STATUS_UNCONFIRM])) {
            $this->error('This command only work on order transfer status is in_process or unconfirm.');
        }

        $order_transfer_detail = $order_transfer->detail_list()->where('order_id', $order->id)->first();

        if (!empty($order_transfer_detail)) {
            $order_transfer_detail->delete();
        }

        $total = $order_transfer->orders()->sum('total');
        /** @var OrderReconciliationService $orderReconciliationService */
        $orderReconciliationService = app(OrderReconciliationService::class);
        $orderReconciliationService->updateOrderTransferTotal($order_transfer, $total);
        $order->status = \App\Enums\OrderStatusEnum::PLATFORM_APPLICATION_CANCELLED;
        $order->save();
    }
}
