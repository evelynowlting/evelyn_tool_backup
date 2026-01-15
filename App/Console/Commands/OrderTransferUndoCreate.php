<?php

namespace App\Console\Commands;

use App\Cores\Platform\OrderTransferCore;
use App\Models\OrderTransfer;
use Illuminate\Console\Command;

class OrderTransferUndoCreate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order_transfer:undo_create {order_transfer_uuid}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove order transfer uuid';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        /** @var OrderTransferCore $orderTransferCore */
        $orderTransferCore = app(OrderTransferCore::class);

        $order_transfer_uuid = $this->argument('order_transfer_uuid');

        /** @var OrderTransfer $order_transfer */
        $order_transfer = OrderTransfer::where('uuid', $order_transfer_uuid)->first();

        if (empty($order_transfer)) {
            $this->info('order transfer not found');

            return false;
        }

        $this->info("Order Transfer uuid: $order_transfer_uuid");

        if ($this->confirm('Are you sure to delete the order transfer?')) {
            $deleted_success = $orderTransferCore->deleteOrderTransfer($order_transfer);

            if ($deleted_success) {
                $this->info('Success deleted');
            }
        }
    }
}
