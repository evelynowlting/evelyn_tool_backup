<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\PayoutChannel\CrossBorderPayoutEnum;
use App\Enums\PayoutStatusEnum;
use App\Models\Payout;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class VisaPayoutStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visa:payout_status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'check visa payout status';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $payouts = Payout::where('gateway', CrossBorderPayoutEnum::VISA_VPA)
            ->whereIn('status', [
                PayoutStatusEnum::STATUS_IN_PROCESS,
            ])->whereHas('visaVirtualAccount', function (Builder $builder) {
                $builder->where('end_date', '<', now()->toDateTimeString());
            })->get();

        $payouts = $payouts->map(function ($payout) {
            return [
                'id' => $payout->id,
                'status' => PayoutStatusEnum::STATUS_EXPIRED,
            ];
        });

        Payout::query()->upsert($payouts->toArray(), ['id']);

        _owlPayLog('visa_payout_to_expired', $payouts->pluck('id')->toArray(), 'system', 'warning');

        return 0;
    }
}
