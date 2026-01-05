<?php

namespace App\Console\Commands\evelyn;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Infrastructure\VISA_VDA\ValueObjects\Requests\Payout\Detail\TransactionDetail;
use Infrastructure\VISA_VDA\ValueObjects\Requests\Payout\Recipient\IndividualRecipientDetail;
use Infrastructure\VISA_VDA\ValueObjects\Requests\Payout\Sender\IndividualSenderDetail;
use Infrastructure\VISA_VDA\VisaDirectAccountPayoutClient;
use InvalidArgumentException;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use RuntimeException;
use Throwable;

class VisaVDAProdUtil extends Command
{
    private const VALID_MODES = [
        'validate_payout' => 'Validate request payloads for payout.',
        'send_payout' => 'Send payout.',
    ];

    private const PAYLOAD_RELATIVE_PATH = '/testing_data/visa_vda';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visa:vda-prod-util
        {mode : The modes to process}
        ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This tool is used to validate and send Visa Direct Account payout in production environment.';

    protected VisaDirectAccountPayoutClient $visaDirectAccountPayoutClient;

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
        $this->listExistingFiles();
        $file = trim($this->ask('Given file name: visa_vda_sandbox_argentina.json'));
        $payload = $this->loadPayloadFromFile($file);

        if (!is_array($payload)) {
            $this->error('Failed to load payload file.');

            return 1;
        }

        $settlementCurrencyCode = trim($this->ask('Please provide the settlement currency code', 'USD'));
        $quoteId = trim($this->ask('Please provide the quote id'));

        $senderDetail = $payload['senderDetail'];
        $recipientDetail = $payload['recipientDetail'];
        $transactionDetail = $payload['transactionDetail'];

        $redisKey = strtolower($transactionDetail['transactionCurrencyCode']).'-txn-uuid';
        $this->info('Last client reference id: '.Redis::get($redisKey));
        $clientReferenceId = trim($this->ask('Please provide the client reference id'));

        // Parse transactionAmount: accept minor units (int) or decimal strings (e.g. "10.00")
        $amount = $transactionDetail['transactionAmount'];
        $currencyCode = $transactionDetail['transactionCurrencyCode'];
        $transactionDetail['transactionAmount'] = $this->parseTransactionAmount($amount, $currencyCode);
        $transactionDetail['clientReferenceId'] = $clientReferenceId;
        $transactionDetail['settlementCurrencyCode'] = $settlementCurrencyCode;
        $transactionDetail['quoteId'] = $quoteId ?: null;

        $senderVO = IndividualSenderDetail::new($senderDetail);
        $recipientVO = IndividualRecipientDetail::new($recipientDetail);
        $txnVO = TransactionDetail::new($transactionDetail);

        try {
            if ('validate_payout' === $mode) {
                $response = $this->visaDirectAccountPayoutClient->validatePayoutV3(
                    $senderVO,
                    $recipientVO,
                    $txnVO
                );
                $this->info(json_encode($response, JSON_PRETTY_PRINT));

                return 0;
            }

            $ledgerId = trim($this->ask('Please provide the owlpay cash ledgerId id', 'xxx'));
            $this->info("Sending payout from ledger {$ledgerId}...");

            $response = $this->visaDirectAccountPayoutClient->sendPayoutV3(
                ledgerId: $ledgerId,
                senderDetail: $senderVO,
                recipientDetail: $recipientVO,
                transactionDetail: $txnVO,
            );

            $this->info('============ transaction result ============');
            $this->info(json_encode($response, JSON_PRETTY_PRINT));

            return 0;
        } catch (Throwable $e) {
            $this->error('Failed to process payout: '.$e->getMessage());
            Log::error('[VDA] Payout processing error', ['exception' => $e, 'payload' => $payload]);

            return 1;
        }
    }

    /**
     * Parse an amount and return a Money instance.
     * Supports minor units (integer or digit string) or decimal major-unit strings (e.g. "12.34").
     *
     * @param mixed $amount
     *
     * @throws InvalidArgumentException
     */
    private function parseTransactionAmount($amount, string $currencyCode): Money
    {
        $currencyCode = strtoupper(trim((string) ($currencyCode ?? '')));

        // sanitize string amounts
        if (is_string($amount)) {
            $amount = str_replace(',', '', $amount);
        }

        // Minor units: integer or numeric string without decimals
        if (is_int($amount) || (is_string($amount) && ctype_digit($amount))) {
            return new Money((string) $amount, new Currency($currencyCode));
        }

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

        // 列出 visa_vda 底下所有 json 檔（顯示相對路徑）
        $allFiles = glob($baseDir.'/*/*.json');
        if (!empty($allFiles)) {
            $this->info('Available visa_vda payload files:');
            foreach ($allFiles as $i => $fullPath) {
                $rel = substr($fullPath, strlen($baseDir) + 1);
                $this->info("  [{$i}] {$rel}");
            }
        } else {
            $this->warn("No payload files found under: {$baseDir}");
        }
    }

    protected function loadPayloadFromFile(string $file): ?array
    {
        $baseDir = __DIR__.self::PAYLOAD_RELATIVE_PATH;

        if (!is_dir($baseDir)) {
            $this->warn('Base directory not found: '.$baseDir);
            $this->warn('Please create the directory structure for payload files.');

            return null;
        }

        $matches = glob($baseDir."/*/$file");

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
