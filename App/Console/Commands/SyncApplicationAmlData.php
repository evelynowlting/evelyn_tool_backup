<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\BaseInformation;
use App\Services\InternalService;
use Illuminate\Console\Command;

class SyncApplicationAmlData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:application_aml_data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync application aml data';

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
        /** @var InternalService $internalService */
        $internalService = app(InternalService::class);

        $baseInformationList = BaseInformation::where('model_type', (new Application())->getMorphClass())->whereNotNull('aml_uuid')->get();

        $applicationIds = $baseInformationList->pluck('model_id')->unique()->toArray();

        $applications = Application::whereIn('id', $applicationIds)->get();

        $total = $applications->count();
        foreach ($applications as $key => $application) {
            $internalService->syncAmlByApplication($application);

            $progress_count = ++$key;

            $this->info("[$progress_count/$total][Sync AML Data] $application->name (ID: $application->id) sync success");
        }
    }
}
