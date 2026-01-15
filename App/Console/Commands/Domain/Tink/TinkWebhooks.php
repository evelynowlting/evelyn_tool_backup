<?php

namespace App\Console\Commands\Domain\Tink;

use App\Console\Commands\Domain\Tink\Concerns\ShowMessage;
use Illuminate\Console\Command;
use Infrastructure\Tink\TinkRawClient;
use Psr\Log\LogLevel;

class TinkWebhooks extends Command
{
    use ShowMessage;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tink:webhooks
                            {--url=}
                            {--enabled_events= : (comma-separated)}
                            {--disabled=false : false | true }
                            {--description=}
                            {action : list | create | update | delete }
                            ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tink Webhooks utility';

    private TinkRawClient $tinkRawClient;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
    ) {
        parent::__construct();
        $this->tinkRawClient = new TinkRawClient(
            config('payoutchannel.tink.client_id'),
            config('payoutchannel.tink.client_secret'),
            config('payoutchannel.tink.url.api_base'),
            config('payoutchannel.tink.url.api_data_v2_base'),
        );
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $action = $this->argument('action');
        $url = $this->option('url');
        $raw_enabled_events = $this->option('enabled_events');
        $disabled = $this->option('disabled');
        $description = $this->option('description');

        switch ($action) {
            case 'create':
                if (empty($url) || empty($raw_enabled_events) || is_null($disabled)) {
                    $this->showMessage(LogLevel::ERROR, 'Invalid inputs');

                    return 2;
                }
                $enabled_events = explode(',', $raw_enabled_events);
                break;
            case 'list':
                break;
            default:
                $this->showMessage(LogLevel::ERROR, 'Invalid action: '.$action);

                return 1;
        }

        // Generate client token
        $client_token = $this->generateClientToken();

        switch ($action) {
            case 'create':
                $res = $this->createWebhook($client_token, $url, $enabled_events, 'true' == $disabled, $description);
                $this->showMessage(LogLevel::INFO, print_r($res, true));
                break;
            case 'list':
                $webhooks = $this->listWebhooks($client_token);
                $this->showMessage(LogLevel::INFO, print_r($webhooks, true));
                break;
        }
    }

    private function generateClientToken()
    {
        $res = $this->tinkRawClient->generateClientToken();

        return $res->access_token;
    }

    private function createWebhook(
        string $client_token,
        string $url,
        array $enabled_events,
        bool $disabled = false,
        ?string $description = null,
    ) {
        $res = $this->tinkRawClient->createWebhook($client_token, $url, $enabled_events, $disabled, $description);

        return $res;
    }

    private function listWebhooks(
        string $client_token,
        int $page_size = 10,
        ?string $page_token = null,
    ) {
        $res = $this->tinkRawClient->listWebhooks($client_token, $page_size, $page_token);

        return $res;
    }
}
