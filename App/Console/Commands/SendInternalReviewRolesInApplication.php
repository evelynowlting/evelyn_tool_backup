<?php

namespace App\Console\Commands;

use App\Enums\GuardPlatformEnum;
use App\Events\SendInternalReviewRolesInApplicationEvent;
use App\Models\Application;
use App\Models\Platform;
use App\Repositories\ApplicationRoleInviteRepository;
use Illuminate\Console\Command;

class SendInternalReviewRolesInApplication extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'internal_review:role_in_application';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send application roles report every monday';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        // private ApplicationRoleInviteRepository $applicationRoleInviteRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // $roles = $this->applicationRoleInviteRepository->getOwltingRoles();
        $users = Platform::where('email', 'LIKE', '%owlting.com')->with(['applications', 'roles'])->get();

        $data = [];
        foreach ($users as $user) {
            $applications = $user->applications;
            $roles = $user->roles;
            foreach ($applications as $application) {
                $application_roles = $roles->filter(function ($role) use ($application) {
                    return $role->model_type === (new Application())->getMorphClass() &&
                           $role->model_id === $application->id &&
                           GuardPlatformEnum::GUARD_PLATFORM === $role->guard_name;
                });

                if (!empty($application_roles)) {
                    $data[] = [
                        'email' => $user->email,
                        'application_uuid' => $application->uuid,
                        'application_name' => $application->name,
                        'roles' => $application_roles->pluck('name')->implode(','),
                    ];
                }
            }
        }

        if (!empty($data)) {
            event(new SendInternalReviewRolesInApplicationEvent($data));
        }

        return 0;
    }
}
