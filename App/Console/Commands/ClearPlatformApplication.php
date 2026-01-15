<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Services\ApplicationService;
use App\Services\PlatformService;
use Illuminate\Console\Command;

class ClearPlatformApplication extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clear:platform_application';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';
    /**
     * @var ApplicationService
     */
    private $applicationService;
    /**
     * @var PlatformService
     */
    private $platformService;

    /**
     * Create a new command instance.
     */
    public function __construct(ApplicationService $applicationService,
        PlatformService $platformService)
    {
        parent::__construct();

        $this->applicationService = $applicationService;
        $this->platformService = $platformService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $application = $this->askApplication();

        $platform = $this->askPlatform($application);

        $this->applicationService->destroyAgent($application, $platform);

        $this->info("[Success] $platform->email on $application->name ($application->id) agents deleted");

//        $application->order_transfers()->get()->each(function ($order_transfer) {
//            $order_transfer->detail_list()->forceDelete();
//            $order_transfer->accounting_logs()->forceDelete();
//        });
//        $application->accountings()->get()->each(function ($accounting) {
//            $accounting->logs()->forceDelete();
//        });
//        $application->order_transfers()->forceDelete();
//        $application->accountings()->forceDelete();
//        $application->orders()->forceDelete();
//        $application->vendors()->forceDelete();
//        $application->balances()->forceDelete();
    }

    /**
     * @return |null
     */
    private function askApplication()
    {
        $application_options = collect();
        $select_check = false;
        $application = null;

        while (0 == $application_options->count()) {
            $application_name_or_uuid = $this->ask('what your application name or uuid?');

            $application_options = $this->applicationService
                ->getApplicationsByNameOrUUID($application_name_or_uuid);
        }

        while (null == $application && false == $select_check) {
            if ($application_options->count() > 1) {
                $options = $application_options->map(function ($application) {
                    return "$application->name ($application->uuid)";
                })->toArray();

                $choice = $this->choice('What your application?', $options);

                $application = $application_options[array_search($choice, $options)] ?? null;
            } else {
                $application = $application_options->first();
            }

            $this->table(
                ['id', 'uuid', 'name'],
                [[$application->id, $application->uuid, $application->name]]
            );

            $select_check = $this->confirm('Is application select correctly?');
        }

        if (!empty($application)) {
            return $application;
        }

        return null;
    }

    private function askPlatform(Application $application)
    {
//            $platform_options = collect();
        $platform = null;
        $select_check = false;

        $platform_options = $application->platforms;

//        while($platform_options->count() == 0) {
//            $platform_uuid_or_email_or_name = $this->ask('what your platform account uuid or email or name?');
//
//            $platform_options = $this->platformService
//                ->getPlatformByUUIDOrEmailOrName($platform_uuid_or_email_or_name);
//        }

        while (null == $platform && false == $select_check) {
            if ($platform_options->count() > 1) {
                $options = $platform_options->map(function ($platform) {
                    return "$platform->name ($platform->email)";
                })->toArray();

                $choice = $this->choice('Which account do you want to delete?', $options);

                $platform = $platform_options[array_search($choice, $options)] ?? null;
            } else {
                $platform = $platform_options->first();
            }

            $this->table(
                ['id', 'owlting_uuid', 'owlting_id', 'name', 'email'],
                [[$platform->id, $platform->owlting_uuid, $platform->owlting_id, $platform->name, $platform->email]]
            );

            $select_check = $this->confirm('Is platform account select correctly?');
        }

        if (!empty($platform)) {
            return $platform;
        }

        return null;
    }
}
