<?php

namespace App\Console\Commands\Development;

use App\Enums\PayoutStatusEnum;
use App\Events\UnusualPayoutDetectedEvent;
use App\Models\Payout;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DetectUnusualPayouts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'detect:unusual_payouts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect unusual payouts (large total or in_process > 1days payouts)';

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
        $payouts = Payout::where([
            'status' => PayoutStatusEnum::STATUS_IN_PROCESS,
        ])
        ->with(['accounting', 'application'])
        ->where('is_test', false)
        ->where('created_at', '<', Carbon::today()->subDay())
        ->get();

        $payouts = $payouts->filter(function ($payout) {
            $accounting = $payout->accounting;
            $application = $payout->application;
            $timezone = $application->timezone;
            $payout_date = $accounting->payout_date;
            $today = Carbon::today($timezone);

            return $today > $accounting->payout_date && $today->diffInWeekdays($payout_date) > 1;
        })->values();

        if ($payouts->count() > 0) {
            event(new UnusualPayoutDetectedEvent($payouts));
        }
    }
}
