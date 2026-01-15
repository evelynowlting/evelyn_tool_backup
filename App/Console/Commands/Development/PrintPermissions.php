<?php

namespace App\Console\Commands\Development;

use App\Enums\GuardPlatformEnum;
use App\Enums\GuardVendorEnum;
use App\Enums\PlatformRolesEnum;
use Illuminate\Console\Command;

class PrintPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'print:permission_provider';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Print permission service provider bind role.';

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
        $config_permissions = config('role_permission.permissions');

        $config_roles = config('role_permission.roles');

        $permissions = [];

        foreach ($config_permissions as $config_permission) {
            foreach ($config_roles as $config_role) {
                $permissions[$config_permission]['name'] = $config_permission;
                if (PlatformRolesEnum::OWNER == $config_role['name'] && GuardPlatformEnum::GUARD_PLATFORM == $config_role['guard_name']) {
                    $permissions[$config_permission][PlatformRolesEnum::OWNER] = in_array($config_permission, $config_role['permissions']) ? '✅' : '❌';
                }

                if (PlatformRolesEnum::ADMIN == $config_role['name'] && GuardPlatformEnum::GUARD_PLATFORM == $config_role['guard_name']) {
                    $permissions[$config_permission][PlatformRolesEnum::ADMIN] = in_array($config_permission, $config_role['permissions']) ? '✅' : '❌';
                }

                if (PlatformRolesEnum::SUPPORT == $config_role['name'] && GuardPlatformEnum::GUARD_PLATFORM == $config_role['guard_name']) {
                    $permissions[$config_permission][PlatformRolesEnum::SUPPORT] = in_array($config_permission, $config_role['permissions']) ? '✅' : '❌';
                }

                if (PlatformRolesEnum::DEVELOPER == $config_role['name'] && GuardPlatformEnum::GUARD_PLATFORM == $config_role['guard_name']) {
                    $permissions[$config_permission][PlatformRolesEnum::DEVELOPER] = in_array($config_permission, $config_role['permissions']) ? '✅' : '❌';
                }

                if (PlatformRolesEnum::FINANCE_MANAGER == $config_role['name'] && GuardPlatformEnum::GUARD_PLATFORM == $config_role['guard_name']) {
                    $permissions[$config_permission][PlatformRolesEnum::FINANCE_MANAGER] = in_array($config_permission, $config_role['permissions']) ? '✅' : '❌';
                }

                if (PlatformRolesEnum::FINANCE_OFFICER == $config_role['name'] && GuardPlatformEnum::GUARD_PLATFORM == $config_role['guard_name']) {
                    $permissions[$config_permission][PlatformRolesEnum::FINANCE_OFFICER] = in_array($config_permission, $config_role['permissions']) ? '✅' : '❌';
                }

                if (PlatformRolesEnum::OWNER == $config_role['name'] && GuardVendorEnum::GUARD_VENDOR_USER == $config_role['guard_name']) {
                    $permissions[$config_permission][GuardVendorEnum::GUARD_VENDOR_USER.' '.PlatformRolesEnum::OWNER] = in_array($config_permission, $config_role['permissions']) ? '✅' : '❌';
                }
            }
        }

        $this->table([
            'permission name',
            PlatformRolesEnum::OWNER,
            PlatformRolesEnum::ADMIN,
            PlatformRolesEnum::SUPPORT,
            PlatformRolesEnum::DEVELOPER,
            PlatformRolesEnum::FINANCE_MANAGER,
            PlatformRolesEnum::FINANCE_OFFICER,
            GuardVendorEnum::GUARD_VENDOR_USER.' '.PlatformRolesEnum::OWNER,
        ], $permissions);
    }
}
