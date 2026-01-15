<?php

namespace App\Console\Commands;

use App\Events\Billing\BillingAutoRenewedEvent;
use App\Events\Billing\BillingAutoRenewFailedEvent;
use App\Generators\OwlPayTokenGenerator;
use App\Models\Application;
use App\Models\Billing;
use App\Models\BillingPlan;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BillingAutoRenew extends Command
{
    protected $notify_billing_renew_failed_objects = [];
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:auto_renew {--date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Billing renew automatically.';
    /**
     * @var BillingService
     */
    private $billingService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->billingService = app(BillingService::class);
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $renew_date = $this->option('date');

        if (empty($renew_date)) {
            $renew_date = Carbon::today()->format('Y-m-d');
        }

        $renew_date = Carbon::parse($renew_date);

        // 1. 查詢當前合約到期日最大值
        // 2. 合約到期日最大值 介於 renew_date 10天內
        // 3. 查詢是否有下一期合約
        // 3-1. 有，跳過
        // 3-2. 沒有，自動添加新的合約

        // 2022-03-11
        // today: 2022-03-10
        $billings = Billing::with(['application', 'admin', 'author'])
            ->leftJoin('applications', 'applications.id', '=', 'billings.application_id')
            ->select([
                'applications.uuid as application_uuid',
                'applications.name as application_name',
                'billings.admin_id',
                'billings.author_admin_id',
                'billings.application_id',
                'billings.billings_plan_id',
                \DB::raw('max(billings.during_end_at) as max_during_end_at'),
                'billings.enable_at',
            ])
            ->whereNotNull('billings.enable_at')
            ->whereNotNull('billings.billings_plan_id')
            ->whereNull('applications.deleted_at')
            ->whereNotNull('applications.billings_plan_id') // 有預設的帳單專案
            ->where('applications.status', '=', 1) // 狀態為可以用正式
            ->where('billings.during_end_at', '>=', $renew_date->copy()->subDays(10))
            ->where('billings.during_end_at', '<=', $renew_date)
            ->groupBy('billings.application_id')
            ->orderBy('billings.during_end_at', 'desc')
            ->get();

        foreach ($billings as $billing) {
            $this->info("Check if [$billing->application_name] has the next billing:");

            $next_billing = Billing::leftJoin('billings_plans', 'billings_plans.id', '=', 'billings_plan_id')
                ->where('application_id', $billing->application_id)
                ->where('billings.during_end_at', '>', $billing->max_during_end_at)
                // ->where('billings.total', '>', 0)
                // ->whereNull('enable_at')
                ->select([
                    'application_id',
                    \DB::raw('min(during_start_at) as during_start_at'),
                    \DB::raw('max(during_end_at) as during_end_at'),
                    \DB::raw('count(*) as remain_billings_count'),
                ])
                ->groupBy('application_id')
                ->first();

            if (!empty($next_billing)) {
                $this->info("[$billing->application_name] has next billings (remain_count: $next_billing->remain_billings_count) during at: $next_billing->during_start_at ~ $next_billing->during_end_at");
            } else {
                $this->info("Generate [$billing->application_name] next billings");

                $application = $billing->application;

                if (empty($application)) {
                    continue;
                }

                if (empty($application->billings_plan_id)) {
                    $this->addNotifyBillingRenewFailedObjects($application, $billing);
                    continue;
                }

                $new_billing_plan = BillingPlan::where('is_active', true)
                    ->find($application->billings_plan_id);

                if (empty($new_billing_plan)) {
                    $this->addNotifyBillingRenewFailedObjects($application, $billing);
                    continue;
                }

                $token_generator = (new OwlPayTokenGenerator());

                $group_uuid = $token_generator->create('billing_group');

                $during_start_at = Carbon::parse($billing->max_during_end_at)->addSecond();

                $latest_group_billing = Billing::select([
                        'billings.group_uuid',
                        'billings.application_id',
                        'billings.billings_plan_id',
                        'billings.during_end_at',
                        'billings.enable_at',
                    ])
                    ->whereNotNull('billings.enable_at')
                    ->whereNotNull('billings.billings_plan_id')
                    ->where('billings.during_end_at', '=', $billing->max_during_end_at)
                    ->where('billings.application_id', $application->id)
                    ->orderBy('billings.during_end_at', 'desc')
                    ->first();

                $latest_group_uuid = $latest_group_billing->group_uuid;

                $latest_group_billing = Billing::select([
                        \DB::raw('count(*) as billings_group_count'),
                        'billings.quantity',
                        'billings.group_uuid',
                        'billings.application_id',
                        'billings.billings_plan_id',
                        'billings.during_end_at',
                        'billings.enable_at',
                    ])
                    ->whereNotNull('billings.enable_at')
                    ->whereNotNull('billings.billings_plan_id')
                    ->where('billings.group_uuid', $latest_group_uuid)
                    ->where('billings.application_id', $application->id)
                    ->groupBy('billings.group_uuid')
                    ->orderBy('billings.during_end_at', 'desc')
                    ->first();

                $quantity = $latest_group_billing->quantity;
                $expired_at = $during_start_at->copy()->addDays(7);
                $checkout_type = ($latest_group_billing->billings_group_count > 1) ? 'installment' : 'pay_off';

                $billings = $this->billingService->createBillings(
                    $application,
                    $new_billing_plan,
                    $billing->admin,
                    $billing->author,
                    $group_uuid,
                    $during_start_at,
                    $quantity,
                    $expired_at,
                    $checkout_type,
                    0,
                    0,
                    'auto_renew_by_owlpay_system',
                    null,
                    true
                );

                $this->info('Generate Success, billings uuid:'.$billings->pluck('uuid')->implode(','));

                // event(new BillingAutoRenewedEvent($application, $billings));
            }
        }

        if (!empty($this->notify_billing_renew_failed_objects)) {
            event(new BillingAutoRenewFailedEvent($this->notify_billing_renew_failed_objects));
        }
    }

    private function addNotifyBillingRenewFailedObjects(Application $application, Billing $billing)
    {
        $this->warn("[$application->name] default billings not exist.");

        $this->notify_billing_renew_failed_objects[] = [
            'application' => $application,
            'billing' => $billing,
        ];
    }
}
