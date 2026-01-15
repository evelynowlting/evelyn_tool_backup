<?php

namespace App\Console\Commands;

use App\Cores\Platform\AccountingCore;
use App\Enums\GuardPlatformEnum;
use App\Enums\PlatformRolesEnum;
use App\Events\Order\PlatformAccountingScheduledApproachingExpiredEvent;
use App\Events\Order\PlatformAccountingScheduledExpiredDeleteEvent;
use App\Services\AccountingService;
use App\Services\ApplicationService;
use App\Services\RoleService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AccountingScheduledExpiredCheck extends Command
{
    protected $signature = 'accounting:scheduled_expired_check';

    protected $description = 'Check scheduled accounting is expired or not and notify';

    public function __construct(
        private ApplicationService $applicationService,
        private AccountingService $accountingService,
        private RoleService $roleService,
        private AccountingCore $accountingCore,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = Carbon::now();
        $timezones = $this->applicationService->getAllApplicationTimezones();
        $zeroClockTimezones = [];
        foreach ($timezones as $timezone) {
            $timezoneTime = $now->setTimezone($timezone);
            $isAtZeroClock = '00' === $timezoneTime->format('H');
            if ($isAtZeroClock) {
                $zeroClockTimezones[] = $timezone;
            }
        }

        // only get application which timezone time is zero clock
        $applications = $this->applicationService->getByTimezones($zeroClockTimezones);
        $applicationIdToApplicationMap = $applications->keyBy('id');
        $applicationIds = $applications->pluck('id')->toArray();

        $accountings = $this->accountingService->getWaitExecuteScheduledAccounting($applicationIds);
        $applicationIdToAccountings = $accountings->groupBy('application_id');
        $applicationIds = $accountings->pluck('application_id')->unique()->toArray();

        $roles = $this->roleService->getApplicationRoleByModelIds($applicationIds, GuardPlatformEnum::GUARD_PLATFORM, [PlatformRolesEnum::FINANCE_MANAGER]);
        $roles->load([
            'platforms',
        ]);
        $applicationIdToFinanceManagerPlatformsMap = [];
        foreach ($roles as $role) {
            $applicationIdToFinanceManagerPlatformsMap[$role->model_id] = $role->platforms;
        }

        $expiredAccountings = [];
        $todayAccountings = [];
        $expiredAccountingIds = [];
        $todayAccountingIds = [];

        foreach ($applicationIdToAccountings as $applicationId => $accountings) {
            $application = $applicationIdToApplicationMap[$applicationId];
            $applicationTimezone = $application->timezone;
            // compare schedule_date and now date in same timezone
            $dateString = $now->setTimezone($applicationTimezone)->format('Y-m-d');
            $datetime = Carbon::parse($dateString, $applicationTimezone);
            foreach ($accountings as $accounting) {
                $scheduleDate = Carbon::parse($accounting->schedule_date, $applicationTimezone);

                if ($datetime->equalTo($scheduleDate)) {
                    $todayAccountings[] = $accounting;
                    $todayAccountingIds[] = $accounting->id;
                } elseif ($datetime->greaterThan($scheduleDate)) {
                    $expiredAccountings[] = $accounting;
                    $expiredAccountingIds[] = $accounting->id;
                }
            }
        }

        foreach ($todayAccountings as $todayAccounting) {
            $applicationId = $todayAccounting->application_id;
            $application = $applicationIdToApplicationMap[$applicationId];
            $financeManagerPlatforms = $applicationIdToFinanceManagerPlatformsMap[$applicationId];
            event(new PlatformAccountingScheduledApproachingExpiredEvent($application, $todayAccounting, $financeManagerPlatforms));
        }

        foreach ($expiredAccountings as $expiredAccounting) {
            $applicationId = $expiredAccounting->application_id;
            $application = $applicationIdToApplicationMap[$applicationId];
            $financeManagerPlatforms = $applicationIdToFinanceManagerPlatformsMap[$applicationId];
            $this->accountingCore->deleteAccounting($application, $expiredAccounting->uuid, $expiredAccounting->is_test);
            event(new PlatformAccountingScheduledExpiredDeleteEvent($application, $expiredAccounting, $financeManagerPlatforms));
        }

        _owlPayLog('schedule_accounting_check', [
            'accounting_ids_today_is_schedule_date' => $todayAccountingIds,
            'accounting_ids_expired' => $expiredAccountingIds,
        ], 'system', 'info');

        return Command::SUCCESS;
    }
}
