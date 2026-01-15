<?php

namespace App\Console\Commands;

use App\Exceptions\HttpException\EmptyException;
use App\Services\OrderService;
use Illuminate\Console\Command;

class OrderClose extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order:close {order_uuid}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Close order, let order cannot pack to order_transfer';
    /**
     * @var OrderService
     */
    private $orderService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(OrderService $orderService)
    {
        parent::__construct();
        $this->orderService = $orderService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $order_uuid = $this->argument('order_uuid');

        $order = $this->orderService->getOrderByOrderUUID($order_uuid, true);

        if (empty($order)) {
            $order = $this->orderService->getOrderByOrderUUID($order_uuid, false);
        }

        throw_if(empty($order), new EmptyException('order not found'));

        $this->info('Order information');
        $this->info('------------');
        $this->info('application uuid: '.$order->application->uuid);
        $this->info('application name: '.$order->application->name);
        $this->info("uuid: $order->uuid");
        $this->info("current status: $order->status");
        $this->info("currency: $order->currency");
        $this->info('total: '.$order->total);
        $this->info('created at: '._convertISOTime($order->created_at));
        $this->info('updated at: '._convertISOTime($order->updated_at));
        $this->info('------------');

        if ($this->confirm('Is order correctly?')) {
            $this->orderService->updateOrderStatusClosed($order);
        }
    }
}
