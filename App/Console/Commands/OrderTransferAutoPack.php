<?php

namespace App\Console\Commands;

use App\Jobs\OrderTransferPackJob;
use App\Repositories\OrderRepository;
use App\Services\CronJobService;
use Illuminate\Console\Command;

class OrderTransferAutoPack extends Command
{
    /**
     * 自動打包訂單.
     *
     * @var string
     */
    protected $signature = 'cron:order_transfer_auto_pack
                            {--is_test=: is_test}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'OrderTransfer auto pack.';

    /**
     * @var CronJobService
     */
    private $cronJobService;
    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(CronJobService $cronJobService, OrderRepository $orderRepository)
    {
        parent::__construct();
        $this->cronJobService = $cronJobService;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $cronJobs = $this->cronJobService->getOrderTransferPackCronJobs();

        $is_test = $this->option('is_test');
        $is_test = 'true' === $is_test;

        $cronJobs->each(function ($packCron) use ($is_test) {
            $application = $packCron->application;
            $pack_rules = $packCron->pack_rules;

            $this->cronJobService->doCron($packCron->cron, $packCron->application->timezone, function () use ($application, $pack_rules, $is_test) {
                $this->orderRepository->getOrdersBuilderByAllowTransfer($application->id, $application->timezone, $is_test)
                    ->chunk(500, function ($orders) use ($application, $pack_rules, $is_test) {
                        $orders->groupBy('vendor_id')->each(function ($orders_group_by_vendor_id) use ($application, $pack_rules, $is_test) {
                            $order = $orders_group_by_vendor_id->first();
                            $vendor = $order->vendor;
                            if (!empty($vendor)) {
                                OrderTransferPackJob::dispatch($application, $vendor, $pack_rules, $is_test)->onQueue('auto_pack');
                            }
                        });
                    });
            });
        });

        return 0;
    }
}
