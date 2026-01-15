<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Vendor;
use App\Services\OrderService;
use Illuminate\Console\Command;

class GenerateOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:order {--force_create}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate order';

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
        /** @var OrderService $orderService */
        $orderService = app(OrderService::class);

        $is_test = $this->choice('test mode or production mode?', [
            'test',
            'production',
        ]);

        $force_create = $this->option('force_create');

        $is_test = ('test' == $is_test);

        while (!isset($application) || null == $application) {
            $application = $this->askApplication();
        }

        while (!isset($vendor) || null == $vendor) {
            $vendor = $this->askVendor($application, $is_test);
        }

        while (!isset($order_input) || empty($order_input)) {
            $order_input = $this->askOrder();
        }

        $confirm = $this->confirmSummary($application, $vendor, $order_input);

        if ($confirm) {
            if (!empty($order_input) && !empty($application)) {
                $order = $orderService->getOrderByApplicationOrderSerial(
                    $application->application_order_serial,
                    $is_test
                );

                if (!empty($order) && !$force_create) {
                    $this->info('Order');
                    $this->info('id:'.$order->id);
                    $this->info('uuid:'.$order->uuid);
                    $this->info('order serial on application:'.$order->application_order_serial);
                    $this->info('currency:'.$order->currency);
                    $this->info('total:'.$order->total);
                    $this->info('allow transfer time:'._convertISOTime($order->allow_transfer_time_at));
                    $this->info('created time on application:'._convertISOTime($order->order_created_at));
                    $this->info('created time on owlpay:'._convertISOTime($order->created_at));

                    $force_create = $this->confirm("OwlPay found order $order->application_order_serial on $application->name, do you want to force create?");
                }

                if ($force_create || empty($order)) {
                    $orderService->createOrderByApplication(
                        $application,
                        $order_input['application_order_serial'],
                        $order_input['currency'],
                        $order_input['total'],
                        $vendor,
                        !empty($order_input['order_created_at']) ? _convertISOTime($order_input['order_created_at']) : null,
                        !empty($order_input['allow_transfer_time_at']) ? _convertISOTime($order_input['allow_transfer_time_at']) : null,
                        $order_input['description'],
                        $is_test
                    );
                } else {
                    $orderService->updateOrderByApplication(
                        $order,
                        $order_input['currency'],
                        $order_input['total'],
                        $vendor,
                        !empty($order_input['order_created_at']) ? _convertISOTime($order_input['order_created_at']) : null,
                        !empty($order_input['allow_transfer_time_at']) ? _convertISOTime($order_input['allow_transfer_time_at']) : null,
                        $order_input['description'],
                        true
                    );
                }
            }
        }
    }

    /**
     * @return null
     */
    private function askApplication()
    {
        $application_uuid = $this->ask('application uuid or id?');

        $application = Application::where([
            'uuid' => $application_uuid,
        ])->orWhere([
            'id' => $application_uuid,
        ])->first();

        if (empty($application)) {
            $this->warn('Application not found');

            return null;
        }

        $this->printApplication($application);

        if ($this->confirm('Confirm?')) {
            return $application;
        }

        return null;
    }

    /**
     * @param $is_test
     *
     * @return mixed|null
     */
    private function askVendor(Application $application, $is_test)
    {
        $vendor_uuid = $this->ask('vendor uuid or id?');

        $vendor = $application->vendors()
            ->where([
                'is_test' => $is_test,
            ])
            ->where(function ($query) use ($vendor_uuid) {
                return
                    $query
                        ->where('uuid', $vendor_uuid)
                        ->orWhere('id', $vendor_uuid)
                        ->orWhere('application_vendor_uuid', $vendor_uuid);
            })->first();

        if (empty($vendor)) {
            $this->warn('Application vendor not found');

            return null;
        }

        $this->printVendor($vendor);

        if ($this->confirm('Confirm?')) {
            return $vendor;
        }

        return null;
    }

    private function askOrder(): array
    {
        $currency = $this->choice('currency?', [
            'TWD',
            'MYR',
            'USD',
            'JPY',
        ], 'TWD');

        while (!isset($total) || null == $total) {
            $total = $this->ask('order total?');

            if (!is_numeric($total)) {
                $this->warn('total should be numeric');
                $total = null;
            }
        }

        $application_order_serial = $this->ask('order serial from application');

        $order_created_at = $this->ask('order create time?');

        $allow_transfer_time_at = $this->ask('allow transfer time?');

        $description = $this->ask('description?');

        return compact(
            'currency',
            'total',
            'order_created_at',
            'allow_transfer_time_at',
            'description',
            'application_order_serial'
        );
    }

    private function confirmSummary(Application $application, Vendor $vendor, array $order_input)
    {
        $this->info('Summary');
        $this->info('-----------------');
        $this->printApplication($application);
        $this->info('----');
        $this->printVendor($vendor);
        $this->info('----');
        $this->printOrderInput($order_input);
        $this->info('-----------------');

        return $this->confirm('Confirm?');
    }

    private function printVendor($vendor)
    {
        $this->info('Vendor');
        $this->info('ID:'.$vendor->id);
        $this->info('UUID:'.$vendor->uuid);
        $this->info('Vendor UUID on application:'.$vendor->application_vendor_uuid);
        $this->info('Name:'.$vendor->name);
    }

    private function printApplication($application)
    {
        $this->info('Application');
        $this->info('ID:'.$application->id);
        $this->info('UUID:'.$application->uuid);
        $this->info('Name:'.$application->name);
    }

    private function printOrderInput(array $order_input)
    {
        $this->info('Order');
        $this->info('order serial on application:'.$order_input['application_order_serial']);
        $this->info('currency:'.$order_input['currency']);
        $this->info('total:'.$order_input['total']);
        $this->info('allow transfer time:'.$order_input['allow_transfer_time_at']);
        $this->info('created time on application:'.$order_input['order_created_at']);
    }
}
