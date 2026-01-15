<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class GenerateRole extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:roles {--application_id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make roles';

    public function handle()
    {
        $application_id = $this->option('application_id');

        // 取得 applications 資料.
        if (!empty($application_id)) {
            $applications = Application::where('id', $application_id)->get();
        } else {
            $applications = Application::get();
        }

        if ($applications->isEmpty()) {
            $this->info('application not found.');

            return false;
        }

        // 取得 config roles 資料.
        $config_roles = config('roles');

        // 取得原始新增資料.
        $original_data = $this->getOriginalData($applications, $config_roles);

        // 取得 roles 資料.
        $roles = Role::get(['name', 'guard_name', 'model_id', 'model_type'])
            ->toArray();

        // 取得新增資料.
        $insert_data = array_filter($original_data, function ($element) use ($roles) {
            return !in_array(Arr::except($element, ['created_at', 'updated_at']), $roles);
        });

        if (empty($insert_data)) {
            $this->info('insert data is empty.');

            return false;
        }

        // 新增 role.
        Role::insert($insert_data);

        $this->info('Roles created.');
    }

    private function getOriginalData($applications, $config_roles): array
    {
        $original_data = [];

        $applications->map(function ($application) use ($config_roles, &$original_data) {
            array_map(function ($names, $guard) use ($application, &$original_data) {
                array_map(function ($name) use ($application, $guard, &$original_data) {
                    $original_data[] = [
                        'name' => $name,
                        'guard_name' => $guard,
                        'model_id' => $application->id,
                        'model_type' => $application->getMorphClass(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $names);
            }, $config_roles, array_keys($config_roles));
        });

        return $original_data;
    }
}
