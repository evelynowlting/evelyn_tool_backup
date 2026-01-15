<?php

namespace App\Console\Commands;

use App\Enums\GuardVendorEnum;
use App\Models\Application;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncVendorUserRole extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:vendor_user_role';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync vendor user role';

    public function handle()
    {
        // 取得 applications 資料.
        $applications = Application::get();

        if ($applications->isEmpty()) {
            $this->info('applications not found.');

            return false;
        }

        // 取得 vendor_user roles 資料.
        $roles = Role::where('guard_name', GuardVendorEnum::GUARD_VENDOR_USER)->get();

        if ($roles->isEmpty()) {
            $this->info('roles not found.');

            return false;
        }

        // 取得原始新增資料.
        $original_data = $this->getOriginalData($applications, $roles);

        // sync model has roles table.
        $this->syncModelHasRoles($original_data);

        $this->info('Sync vendor user role.');
    }

    private function getOriginalData($applications, $roles): array
    {
        $original_data = [];

        $applications->map(function ($application) use ($roles, &$original_data) {
            $application->vendors->map(function ($vendor) use ($application, $roles, &$original_data) {
                if (data_get($vendor, 'vendor_user.id')) {
                    $roles->map(function ($role) use ($vendor, $application, &$original_data) {
                        if ($application->id == $role->model_id) {
                            $original_data[] = [
                                'role_id' => $role->id,
                                'model_type' => $vendor->vendor_user->getMorphClass(),
                                'model_id' => data_get($vendor, 'vendor_user.id'),
                            ];
                        }
                    });
                }
            });
        });

        return $original_data;
    }

    private function syncModelHasRoles($original_data)
    {
        $model_has_roles = DB::table('model_has_roles')
            ->where('model_type', GuardVendorEnum::GUARD_VENDOR_USER)
            ->get();

        $model_has_roles_to_array = json_decode(json_encode($model_has_roles), true);

        $delete_data = array_filter($model_has_roles_to_array, function ($element) use ($original_data) {
            return !in_array($element, $original_data);
        });

        $insert_data = array_filter($original_data, function ($element) use ($model_has_roles_to_array) {
            return !in_array($element, $model_has_roles_to_array);
        });

        if (!empty($delete_data)) {
            $delete_data_values = array_map(function (array $delete) {
                return "('".implode("', '", $delete)."')";
            }, $delete_data);

            DB::table('model_has_roles')
                ->whereRaw(
                    '(role_id, model_type, model_id) IN ('.implode(', ', $delete_data_values).')'
                )->delete();

            $this->info('Model has roles deleted.');
        }

        if (!empty($insert_data)) {
            DB::table('model_has_roles')->insert($insert_data);

            $this->info('Model has roles created.');
        }
    }
}
