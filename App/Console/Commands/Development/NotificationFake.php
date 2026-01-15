<?php

namespace App\Console\Commands\Development;

use App\Events\Agent\AgentCreatedEvent;
use App\Events\OrdersTransfer\OrderReconciliationsCreatedEvent;
use App\Events\OrdersTransfer\OrderReconciliationsRejectedEvent;
use App\Events\OrdersTransfer\OrderReconciliationsSettledEvent;
use App\Events\Platform\ApplicationKycApprovedEvent;
use App\Events\Platform\ApplicationKycRejectEvent;
use App\Events\Vendor\VendorKycApprovedEvent;
use App\Events\Vendor\VendorKycRejectEvent;
use App\Models\Application;
use App\Models\Platform;
use App\Services\ApplicationService;
use App\Services\OrderService;
use App\Services\OrderTransferService;
use App\Services\VendorService;
use Illuminate\Console\Command;

class NotificationFake extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:notification {application_uuid} {platform_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'generate fake notifications';
    /**
     * @var VendorService
     */
    private $vendorService;
    /**
     * @var ApplicationService
     */
    private $applicationService;
    /**
     * @var OrderTransferService
     */
    private $orderTransferService;
    /**
     * @var OrderService
     */
    private $orderService;

    /**
     * Create a new command instance.
     */
    public function __construct(ApplicationService $applicationService)
    {
        parent::__construct();
        $this->applicationService = $applicationService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $application_uuid = $this->argument('application_uuid');

        $platform_id = $this->argument('platform_id');

        $application = Application::where('uuid', $application_uuid)->first();

        $platform = Platform::find($platform_id);
        $agent = $platform;
        $role = $application->roles()->first();
        $order_transfer = $application->order_transfers()->has('orders.vendor.vendor_user')->get();
        $order = $order_transfer->orders->first();
        $vendor = $application->vendors()->has('vendor_user')->first();

        event(new OrderReconciliationsCreatedEvent($application, collect([$order_transfer])));
        event(new OrderReconciliationsSettledEvent($application, collect([$order_transfer])));
        event(new OrderReconciliationsRejectedEvent($application, $order_transfer, $order));
        event(new AgentCreatedEvent($agent, $application, $role));

        event(new ApplicationKycApprovedEvent($platform, $application));
        event(new ApplicationKycRejectEvent($platform, $application));
        event(new VendorKycApprovedEvent($application, $vendor));
        event(new VendorKycRejectEvent($application, $vendor));

        $this->info('ok');

        return 0;
    }
}
