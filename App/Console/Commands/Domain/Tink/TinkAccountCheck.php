<?php

namespace App\Console\Commands\Domain\Tink;

use App\Console\Commands\Domain\Tink\Concerns\ShowMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Infrastructure\Tink\Enums\OAuth\AuthorizationType;
use Infrastructure\Tink\Enums\User\RetentionClass;
use Infrastructure\Tink\TinkRawClient;
use Infrastructure\Tink\ValueObjects\Responses\Data\v1\AccountVerificationResponse;
use Infrastructure\Tink\ValueObjects\Responses\User\CreateUserResponse;
use Psr\Log\LogLevel;
use Shared\ParseURL;

class TinkAccountCheck extends Command
{
    use ShowMessage;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tink:account_check
                            {--internal_user_id=}
                            {--permanent_user}
                            {--tink_user_id=}
                            {--credentials_id=}
                            {--report_id=}
                            {--redirect_uri=http://localhost:8000/callback}
                            ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Tink Account Check utility';

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
            config('payoutchannel.tink.client_id', ''),
            config('payoutchannel.tink.client_secret', ''),
            config('payoutchannel.tink.url.api_base', ''),
            config('payoutchannel.tink.url.api_data_v2_base', ''),
        );
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $internal_user_id = $this->option('internal_user_id');
        $permanent_user = boolval($this->option('permanent_user'));
        $tink_user_id = $this->option('tink_user_id');
        $do_create_user = empty($tink_user_id);
        $report_id = $this->option('report_id');
        $do_account_link = empty($report_id);
        $redirect_uri = $this->option('redirect_uri');

        if ($do_account_link) {
            if (!empty($internal_user_id) && !empty($tink_user_id)) {
                $this->showMessage(LogLevel::ERROR, 'Must specify either internal_user_id or tink_user_id, but not both.');

                return 1;
            }
            if (empty($internal_user_id) && empty($tink_user_id)) {
                $this->showMessage(LogLevel::ERROR, 'Must specify either internal_user_id or tink_user_id.');

                return 2;
            }
        }

        // Generate client token
        $client_token = $this->generateClientToken();

        if ($do_account_link) {
            // Create user
            if ($do_create_user) {
                $external_user_id = $internal_user_id.'_'.Str::uuid()->toString();

                $tink_user = $this->createUser(
                    $client_token,
                    $external_user_id,
                    retention_class: $permanent_user ? RetentionClass::PERMANENT : RetentionClass::TEMPORARY,
                );

                $tink_user_id = $tink_user->user_id;

                $this->showMessage(LogLevel::INFO, 'User created, user_id = '.$tink_user_id);
            }

            $this->showMessage(LogLevel::INFO, 'user_id = '.$tink_user_id);

            // Generate authorization code
            $authorization_code = $this->generateAccountCheckAuthorizationCode($client_token, $tink_user_id);

            $this->showMessage(LogLevel::INFO, 'authorization_code = '.$authorization_code);

            // Generate Account Check URL
            $account_check_url = $this->generateAccountCheckUrl($redirect_uri, $authorization_code);

            $this->showMessage(LogLevel::INFO, 'Account check URL: '.$account_check_url);

            // Get report_id
            list($credentials_id, $report_id) = $this->getCredentialsIdAndReportId();

            $this->showMessage(LogLevel::INFO, 'credentials_id = '.$credentials_id);
        }

        $this->showMessage(LogLevel::INFO, 'report_id = '.$report_id);

        // Fetch report
        $read_report_client_token = $this->generateReadReportClientToken();

        $report = $this->getReport($read_report_client_token, $report_id);

        $this->showMessage(LogLevel::INFO, 'report = '.print_r($report->toArray(), true));

        $account_id = $report->userDataByProvider[0]->accounts[0]->id;

        $this->showMessage(LogLevel::INFO, 'user_id = '.$tink_user_id.' account_id = '.$account_id);
    }

    private function generateClientToken(): string
    {
        $res = $this->tinkRawClient->generateClientToken();

        return $res->access_token;
    }

    private function createUser(
        string $client_token,
        string $external_user_id,
        string $market = 'US',
        string $locale = 'en_US',
        RetentionClass $retention_class = RetentionClass::PERMANENT,
    ): CreateUserResponse {
        $res = $this->tinkRawClient->createUser($client_token, $external_user_id, $market, $locale, $retention_class);

        return $res;
    }

    private function generateAccountCheckAuthorizationCode(string $client_token, string $user_id): string
    {
        $res = $this->tinkRawClient->generateAuthorizationCode(AuthorizationType::ACCOUNT_CHECK, $client_token, $user_id);

        return $res->code;
    }

    private function generateAccountCheckUrl(
        string $redirect_uri,
        string $authorization_code,
        string $market = 'US',
        string $locale = 'en_US',
    ): string {
        return $this->tinkRawClient->generateAccountCheckUrl($redirect_uri, $authorization_code, $market, $locale);
    }

    private function getCredentialsIdAndReportId(): array
    {
        $res = $this->ask('Redirected URL: ');
        $components = ParseURL::parse($res);

        return [
            $components['query_arr']['credentials_id'] ?? null,
            $components['query_arr']['account_verification_report_id'],
        ];
    }

    private function generateReadReportClientToken(): string
    {
        $res = $this->tinkRawClient->generateReadReportClientToken();

        return $res->access_token;
    }

    private function getReport(string $read_report_client_token, string $report_id): AccountVerificationResponse
    {
        $report = $this->tinkRawClient->getAccountVerificationReport($read_report_client_token, $report_id);

        return $report;
    }
}
