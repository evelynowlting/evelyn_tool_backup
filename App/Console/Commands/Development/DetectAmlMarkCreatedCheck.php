<?php

namespace App\Console\Commands\Development;

use App\Models\Vendor;
use App\Services\AmlMarkService;
use Illuminate\Console\Command;

class DetectAmlMarkCreatedCheck extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'aml_mark:created_check {--auto_add}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aml mark created checked';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(private AmlMarkService $amlMarkService)
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
        $amlCheckList = [];
        $vendors = Vendor::where('is_test', false)->with(['baseInformations'])->get();
        $isAutoAdd = $this->option('auto_add');

        foreach ($vendors as $vendor) {
            $amlCheckList[$vendor->id] = [];

            foreach ($vendor->baseInformations as $baseInformation) {
                if (!empty($baseInformation->currency) && !empty($baseInformation->country)) {
                    $amlCheckList[$vendor->id]["$baseInformation->country-$baseInformation->currency"][] = $baseInformation->payout_gateway;
                    $amlCheckList[$vendor->id]["$baseInformation->country-$baseInformation->currency"] = array_values(array_unique($amlCheckList[$vendor->id]["$baseInformation->country-$baseInformation->currency"]));
                }
            }

            if (empty($amlCheckList[$vendor->id])) {
                unset($amlCheckList[$vendor->id]);
            }
        }

        $vendors = Vendor::whereIn('id', array_keys($amlCheckList))->with(['aml_marks', 'application'])->get();

        foreach ($amlCheckList as $vendor_id => $pairs) {
            $vendor = $vendors->where('id', $vendor_id)->first();

            $amlMarks = $vendor->aml_marks;
            $application = $vendor->application;

            if (empty($application)) {
                continue;
            }

            foreach ($pairs as $countryCurrency => $gateways) {
                $country = explode('-', $countryCurrency)[0];
                $currency = explode('-', $countryCurrency)[1];
                $regionCountry = _findCountryRegion($country);
                $country = $regionCountry ?? $country;

                foreach ($gateways as $gateway) {
                    if (empty($gateway)) {
                        continue;
                    }

                    $amlMark = $amlMarks->filter(function ($amlMark) use ($currency, $country, $gateway) {
                        return $amlMark->currency == $currency && $amlMark->country == $country && 'created' == $amlMark->type && $amlMark->payout_gateway == $gateway;
                    })->first();

                    if (empty($amlMark)) {
                        $this->warn("Missing $vendor_id $gateway $countryCurrency");
                        if ($isAutoAdd || $this->confirm('Do you want to add a row with this?')) {
                            $this->amlMarkService->firstOrCreateAmlMarkByVendor($application, $vendor, $currency, $gateway, $country);
                            $this->info("$vendor_id $gateway $countryCurrency Created ");
                        }
                        $this->info('-----');
                    }
                }
            }
        }
    }
}
