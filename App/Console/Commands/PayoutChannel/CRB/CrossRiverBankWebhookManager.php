<?php

namespace App\Console\Commands\PayoutChannel\CRB;

use Domain\CRB\Enums\CrossRiverBankWebhookEnum;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Infrastructure\CRB\CRBBase;
use InvalidArgumentException;
use function PHPUnit\Framework\isEmpty;

class CrossRiverBankWebhookManager extends Command
{
    protected $signature = 'crb-webhook:manage
                    {action : Action to perform (register, delete, get_list...).}
                    {--eventName= : Event name to be registered}
                    {--eventFile= : A file consists of event names to be registered}
                    {--url=}
                    ';
    protected $description = 'Manage CRB webhook registration: register, get_list, get_by_id, restart_by_id, delete_by_id, ping_by_id, update_by_id, poll';

    public const HTTP_GET = 'get';
    public const HTTP_POST = 'post';
    public const HTTP_PUT = 'put';
    public const HTTP_DELETE = 'delete';

    private const CRB_CONNECTION_TIMEOUT = 30;

    private const CRB_MAX_PAGE_NUMBER = 50;

    private $crbBase;

    public function __construct(
        CRBBase $crbBase,
    ) {
        parent::__construct();
        $this->crbBase = $crbBase;
    }

