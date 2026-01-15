<?php

namespace App\Console\Commands\PayoutChannel;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Infrastructure\VISA_VDA\ValueObjects\Requests\Payout\Detail\TransactionDetail;
use Infrastructure\VISA_VDA\ValueObjects\Requests\Payout\Recipient\IndividualRecipientDetail;
use Infrastructure\VISA_VDA\ValueObjects\Requests\Payout\Sender\IndividualSenderDetail;
use Infrastructure\VISA_VDA\VisaDirectAccountPayoutClient;
use InvalidArgumentException;
use Money\Currencies\ISOCurrencies;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use RuntimeException;
use Throwable;

// 請在PAYLOAD_RELATIVE_PATH設定之目錄下放入欲測試的json payload
// 例如：
// production/x_r2_visa_vda_production_tc_vda_myr.json
// production/x_r2_visa_vda_production_tc_vda_myr_002.json
// sandbox/0_visa_vda_sandbox_api_basic_individual.json
// sandbox/visa_vda_sandbox_argentina_id_type_l copy.json
class VisaVDAProdUtil extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visa:vda-prod-util
        {mode : The modes to process}
        ';

    private const VALID_MODES = [
        'validate_payout' => 'Validate request payloads for payout.',
        'send_payout' => 'Send payout.',
    ];

    private const PAYLOAD_RELATIVE_PATH = '/testing_data/visa_vda';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This tool is used to validate and send Visa Direct Account payout in production environment.';

    protected VisaDirectAccountPayoutClient $visaDirectAccountPayoutClient;

    protected $baseDir;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(VisaDirectAccountPayoutClient $visaDirectAccountPayoutClient)
    {
        parent::__construct();
        $this->visaDirectAccountPayoutClient = $visaDirectAccountPayoutClient;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $mode = $this->normalizeMode($this->argument('mode'));
        $this->baseDir = __DIR__.self::PAYLOAD_RELATIVE_PATH;

        if (!$this->isValidMode($mode)) {
            $this->displayModeHelp();

            return 1;
        }

        return $this->processPayout($mode);
    }

    private function normalizeMode(mixed $mode): string
    {
        return strtolower(trim((string) $mode));
    }

    private function isValidMode(string $mode): bool
    {
        return array_key_exists($mode, self::VALID_MODES);
    }

    private function displayModeHelp(): void
    {
        $this->error('[VDA] Please use the correct mode.');

        foreach (self::VALID_MODES as $key => $description) {
            $this->info("    {$key}: {$description}");
        }
    }

    private function processPayout(string $mode): int
    {
        $payload = $this->promptAndLoadPayload();
        if (!is_array($payload)) {
            $this->error('Failed to load payload file.');

            return 1;
        }

        $senderDetail = $payload['senderDetail'];
        $recipientDetail = $payload['recipientDetail'];
        $transactionDetail = $payload['transactionDetail'];

        $redisKey = strtolower($transactionDetail['transactionCurrencyCode']).'-txn-uuid';
        $this->info('Last client reference id: '.Redis::get($redisKey));
        $clientReferenceId = trim($this->ask('Please provide the Idempotency Key (Client Identifier)'));

        [$senderVO, $recipientVO, $txnVO] = $this->buildPayoutValueObjects(
            $senderDetail,
            $recipientDetail,
            $transactionDetail,
            $clientReferenceId,
            'USD',
        );

        // file_put_contents(__DIR__.'/visa_vda_prod_payout_request_'.$clientReferenceId.'_'.date('Ymd_His').'.json', json_encode([$senderVO, $recipientVO, $txnVO], JSON_PRETTY_PRINT));

        try {
            Redis::set($redisKey, $clientReferenceId);
            if ('validate_payout' === $mode) {
                $response = $this->visaDirectAccountPayoutClient->validatePayoutV3(
                    $senderVO,
                    $recipientVO,
                    $txnVO
                );
                $this->info(json_encode($response, JSON_PRETTY_PRINT));

                return 0;
            }

            $ledgerId = '2'; // 飛天牛在production上面的cash ledger id
            $this->info("Sending payout from cash ledger id {$ledgerId}...");
            $response = $this->visaDirectAccountPayoutClient->sendPayoutV3(
                ledgerId: $ledgerId,
                senderDetail: $senderVO,
                recipientDetail: $recipientVO,
                transactionDetail: $txnVO,
            );

            $this->info('============ Transaction Result ============');
            $this->info(
                json_encode(
                    $response->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                )
            );
            file_put_contents($this->baseDir.'/response/visa_vda_prod_payout_response_'.$clientReferenceId.'_'.date('Ymd_His').'.json', json_encode($response, JSON_PRETTY_PRINT));

            return 0;
        } catch (Throwable $e) {
            $this->error('Failed to process payout: '.$e->getMessage());
            Log::error('[VDA] Payout processing error', ['exception' => $e, 'payload' => $payload]);

            return 1;
        }
    }

    private function buildPayoutValueObjects(
        array $senderDetail,
        array $recipientDetail,
        array $transactionDetail,
        string $clientReferenceId,
        string $settlementCurrencyCode,
    ): array {
        // Parse transactionAmount: accept minor units (int) or decimal strings (e.g. "10.00")
        $amount = $transactionDetail['transactionAmount'];
        $currencyCode = $transactionDetail['transactionCurrencyCode'];
        $transactionDetail['transactionAmount'] = $this->parseTransactionAmount($amount, $currencyCode);
        $transactionDetail['clientReferenceId'] = $clientReferenceId;
        $transactionDetail['settlementCurrencyCode'] = $settlementCurrencyCode;

        return [
            IndividualSenderDetail::new($senderDetail),
            IndividualRecipientDetail::new($recipientDetail),
            TransactionDetail::new($transactionDetail),
        ];
    }

    private function promptLedgerId(array $payload): string
    {
        $defaultLedger = isset($payload['ledgerId']) ? (string) $payload['ledgerId'] : 'xxx';

        return trim($this->ask('Please provide the owlpay cash ledgerId id', $defaultLedger));
    }

    private function promptAndLoadPayload(): ?array
    {
        $fileMapping = $this->listExistingFiles();
        $choice = trim($this->ask('Please select a file by index: 1'));

        if (!isset($fileMapping[$choice])) {
            $this->error('Invalid index.');

            return [];
        }

        $selectedFile = $fileMapping[$choice];

        return $this->loadPayloadFromFile($selectedFile, $this->baseDir);
    }

    /**
     * Parse an amount and return a Money instance.
     * Supports minor units (integer or digit string) or decimal major-unit strings (e.g. "12.34").
     *
     * @param mixed $amount
     *
     * @throws InvalidArgumentException
     */
    private function parseTransactionAmount(float $amount, string $currencyCode): Money
    {
        $currencyCode = strtoupper(trim((string) ($currencyCode ?? '')));

        // Decimal string or float provided: parse as major units using DecimalMoneyParser
        $parser = new DecimalMoneyParser(new ISOCurrencies());
        try {
            return $parser->parse((string) $amount, $currencyCode);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Unable to parse amount for currency '.$currencyCode.': '.$e->getMessage(), 0, $e);
        }
    }

    protected function listExistingFiles(string $path = self::PAYLOAD_RELATIVE_PATH)
    {
        $baseDir = __DIR__.$path;

        $allFiles = glob($baseDir.'/*/*.json');
        $mapping = [];
        if (!empty($allFiles)) {
            $this->info('Available visa_vda payload files:');
            foreach ($allFiles as $i => $fullPath) {
                $i = (int) $i + 1;
                $rel = substr($fullPath, strlen($baseDir) + 1);

                // filter response
                if (str_contains($rel, 'response/')) {
                    continue;
                }
                $this->info(string: "  [{$i}] {$rel}");

                $mapping[$i] = $rel;
            }

            return $mapping;
        } else {
            $this->warn("No payload files found under: {$baseDir}");
        }

        return null;
    }

    protected function loadPayloadFromFile(string $file, ?string $path = self::PAYLOAD_RELATIVE_PATH): ?array
    {
        $this->info('The file you selected is '.$file);
        if (!is_dir($path)) {
            $this->warn('Base directory not found: '.$path);
            $this->warn('Please create the directory structure for payload files.');

            return null;
        }

        $matches = glob($path."/$file");

        if (empty($matches)) {
            throw new RuntimeException("File not found: $file");
        }

        $path = $matches[0];
        $this->info("Reading data from file: {$path}");

        $contents = file_get_contents($path);
        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON in file: {$path}");
        }

        return $decoded;
    }
}
