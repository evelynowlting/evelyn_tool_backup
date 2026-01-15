<?php

namespace App\Console\Commands\Development;

use App\Enums\PayoutChannel\NiumBaaSEnum;
use App\Enums\PayoutChannel\NiumBaaSWebhookTemplateEnum;
use App\Http\Controllers\Payout\NiumBaaSWebhookController;
use App\Jobs\NiumBaaSPayoutStatusUpdateJob;
use App\Models\Application;
use App\Services\ApplicationService;
use App\Services\PayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class NiumBaaSMockWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium_baas:mock_webhook {--template=} {--application_uuid=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nium BaaS mock webhook';

    private $header;

    private const PAYOUT_TEMPLATE_PREFIX = 'REMIT_TRANSACTION';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private ApplicationService $applicationService,
        private NiumBaaSWebhookController $niumBaaSWebhookController,
        private PayoutService $payoutService,
    ) {
        parent::__construct();

        $this->header = [
            NiumBaaSEnum::NIUM_BAAS_API_KEY => config('payoutchannel.niumBaaS.api_key_for_webhook'),
            NiumBaaSEnum::NIUM_BAAS_HEADER_REQUEST_ID => 'owlpay_command_'.time(),
        ];
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $template = $this->option('template');

        if ('production' == config('app.env')) {
            $this->warn('This command is forbidden to be used on the production environment.');
        }

        if (empty($template)) {
            $template = $this->choice('which one payout template?', array_values(NiumBaaSWebhookTemplateEnum::toArray()));
        }

        $applicationUuid = $this->option('application_uuid');

        if (!empty($applicationUuid)) {
            $application = $this->applicationService->getByUuid($applicationUuid);

            if (empty($application)) {
                $this->warn('Application not found');
            }
        }

        $customerInfo = $application->nium_baas_customer_info;

        if (empty($customerInfo)) {
            $this->warn('The application\'s customerInfo not found.');
        }

        $clientInfo = $customerInfo?->nium_baas_client_info;

        if (empty($clientInfo)) {
            $this->warn('The application\'s clientInfo not found.');
        }

        $customerId = $customerInfo?->customer_id;
        $walletHashId = $customerInfo?->wallet_id;
        $clientId = $clientInfo?->client_id;

        switch ($template) {
            case NiumBaaSWebhookTemplateEnum::CARD_WALLET_FUNDING_WEBHOOK:
                $name = $application->name;
                $brandName = $application->name;
                $transactionCurrency = $this->ask('currency?');
                $transactionAmount = $this->ask('amount?');
                $walletBalance = $this->ask('how much balance after amount funding finish?');
                $authCode = $this->ask('auth code?');

                $parameters = [
                    'name' => $name,
                    'brandName' => $brandName,
                    'customerHashId' => $customerId,
                    'walletHashId' => $walletHashId,
                    'transactionCurrency' => $transactionCurrency,
                    'transactionAmount' => $transactionAmount,
                    'walletBalance' => $walletBalance,
                    'authCode' => $authCode,
                    'template' => $template,
                ];

                $this->printColumns($parameters, $template, $application);

                $isConfirm = $this->isConfirm();

                if ($isConfirm) {
                    $request = Http::withHeaders($this->header)->post(route('v1.payout.api.nium.baas.status.webhook'), $parameters);
                }

                break;
            case NiumBaaSWebhookTemplateEnum::VIRTUAL_ACCOUNT_ASSIGNED_WEBHOOK:
                $this->warn("Command unsupported $template yet");
                // @TODO: VIRTUAL_ACCOUNT_ASSIGNED_WEBHOOK
                // NiumBaaSVirtualAccountAssignedJob::dispatch($application, $params)->onQueue('nium_baas-onboarding');
                break;
            case NiumBaaSWebhookTemplateEnum::VIRTUAL_ACCOUNT_ASSIGNMENT_FAILED_WEBHOOK:
                $this->warn("Command unsupported $template yet");
                // @TODO: VIRTUAL_ACCOUNT_ASSIGNMENT_FAILED_WEBHOOK
                // NiumBaasPayoutVANAssignmentFailedJob::dispatch($application, $params)->onQueue('nium_baas-onboarding');
                break;
            case NiumBaaSWebhookTemplateEnum::CARD_BALANCE_TRF_BETWEEN_CURRENCIES_WITHIN_SAME_WALLET_WEBHOOK:
                $this->warn("Command unsupported $template yet");
                // @TODO: CARD_BALANCE_TRF_BETWEEN_CURRENCIES_WITHIN_SAME_WALLET_WEBHOOK
                // event(new NiumBaasFXCreatedEvent($customer_info, $params));
                break;
            default:
                if (str_starts_with($template, self::PAYOUT_TEMPLATE_PREFIX)) {
                    $payoutUuid = $this->ask('payout uuid?');
                    $isTest = $this->confirm('is test mode?', false);

                    $payout = $this->payoutService->getPayoutByPayoutUUID($payoutUuid, $isTest, ['meta_data_list']);

                    if (empty($payout)) {
                        $this->error('The payout not found.');

                        return;
                    }

                    if ($payout->application_id != $application->id) {
                        $this->error('The payout object not belongs to application.');

                        return;
                    }

                    $metaDataList = $payout->meta_data_list->map(function ($meta_data) {
                        return [$meta_data->key => $meta_data->value];
                    })->collapse()->toArray();

                    $beneficiaryName = $metaDataList['account_name#target'] ?? null;
                    $beneficiaryAccountNumber = $metaDataList['account#target'] ?? null;
                    $beneficiaryBankName = $metaDataList['bank_name#target'] ?? null;

                    $parameters = [
                        'clientHashId' => $clientId,
                        'customerHashId' => $customerId,
                        'walletHashId' => $walletHashId,
                        'transactionCurrency' => $payout->target_currency,
                        'transactionAmount' => $payout->target_total,
                        'systemReferenceNumber' => $payout->external_payment_uuid,
                        'exchangeRate' => $payout->gateway_exchange_rate,
                        'beneficiaryName' => $beneficiaryName,
                        'beneficiaryAccountNumber' => $beneficiaryAccountNumber,
                        'beneficiaryBankName' => $beneficiaryBankName,
                        'billingCurrency' => $payout->gateway_currency,
                        'billingAmount' => $payout->gateway_total,
                        'template' => $template,
                    ];

                    $this->printColumns($parameters, $template, $application);

                    $isConfirm = $this->isConfirm();

                    if ($isConfirm) {
                        NiumBaaSPayoutStatusUpdateJob::dispatch($parameters)->onQueue('nium_baas-payout');
                    }
                } else {
                    $this->warn("Command unsupported $template yet");
                }
                break;
        }

        return 0;
    }

    private function printColumns($parameters, $template, ?Application $application = null)
    {
        if (!empty($application)) {
            $this->info("Application name: $application?->name");
            $this->info("Application id: $application?->id");
            $this->info("Application uuid: $application?->uuid");
        }

        $this->info("Template: $template");

        $this->info('---------');

        foreach ($parameters as $key => $value) {
            $this->info("$key: $value");
        }
    }

    private function isConfirm(): bool
    {
        return $this->confirm('Please make sure all information is correct?');
    }
}