    // webhook events: https://docs.crossriver.com/apis/lMTn-webhook-events
    // webhook management: https://docs.crossriver.com/apis/webhook-registration#IuysFXK0mOCWmZq4rzkCn
    // webhook example:
    // id: 2c9a01c2-0aa9-444f-b11b-b2030086cc1f
    // partnerId: 5c6c5cf4-0e6f-44b1-893f-b01200fc8570
    // eventName: Ach.Payment.Received
    // callbackUrl: https://sandbox.staging.com/test-webhooks
    // consecutiveErrors: 2
    // lastError: 2024-10-08T04:23:46.283-04:00
    // status: Suspended
    // type: Push
    // format: Basic
    public function handle()
    {
        $action = strtolower($this->argument('action'));

        $eventName = $this->option('eventName');
        $eventFile = $this->option('eventFile');
        if ($eventName && $eventFile) {
            $this->error('assign either eventName or eventFile');

            return;
        }
        $callbackUrl = $this->option('url');

        $availableActions = [
            'get_webhook_meta' => 'Get available webhook events.',
            'register' => 'Registers for webhook event delivery.',
            'get_list' => 'Returns a list of all webhook registrations by event delivery or by polling the system.',
            'get_by_id' => 'Returns a webhook registration by ID.',
            'restart_by_id' => 'Restarts a webhook registration by ID.',
            'delete_by_id' => 'Deletes a webhook registration by ID.',
            'ping_by_id' => 'Calls a webhook registration to check its status.',
            'update_by_id' => 'Updates the details of a webhook registration by ID.',
            'poll_all_events' => 'Retrieve webhook status by polling. !!!!! Don\'t poll more than once every 30 seconds.',
            'delete_all_subscriptions' => 'Delete all subscriptions.',
        ];

        if (!array_key_exists($action, $availableActions)) {
            $this->error('[CRB webhook] Please input correct mode.');

            foreach ($availableActions as $key => $description) {
                $this->info("    {$key}: {$description}");
            }

            $this->info('==================================Parameters===================================');
            $this->info('--id=a4c3bb6b-b34d-4970-9e5b-b20300711acb: The webhook registration ID');
            $this->info('--eventName=Ach.Payment.Received: Webhook event being reported');
            $this->info('--url=http://test.stage.webhook/callback: Callback url.');
            $this->info('--useBatchRegistration=false: Batch registration by reading event name list from file.');
        }

        // Event delivery unsuccessful

        // Monitor the status of your webhook registrations.
        // If the system can't deliver an event to one of your registrations, it makes several attempts to re-send the event.
        // If we still can't deliver the event after several retries, its registration status changes to Suspended and we start to queue all your events for your registration.
        // No further attempts are made to deliver previous or future events to this endpoint until your registration returns to an Active status.
        // When a suspended webhook is restarted, all queued webhook notifications are delivered immediately.

        // If you get a Suspended status, review the logs of recent failed events to identify the issue. When the issue is resolved, restart your registration with the PUT /v1/registrations/{id}/restart webhooks API.
        // The status transitions to Restarting. If we can deliver an event successfully the status returns to Active. When the status returns to Active we deliver all the queued events from when your registration status was suspended.
        // If we can't successfully deliver at least 1 event, the status returns to Suspended.

        // Delivery failure
        // If there is an event delivery failure, don't delete a registration and re-register for the same event. This prevents you from retrieving any events that were queued for delivery, as well as any events that fired in the time between deletion and re-registration.

        switch ($action) {
            case 'get_webhook_meta':
                $this->getWebhookEventList();
                break;
            case 'register':
                $this->register($callbackUrl, $eventName, $eventFile);

                break;
            case 'get_list':
                $callbackUrl = null;
                $eventName = null;
                $pageNumber = null;
                $pageSize = self::CRB_MAX_PAGE_NUMBER;

                $parameters = [
                    'eventName' => 'Do you want to query with eventName? y or n',
                    'callbackUrl' => 'Do you want to query with callbackUrl? y or n (http://xxxx.com)',
                    'pageSize' => 'Do you want to query with pageSize? y or n (ex: 1,2...50)',
                    'pageNumber' => 'Do you want to query with pageNumber? y or n',
                ];

                foreach ($parameters as $param => $message) {
                    if ('Y' === strtoupper(trim($this->ask($message)))) {
                        ${$param} = trim($this->ask("Please provide the $param"));
                    }
                }

                // Call the getList function with the collected parameters
                $this->getList($eventName, $callbackUrl, $pageNumber, $pageSize);

                break;
            case 'get_by_id':
                $ids = trim($this->ask('Please provide the webhook IDs (e.g., 2c9a01c2-0aa9-444f-b11b-b2030086cc1f c287ede6-b33f-41ec-a940-b27e008dd936)'));

                $this->getById($ids);
                break;
            case 'restart_by_id':
                $ids = trim($this->ask('Please provide the webhook IDs (e.g., 2c9a01c2-0aa9-444f-b11b-b2030086cc1f c287ede6-b33f-41ec-a940-b27e008dd936)'));

                $this->restartById($ids);
                break;
            case 'delete_by_id':
                $ids = trim($this->ask('Please provide the webhook IDs (e.g., 2c9a01c2-0aa9-444f-b11b-b2030086cc1f c287ede6-b33f-41ec-a940-b27e008dd936)'));

                $this->deleteByIds($ids);
                break;
            case 'ping_by_id':
                $ids = trim($this->ask('Please provide the webhook IDs (e.g., 2c9a01c2-0aa9-444f-b11b-b2030086cc1f c287ede6-b33f-41ec-a940-b27e008dd936)'));

                $this->pingById($ids);
                break;
            case 'update_by_id':
                $ids = trim($this->ask('Please provide the webhook IDs (e.g., 2c9a01c2-0aa9-444f-b11b-b2030086cc1f c287ede6-b33f-41ec-a940-b27e008dd936)'));

                $this->updateById($ids, $eventName);
                break;

            case 'poll_all_events':
                $this->pollAllEvents();
                break;

            case 'delete_all_subscriptions':
                $this->deleteAllSubscriptions();
                break;
            default:
                $this->error('Unknown option: '.$action);
                break;
        }
    }

    // Retrieve for webhook event list
    protected function getWebhookEventList()
    {
        $url = config('payoutchannel.crb.url.webhook').'meta';
        $response = $this->sendRequest(self::HTTP_GET, $url);

        $eventList = $response->json();
        $this->info('[CRB webhook]Event List:');
        foreach ($eventList['events'] as $e) {
            $this->info($e);
        }
    }

