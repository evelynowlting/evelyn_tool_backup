<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\PayoutChannel\VisaVpaRuleCodeEnum;
use App\Models\Application;
use App\Services\Payout\Visa\VisaB2BCard\VisaVpaVpasCard;
use App\Services\Payout\Visa\VisaVPAInfoService;
use App\Services\Payout\Visa\VisaVPAPayoutService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VisaVpaIssueCard extends Command
{
    /**
     * Issue a VISA VPA card.
     *
     * @var string
     */
    protected $signature = 'visa:vpa_issue_card
    {--currency=TWD}
    {--total_amount=100}
    {--qty=1}
    {application_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'VISA VPA Issue Card';
    private $visa_vpa_info_service;
    private $visa_vpa_payout_service;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(VisaVpaInfoService $visa_vpa_info_service, VisaVPAPayoutService $visa_vpa_payout_service)
    {
        parent::__construct();
        $this->visa_vpa_info_service = $visa_vpa_info_service;
        $this->visa_vpa_payout_service = $visa_vpa_payout_service;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $currency = $this->option('currency');
        $total_amount = $this->option('total_amount');
        $qty = $this->option('qty');
        $application_id = $this->argument('application_id');

        for ($i = 1; $i <= $qty; ++$i) {
            $this->line("===== Card $i");
            $this->issueCard($application_id, $currency, $total_amount, $qty);
        }

        return 0;
    }

    public function issueCard($application_id, $currency, $total_amount)
    {
        $application = Application::find($application_id);
        $today = Carbon::today($application->timezone);
        $days = config('payoutchannel.visa.vpa_card_valid_days');
        $vpa_info = $this->visa_vpa_info_service->getVpaInfoByApplicationId($application_id);
        throw_if(empty($vpa_info), 'User does not apply VISA VPA service');
        /*
         *  Owlting user is default setting. If there is any customer,
         *  we should change the way to retrieve the visa configuration.
         */
        if (empty($startDate)) {
            $startDate = Carbon::parse($today, $application->timezone)->startOfDay();
        }
        if (empty($endDate)) {
            $endDate = $startDate->copy()->addDays($days - 1)->endOfDay();
        }

        $message = sprintf(
            '[VISA VPA B2B] Application id: %s issue card date: %s - %s',
            $application->id,
            $startDate->toDateString(),
            $endDate->toDateString()
        );
        Log::debug($message);

        // VISA VPA b2b payout only support VPAS now
        $cardType = [
            VisaVpaRuleCodeEnum::BLOCK_ATM->value,
            VisaVpaRuleCodeEnum::BLOCK_CASH->value,
            VisaVpaRuleCodeEnum::BLOCK_ADULT->value,
            VisaVpaRuleCodeEnum::BLOCK_TOBACCO_ALCOHOL->value,
            VisaVpaRuleCodeEnum::BLOCK_JEWELRY->value,
        ];

        // start_date is not today, still can be issued a card
        Log::debug('[VISA VPA Job] VPAS card');
        /** @var VisaVpaVpasCard */
        $vpasCard = app(VisaVpaVpasCard::class);

        $timezone = $this->getVisaTimezone($application->timezone);

        $card = $vpasCard->setBuyerId($application->visa_vpa_info->buyer_id)
            ->setClientId($application->visa_vpa_info->client_id)
            ->setProxyPoolId($application->visa_vpa_info->proxy_pool_id)
            ->setCardRestrictionCode($cardType)
            ->issueCard($startDate,
            $endDate,
            $currency,
            $total_amount,
            $timezone,
            $application->id,
            null,
        );

        $this->info('====================== Card Info =============');
        $this->info('card number: '.$card['card_number']);
        $this->info('expire date: '.$card['expire_date']);
        $this->info('cvv: '.$card['cvv']);
        $this->info('==============================================');
    }

    private function getVisaTimezone($timezone)
    {
        $carbon = Carbon::now($timezone);
        $offsetSec = $carbon->getOffset();

        if (0 == $offsetSec) {
            return 'UTC+0';
        }

        $offsetHours = $offsetSec / 3600;
        if ($offsetHours > 0) {
            $timezone = 'UTC+'.$offsetHours;
        } else {
            $timezone = 'UTC-'.$offsetHours;
        }

        return $timezone;
    }
}
