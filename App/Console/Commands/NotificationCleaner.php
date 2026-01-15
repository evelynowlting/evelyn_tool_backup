<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotificationCleaner extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notification:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notification clear data.';

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
        $first_notification_date = Carbon::parse('2022-01-10');

        $now = Carbon::now();
        $end_date = $now->copy()->addDays(-5);

        for ($date = $first_notification_date; $date < $end_date; $date->addDay()) {
            $read_start_at = $date->copy()->addDays(-2);
            $read_end_at = $date->copy()->addDays(-1);
            $this->info("[Info] Notification Cleaner[$read_start_at ~ $read_end_at]");
            $read_notifications_in_past_count = Notification
//                ::whereNotNull('read_at')
                ::where('created_at', '>=', $read_start_at)
                ->where('created_at', '<=', $read_end_at)
                ->count();
//            $unread_end_at = $date->copy()->addMonths(-2);
//            $unread_notification_in_past_count = Notification
//                ::whereNotNull('read_at')
//                ->where('created_at', '>=', $read_end_at->copy()->addMonths(-3))
//                ->where('created_at', '<=',$unread_end_at)
//                ->count();
            Notification
//                ::whereNotNull('read_at')
                ::where('created_at', '>=', $read_start_at)
                ->where('created_at', '<=', $read_end_at)
                ->forceDelete();

//            Notification
//                ::whereNull('read_at')
//                ->where('created_at', '>=', $unread_end_at->copy()->addMonths(-1))
//                ->where('created_at', '<=',$unread_end_at)
//                ->forceDelete();

            $this->info('Delete read notifications count in past ['.$read_end_at.']:'.$read_notifications_in_past_count);
//            $this->info('Delete unread notifications count in past ['.$unread_end_at.']:'.$unread_notification_in_past_count);
        }
    }
}
