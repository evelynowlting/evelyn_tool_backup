<?php

namespace App\Console\Commands;

use App\Enums\PayoutStatusEnum;
use App\Events\Payout\AccountingPayoutFailedEvent;
use App\Events\Payout\AccountingPayoutSucceedEvent;
use App\Models\Application;
use App\Models\Payout;
use Carbon\Carbon;
use Illuminate\Console\Command;

class MarkPayoutStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payout:mark_status {status} {application_uuid} {payout_uuid}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark in_process Payout to failed';

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
        $application_uuid = $this->argument('application_uuid');

        $payout_uuid = $this->argument('payout_uuid');

        $status = $this->argument('status');

        if (!in_array($status, ['success', 'failed'])) {
            $this->error("[ERROR] Status limit input 'success' or 'failed'");

            return 0;
        }

        $payout = Payout::where('uuid', $payout_uuid)->first();

        if (empty($payout)) {
            $this->error('[Error] Payout not found.');
        }

        if (PayoutStatusEnum::STATUS_IN_PROCESS != $payout->status) {
            $this->warn('[Warning] Payout Status not in_process.');

            return 0;
        }

        $receiver = $payout->receiver_model;
        $sender = $payout->sender_model;

        if ($sender instanceof Application) {
            $timezone = $sender->timezone;
        }

        $this->info('----------------------------------------');

        $application = Application::where('uuid', $application_uuid)->first();
        $accounting = $payout->accounting;

        $this->info('-----------------------------------');
        $this->info("[Info] Payout Status: $payout->status");
        $this->info("[Info] Payout UUID: $payout->uuid");
        $this->info("[Info] Sender UUID: $sender->uuid");
        $this->info("[Info] Sender Name: $sender->name");
        $this->info("[Info] Receiver UUID: $receiver->uuid");
        $this->info("[Info] Receiver Email: $receiver->email");
        $this->info("[Info] Receiver Name: $receiver->name");
        $this->info("[Info] Accounting UUID: $accounting->uuid");
        $this->info("[Info] Currency: $payout->currency");
        $this->info('[Info] Total: '.$payout->total);
        $this->info('[Info] Payout Created At:'.Carbon::parse($payout->created_at)->setTimezone($timezone));
        $this->info('----------------------------------------');

        $is_correctly = $this->confirm('Is Payout information correct?');

        if ($is_correctly) {
            switch ($status) {
                case 'success':
                    $this->markSuccess($application, $accounting, $payout);
                    break;
                case 'failed':
                    $this->markFailed($application, $accounting, $payout);
                    break;
                default:
                    break;
            }
        }
    }

    public function markSuccess($application, $accounting, $payout)
    {
        $finished_payouts = [$payout];

        event(new AccountingPayoutSucceedEvent(
            $application,
            $accounting,
            $finished_payouts
        ));
    }

    public function markFailed($application, $accounting, $payout)
    {
        event(new AccountingPayoutFailedEvent(
            $application,
            $accounting,
            $payout
        ));
    }
}
