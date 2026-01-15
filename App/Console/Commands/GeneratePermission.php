<?php

namespace App\Console\Commands;

use App\Enums\GuardOwltingEnum;
use App\Enums\GuardPlatformEnum;
use App\Enums\GuardVendorEnum;
use App\Models\Permission;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

class GeneratePermission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:permissions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make permissions';

    public function handle()
    {
        // 取得原始新增資料.
        $original_data = $this->getOriginalData();

        // 取得 permissions 資料.
        $permissions = Permission::get(['name', 'guard_name'])
            ->toArray();

        // 取得新增資料.
        $insert_data = array_filter($original_data, function ($element) use ($permissions) {
            return !in_array(Arr::except($element, ['created_at', 'updated_at']), $permissions);
        });

        if (empty($insert_data)) {
            $this->info('insert data is empty.');

            return false;
        }

        // 新增 permission.
        Permission::insert($insert_data);

        $this->info('Permissions created.');
    }

    private function getOriginalData(): array
    {
        $guards = [
            GuardOwltingEnum::GUARD_OWLTING,
            GuardPlatformEnum::GUARD_PLATFORM,
            GuardVendorEnum::GUARD_VENDOR_USER,
        ];

        $original_data = array_map(function ($guard) {
            return array_map(function ($permission) use ($guard) {
                return [
                    'name' => $permission,
                    'guard_name' => $guard,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }, config('role_permission.permissions'));
        }, $guards);

        return Arr::flatten($original_data, 1);
    }
}
