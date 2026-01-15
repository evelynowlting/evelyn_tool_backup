<?php

namespace App\Console\Commands;

use App\Enums\GuardAdminEnum;
use App\Enums\GuardOwltingEnum;
use App\Enums\GuardPlatformEnum;
use App\Enums\GuardVendorEnum;
use App\Models\Application;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SyncRolePermission extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:role_permission {--platform} {--admin} {--owlting} {--application_id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync role permission';

    public function handle()
    {
        $isSyncPlatform = $this->option('platform');

        $isSyncAdmin = $this->option('admin');

        $isSyncOwlting = $this->option('owlting');

        $application_id = $this->option('application_id');

        $application_ids = [];

        if (!empty($application_id)) {
            $application_ids = [$application_id];
        } else {
            $application_ids = Application::all()->pluck('id')->toArray();
        }

        if ($isSyncPlatform || $isSyncOwlting) {
            $index = 1;
            foreach ($application_ids as $application_id) {
                $this->info("[$index/".count($application_ids)."] sync application, application_id: $application_id");

                if ($isSyncPlatform) {
                    $this->isSyncPlatformPermission($application_id);
                }

                if ($isSyncOwlting) {
                    $this->isSyncOwltingPermission($application_id);
                }

                ++$index;
            }
        }

        if ($isSyncAdmin) {
            $this->isSyncAdminPermission();
        }
    }

    private function isSyncAdminPermission()
    {
        // 取得 permissions 資料.
        $permissions = Permission::where('guard_name', GuardAdminEnum::GUARD_ADMIN)->get();

        if ($permissions->isEmpty()) {
            $this->info("Please run 'php artisan make:admin_permissions'");

            return false;
        }

        // 取得 config role_permissions 資料.
        $config_role_permissions = $this->getConfigRolePermissions($permissions, GuardAdminEnum::GUARD_ADMIN);

        // 取得 roles 資料.
        $roles = Role::where('guard_name', GuardAdminEnum::GUARD_ADMIN)->get();

        if ($roles->isEmpty()) {
            $this->info('role not found.');

            return false;
        }

        // 取得原始新增資料.
        $original_data = $this->getOriginalData($config_role_permissions, $roles);

        // sync role has permissions table.
        $this->syncRoleHasPermissions($roles, $original_data);

        $this->info('Sync admin role permission.');
    }

    private function isSyncPlatformPermission(?int $application_id = null)
    {
        // 取得 permissions 資料.
        $permissions = Permission::whereIn('guard_name', [GuardPlatformEnum::GUARD_PLATFORM, GuardVendorEnum::GUARD_VENDOR_USER])->get();

        if ($permissions->isEmpty()) {
            $this->info("Please run 'php artisan make:permissions'");

            return false;
        }

        // 取得 config role_permissions 資料.
        $config_role_permissions = $this->getConfigRolePermissions($permissions, GuardPlatformEnum::GUARD_PLATFORM);

        // 取得 roles 資料.
        $roles = Role::where('guard_name', GuardPlatformEnum::GUARD_PLATFORM)
            ->when(!empty($application_id), function ($query) use ($application_id) {
                $query->where('model_id', $application_id)->where('model_type', 'application');
            })
            ->get();

        $this->info('Sync Roles count:'.$roles->count());

        if ($roles->isEmpty()) {
            $this->info('role not found.');

            return false;
        }

        // 取得原始新增資料.
        $original_data = $this->getOriginalData($config_role_permissions, $roles);

        // sync role has permissions table.
        $this->syncRoleHasPermissions($roles, $original_data);

        $this->info('Sync role permission.');
    }

    private function isSyncOwltingPermission(?int $application_id = null)
    {
        // 取得 permissions 資料.
        $permissions = Permission::where('guard_name', GuardOwltingEnum::GUARD_OWLTING)->get();

        if ($permissions->isEmpty()) {
            $this->info("Please run 'php artisan make:permissions'");

            return false;
        }

        // 取得 config role_permissions 資料.
        $config_role_permissions = $this->getConfigRolePermissions($permissions, GuardOwltingEnum::GUARD_OWLTING);

        // 取得 roles 資料.
        $roles = Role::where('guard_name', GuardOwltingEnum::GUARD_OWLTING)
            ->when(!empty($application_id), function ($query) use ($application_id) {
                $query->where('model_id', $application_id)->where('model_type', 'application');
            })
            ->get();

        $this->info('Sync Roles count:'.$roles->count());

        if ($roles->isEmpty()) {
            $this->info('role not found.');

            return false;
        }

        // 取得原始新增資料.
        $original_data = $this->getOriginalData($config_role_permissions, $roles);

        // sync role has permissions table.
        $this->syncRoleHasPermissions($roles, $original_data);

        $this->info('Sync role permission.');
    }

    private function getConfigRolePermissions($permissions, $guard)
    {
        if (GuardAdminEnum::GUARD_ADMIN == $guard) {
            $config_role_permissions = config('admin_role_permission.roles');
        }

        if (GuardPlatformEnum::GUARD_PLATFORM == $guard) {
            $config_role_permissions = array_filter(config('role_permission.roles'), function ($row) {
                return GuardPlatformEnum::GUARD_PLATFORM == $row['guard_name'];
            });
            $config_role_permissions = array_values($config_role_permissions);
        }

        if (GuardOwltingEnum::GUARD_OWLTING == $guard) {
            $config_role_permissions = array_filter(config('role_permission.roles'), function ($row) {
                return GuardOwltingEnum::GUARD_OWLTING == $row['guard_name'];
            });
            $config_role_permissions = array_values($config_role_permissions);
        }

        array_walk($config_role_permissions, function (&$config_role_permission) use ($permissions) {
            array_walk($config_role_permission['permissions'], function (&$name) use ($config_role_permission, $permissions) {
                $name = Arr::first($permissions, function ($permission) use ($name, $config_role_permission) {
                    return $permission->name == $name && $permission->guard_name == $config_role_permission['guard_name'];
                })->id;
            });
        });

        return $config_role_permissions;
    }

    private function getOriginalData($config_role_permissions, $roles): array
    {
        $original_data = [];

        $roles->map(function ($role) use ($config_role_permissions, &$original_data) {
            $sync_role = Arr::first($config_role_permissions, function ($value) use ($role) {
                return $value['name'] == $role->name && $value['guard_name'] == $role->guard_name;
            });

            array_map(function ($permission_id) use ($role, &$original_data) {
                $original_data[] = [
                    'role_id' => $role->id,
                    'permission_id' => $permission_id,
                ];
            }, data_get($sync_role, 'permissions', []));
        });

        return $original_data;
    }

    private function syncRoleHasPermissions($roles, $original_data)
    {
        $role_has_permissions = DB::table('role_has_permissions')->whereIn('role_id', $roles->pluck('id')->toArray())->get();

        $role_has_permissions_to_array = json_decode(json_encode($role_has_permissions), true);

        $delete_data = array_filter($role_has_permissions_to_array, function ($element) use ($original_data) {
            return !in_array($element, $original_data);
        });

        $insert_data = array_filter($original_data, function ($element) use ($role_has_permissions_to_array) {
            return !in_array($element, $role_has_permissions_to_array);
        });

        if (!empty($delete_data)) {
            $delete_data_values = array_map(function (array $delete) {
                return "('".implode("', '", $delete)."')";
            }, $delete_data);

            DB::table('role_has_permissions')
                ->whereRaw(
                    '(permission_id, role_id) IN ('.implode(', ', $delete_data_values).')'
                )->delete();

            $this->info('Model has permissions deleted.');
        }

        if (!empty($insert_data)) {
            DB::table('role_has_permissions')->insert($insert_data);

            $this->info('Model has permissions created.');
        }
    }
}
