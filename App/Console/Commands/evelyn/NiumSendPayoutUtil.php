<?php

namespace App\Console\Commands\evelyn;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class NiumSendPayoutUtil extends Command
{
    protected $signature = 'submit:dynamic-payouts';

    protected $description = 'Submit multiple payout requests with dynamic currencies, amounts, audit IDs, and purpose codes';

    private string $apiUrl = 'https://apisandbox.spend.nium.com/api/v2/client/66ec6e47-bceb-437f-bf09-c7537dad6b6b/customer/dabf654b-9062-4ac7-8060-8ff2c04077a5/payouts';

    private string $apiKey = 'your-api-key'; // 替換為你的 API 金鑰

    private array $purposeCodes = [
        'IR001' => 'Transfer to own account',
        'IR002' => 'Family Maintenance',
        'IR003' => 'Education-related student expenses',
        'IR004' => 'Medical Treatment',
        'IR005' => 'Hotel Accommodation',
        'IR006' => 'Travel',
        'IR007' => 'Utility Bills',
        'IR008' => 'Repayment of Loans',
        'IR009' => 'Tax Payment',
        'IR010' => 'Purchase of Residential Property',
        'IR011' => 'Payment of Property Rental',
        'IR012' => 'Insurance Premium',
        'IR013' => 'Product indemnity insurance',
        'IR014' => 'Insurance Claims Payment',
        'IR015' => 'Mutual Fund Investment',
        'IR016' => 'Investment in Shares',
        'IR017' => 'Donations',
        'IR020' => 'Salary',
        'IR01801' => 'Information Service Charges',
        'IR01802' => 'Advertising & Public relations-related expenses',
        'IR01803' => 'Royalty fees, trademark fees, patent fees, and copyright fees',
        'IR01804' => 'Fees for brokers, front end fee, commitment fee, guarantee fee and custodian fee',
        'IR01805' => 'Fees for advisors, technical assistance, and academic knowledge',
        'IR01806' => 'Representative office expenses',
        'IR01807' => 'Construction costs/expenses',
        'IR01808' => 'Transportation fees for goods',
        'IR01809' => 'For payment of exported goods',
        'IR01810' => 'Delivery fees for goods',
        'IR01811' => 'General Goods Trades - Offline trade',
    ];

    private array $currencies = [
        'JP' => 'JPY',
        'Europe' => 'EUR',
        'SG' => 'SGD',
        'US' => 'USD',
        'MY' => 'MYR',
        'HK' => 'HKD',
        'PH' => 'PHP',
    ];

    public function handle()
    {
        foreach ($this->currencies as $countryCode => $currencyCode) {
            foreach ($this->purposeCodes as $purposeCode => $description) {
                $destinationAmount = $this->generateDynamicAmount($countryCode);
                $auditId = $this->generateAuditId();
                $payload = $this->generatePayload($countryCode, $currencyCode, $purposeCode, $destinationAmount, $auditId);
                $this->submitPayload($payload, $countryCode, $currencyCode);
            }
        }
    }

    private function generatePayload(string $countryCode, string $currencyCode, string $purposeCode, float $destinationAmount, int $auditId): array
    {
        return [
            'beneficiary' => ['id' => '6743e654a69539d2b628a117'],
            'customerComments' => "{$countryCode} payout",
            'payout' => [
                'swiftFeeType' => 'SHA',
                'destination_currency' => $currencyCode,
                'destination_amount' => $destinationAmount,
                'audit_id' => $auditId,
            ],
            'purposeCode' => $purposeCode,
            'sourceOfFunds' => 'Business Owner/Shareholder',
        ];
    }

    private function generateDynamicAmount(string $countryCode): float
    {
        $amountRange = match ($countryCode) {
            'JP' => [10000, 1300],
            'Europe' => [8000, 100],
            'SG' => [5000, 100],
            'US' => [7000, 10],
            'MY' => [4000, 1000],
            'HK' => [6000, 100],
            'PH' => [3000, 1000],
            default => [5000, 1000],
        };

        return mt_rand($amountRange[0] * 100, $amountRange[1] * 100) / 100;
    }

    private function generateAuditId(): int
    {
        return random_int(1000000, 9999999);
    }

    private function submitPayload(array $payload, string $countryCode, string $currencyCode)
    {
        $client = new Client();

        try {
            $response = $client->post($this->apiUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->apiKey,
                    'x-client-name' => 'ExampleClient',
                ],
                'json' => $payload,
            ]);

            $responseBody = $response->getBody()->getContents();
            $this->info("Payout for {$countryCode} in {$currencyCode} with purpose {$payload['purposeCode']} submitted successfully.");
            $this->saveResponseToFile($countryCode, $currencyCode, $responseBody);
        } catch (RequestException $e) {
            $this->error("Error submitting payout for {$countryCode} in {$currencyCode}: ".$e->getMessage());
            $this->saveResponseToFile($countryCode, $currencyCode, $e->getMessage());
        }
    }

    private function saveResponseToFile(string $countryCode, string $currencyCode, string $response): void
    {
        $filename = "{$countryCode}_{$currencyCode}.json";
        Storage::disk('local')->put("responses/{$filename}", $response);
        $this->info("Response saved to responses/{$filename}");
    }
}
