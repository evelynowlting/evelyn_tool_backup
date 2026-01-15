<?php

namespace App\Console\Commands\Domain\Tink;

use App\Console\Commands\Domain\Tink\Concerns\ShowMessage;
use Domain\Tink\Events\AccountBalanceReady;
use Domain\Tink\TinkClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Psr\Log\LogLevel;
use Shared\Concerns\ThisLog;
use Shared\ParseURL;

enum TinkClientUtilAction: string
{
    case LINK_ACCOUNT = 'link_account';
    case GET_REPORT = 'get_report';
    case QUERY_BALANCE = 'query_balance';
}

class TinkClientUtil extends Command
{
    use ShowMessage;
    use ThisLog;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tink:client_util
                            {action}
                            {--internal_user_id=}
                            {--permanent_user}
                            {--tink_user_id=}
                            {--redirect_uri=http://localhost:8000/callback}
                            {--account_link_id=}
                            {--cached_report}
                            {--redirected_uri=}
                            {--credentials_id=}
                            {--report_id=}
                            {--cached_balance}
                            {--do_refresh_balance}
                            {--async}
                            {--json_output}
                            ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tink Client utility';

    private TinkClient $tinkClient;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
    ) {
        parent::__construct();
        $this->tinkClient = new TinkClient(
        );
    }

    private function action_link_account(
        ?string $internal_user_id = null,
        bool $permanent_user = false,
        ?string $tink_user_id = null,
        ?string $redirect_uri = 'http://localhost:8000/callback',
        bool $json_output = false,
    ) {
        $do_create_user = empty($tink_user_id);

        if (!empty($internal_user_id) && !empty($tink_user_id)) {
            $this->showMessage(LogLevel::ERROR, 'Must specify either internal_user_id or tink_user_id, but not both.');

            return 2;
        }
        if (empty($internal_user_id) && empty($tink_user_id)) {
            $this->showMessage(LogLevel::ERROR, 'Must specify either internal_user_id or tink_user_id.');

            return 3;
        }

        $account_link_requested = $this->tinkClient->requestLinkAccount($internal_user_id, tink_user_id: $tink_user_id);
        $account_link_id = $account_link_requested->account_link_id;
        $authorization_code = $account_link_requested->authorization_code;

        $this->showMessage(LogLevel::INFO, 'account_link_id = '.$account_link_id);
        $this->showMessage(LogLevel::INFO, 'authorization_code = '.$account_link_requested->authorization_code);

        // Generate Account Check URL
        $account_check_url = $this->tinkClient->generateAccountCheckUrl($redirect_uri, $authorization_code);

        $this->showMessage(LogLevel::INFO, 'Account check URL: '.$account_check_url);

        return 0;
    }

    private function action_get_report(
        string $account_link_id,
        ?string $redirected_uri = null,
        ?string $credentials_id = null,
        ?string $report_id = null,
        bool $cached_report = false,
        bool $json_output = false,
    ) {
        $this->showMessage(LogLevel::INFO, 'account_link_id = '.$account_link_id);

        if ($cached_report) {
            $report = $this->tinkClient->getAccountLinkReport(
                account_link_id: $account_link_id,
                credentials_id: null,
                report_id: $report_id,
                cached_report: true,
            );
        } else {
            if (!empty($redirected_uri) && !empty($report_id)) {
                $this->showMessage(LogLevel::ERROR, 'Must specify either redirected_uri or report_id , but not both.');

                return 2;
            }

            if (!empty($redirected_uri)) {
                list($credentials_id, $report_id) = $this->getCredentialsIdAndReportId($redirected_uri);
            }
            if (empty($report_id)) {
                $this->showMessage(LogLevel::ERROR, 'Unable to get report_id');

                return 3;
            }

            $this->showMessage(LogLevel::INFO, 'credentials_id = '.$credentials_id);
            $this->showMessage(LogLevel::INFO, 'report_id = '.$report_id);

            $report = $this->tinkClient->getAccountLinkReport(
                account_link_id: $account_link_id,
                credentials_id: $credentials_id,
                report_id: $report_id,
            );
        }

        $this->showMessage(LogLevel::INFO, 'report = '.print_r($report->toArray(), true));

        return 0;
    }

