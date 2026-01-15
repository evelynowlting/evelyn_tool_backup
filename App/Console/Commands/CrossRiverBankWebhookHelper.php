<?php

namespace App\Console\Commands;

use App\Mail\AdminCrossRiverBankWebhookRestartNotificationMail;
use Domain\CRB\Enums\CrossRiverBankWebhookEnum;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Infrastructure\CRB\CRBBase;

class CrossRiverBankWebhookHelper extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crb-webhook-helper
                    {action : Action to perform (restart, list_suspended,list_with_status)}
                    {--callbackUrl= : Callback URL}
                    {--eventName= : Event name to be restarted}
                    {--eventId= : Event ID to be restarted}
                    {--status= : Event status to be filtered. Active, Suspended}';

    public const HTTP_GET = 'get';
    public const HTTP_POST = 'post';
    public const HTTP_PUT = 'put';

    private const CRB_CONNECTION_TIMEOUT = 30;

    private $crbBase;

    public function __construct(
        CRBBase $crbBase,
    ) {
        parent::__construct();
        $this->crbBase = $crbBase;
    }

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command line tool helps restart a CRB suspended webhook event.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $action = $this->argument('action');
        $callbackUrl = $this->option('callbackUrl') ?? route('v1.payout.api.crb.webhook');
        $eventName = $this->option('eventName') ?? null;
        $status = $this->option('status') ?? null;

        $filters = array_filter([
            'eventName' => $eventName ?? null,
            'callbackUrl' => $callbackUrl ?? null,
        ]);

        $registeredWebhookEventIds = $this->fetchRegisteredWebhookEvents($filters);
        $actions = [
            'restart_suspended' => fn () => $this->restartSuspendedWebhookEvents($registeredWebhookEventIds),
            'list_suspended' => fn () => $this->listSuspendedWebhookEvents($registeredWebhookEventIds),
            'list_with_status' => fn () => $this->listWebhookEventsWithStatus($registeredWebhookEventIds, $status),
        ];

        if (isset($actions[$action])) {
            $actions[$action]();
        } else {
            $this->warn("[CRB webhook]Unknown action: {$action}");
            $this->warn('[CRB webhook]Available actions: '.implode(', ', array_keys($actions)));
        }

        return Command::SUCCESS;
    }

    // Fetch all registered webhook events from CRB with pagination support and filtered all suspended events.
    private function fetchRegisteredWebhookEvents(array $filters = [])
    {
        Log::info('[CRB webhook]Fetching registered webhook events.');
        $url = config('payoutchannel.crb.url.webhook').'registrations';

        $queryParams = array_filter([
            'eventName' => isset($filters['eventName']) ? $filters['eventName'] : null,
            'callbackUrl' => $filters['callbackUrl'],
            'pageSize' => 50,
        ]);

        $allRegisteredEventList = [];
        while (true) {
            $response = $this->sendRequest(self::HTTP_GET, $url, $queryParams);
            if (200 != $response->status()) {
                $this->error('[CRB webhook]Failed to fetch registered webhook events. Response status: '.$response->status());
                break;
            }

            $allRegisteredEventList = array_merge($allRegisteredEventList, $response['results']);

            if (empty($response) || empty($response['hasNextPage'])) {
                break;
            }

            $queryParams['pageNumber'] = $response['pageNumber'] + 1;
        }

        return $allRegisteredEventList;
    }

    // List all webhook events with status.
    private function listWebhookEventsWithStatus(array $registeredWebhookEventList, string $status)
    {
        $eventList = [];
        foreach ($registeredWebhookEventList as $event) {
            $eventId = $event['id'];
            $eventName = $event['eventName'];
            $eventStatus = $event['status'];

            if ($status == $eventStatus) {
                $this->info("[CRB Webhook]Fetched {$status} webhook event with ID {$eventId} and event name {$eventName}");
                $eventList[] = $event;
            }
        }

        return array_column($eventList, 'eventName', 'id');
    }

    // List all suspended webhook events.
    // $suspendedEventList = [
    //     [
    //         'id' => 'c1b779f9-e4b3-494d-8b0d-b38e00f598fd',
    //         'partnerId' => '5c6c5cf4-0e6f-44b1-893f-b01200fc8570',
    //         'eventName' => 'Wire.Payment.Sent',
    //         'callbackUrl' => 'https://api-release.owlpay.com/api/v1/payout/crb/webhook',
    //         'consecutiveErrors' => 0,
    //         'status' => 'Suspended',
    //         'type' => 'Push',
    //         'format' => 'Extended',
    //     ],
    // ];
    private function listSuspendedWebhookEvents(array $registeredWebhookEventList): array
    {
        $suspendedEventList = [];
        foreach ($registeredWebhookEventList as $event) {
            $eventId = $event['id'];
            $eventName = $event['eventName'];
            $eventStatus = $event['status'];

            if (CrossRiverBankWebhookEnum::WEBHOOK_STATUS_SUSPENDED == $eventStatus) {
                $this->info("[CRB Webhook]Fetched suspended webhook event with ID {$eventId} and event name {$eventName}");
                $suspendedEventList[] = $event;
            }
        }

        return $suspendedEventList ?? [];
    }

    // Restart all suspended webhook events.
    private function restartSuspendedWebhookEvents(array $registeredWebhookEventList)
    {
        $suspendedEventList = $this->listSuspendedWebhookEvents($registeredWebhookEventList);
        if ($suspendedEventList) {
            Mail::to(
                [config('owlpay_notify.owlpay_rd_customer_email')])
                ->queue((new AdminCrossRiverBankWebhookRestartNotificationMail($suspendedEventList))
                ->onQueue('send-mail'));

            $this->info('[CRB webhook]Sending notification email to RD team about restarted suspended webhook events.');
        }

        $webhookBaseUrl = config('payoutchannel.crb.url.webhook').'registrations/';
        foreach ($suspendedEventList as $event) {
            $id = $event['id'];
            $eventName = $event['eventName'];
            $url = $webhookBaseUrl.$id.'/restart';

            $response = $this->sendRequest(self::HTTP_PUT, $url);
            $status = $response->status();
            if (200 == $status) {
                $this->info("[CRB webhook]Restarting suspended webhook event with id {$id} and event name {$eventName}");
            } else {
                $this->error(sprintf('[CRB webhook]Failed to restart suspended webhook event with id %s, Error messages: %s, Response status: %s',
                    $id,
                    json_encode($response->json()['errors']) ?? '',
                    $status
                ));
            }
        }

        return Command::SUCCESS;
    }

    private function sendRequest($method, $url, $data = [])
    {
        $functionName = debug_backtrace()[1]['function'];
        Log::info(sprintf('[CRB webhook]Sending %s request with url %s', $functionName, $url));
        Log::info(sprintf('[CRB webhook]Request params= %s', json_encode($data)));
        $accessToken = $this->crbBase->_getAuthToken();

        try {
            $response = Http::/* dd()-> */ withToken($accessToken)
                    ->timeout(self::CRB_CONNECTION_TIMEOUT)
                    ->$method($url, $data);

            return $response;
        } catch (Exception $e) {
            $this->error('[CRB webhook]An error occurred: '.$e->getMessage());
        }
    }

    // sample response for fetching registered webhook events:
        // "results": [
        //     {
        //         "id": "5614ec94-af50-4d9d-9c36-b3440090a45f",
        //         "partnerId": "5c6c5cf4-0e6f-44b1-893f-b01200fc8570",
        //         "eventName": "Ach.Batch.Canceled",
        //         "callbackUrl": "https://api-stage.owlpay.com/api/v1/payout/crb/webhook",
        //         "consecutiveErrors": 0,
        //         "status": "Active",
        //         "type": "Push",
        //         "format": "Extended"
        //     },
        //     {},
        //     "pageNumber": 1,
        //     "pageSize": 10,
        //     "hasPreviousPage": false,
        //     "hasNextPage": true
        // }
}
