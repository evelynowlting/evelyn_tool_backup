<?php

namespace App\Console\Commands;

use App\Enums\BaseInformationTypeEnum;
use App\Enums\PayoutChannel\CrossBorderPayoutEnum;
use App\Enums\PayoutChannel\DomesticPayoutEnum;
use App\Models\Application;
use App\Models\PayoutGateway;
use App\Models\Vendor;
use App\Services\VendorPayoutGatewayService;
use Illuminate\Console\Command;

class SyncVendorPayoutGateway extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:vendor_payout_gateways {vendor_uuid?} {--payout_gateway=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync vendor payout gateway';

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
        $vendorUuid = $this->argument('vendor_uuid');
        $payoutGateway = $this->option('payout_gateway');
        $payoutGatewayList = array_merge(DomesticPayoutEnum::toArray(), CrossBorderPayoutEnum::toArray());

        if (!is_null($payoutGateway) && !in_array($payoutGateway, $payoutGatewayList)) {
            $this->info("payout gateway - $payoutGateway not supported. please refer DomesticPayoutEnum & CrossBorderPayoutEnum");

            return;
        }

        /** @var VendorPayoutGatewayService $vendorPayoutGatewayService */
        $vendorPayoutGatewayService = app(VendorPayoutGatewayService::class);

        if (!empty($payoutGateway)) {
            $installedApplicationIds = PayoutGateway::where([
                'gateway' => $payoutGateway,
              ])
                ->select(['application_id'])
                ->groupBy('application_id')
                ->get()
                ->pluck('application_id')
                ->toArray();

            $applications = Application::orderBy('id', 'desc')->whereIn('id', $installedApplicationIds)->get();
        } else {
            $applications = Application::orderBy('id', 'desc')->get();
        }

        $applicationCount = $applications->count();
        $applicationIndex = 0;
        foreach ($applications as $applicationKey => $application) {
            ++$applicationIndex;
            $this->info('----');
            $this->info("application count: [$applicationIndex/$applicationCount]");

            $vendors = Vendor::leftJoin('base_information', function ($join) use ($payoutGateway) {
                $join->on('base_information.model_id', '=', 'vendors.id')
                        ->where('base_information.model_type', (new Vendor())->getMorphClass())
                        ->where('base_information.type', BaseInformationTypeEnum::REMIT_INFO)
                        ->when(!is_null($payoutGateway), function ($query) use ($payoutGateway) {
                            $query->where('base_information.payout_gateway', $payoutGateway);
                        });
            })
                ->where('application_id', $application->id)
                ->whereNotNull('base_information.currency')
                ->whereNotNull('vendors.application_id')
                ->with(['application'])
                ->where('is_test', false)
                ->select('vendors.*')
                ->when(!empty($vendorUuid), function ($query) use ($vendorUuid) {
                    $query->where('vendors.uuid', $vendorUuid);
                })
                ->get();

            $vendorCount = $vendors->count();
            $vendorIndex = 0;

            foreach ($vendors as $vendorKey => $vendor) {
                ++$vendorIndex;
                $application = $vendor->application;
                $this->info("vendor count: [$applicationIndex/$applicationCount][$vendorIndex/$vendorCount] Sync vendor Payout Gateway");

                if (empty($application)) {
                    $this->info("[$applicationIndex/$applicationCount][$vendorIndex/$vendorCount] Sync Finished");
                    continue;
                }

                if (is_null($payoutGateway)) {
                    $vendorPayoutGatewayService->syncModelVendorPayoutGatewaysByVendor($application, $vendor);
                } else {
                    $vendorPayoutGatewayService->syncModelVendorPayoutGatewaysByVendorPayoutGateway($application, $vendor, $payoutGateway);
                }

                $this->info("vendor count: [$applicationIndex/$applicationCount][$vendorIndex/$vendorCount] Sync Finished");
            }
        }
    }
}
