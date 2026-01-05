<?php

namespace App\Console\Commands\evelyn;

use Domain\VISA_VDA\Enums\VisaDirectAccountEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Infrastructure\VISA_VDA\ValueObjects\Requests\AccountBalance\AccountBalanceRequest;
use Infrastructure\VISA_VDA\ValueObjects\Requests\ForeignExchange\ForeignExchangeRequest;
use Infrastructure\VISA_VDA\ValueObjects\Requests\Payout\Bank;
use Infrastructure\VISA_VDA\ValueObjects\Requests\Payout\Detail\TransactionDetail;
use Infrastructure\VISA_VDA\ValueObjects\Requests\Payout\Recipient\IndividualRecipientDetail;
use Infrastructure\VISA_VDA\ValueObjects\Requests\Payout\Recipient\PayoutMetaDataDetail;
use Infrastructure\VISA_VDA\ValueObjects\Requests\Payout\Sender\IndividualSenderDetail;
use Infrastructure\VISA_VDA\ValueObjects\Requests\PostingCalendar\PostingCalendarRequest;
use Infrastructure\VISA_VDA\VisaDirectAccountPayoutClient;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use ReflectionClass;
use RuntimeException;

class VisaVDAUtil extends Command
{
    private const VALID_MODES = [
        'fetch_fx_rates_with_source_amount' => 'Retrieve fx rate with source amount.',
        'fetch_fx_rates_with_destination_amount' => 'Retrieve fx rate with destination amount.',
        'fetch_fx_rates_with_quote_id_required' => 'Retrieve fx rate with quote ID',
        'fetch_account_balance' => ' Retrieve account balance of account.',
        'fetch_posting_calendar' => 'Retrieve posting calendar.',
        'validate_payout' => 'Validate payout details.',
        'send_payout' => 'Create payout.',
        'query_payout_by_cid' => 'Retrieve payout by client reference id.',
        'query_payout_by_pid' => 'Retrieve payout by payout id.',
        'cancel_payout_by_cid' => 'Cancel payout by client reference id',
        'cancel_payout_by_pid' => 'Cancel payout by payout id',
        'get_meta_data' => 'Get metadata',
    ];

