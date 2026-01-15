<?php

namespace App\Console\Commands\Development;

use App\Enums\EventAliasEnum;
use App\Enums\GuardAdminEnum;
use App\Enums\GuardPlatformEnum;
use App\Enums\GuardVendorEnum;
use App\Events\ActivityEnableInterface;
use App\Events\MailSendInterface;
use App\Events\RecordEnableInterface;
use App\Events\UserNotificationInterface;
use App\Events\WebhookEnableInterface;
use App\Providers\EventServiceProvider;
use Illuminate\Console\Command;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Facades\App;
use ReflectionClass;

class PrintEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'print:event_provider';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Print event service provider bind status.';

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
        $events = [];

        App::setLocale('zh_tw');

        $subscribe_events = array_keys(app()->getProvider(EventServiceProvider::class)->getEvents());

        foreach ($subscribe_events as $subscribe_event) {
            $reflect = new ReflectionClass($subscribe_event);

            $object = $reflect->newInstanceWithoutConstructor();

            $is_implements_broadcast = (
                in_array(ShouldBroadcast::class, $reflect->getInterfaceNames()) ||
                in_array(ShouldBroadcastNow::class, $reflect->getInterfaceNames())
            );
            $is_implements_notification = in_array(UserNotificationInterface::class, $reflect->getInterfaceNames());
            $is_implements_webhook = in_array(WebhookEnableInterface::class, $reflect->getInterfaceNames());
            $is_implements_record = in_array(RecordEnableInterface::class, $reflect->getInterfaceNames());
            $is_implements_activity = in_array(ActivityEnableInterface::class, $reflect->getInterfaceNames());
            $is_implements_email = in_array(MailSendInterface::class, $reflect->getInterfaceNames());

            if (!$reflect->hasMethod('getEventAlias')) {
                $this->warn("$subscribe_event should implement getEventAlias() method.");
                continue;
            }

            $receiver_guard = '';
            if ($is_implements_email && $reflect->hasMethod('getReceiverGuard')) {
                switch ($object->getReceiverGuard()) {
                    case GuardPlatformEnum::GUARD_PLATFORM:
                        $receiver_guard = 'Platform';
                        break;
                    case GuardVendorEnum::GUARD_VENDOR:
                        $receiver_guard = 'Vendor';
                        break;
                    case GuardAdminEnum::GUARD_ADMIN:
                        $receiver_guard = 'Admin';
                        break;
                }
            }

            $event_alias = $object->getEventAlias();

            if (!isset($events[$event_alias])) {
                $events[$event_alias] = [
                    'alias' => $event_alias,
                    'broadcast' => false,
                    'notification' => $is_implements_notification,
                    'webhook' => false,
                    'record' => false,
                    'activity' => false,
                    'email' => [],
                    'translate' => __("activity.$event_alias"),
//                    'notification_type' => '',
                ];
            }
            $events[$event_alias]['broadcast'] |= $is_implements_broadcast;
            $events[$event_alias]['notification'] |= $is_implements_notification;
            $events[$event_alias]['webhook'] |= $is_implements_webhook;
            $events[$event_alias]['record'] |= $is_implements_record;
            $events[$event_alias]['activity'] |= $is_implements_activity;

            if ($is_implements_email && !empty($receiver_guard)) {
                $events[$event_alias]['email'][] = $receiver_guard;
            }

            if (in_array($event_alias, [
                EventAliasEnum::AGENT_INVITE_CREATED,
                EventAliasEnum::VENDOR_INVITE_CREATED,
                EventAliasEnum::VENDOR_INVITE_RESEND,
            ])) {
                $events[$event_alias]['email'][] = 'Invited user';
            }

//            if ($is_implements_notification) {
//                $reflect = new ReflectionClass($object->getNotificationObject());
//                $object = $reflect->newInstanceWithoutConstructor();
//                $events[$event_alias]['notification_type'] = $object::getNotificationType();
//            }
        }

        $events = array_map(function ($event) {
            $boolean_columns = [
                'broadcast',
                'notification',
                'webhook',
                'record',
                'activity',
            ];

            foreach ($boolean_columns as $boolean_column) {
                $event[$boolean_column] = $event[$boolean_column] ? '✅' : '❌';
            }

            $event['email'] = implode(',', array_unique($event['email']));

            return $event;
        }, $events);

        $this->table(['alias(notification type)', 'broadcast', 'notification', 'webhook', 'record', 'activity', 'email', 'translate'], $events);
    }
}
