<?php

namespace App\Console\Commands;

use App\Services\BaseInformationService;
use App\Services\VendorService;
use Illuminate\Console\Command;

class SyncVendorBaseInformationStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:vendor_base_information_status_check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync all formal vendors base information status';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(private VendorService $vendorService)
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
        /** @var BaseInformationService */
        $baseInformationService = app(BaseInformationService::class);

        $vendors = $this->vendorService->getVendorsByIsTest(isTest: false);

        $count = $vendors->count();

        foreach ($vendors as $key => $vendor) {
            $index = ++$key;
            echo "[$index/$count] sync vendor base information status $vendor->name (id: $vendor->id) \n";
            $baseInformationService->syncBaseInformationStatusByVendorBaseInformationList($vendor);
        }
    }
}