    private const PAYLOAD_RELATIVE_PATH = '/testing_data/visa_vda';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visa:vda-util {mode : The modes to process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Visa VDA Utility Command Line Tool';

    protected VisaDirectAccountPayoutClient $visaDirectAccountPayoutClient;

    // currency code mapping
    protected $currencyCodeMap = [
        'argentina' => 'ARS',
        'mexico' => 'MXN',
        'colombia' => 'COP',
        'india' => 'INR',
        'peru' => 'PEN',
        'japan' => 'JPY',
        'spain' => 'EUR',
        'canada' => 'CAD',
        'korea' => 'KRW',
    ];

    // country code mapping
    protected $countryCodeMap = [
        'argentina' => 'ARG',
        'mexico' => 'MEX',
        'colombia' => 'COL',
        'india' => 'IND',
        'peru' => 'PER',
        'japan' => 'JPN',
        'spain' => 'ESP',
        'canada' => 'CAN',
        'korea' => 'KOR',
    ];

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

        switch ($mode) {
            case 'validate_payout':
            case 'send_payout':
                return $this->processPayout($mode);
            case 'fetch_account_balance':  // OK
                $accountBalanceRequest = AccountBalanceRequest::new([
                    // 'currencyCode' => 'USD', // 註解可測全部幣別
                ]);
                $rst = $this->visaDirectAccountPayoutClient->fetchAccountBalanceV3($accountBalanceRequest);

                dd($rst->toArray());
                // foreach ($rst as $r) {
                //     $this->info('==== currency '.$r['balance']['currencyCode'].' ====');
                //     $this->info('account id: '.$r['accountId']);
                //     $this->info('account  '.$r['balance']['amount']);
                //     $this->info('timestamp: '.$r['balanceTimestamp']);
                //     $this->info('last transaction timestamp: '.$r['lastTransactionTimestamp']);
                // }
                break;
            case 'fetch_posting_calendar':  // OK
                $postingCalendarRequest = PostingCalendarRequest::new(
                    [
                        'recipientBankCountryCode' => 'ARG',
                    ]
                );
                $rst = $this->visaDirectAccountPayoutClient->fetchPostingCalendarV3($postingCalendarRequest);

                dd($rst);
                // $this->info('cutOffDateTime: '.$rst['postingCalendar'][0]['cutOffDateTime']);
                // $this->info('expectedPostingDate: '.$rst['postingCalendar'][0]['expectedPostingDate']);

                break;
            case 'fetch_fx_rates_with_source_amount': // OK
                // TODO: 'TWD' 可以測試 sourceCurrency TWD 可以測出4
                $sourceCurrencyCode = trim($this->ask('Please provide the source currency code'));
                $destinationCurrencyCode = trim($this->ask('Please provide the destination currency code'));
                $sourceAmount = trim($this->ask('Please provide the source amount'));

                $foreignExchange = ForeignExchangeRequest::new(
                    [
                        'sourceCurrencyCode' => $sourceCurrencyCode, // Required. Destination amount 3-alpha currency code in ISO 4217. Example: "GBP"
                        // 'sourceCurrencyCode' => 'TWD', // Required. Destination amount 3-alpha currency code in ISO 4217. Example: "GBP"
                        'rateProductCode' => 'BANK',         // Required. Indicates which rate source is to be used. Enum: "BANK" or "WALLET"
                        'destinationCurrencyCode' => $destinationCurrencyCode,      // Required. Source amount 3-alpha currency code in ISO 4217. Example: "USD"
                        'sourceAmount' => new Money($sourceAmount, new Currency($sourceCurrencyCode)),
                        'quoteIdRequired' => true,
                    ]
                );

                $rst = $this->visaDirectAccountPayoutClient->fetchForeignExchangeRatesWithSourceAmountV2($foreignExchange);
                // Initialize ISOCurrencies to get currency subunits dynamically
                $currencies = new ISOCurrencies();

                // Use DecimalMoneyFormatter for correct formatting based on currency subunit
                $formatter = new DecimalMoneyFormatter($currencies);
                $this->info('destinationAmount: '.$formatter->format($rst->destinationAmount));
                $this->info('sourceAmount: '.$formatter->format($rst->sourceAmount));
                $this->info('rateProductCode: '.$rst->rateProductCode);
                $this->info('sourceCurrencyCode: '.$rst->sourceCurrencyCode);
                $this->info('destinationCurrencyCode: '.$rst->destinationCurrencyCode);
                $exchange = $rst->conversionRate;
                $sourceCurrencyCode = $rst->sourceCurrencyCode;
                $destinationCurrencyCode = $rst->destinationCurrencyCode;
                $reflection = new ReflectionClass($exchange);
                $property = $reflection->getProperty('list');
                $property->setAccessible(true);
                $list = $property->getValue($exchange);
                $this->info('conversionRate: '.$list[$sourceCurrencyCode][$destinationCurrencyCode]);
                break;
            case 'fetch_fx_rates_with_destination_amount': // OK
                $sourceCurrencyCode = trim($this->ask('Please provide the source currency code'));
                $destinationCurrencyCode = trim($this->ask('Please provide the destination currency code'));
                $destinationAmount = trim($this->ask('Please provide the destination amount'));

                $foreignExchange = ForeignExchangeRequest::new(
                    [
                        'sourceCurrencyCode' => $sourceCurrencyCode, // Required. Destination amount 3-alpha currency code in ISO 4217. Example: "GBP"
                        // 'sourceCurrencyCode' => 'TWD', // Required. Destination amount 3-alpha currency code in ISO 4217. Example: "GBP"
                        'rateProductCode' => 'BANK',         // Required. Indicates which rate source is to be used. Enum: "BANK" or "WALLET"
                        'destinationCurrencyCode' => $destinationCurrencyCode,      // Required. Source amount 3-alpha currency code in ISO 4217. Example: "USD"
                        'destinationAmount' => new Money($destinationAmount, new Currency($destinationCurrencyCode)),
                        // [VDA] Client error 400 for fetchForeignExchangeRatesWithDestinationAmountV2. Reason: 3003. Message: Invalid Schema. Details: [{"location":"destinationAmount","message":"destinationAmount is invalid"}].
                    ]
                );

                $rst = $this->visaDirectAccountPayoutClient->fetchForeignExchangeRatesWithDestinationAmountV2($foreignExchange);

                // Initialize ISOCurrencies to get currency subunits dynamically
                $currencies = new ISOCurrencies();

                // Use DecimalMoneyFormatter for correct formatting based on currency subunit
                $formatter = new DecimalMoneyFormatter($currencies);

                $this->info('destinationAmount: '.$formatter->format($rst->destinationAmount));
                $this->info('sourceAmount: '.$formatter->format($rst->sourceAmount));
                $this->info('rateProductCode: '.$rst->rateProductCode);
                $this->info('sourceCurrencyCode: '.$rst->sourceCurrencyCode);
                $this->info('destinationCurrencyCode: '.$rst->destinationCurrencyCode);
                $exchange = $rst->conversionRate;
                $sourceCurrencyCode = $rst->sourceCurrencyCode;
                $destinationCurrencyCode = $rst->destinationCurrencyCode;
                $reflection = new ReflectionClass($exchange);
                $property = $reflection->getProperty('list');
                $property->setAccessible(true);
                $list = $property->getValue($exchange);
                $this->info('conversionRate: '.$list[$sourceCurrencyCode][$destinationCurrencyCode]);
                break;
            case 'fetch_fx_rates_with_quote_id_required': // OK
                $sourceCurrencyCode = trim($this->ask('Please provide the source currency code: '));
                $destinationCurrencyCode = trim($this->ask('Please provide the destination currency code: '));

                $foreignExchange = ForeignExchangeRequest::new(
                    [
                        'sourceCurrencyCode' => $sourceCurrencyCode, // Required. Destination amount 3-alpha currency code in ISO 4217. Example: "GBP"
                        'rateProductCode' => 'BANK',         // Required. Indicates which rate source is to be used. Enum: "BANK" or "WALLET"
                        'destinationCurrencyCode' => $destinationCurrencyCode,      // Required. Source amount 3-alpha currency code in ISO 4217. Example: "USD"
                        // 'destinationAmount' => new Money('10000', new Currency('MXN')),
                    ]
                );

                $rst = $this->visaDirectAccountPayoutClient->fetchForeignExchangeRatesWithQuoteIdRequiredV2($foreignExchange, true);

                $this->info('rateProductCode: '.$rst->rateProductCode);
                $this->info('sourceCurrencyCode: '.$rst->sourceCurrencyCode);
                $this->info('destinationCurrencyCode: '.$rst->destinationCurrencyCode);
                $exchange = $rst->conversionRate;
                $sourceCurrencyCode = $rst->sourceCurrencyCode;
                $destinationCurrencyCode = $rst->destinationCurrencyCode;
                $reflection = new ReflectionClass($exchange);
                $property = $reflection->getProperty('list');
                $property->setAccessible(true);
                $list = $property->getValue($exchange);
                $this->info('conversionRate: '.$list[$sourceCurrencyCode][$destinationCurrencyCode]);
                $this->info('quoteId: '.$rst->quoteId);
                $this->info('quoteIdExpiryDateTime: '.$rst->quoteIdExpiryDateTime);
                break;
            case 'query_payout_by_cid':
                $clientReferenceId = trim($this->ask('Please provide the client reference id'));
                $rst = $this->visaDirectAccountPayoutClient->fetchPayoutByClientReferenceIdV3($clientReferenceId);

                dd($rst);

                // {
                //     "transactionDetail": {
                //         "initiatingPartyId": 1002,
                //         "payoutId": "172293713063281",
                //         "clientReferenceId": "1722937128566",
                //         "expectedPostingDate": "2022-11-16",
                //         "transactionDateTime": "2024-08-06T09:38:50.000Z",
                //         "status": "PAYMENT_RECEIVED",
                //         "transactionAmount": 1.5,
                //         "transactionCurrencyCode": "GBP",
                //         "destinationAmount": 1557,
                //         "destinationCurrencyCode": "GBP",
                //         "settlementAmount": 1557,
                //         "settlementCurrencyCode": "GBP",
                //         "fxConversionRate": 1,
                //         "payoutSpeed": "STANDARD"
                //     }
                // }
                break;
            case 'query_payout_by_pid':
                // clientReferenceId:, 1722936221570
                // payoutSpeed:, STANDARD
                // expectedPostingDate:, 2022-11-16
                // transactionAmount:, 1.5
                // transactionCurrencyCode:, GBP
                // destinationAmount:, 1557
                // destinationCurrencyCode:, GBP
                // fxConversionRate:, 1
                // initiatingPartyId:, 1002
                // payoutId:, 172293622302364
                // settlementAmount:, 1557
                // settlementCurrencyCode:, GBP
                // transactionDateTime:, 2022-11-16T10:36:07.000Z
                // status:, PAYMENT_RECEIVED

                $payoutId = '173476963945797';
                $payoutId = trim($this->ask('Please provide the client payoutId id'));
                $rst = $this->visaDirectAccountPayoutClient->fetchPayoutByPayoutIdV3($payoutId);

                dd($rst);

                break;
            case 'cancel_payout_by_cid':
                $clientReferenceId = 'EVELYN-TEST--0012';
                $clientReferenceId = trim($this->ask('Please provide the client reference id'));

                $rst = $this->visaDirectAccountPayoutClient->cancelPayoutByClientReferenceIdV3('1111', $clientReferenceId);
                dd($rst);
                break;
            case 'cancel_payout_by_pid':
                $payoutId = '281474989427295';
                $payoutId = trim($this->ask('Please provide the payout id: '));

                $rst = $this->visaDirectAccountPayoutClient->cancelPayoutByPayoutIdV3('2222', $payoutId);

                dd($rst);

                break;

            case 'get_meta_data':
                $recipientCountryCode = trim($this->ask('Please provide the recipient country code'));
                $recipientCurrencyCode = trim($this->ask('Please provide the recipient currency code'));
                $detail = PayoutMetaDataDetail::new([
                    'payoutMethod' => VisaDirectAccountEnum::PAYOUT_METHOD_VDA,
                    'recipientCountryCode' => $recipientCountryCode,
                    'recipientCurrencyCode' => $recipientCurrencyCode,
                ]);

                $rst = $this->visaDirectAccountPayoutClient->fetchPayoutMetaData($detail);

                dd($rst);
                break;

            default:
                $this->error("Unexpected mode: $mode");
                break;
        }

        return 0;
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

        $sender = IndividualSenderDetail::new($senderDetail);
        $recipient = IndividualRecipientDetail::new($recipientDetail);
        $transaction = TransactionDetail::new($transactionDetail);

        Redis::set($redisKey, $clientReferenceId);
        if ('validate_payout' === $mode) {
            $response = $this->visaDirectAccountPayoutClient->validatePayoutV3(
                $sender,
                $recipient,
                $transaction
            );
            $this->info(json_encode($response, JSON_PRETTY_PRINT));

            return 0;
        }

        $ledgerId = $payload['ledgerId'] ?? 1111;
        $response = $this->visaDirectAccountPayoutClient->sendPayoutV3(
            ledgerId: $ledgerId,
            senderDetail: $sender,
            recipientDetail: $recipient,
            transactionDetail: $transaction,
        );
        $this->info('============ transaction result ============');
        $this->info(json_encode($response, JSON_PRETTY_PRINT));

        return 0;
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

    protected function loadPayloadFromFile(?string $file = null, string $path = self::PAYLOAD_RELATIVE_PATH): ?array
    {
        $baseDir = __DIR__.$path;

        if (!is_dir($baseDir)) {
            $this->warn('Base directory not found: '.$baseDir);

            return null;
        }

        $matches = glob($baseDir."/*/$file");

        if (empty($matches)) {
            throw new RuntimeException("File not found: $file");
        }

        $path = $matches[0];
        $this->info("Reading data from file: $path");
        $contents = file_get_contents($path);

        if (is_null($contents)) {
            $this->warn('No payload loaded.');

            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON in file: $path");
        }

        return $decoded;
    }

    /**
     * Parse an amount and return a Money instance.
     * Supports minor units (integer or digit string) or decimal major-unit strings (e.g. "12.34").
     *
     * @param mixed $amount
     *
     * @throws \InvalidArgumentException
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
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Unable to parse amount for currency '.$currencyCode.': '.$e->getMessage(), 0, $e);
        }
    }
}

// ============ transactionDetail ============
// clientReferenceId: 1730378227427
// payoutSpeed: STANDARD
// expectedPostingDate: 2022-11-16-
// transactionAmount: 1.5
// transactionCurrencyCode: GBP
// destinationAmount: 1557
// destinationCurrencyCode: GBP
// fxConversionRate: 1
// initiatingPartyId: 1002
// payoutId: 173037822876747
// settlementAmount: 1557
// settlementCurrencyCode: GBP
// transactionDateTime: 2022-11-16T10:36:07.000Z
// status: PAYMENT_RECEIVED

//     "transactionDetail": {
//         "clientReferenceId": "1722935892258",
//         "payoutSpeed": "STANDARD",
//         "expectedPostingDate": "2022-11-16",
//         "transactionAmount": 1.5,
//         "transactionCurrencyCode": "GBP",
//         "destinationAmount": 1557,
//         "destinationCurrencyCode": "GBP",
//         "fxConversionRate": 1,
//         "initiatingPartyId": 1002,
//         "payoutId": "172293589424950",
//         "settlementAmount": 1557,
//         "settlementCurrencyCode": "GBP",
//         "transactionDateTime": "2022-11-16T10:36:07.000Z",
//         "status": "PAYMENT_RECEIVED"

// clientReferenceId: 1730378227427
// payoutSpeed: STANDARD
// expectedPostingDate: 2022-11-16
// transactionAmount: 1.5
// transactionCurrencyCode: GBP
// destinationAmount: 1557
// destinationCurrencyCode: GBP
// fxConversionRate: 1
// initiatingPartyId: 1002
// payoutId: 173037822876747
// settlementAmount: 1557
// settlementCurrencyCode: GBP
// transactionDateTime: 2022-11-16T10:36:07.000Z
// status: PAYMENT_RECEIVED

// ============ transactionDetail ============
// clientReferenceId: 1730533799938
// payoutSpeed: STANDARD
// expectedPostingDate: 2022-11-16
// transactionAmount: 1.5
// transactionCurrencyCode: GBP
// destinationAmount: 1557
// destinationCurrencyCode: GBP
// fxConversionRate: 1
// initiatingPartyId: 1002
// payoutId: 173053380150222
// settlementAmount: 1557
// settlementCurrencyCode: GBP
// transactionDateTime: 2022-11-16T10:36:07.000Z
// status: PAYMENT_RECEIVED

// ============ transactionDetail ============
// clientReferenceId: 20241102083846
// payoutSpeed: STANDARD
// expectedPostingDate: 2022-11-16
// transactionAmount: 100
// transactionCurrencyCode: GBP
// destinationAmount: 1557
// destinationCurrencyCode: GBP
// fxConversionRate: 1
// initiatingPartyId: 1002
// payoutId: 173053672770791
// settlementAmount: 1557
// settlementCurrencyCode: GBP
// transactionDateTime: 2022-11-16T10:36:07.000Z
// status: PAYMENT_RECEIVED

// ============ transactionDetail ============
// clientReferenceId: 20241102092634
// payoutSpeed: STANDARD
// expectedPostingDate: 2022-11-16
// transactionAmount: 100
// transactionCurrencyCode: GBP
// destinationAmount: 1557
// destinationCurrencyCode: GBP
// fxConversionRate: 1
// initiatingPartyId: 1002
// payoutId: 173053959652775
// settlementAmount: 1557
// settlementCurrencyCode: GBP
// transactionDateTime: 2022-11-16T10:36:07.000Z
// status: PAYMENT_RECEIVED
