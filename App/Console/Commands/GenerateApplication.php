<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Services\ApplicationService;
use Illuminate\Console\Command;

class GenerateApplication extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:application';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make Application by Platform';
    /**
     * @var ApplicationService
     */
    private $applicationService;

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
        $is_platform_set = false;
        $platform = null;

        while (!$is_platform_set) {
            $platform_id = $this->ask('Platform id?');

            $platform = Platform::find($platform_id);

            if (!empty($platform)) {
                $this->showPlatformInfo($platform);

                $is_platform_set = $this->confirm('is platform set correctly?');
            }
        }

        $is_create_application = $this->confirm('do you want to create Application?');

        $is_set_application_correctly = false;

        if ($is_create_application && !empty($platform)) {
            while (!$is_set_application_correctly) {
                $application_name = $this->ask('Application Name?');

                $this->info("Platform ID: $platform->id");
                $this->info("Platform Name: $platform->name");
                $this->info("Application Name: $application_name");

                $is_set_application_correctly = $this->confirm('is set Application correctly?');
            }

            if (!empty($application_name)) {
                $application = $this->applicationService->createApplicationByPlatform($platform, [
                    'name' => $application_name,
                ]);

                $this->info('Platform Application created!');

                $this->showApplicationInfo($platform, $application);
            }
        }
    }

    private function showPlatformInfo($platform)
    {
        $this->info('---------------');
        $this->info("Platform ID: $platform->id");
        $this->info("Platform Name: $platform->name");
    }

    private function showApplicationInfo($platform, $application)
    {
        $this->info("Platform ID: $platform->id");
        $this->info("Platform Name: $platform->name");
        $this->info("Application Name: $application->name");
    }
}
