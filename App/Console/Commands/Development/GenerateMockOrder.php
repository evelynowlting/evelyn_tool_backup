<?php

namespace App\Console\Commands\Development;

use App\Cores\Platform\OrdersCore;
use App\Generators\OwlPayTokenGenerator;
use App\Models\Application;
use Illuminate\Console\Command;

class GenerateMockOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:mock_order {application_uuid} {count=1} {is_test=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate mock order';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private OrdersCore $ordersCore,
        private OwlPayTokenGenerator $owlPayTokenGenerator,
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
        $is_test = $this->argument('is_test');

        $application_uuid = $this->argument('application_uuid');

        $count = $this->argument('count');

        $application = Application::where('uuid', $application_uuid)->first();

        $vendors = $application->vendors()
            ->with(['vendor_payout_gateways'])
            ->where('is_test', $is_test)
            ->get();

        foreach ($vendors as $vendor) {
            if (0 === $vendor->vendor_payout_gateways->count()) {
                continue;
            }

            $currencies = $vendor->vendor_payout_gateways->pluck('currency')->unique();

            $order_input = [];

            foreach ($currencies as $key => $currency) {
                for ($i = 0; $i <= $count; ++$i) {
                    $application_order_serial = 'RTOR_'.time()."{$key}_{$i}".$vendor->id;
                    $hash_data = hash('sha256', $application->uuid.'_'.$application_order_serial.'_'.time().random_int(0, 999999));
                    $uuid = $this->owlPayTokenGenerator->create('order', $hash_data);

                    if ('USD' === $currency) {
                        $total = random_int(20, 50);
                    } elseif ('VND' === $currency) {
                        $total = random_int(15, 25) * 10000;
                    } else {
                        $total = random_int(1000, 5000);
                    }

                    $order_input[] = [
                        'uuid' => $uuid,
                        'application_order_serial' => $application_order_serial,
                        'currency' => $currency,
                        'total' => $total,
                        'description' => '',
                        'vendor_id' => $vendor->id,
                        'is_test' => $is_test,
                        'creator_type' => 'application',
                        'creator_id' => $application->id,
                    ];
                }
            }

            if (!empty($order_input)) {
                $this->ordersCore->createOrders($application, $order_input, $is_test);
            }
        }

        return 0;
    }
}