    // Registers for webhook event delivery
    protected function register($callbackUrl, $eventName, $eventFile = null)
    {
        if (!$callbackUrl || '' === trim($callbackUrl)) {
            $callbackUrl = route('v1.payout.api.crb.webhook');
            $this->warn('url is empty, using config '.$callbackUrl);
        }

        $endpointUrl = config('payoutchannel.crb.url.webhook').'registrations';
        $partnerId = config('payoutchannel.crb.owlting_usa_info.partner_id');
        $authUsername = config('payoutchannel.crb.webhook_auth_name'); // Reserve for basic auth. Basic authentication username to include in header of event. 255 character limit.
        $authPassword = config('payoutchannel.crb.webhook_auth_pwd'); // Reserve for basic auth. Basic authentication password to include in event header. 255 character limit.

        $data = [
            'partnerId' => $partnerId, // required Your ID in the CR system. This ID is in GUID format.
            'callbackUrl' => $callbackUrl, // The value is a URL. Webhooks are reported to this URL as a result of a triggered action. Make sure the callback URL is added to your allowlist. SSL required.
            'type' => 'Push', // Type of registration
        ];

        if ($authUsername && $authPassword) {
            $data['authUsername'] = $authUsername;
            $data['authPassword'] = $authPassword;
        }

        if ($eventFile) {
            $eventNames = file($eventFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        } else {
            $eventNames = [$eventName];
        }

        foreach ($eventNames as $eventName) {
            $data['eventName'] = $eventName; // required Webhook event being reported

            // https://docs.crossriver.com/apis/webhooks/accounts-cards-payments/event-formats

            // Extended events have a larger payload, including additional details. They can deliver up to 1k resources at once.
            // We recommend using extended webhooks for all xxx.xxx.Received events.
            // This format returns all the information included in the basic format and a details object. The details object includes information relevant to the event type.

            // Example
            // ACH.Payment.Sent webhook event in the extended webhook format.

            // The first part of the webhook event payload provides information about the webhook event itself.
            // The resources object of the webhook event shows information about the ACH payment.
            // The details object gives specific information.

            // $format = (1 == preg_match('/\.Payment\.Sent$/', $eventName)) ? CrossRiverBankWebhookEnum::WEBHOOK_JSON_FORMAT_BASIC : CrossRiverBankWebhookEnum::WEBHOOK_JSON_FORMAT_EXTENDED;
            $format = CrossRiverBankWebhookEnum::WEBHOOK_JSON_FORMAT_EXTENDED;
            $data['format'] = $format; // required Webhook event being reported

            $response = $this->sendRequest(self::HTTP_POST, $endpointUrl, $data, $eventName);
            $this->info($response->body());
        }
    }

    // Returns a list of all webhook registrations by event delivery or by polling the system
    protected function getList(
        $eventName = null,
        $callbackUrl = null,
        $pageNumber = 1,
        $pageSize = self::CRB_MAX_PAGE_NUMBER
    ) {
        $url = config('payoutchannel.crb.url.webhook').'registrations/';

        if ($pageNumber < 1) {
            $pageNumber = 1;
        }

        if ($pageSize < 1 || $pageSize > self::CRB_MAX_PAGE_NUMBER) {
            $pageSize = self::CRB_MAX_PAGE_NUMBER;
        }

        $data = array_filter([
            'eventName' => $eventName,
            'callbackUrl' => $callbackUrl,
            'pageNumber' => $pageNumber,
            'pageSize' => $pageSize,
        ]);

        $ids = [];
        while (true) {
            $response = $this->sendRequest(self::HTTP_GET, $url, $data);
            $registrationList = json_decode($response->body(), true);

            // $registrationList = [
            //     'results' => [],
            //     'pageNumber' => 1,
            //     'pageSize' => 0,
            //     'hasPreviousPage' => false,
            //     'hasNextPage' => false,
            // ];

            $hasNextPage = $registrationList['hasNextPage'];
            $currentData = $registrationList['results'] ?? [];

            $currentPageSize = count($currentData);
            $this->info(sprintf('[CRB webhook]Current registration List page %d (%d records):',
                $pageNumber,
                $currentPageSize
            ));
            $fetchedIds = $this->printList($registrationList);

            if (empty($fetchedIds)) {
                return [];
            }

            // Payload example while webhook subscription is empty:
            // {
            //     "results": [],
            //     "pageNumber": 1,
            //     "pageSize": 0,
            //     "hasPreviousPage": false,
            //     "hasNextPage": false
            // }
            $ids = array_merge($ids, $fetchedIds);

            $this->warn(sprintf('pageNumber:%d, hasNextPage:%s, pageSize:%d, currentPageSize:%d',
    $pageNumber, $hasNextPage ? 'Y' : 'N', $pageSize, $currentPageSize
));
            if (!$hasNextPage || $currentPageSize < $pageSize) {
                break;
            }

            $data['pageNumber'] = ++$pageNumber;
        }

        return $ids;
    }

    private function printList($registrationList)
    {
        if (is_null($registrationList)) {
            $this->error('[CRB webhook] empty registration list.');
        }

        $ids = null;
        foreach ($registrationList['results'] as $r) {
            foreach ($r as $key => $val) {
                $this->info(sprintf('%s: %s', $key, $val));
                if ('id' == $key) {
                    $ids[] = $val;
                }
            }
            $this->info('============================================');
        }

        return $ids;
    }

    // Returns a webhook registration by ID
    protected function getById($ids)
    {
        $ids = explode(' ', trim($ids));

        foreach ($ids as $id) {
            if (!$this->isValidIdFormat($id)) {
                throw new InvalidArgumentException('Invalid ID format.');
            }

            $url = config('payoutchannel.crb.url.webhook')."registrations/$id";

            $response = $this->sendRequest(self::HTTP_GET, $url);

            $this->info($response->body());
        }

        $this->info('============================================');
    }

    // Restarts a webhook registration by ID
    protected function restartById($ids)
    {
        $ids = explode(' ', trim($ids));

        foreach ($ids as $id) {
            if (!$this->isValidIdFormat($id)) {
                throw new InvalidArgumentException('Invalid ID format.');
            }

            $url = config('payoutchannel.crb.url.webhook')."registrations/$id".'/restart';

            $response = $this->sendRequest(self::HTTP_PUT, $url);

            $this->info($response->body());
        }
    }

    // Deletes a webhook registration by ID
    protected function deleteByIds($ids)
    {
        $ids = explode(' ', trim($ids));

        foreach ($ids as $id) {
            if (!$this->isValidIdFormat($id)) {
                throw new InvalidArgumentException('Invalid ID format.');
            }

            $url = config('payoutchannel.crb.url.webhook')."registrations/$id";

            $response = $this->sendRequest(self::HTTP_DELETE, $url);

            $this->info($response->body());
        }
    }

    protected function deleteAllSubscriptions()
    {
        $ids = $this->getList();

        if (empty($ids)) {
            $this->warn('[CRB webhook]No registrations found.');

            return;
        }

        foreach ($ids as $id) {
            if (!$this->isValidIdFormat($id)) {
                throw new InvalidArgumentException('Invalid ID format.');
            }

            $url = config('payoutchannel.crb.url.webhook')."registrations/$id";

            $response = $this->sendRequest(self::HTTP_DELETE, $url);

            $this->info($response->body());
        }
    }

    // Calls a webhook registration to check its status
    protected function pingById($ids)
    {
        $ids = explode(' ', trim($ids));

        foreach ($ids as $id) {
            if (!$this->isValidIdFormat($id)) {
                throw new InvalidArgumentException('Invalid ID format.');
            }

            $url = config('payoutchannel.crb.url.webhook')."registrations/$id/ping";

            $response = $this->sendRequest(self::HTTP_PUT, $url);

            throw_if(null == $response, '[CRB webhook] Response not found.');

            if (Response::HTTP_ACCEPTED == $response->status()) {
                $this->info("[CRB webhook]Pong! webhook with $id exists.");
            } else {
                $this->error("[CRB webhook]Ping $id failed= ".$response->body());
            }
            $this->info('============================================');
        }
    }

    // Updates the details of a webhook registration by ID
    protected function updateById($id, $eventName, $updateDetails = [])
    {
        if (!$this->isValidIdFormat($id)) {
            throw new InvalidArgumentException('Invalid ID format.');
        }

        $partnerId = config('payoutchannel.crb.owlting_usa_info.partner_id');
        $callbackUrl = isset($updateDetails['callbackUrl']) ? $updateDetails['callbackUrl'] : route('v1.payout.api.crb.webhook');
        $url = config('payoutchannel.crb.url.webhook')."registrations/$id";
        $authUsername = config('payoutchannel.crb.webhook_auth_name'); // Reserve for basic auth. Basic authentication username to include in header of event. 255 character limit.
        $authPassword = config('payoutchannel.crb.webhook_auth_pwd'); // Reserve for basic auth. Basic authentication password to include in event header. 255 character limit.

        $data = [
            // 'id' => $id ?? null, // The webhook registration ID. You receive this ID in the response when you register a webhook event. This ID is in GUID format.
            'partnerId' => $partnerId, // required Your ID in the CR system. This ID is in GUID format.
            'eventName' => $eventName, // required Webhook event being reported 255 character limit.
            'callbackUrl' => $callbackUrl, // The value is a URL. Webhooks are reported to this URL as a result of a triggered action. Make sure the callback URL is added to CR's allowlist. SSL required. 255 character limit
            'consecutiveErrors' => null,
            'lastError' => null,
            'status' => null, // Active, Suspended, Restarting
            'type' => null, // Push, Poll, File
            'format' => isset($updateDetails['format']) ? $updateDetails['format'] : null, // Basic, Extended
        ];

        if ($authUsername && $authPassword) {
            $data['authUsername'] = $authUsername;
            $data['authPassword'] = $authPassword;
        }

        $response = $this->sendRequest(self::HTTP_PUT, $url, $data);

        $this->info($response->body());
    }

    // This API requires additional configuration.
    // {
    //     "message": "Authorization has been denied for this request."
    // }
    // Call GET /webhooks/v1/events/poll to poll for events.
    // !!! Don't poll more than once every 30 seconds.
    protected function pollAllEvents()
    {
        // $url = config('payoutchannel.crb.url.webhook').'registrations/webhooks/v1/events/poll';

        // $response = $this->sendRequest(self::HTTP_GET, $url);

        // $this->info($response->body());
    }

    protected function sendRequest($method, $url, $data = [], $eventName = null)
    {
        $functionName = debug_backtrace()[1]['function'];
        Log::info(sprintf('[CRB webhook]Sending %s request with url %s', $functionName, $url));
        Log::info(sprintf('[CRB webhook]Request params= %s', $functionName, $data, $url));
        $accessToken = $this->crbBase->_getAuthToken();

        $eventName = !isEmpty($eventName) ? 'with event name '.$eventName : '';
        try {
            $response = Http::/* dd()-> */ withToken($accessToken)
                            ->when(config('payoutchannel.crb.api_proxy_server'), function ($request) {
                                return $request->withOptions(['proxy' => config('payoutchannel.crb.api_proxy_server')]);
                            })
                            ->timeout(self::CRB_CONNECTION_TIMEOUT)
                            ->$method($url, $data);

            if ($response->successful()) {
                $this->info('[CRB webhook]Webhook '.$functionName.$eventName.' successfully!');
            }

            return $response;
        } catch (Exception $e) {
            $this->error('[CRB webhook]An error occurred: '.$e->getMessage());
        }
    }

    protected function isValidIdFormat($id)
    {
        return 1 === preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $id);
    }
}
