<?php

namespace App\Console\Commands;

use App\Enums\BaseInformationStatusEnum;
use App\Enums\VendorBaseInformationTypeEnum;
use App\Models\Vendor;
use App\Services\BaseInformationService;
use Illuminate\Console\Command;

class VendorKYCCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kyc:vendor
                            {--type=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'check Application kyc data';
    /**
     * @var BaseInformationService
     */
    private $baseInformationService;

    /**
     * Create a new command instance.
     */
    public function __construct(BaseInformationService $baseInformationService)
    {
        parent::__construct();
        $this->baseInformationService = $baseInformationService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $type = $this->option('type');

        if (!in_array($type, VendorBaseInformationTypeEnum::toArray(), true)) {
            $this->error("unknown BaseInformation type: {$type}");

            return 0;
        }

        $baseInformations = $this->baseInformationService->getUnCheckBaseInformations((new Vendor())->getMorphClass(), $type);

        $count = $baseInformations->count();

        $this->info("has $count uncheck.");

        foreach ($baseInformations as $baseInformation) {
            $result = $this->baseInformationService->getCollapseBaseInformationByBaseInformationSubject(
                $baseInformation->model,
                $type
            );

            $headers = array_keys($result);
            $data = array_values($result);

            $this->table($headers, [$data]);
            $status_arr = BaseInformationStatusEnum::toArray();

            $status = $this->choice('是否變更狀態?', array_keys($status_arr), BaseInformationStatusEnum::CHECKING);
            $status = $status_arr[$status];

            $this->baseInformationService->updateBaseInformation($baseInformation, compact('status'));
        }

        return 0;
    }
}
