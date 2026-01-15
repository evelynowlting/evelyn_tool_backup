<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Notification;
use App\Models\Platform;
use App\Models\Vendor;
use App\Notifications\Platform\BalanceFail;
use App\Notifications\Platform\BalanceFailGroup;
use App\Notifications\Platform\BalanceSuccess;
use App\Notifications\Platform\BalanceSuccessGroup;
use App\Notifications\Platform\OrderTransferRejected;
use App\Notifications\Platform\OrderTransferRejectedGroup;
use App\Notifications\Platform\OrderTransferSettled;
use App\Notifications\Platform\OrderTransferSettledGroup;
use App\Notifications\Vendor\BalanceFail as VendorBalanceFail;
use App\Notifications\Vendor\BalanceFailGroup as VendorBalanceFailGroup;
use App\Notifications\Vendor\BalanceSuccess as VendorBalanceSuccess;
use App\Notifications\Vendor\BalanceSuccessGroup as VendorBalanceSuccessGroup;
use App\Notifications\Vendor\OrderTransferRejected as VendorOrderTransferRejected;
use App\Notifications\Vendor\OrderTransferRejectedGroup as VendorOrderTransferRejectedGroup;
use App\Notifications\Vendor\OrderTransferSettled as VendorOrderTransferSettled;
use App\Notifications\Vendor\OrderTransferSettledGroup as VendorOrderTransferSettledGroup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NotificationGroupMerge extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:group_merge';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'if same type notification count over quantity, delete them and create group notification';

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
        try {
            $merge_count = env('NOTIFICATION_GROUP_COUNT', 10);

            $notifications = Notification::whereIn(
                'type',
                [
                    // platform
                    BalanceFail::class,
                    BalanceSuccess::class,
                    OrderTransferRejected::class,
                    OrderTransferSettled::class,
                    // vendor
                    VendorBalanceFail::class,
                    VendorBalanceSuccess::class,
                    VendorOrderTransferRejected::class,
                    VendorOrderTransferSettled::class,
                ]
            )
                ->whereNull('read_at')
                ->get();

            $notifications->groupBy(
                function ($notification) {
                    return $notification->notifiable_id.'_'.$notification->notifiable_type;
                }
            )->map(
                function ($user_group) use ($merge_count) {
                    // platform
                    $this->makeBalanceFailGroup($user_group, $merge_count);
                    $this->makeBalanceSuccessGroup($user_group, $merge_count);
                    $this->makeOrderTransferRejectedGroup($user_group, $merge_count);
                    $this->makeOrderTransferSettledGroup($user_group, $merge_count);
                    // vendor
                    $this->makeVendorBalanceFailGroup($user_group, $merge_count);
                    $this->makeVendorBalanceSuccessGroup($user_group, $merge_count);
                    $this->makeVendorOrderTransferRejectedGroup($user_group, $merge_count);
                    $this->makeVendorOrderTransferSettledGroup($user_group, $merge_count);
                }
            );
        } catch (\Exception $exception) {
            Log::error('[CMD] notifications:group_merge', [
                'message' => $exception->getMessage(),
            ]);
        }

        return 0;
    }

    private function makeBalanceFailGroup($user_group, $count)
    {
        $balanceFails = $user_group->where('type', BalanceFail::class);
        if ($balanceFails->count() > $count) {
            $collection = $balanceFails->take($count);
            /** @var Notification $first_item */
            $first_item = $collection->first();
            /** @var \App\Models\Platform $platform */
            $platform = $first_item->notifiable;
            $platform->notify(new BalanceFailGroup(
                Application::find($first_item->data['application']['id']),
                Vendor::find($first_item->data['vendor']['id']),
                $count - 1,
            ));

            Notification::whereIn('id', $collection->map->id)->delete();
        }
    }

    private function makeVendorBalanceFailGroup($user_group, $count)
    {
        $balanceFails = $user_group->where('type', VendorBalanceFail::class);
        if ($balanceFails->count() > $count) {
            $collection = $balanceFails->take($count);
            /** @var Notification $first_item */
            $first_item = $collection->first();
            /** @var Platform $platform */
            $platform = $first_item->notifiable;
            $platform->notify(new VendorBalanceFailGroup(
                Application::find($first_item->data['application']['id']),
                Vendor::find($first_item->data['vendor']['id']),
                $count - 1,
            ));

            Notification::whereIn('id', $collection->map->id)->delete();
        }
    }

    private function makeBalanceSuccessGroup($user_group, int $count)
    {
        $balanceFails = $user_group->where('type', BalanceSuccess::class);
        if ($balanceFails->count() > $count) {
            $collection = $balanceFails->take($count);
            /** @var Notification $first_item */
            $first_item = $collection->first();
            /** @var \App\Models\Platform $platform */
            $platform = $first_item->notifiable;
            $platform->notify(new BalanceSuccessGroup(
                Application::find($first_item->data['application']['id']),
                Vendor::find($first_item->data['vendor']['id']),
                $count - 1,
            ));
            Notification::whereIn('id', $collection->map->id)->delete();
        }
    }

    private function makeVendorBalanceSuccessGroup($user_group, int $count)
    {
        $balanceFails = $user_group->where('type', VendorBalanceSuccess::class);
        if ($balanceFails->count() > $count) {
            $collection = $balanceFails->take($count);
            /** @var Notification $first_item */
            $first_item = $collection->first();
            /** @var \App\Models\Platform $platform */
            $platform = $first_item->notifiable;
            $platform->notify(new VendorBalanceSuccessGroup(
                Application::find($first_item->data['application']['id']),
                Vendor::find($first_item->data['vendor']['id']),
                $count - 1,
            ));
            Notification::whereIn('id', $collection->map->id)->delete();
        }
    }

    private function makeOrderTransferRejectedGroup($user_group, $count)
    {
        $balanceFails = $user_group->where('type', OrderTransferRejected::class);
        if ($balanceFails->count() > $count) {
            $collection = $balanceFails->take($count);
            /** @var Notification $first_item */
            $first_item = $collection->first();
            /** @var \App\Models\Platform $platform */
            $platform = $first_item->notifiable;
            $platform->notify(new OrderTransferRejectedGroup(
                Application::find($first_item->data['application']['id']),
            ));
            Notification::whereIn('id', $collection->map->id)->delete();
        }
    }

    private function makeVendorOrderTransferRejectedGroup($user_group, $count)
    {
        $balanceFails = $user_group->where('type', VendorOrderTransferRejected::class);
        if ($balanceFails->count() > $count) {
            $collection = $balanceFails->take($count);
            /** @var Notification $first_item */
            $first_item = $collection->first();
            /** @var \App\Models\Platform $platform */
            $platform = $first_item->notifiable;
            $platform->notify(new VendorOrderTransferRejectedGroup(
                Application::find($first_item->data['application']['id']),
            ));
            Notification::whereIn('id', $collection->map->id)->delete();
        }
    }

    private function makeOrderTransferSettledGroup($user_group, $count)
    {
        $balanceFails = $user_group->where('type', OrderTransferSettled::class);
        if ($balanceFails->count() > $count) {
            $collection = $balanceFails->take($count);
            /** @var Notification $first_item */
            $first_item = $collection->first();
            /** @var \App\Models\Platform $platform */
            $platform = $first_item->notifiable;
            $platform->notify(new OrderTransferSettledGroup(
                Application::find($first_item->data['application']['id']),
            ));
            Notification::whereIn('id', $collection->map->id)->delete();
        }
    }

    private function makeVendorOrderTransferSettledGroup($user_group, $count)
    {
        $balanceFails = $user_group->where('type', VendorOrderTransferSettled::class);
        if ($balanceFails->count() > $count) {
            $collection = $balanceFails->take($count);
            /** @var Notification $first_item */
            $first_item = $collection->first();
            /** @var \App\Models\Platform $platform */
            $platform = $first_item->notifiable;
            $platform->notify(new VendorOrderTransferSettledGroup(
                Application::find($first_item->data['application']['id']),
            ));
            Notification::whereIn('id', $collection->map->id)->delete();
        }
    }
}