    private function action_query_balance(
        string $account_link_id,
        bool $cached_balance = false,
        bool $do_refresh = true,
        bool $async = true,
        bool $json_output = false,
    ) {
        Event::listen(AccountBalanceReady::class, [self::class, 'accountBalanceReadyHandler']);

        $this->tinkClient->queryAccountBalance(
            account_link_id: $account_link_id,
            initiator: self::class,
            extra_data: [
                'async' => $async,
                'cached_balance' => $cached_balance,
                'do_refresh' => $do_refresh,
                'json_output' => $json_output,
            ],
            cached_balance: $cached_balance,
            do_refresh: $do_refresh,
            async: $async,
        );
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $action_str = $this->argument('action');
        $action = TinkClientUtilAction::tryFrom($action_str);

        if (is_null($action)) {
            $this->showMessage(LogLevel::ERROR, 'Unknown action: '.$action_str);

            return 1;
        }

        $internal_user_id = $this->option('internal_user_id');
        $permanent_user = boolval($this->option('permanent_user'));
        $tink_user_id = $this->option('tink_user_id');
        $redirect_uri = $this->option('redirect_uri');
        $account_link_id = $this->option('account_link_id');
        $cached_report = boolval($this->option('cached_report'));
        $redirected_uri = $this->option('redirected_uri');
        $credentials_id = $this->option('credentials_id');
        $report_id = $this->option('report_id');
        $cached_balance = $this->option('cached_balance');
        $do_refresh_balance = $this->option('do_refresh_balance');
        $async = boolval($this->option('async'));
        $json_output = $this->option('json_output');

        $r = match ($action) {
            TinkClientUtilAction::LINK_ACCOUNT => $this->action_link_account(
                internal_user_id: $internal_user_id,
                permanent_user: $permanent_user,
                tink_user_id: $tink_user_id,
                redirect_uri: $redirect_uri,
                json_output: $json_output,
            ),
            TinkClientUtilAction::GET_REPORT => $this->action_get_report(
                account_link_id: $account_link_id,
                redirected_uri: $redirected_uri,
                credentials_id: $credentials_id,
                report_id: $report_id,
                cached_report: $cached_report,
                json_output: $json_output,
            ),
            TinkClientUtilAction::QUERY_BALANCE => $this->action_query_balance(
                account_link_id: $account_link_id,
                cached_balance: $cached_balance,
                do_refresh: $do_refresh_balance,
                async: $async,
                json_output: $json_output,
            ),
        };

        return $r;
    }

    private function getCredentialsIdAndReportId(string $redirected_uri): ?array
    {
        $components = ParseURL::parse($redirected_uri);

        if (!$components) {
            return null;
        }

        return [
            $components['query_arr']['credentials_id'] ?? null,
            $components['query_arr']['account_verification_report_id'],
        ];
    }

    public function accountBalanceReadyHandler(AccountBalanceReady $event)
    {
        if (self::class != $event->initiator) {
            $this->showMessage(LogLevel::INFO, 'Initiator is not me, ignore this event.');

            return;
        }
        if ($event->extra_data['json_output']) {
            $output = json_encode([
                'account_balance_data' => $event->account_balance->toArray(),
                'extra_data' => $event->extra_data,
            ]);
            $this->log(LogLevel::INFO, $output);
            echo $output;
        } else {
            $this->showMessage(LogLevel::INFO, 'account_balance_data = '.print_r($event->account_balance->toArray(), true));
            $this->showMessage(LogLevel::INFO, 'extra_data = '.print_r($event->extra_data, true));
        }
    }
}
