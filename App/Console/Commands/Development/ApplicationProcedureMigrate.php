<?php

namespace App\Console\Commands\Development;

use App\Enums\ApplicationApprovalModeEnum;
use App\Models\Application;
use App\Services\ProcedureService;
use Illuminate\Console\Command;

class ApplicationProcedureMigrate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'application:migrate_procedure';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'migrate application approval to procedure';

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
//        $applications = Application::all();
//
//        /** @var ProcedureService $procedure_service */
//        $procedure_service = app(ProcedureService::class);
//
//        foreach ($applications as $application) {
//            if (ApplicationApprovalModeEnum::NONE == $application->approval_mode) {
//                continue;
//            }
//
//            if (ApplicationApprovalModeEnum::APPLICATION_CREATE_VENDOR_APPROVAL == $application->approval_mode) {
//                $role = $application->roles()
//                    ->where('name', 'owner')
//                    ->where('guard_name', 'vendor_user')
//                    ->first();
//                if (empty($role)) {
//                    continue;
//                }
//                $procedure_service->updateProcedureByApplication($application, [
//                    [
//                        [
//                            'id' => $application->id,
//                            'type' => 'application',
//                        ],
//                    ],
//                    [
//                        [
//                            'id' => $role->id,
//                            'type' => 'role',
//                        ],
//                    ],
//                ]);
//            }
//
//            if (ApplicationApprovalModeEnum::VENDOR_CREATE_APPLICATION_APPROVAL == $application->approval_mode) {
//                $role = $application->roles()
//                    ->where('name', 'owner')
//                    ->where('guard_name', 'vendor_user')
//                    ->first();
//                if (empty($role)) {
//                    continue;
//                }
//                $procedure_service->updateProcedureByApplication($application, [
//                    [
//                        [
//                            'id' => $role->id,
//                            'type' => 'role',
//                        ],
//                    ],
//                    [
//                        [
//                            'id' => $application->id,
//                            'type' => 'application',
//                        ],
//                    ],
//                ]);
//            }
//
//            if (ApplicationApprovalModeEnum::APPLICATION_CREATE_FINANCE_APPROVAL == $application->approval_mode) {
//                $procedure_service->updateProcedureByApplication($application, [
//                    [
//                        [
//                            'id' => $application->id,
//                            'type' => 'application',
//                        ],
//                    ],
//                    [
//                        [
//                            'id' => $application->roles()->firstWhere('name', 'finance')->id,
//                            'type' => 'role',
//                        ],
//                    ],
//                ]);
//            }
//        }

        return 0;
    }
}
