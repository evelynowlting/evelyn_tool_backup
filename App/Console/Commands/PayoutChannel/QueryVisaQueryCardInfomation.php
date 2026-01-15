<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\PayoutChannel\CrossBorderPayoutEnum;
use App\Enums\PayoutStatusEnum;
use App\Models\Application;
use App\Models\Payout;
use Carbon\Carbon;
use Illuminate\Console\Command;

class QueryVisaQueryCardInfomation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'query:payout_credit_card {--payout_uuid=} {--status=} {--output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Query payout created card';

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
        $payout_uuid = $this->option('payout_uuid');

        $status = $this->option('status');

        $output = $this->option('output');

        $payout_uuids = array_filter(explode(',', $payout_uuid));

        $payout_query_builder = Payout::where('gateway', CrossBorderPayoutEnum::VISA_VPA);

        if (!empty($payout_uuids)) {
            $payout_query_builder->whereIn('uuid', $payout_uuids);
        }

        if (!empty($status)) {
            $payout_query_builder->where('status', $status);
        }

        // if (!in_array($status, PayoutStatusEnum::toArray())) {
        //     $this->error('[ERROR] status not found, you must be input '.implode(',', PayoutStatusEnum::toArray()));

        //     return 0;
        // }

        $payouts = $payout_query_builder->with([
            'receiver_model',
            'accounting',
            'visaVirtualAccount',
        ])->get();

        if (0 == $payouts->count()) {
            $this->info('[Info] No record of '.$status.' visa payouts in database.');

            return 0;
        }

        $columns[] = [
            'Payout Status',
            'Payout UUID',
            'Sender UUID',
            'Sender Name',
            'Receiver UUID',
            'Receiver Email',
            'Receiver Name',
            'Accounting UUID',
            'Card Currency',
            'Card Total',
            'Card Card Number (Last 8)',
            'Card Start Date',
            'Card End Date',
            'Created At',
        ];

        foreach ($payouts as $payout) {
            $receiver = $payout->receiver_model;
            $sender = $payout->sender_model;

            if ($sender instanceof Application) {
                $timezone = $sender->timezone;
            }

            $accounting = $payout->accounting;
            $visa_credit_card = $payout->visaVirtualAccount;

            $this->info('-----------------------------------');
            $this->info("[Info] Payout Status: $payout->status");
            $this->info("[Info] Payout UUID: $payout->uuid");

            $this->info("[Info] Sender UUID: $sender->uuid");
            $this->info("[Info] Sender Name: $sender->name");
            $this->info("[Info] Receiver UUID: $receiver->uuid");
            $this->info("[Info] Receiver Email: $receiver->email");
            $this->info("[Info] Receiver Name: $receiver->name");
            $this->info("[Info] Accounting UUID: $accounting->uuid");

            $this->info("[Info] Card Currency: $visa_credit_card->currency");
            $this->info('[Info] Card Total: '.$visa_credit_card->total_amount);
            $this->info('[Info] Card Card Number (Last 8): '.mb_substr($visa_credit_card->card_number, 8, 8));
            $this->info('[Info] Card Start Date: '.Carbon::parse($visa_credit_card->start_date)->setTimezone($timezone));
            $this->info('[Info] Card End date: '.Carbon::parse($visa_credit_card->end_date)->setTimezone($timezone));
            $this->info('[Info] Created At: '.Carbon::parse($payout->created_at)->setTimezone($timezone));

            $columns[] = [
                $payout->status,
                $payout->uuid,
                $sender->uuid,
                $sender->name,
                $receiver->uuid,
                $receiver->email,
                $receiver->name,
                $accounting->uuid,
                $visa_credit_card->currency,
                $visa_credit_card->total_amount,
                mb_substr($visa_credit_card->card_number, 8, 8),
                Carbon::parse($visa_credit_card->start_date)->setTimezone($timezone),
                Carbon::parse($visa_credit_card->end_date)->setTimezone($timezone),
                Carbon::parse($payout->created_at)->setTimezone($timezone),
            ];
        }

        $this->info('-----------------------------------');
        $this->info('[Info] found '.$payouts->count().' payouts records');

        if ($output) {
            $fp = fopen('visa_query_card_information'.time().'.csv', 'w');

            foreach ($columns as $fields) {
                fputcsv($fp, $fields);
            }

            fclose($fp);
        }
    }
}
