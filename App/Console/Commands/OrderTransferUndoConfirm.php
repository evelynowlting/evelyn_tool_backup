<?php

namespace App\Console\Commands;

use App\Enums\GuardPlatformEnum;
use App\Enums\GuardVendorEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\OrderTransferDetailStatusEnum;
use App\Enums\OrderTransferStatusEnum;
use App\Models\Order;
use App\Models\OrderTransfer;
use App\Repositories\OrderTransferRepository;
use App\Services\OrderReconciliationService;
use App\Services\OrderService;
use App\Services\OrderTransferService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderTransferUndoConfirm extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'order_transfer:undo_confirm
                           {uuid? : orderTransfer UUID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'undo confirm Order Transfer';
    /**
     * @var OrderTransferRepository
     */
    private $orderTransferRepository;
    /**
     * @var OrderTransferService
     */
    private $orderTransferService;
    /**
     * @var OrderReconciliationService
     */
    private $orderReconciliationService;
    /**
     * @var OrderService
     */
    private $orderService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        OrderTransferRepository $orderTransferRepository,
        OrderTransferService $orderTransferService,
        OrderReconciliationService $orderReconciliationService,
        OrderService $orderService,
    ) {
        parent::__construct();
        $this->orderTransferRepository = $orderTransferRepository;
        $this->orderTransferService = $orderTransferService;
        $this->orderReconciliationService = $orderReconciliationService;
        $this->orderService = $orderService;
    }

    /**
     * 1. Order.staatus 根據發起人變更 ok
     * 2. OrderTransfer.total 重新進行計算 ok
     * 3. OrderTransfer.status STATUS_SETTLED = 'unconfirm' ok
     * 4. OrderTransferDetail.is_draft = true ok
     * 5. 當 OrderTransfer.status = settled 需更新雙方 Balance
     *   5-1. Application Balance -> 為正的 等待付款的金額
     *   5-2. Vendor Balance -> 為負的 等待被付款的金額.
     *
     * @return int
     */
    public function handle()
    {
        $uuid = $this->argument('uuid');

        if (empty($uuid)) {
            $uuid = $this->ask('OrderTransfer uuid?');
        }

        /** @var \App\Models\OrderTransfer $order_transfer */
        $order_transfer = $this->orderTransferRepository->firstBy('uuid', $uuid);

        if (empty($order_transfer)) {
            $this->error('OrderTransfer not found!');

            return 1;
        }

        $this->table([
            'id',
            'uuid',
            'total',
            'currency',
            'status',
            'created_at',
        ], [
            [
                $order_transfer->id,
                $order_transfer->uuid,
                $order_transfer->total,
                $order_transfer->currency,
                $order_transfer->status,
                $order_transfer->created_at->format('c'),
            ],
        ]);

        $is_it = $this->confirm('Is the OrderTransfer?', false);

        if (!$is_it) {
            $this->info('bye');

            return 2;
        }

        if (!in_array($order_transfer->status, [
            OrderTransferStatusEnum::STATUS_SETTLED,
            OrderTransferStatusEnum::STATUS_IN_PROCESS,
        ])) {
            $this->error('Only settled, in_process can revert');

            return 3;
        }

        $original_status = $order_transfer->status;
        $original_detail_status = $order_transfer->detail_list->pluck('status', 'id');

        // 判斷 guard
        $guard = $order_transfer->apply_type;

        throw_unless(
            GuardPlatformEnum::isValid($guard) || GuardVendorEnum::isValid($guard),
            new \Exception('unknown guard')
        );

        DB::beginTransaction();
        /** @var Order $orders */
        $orders = $order_transfer->orders()->lockForUpdate()->get();
        $detail_list = $order_transfer->detail_list()->lockForUpdate()->get();

        $this->orderService->updateBatchBy(
            'id',
            $orders->pluck('id')->toArray(),
            [
                'status' => OrderStatusEnum::ORDER_UNCONFIRMED,
            ]
        );

        $this->orderReconciliationService->updateOrderTransferStatusToUnConfirm($order_transfer);

        $this->orderReconciliationService->updateOrderTransferDetailStatus(
            $orders,
            $detail_list,
            null,
            OrderTransferDetailStatusEnum::STATUS_CHECKING
        );

        $this->orderReconciliationService->updateOrderTransferDetailStatusChecking($order_transfer);

        DB::commit();

        $order_transfer->refresh();

        $detail_log = $order_transfer->detail_list->map(function ($detail) use ($original_detail_status) {
            return [
                'id' => $detail->id,
                'original_status' => $original_detail_status[$detail->id] ?? null,
                'after_status' => $detail->status,
            ];
        })->toArray();

        Log::info('[CMD] order_transfer:undo_confirm', [
            'order_transfer_uuid' => $uuid,
            'original_status' => $original_status,
            'after_status' => $order_transfer->status,
            'detail' => $detail_log,
        ]);

        return 0;
    }
}
