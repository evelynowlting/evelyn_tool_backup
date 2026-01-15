<?php

namespace App\Console\Commands;

use App\Enums\AccountingStatusEnum;
use App\Models\Accounting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class AccountingAutoFinish extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounting:auto_finish';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Accounting auto finish on sandbox mode';

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
        $accountings = Accounting::where('is_test', true)
            ->with(['application'])
            ->where('status', AccountingStatusEnum::STATUS_IN_PROCESS)
            ->get();

        if (!in_array(config('app.env'), ['production']) && config('bot.is_payout_auto_finish_on_formal')) {
            $accountings_formal = Accounting::where('is_test', false)
                ->with(['application'])
                ->where('created_at', '<=', Carbon::NOW()->subMinute())
                ->where('status', AccountingStatusEnum::STATUS_IN_PROCESS)
                ->whereIn('gateway', config('bot.auto_finish_payout_gateways_on_formal'))
                ->orderBy('created_at', 'desc')
                ->get();

            $accountings = $accountings->merge($accountings_formal);
        }

        foreach ($accountings as $accounting) {
            $application = $accounting->application;

            $timezone = $application->timezone;

            // 如果再預約中 不跑
            if (!is_null($accounting->payout_date)) {
                if (Carbon::parse($accounting->payout_date, $timezone) > Carbon::today($timezone)) {
                    continue;
                }
            }

            Artisan::call('accounting:finish', [
                'accounting_uuid' => $accounting->uuid,
            ]);
        }

        return 0;
    }
}
