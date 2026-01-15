<?php

namespace App\Console\Commands;

use App\Enums\GuardAdminEnum;
use App\Models\Role;
use Illuminate\Console\Command;

class GenerateAdminRole extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:admin_roles';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Make roles(Admin)';

    public function handle()
    {
        // 取得 config roles 資料.
        $config_roles = config('admin_roles.admin');

        foreach ($config_roles as $config_role) {
            Role::firstOrCreate([
                'name' => $config_role,
                'guard_name' => GuardAdminEnum::GUARD_ADMIN,
            ],
            [
                'name' => $config_role,
                'guard_name' => GuardAdminEnum::GUARD_ADMIN,
                'model_id' => null,
                'model_type' => null,
            ]);
        }

        $this->info('Admin Roles created.');
    }
}
