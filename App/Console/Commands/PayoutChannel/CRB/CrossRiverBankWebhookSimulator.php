<?php

namespace App\Console\Commands\PayoutChannel\CRB;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Laravel\Reverb\Loggers\Log;

class CrossRiverBankWebhookSimulator extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crb-webhook:simulate
                        {action : Action to perform (send-mock-webhook|generate-signature).}
                        {--eventFile= : Event file with multiple webhook events}
                        {--url= : The url to post webhook payload}
                        ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate CRB webhooks: generate signature and send mock webhook events';

    protected const CRB_TIME_ZONE = 'America/New_York';
    protected const CRB_HTTP_HEADER = 'cos-signature';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = strtolower($this->argument('action'));
        $actions = [
            'generate-signature',
            'send-mock-webhook',
        ];

        if ((!in_array($action, $actions))) {
            $this->error('Please input correct mode.');

            $this->info('action');
            $this->info('    generate-signature: Generate a signature for the webhook.');
            $this->info('    send-mock-webhook: Send mock webhook events to the specified URL.');

            return 0;
        }

        return match ($action) {
            'generate-signature' => $this->handleGenerateSignature(),
            'send-mock-webhook' => $this->handleSendMockWebhook(),
             default => 1,
        };
    }

    private function handleGenerateSignature(): int
    {
        $eventFile = $this->option('eventFile');
        if (!$eventFile) {
            $this->error('Required: --eventFile option');

            return 1;
        }

        $payloads = $this->loadEventFile($eventFile);
        if (null === $payloads) {
            return 1;
        }

        if (!file_exists(__DIR__."/{$eventFile}")) {
            $this->error("Event file not found: {$eventFile}");

            return 1;
        }

        foreach ($payloads as $index => $payload) {
            if (!is_array($payload)) {
                $this->warn('Skipping entry #'.($index + 1).': not an array');
                continue;
            }

            try {
                $createdAt = now(self::CRB_TIME_ZONE)->toIso8601String();
                $encodedData = json_encode($payload, JSON_UNESCAPED_SLASHES);
                $signature = $this->generateHeaderSignature($encodedData, $createdAt);

                $this->info('Entry #'.($index + 1).':');
                $this->info("  Event: {$payload['eventName']}");
                $this->info("  CreatedAt: {$createdAt}");
                $this->info("  Signature: {$signature}");
                $this->newLine();
            } catch (\Throwable $e) {
                $this->error("Failed to generate signature for entry #{$index}: {$e->getMessage()}");
            }
        }

        return 0;
    }

    private function handleSendMockWebhook(): int
    {
        $eventFile = $this->option('eventFile');
        $url = $this->option('url');

        if (!$eventFile) {
            $this->error('Required: --eventFile option');

            return 1;
        }

        if (!$url) {
            $this->error('Required: --url option');

            return 1;
        }

        $payloads = $this->loadEventFile($eventFile);
        if (null === $payloads) {
            return 1;
        }

        $successCount = 0;
        $failureCount = 0;

        foreach ($payloads as $index => $payload) {
            if (!is_array($payload)) {
                $this->warn("Skipping entry #{$index}: not an array");
                ++$failureCount;
                continue;
            }

            try {
                $createdAt = now(self::CRB_TIME_ZONE)->toIso8601String();
                $payload['createdAt'] = $createdAt;
                $encodedData = json_encode($payload, JSON_UNESCAPED_SLASHES);
                $signature = $this->generateHeaderSignature($encodedData, $createdAt);

                $this->info("Sending webhook #{$index}: {$payload['eventName']}");

                if ($this->sendWebhook($url, $signature, $encodedData)) {
                    ++$successCount;
                    $this->info('  ✓ Success');
                } else {
                    ++$failureCount;
                    $this->error('  ✗ Failed');
                }

                $this->line('-------------------');
            } catch (\Throwable $e) {
                ++$failureCount;
                $this->error("Failed to send webhook #{$index}: {$e->getMessage()}");
                Log::error('[CRB webhook] Exception', ['index' => $index, 'error' => $e]);
            }
        }

        $this->newLine();
        $this->info("Results: {$successCount} succeeded, {$failureCount} failed");

        return $failureCount > 0 ? 1 : 0;
    }

    public function generateHeaderSignature($payload, $createdAt)
    {
        $secret = config('payoutchannel.crb.auth_info.webhook_secret');
        // header sample:
        // cos-signature:t:2019-04-02T11:33:26.6672036-04:00, v1:adT6JQ7y+fbL2a0uq4infc6VOX+VPyJizRTMSz158Vs=

        $data = $createdAt.'.'.$payload;

        // Decode the signing secret from base64
        $secretDecode = base64_decode($secret);

        // Create HMAC with SHA-256
        $hmacDigest = base64_encode(hash_hmac('sha256', $data, $secretDecode, true));

        $this->info('[CRB webhook]HMAC Signature: '.$hmacDigest);

        $timestamp = 't:'.$createdAt;
        $signature = ', v1:'.$hmacDigest;

        $header = $timestamp.$signature;

        return $header;
    }

    protected function sendWebhook($url, $header, $data)
    {
        Log::info('[CRB webhook]Sending %s request with url %s', $url);
        Log::info(('[CRB webhook]Request params= %s'.$data));

        try {
            $response = Http::/* dd() */ withHeaders([self::CRB_HTTP_HEADER => $header])
                    ->withBody($data, 'application/json')
                    ->post($url);

            if ($response->successful()) {
                $this->info("[CRB webhook]Webhook post to {$url} successfully!");
            }

            return $response;
        } catch (Exception $e) {
            $this->error('[CRB webhook]An error occurred: '.$e->getMessage());
        }
    }

    private function loadEventFile(string $filePath): ?array
    {
        $fullPath = __DIR__."/{$filePath}";

        if (!file_exists($fullPath)) {
            $this->error("Event file not found: {$filePath}");

            return null;
        }

        try {
            $contents = file_get_contents($fullPath);
            $payloads = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($payloads)) {
                $this->error('Event file must contain a JSON array');

                return null;
            }

            $this->info('Loaded '.count($payloads)." event(s) from {$filePath}");

            return $payloads;
        } catch (\JsonException $e) {
            $this->error("Invalid JSON in event file: {$e->getMessage()}");

            return null;
        }
    }
}
