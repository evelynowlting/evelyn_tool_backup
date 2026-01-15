<?php

namespace App\Console\Commands\PayoutChannel;

use Domain\CRB\Enums\CrossRiverBankCustomerEnum;
use Domain\CRB\Enums\CrossRiverBankEnum;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Infrastructure\CRB\CRBClient;
use Infrastructure\CRB\ValueObjects\Common\IndividualName;
use Infrastructure\CRB\ValueObjects\Common\IndividualProfile;
use Infrastructure\CRB\ValueObjects\Common\PrimaryAddress;
use Infrastructure\CRB\ValueObjects\Common\PrimaryEmail;
use Infrastructure\CRB\ValueObjects\Common\PrimaryIdentification;
use Infrastructure\CRB\ValueObjects\Common\PrimaryPhone;
use Infrastructure\CRB\ValueObjects\Requests\ACH\ACHExternalAccountInfo;
use Infrastructure\CRB\ValueObjects\Requests\ACH\ACHOriginatorInfo;
use Infrastructure\CRB\ValueObjects\Requests\ACH\ACHPayoutDetail;
use Infrastructure\CRB\ValueObjects\Requests\Core\BookTransfer;
use Infrastructure\CRB\ValueObjects\Requests\Core\CustomerOnboarding\BusinessCustomer;
use Infrastructure\CRB\ValueObjects\Requests\Core\CustomerOnboarding\Customer;
use Infrastructure\CRB\ValueObjects\Requests\Core\CustomerOnboarding\IndividualCustomer;
use Infrastructure\CRB\ValueObjects\Requests\Core\Filters\AccountBalanceFilter;
use Infrastructure\CRB\ValueObjects\Requests\Core\Filters\AchListFilter;
use Infrastructure\CRB\ValueObjects\Requests\Core\Filters\MasterAccountActivityFilter;
use Infrastructure\CRB\ValueObjects\Requests\Core\Filters\RTPFilter;
use Infrastructure\CRB\ValueObjects\Requests\Core\Filters\SubledgerFilter;
use Infrastructure\CRB\ValueObjects\Requests\Core\Filters\WirePayoutFilter;
use Infrastructure\CRB\ValueObjects\Requests\Core\Subledger\CreateSubledger;
use Infrastructure\CRB\ValueObjects\Requests\Core\Subledger\SubledgerProfile;
use Infrastructure\CRB\ValueObjects\Requests\Core\Subledger\SubledgerRestriction;
use Infrastructure\CRB\ValueObjects\Requests\RealTimePayment\RTPBeneficiaryInfo;
use Infrastructure\CRB\ValueObjects\Requests\RealTimePayment\RTPPayoutInfo;
use Infrastructure\CRB\ValueObjects\Requests\RealTimePayment\RTPRemitterInfo;
use Infrastructure\CRB\ValueObjects\Requests\Wire\WireBeneficiaryInfo;
use Infrastructure\CRB\ValueObjects\Requests\Wire\WirePayoutDetail;
use Infrastructure\CRB\ValueObjects\Requests\Wire\WireRemitterInfo;
use Money\Currency;
use Money\Money;
use RuntimeException;

// 請在CRB_PAYLOAD_RELATIVE_PATH設定之目錄下放入欲測試的json payload
// 例如：
// production/crb_wire_prod_andrew_tc_prod_wire_006.json
// production/crb_wire_prod_gina_tc_prod_wire_007.json
// sandbox/crb_ach_pull_sandbox.json
// sandbox/crb_ach_push_sandbox.json
class CrossRiverBankUtil extends Command
{
    private const VALID_ACTIONS = [
        // Access token
        'get_access_token',

        // Customer management
        'get_customer_list',
        'get_customer_list_by_type',

        // Ledger management
        'create_subledger',
        'fetch_subledger',
        'fetch_subledger_list',
        'fetch_subledger_balance_history',

        // internal transfer
        'book_transfer',

        // ach
        'fetch_ach_list',
        'fetch_ach_by_id',
        'create_ach_pull',
        'create_ach_push',
        'cancel_ach',

        // wire
        'create_wire_payout',
        'simulate_inbound_wire',
        'cancel_wire_payout',
        'get_wire_payout_by_id',
        'get_wire_payouts',
        'revert_inbound_wire',
        'release_balance_by_act',
        'lock_balance_by_act',

        // fetch balance or account activity
        'fetch_master_balance_history',
        'fetch_master_account_activity',

        // rtp
        'create_micro_deposit',
        'fetch_rtp_list',
        'fetch_transaction_by_id',
        'fetch_micro_deposit_by_id',
        'create_rtp',
        'fetch_rtp_service_info',

        // customer
        'fetch_customer_by_id',
        'onboard_c_customer',
        'onboard_b_customer',

        'update_customer_profile',
        'add_customer_address',
        'add_customer_email',
        'add_customer_phone_number',
        'update_customer_email',
        'update_customer_name',
        'add_customer_identification',
        'update_customer_identification',
        'add_restriction_of_subledger',
        'add_beneficiary_profile_of_subledger',
        'close_subledger',
        'reopen_subledger',
        'update_title_of_subledger',
        'remove_restriction_of_subledger',

        // balance
        'release_balance_by_cid',
        'lock_balance_by_cid',
    ];

    private const CRB_PAYLOAD_RELATIVE_PATH = '/testing_data/crb';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crb:util  {action : Action to perform (register, delete, get_list...).}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cross River Bank API Tool';

    protected CRBClient $crbClient;

    protected $baseDir;
    protected $redisKey;

    private const CRB_CLIENT_IDENTIFIER_REDIS_KEY = 'crb-clientIdentifier';

    public function __construct(
        CRBClient $crbClient,
    ) {
        parent::__construct();
        $this->crbClient = $crbClient;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->baseDir = __DIR__.self::CRB_PAYLOAD_RELATIVE_PATH;
        $this->redisKey = self::CRB_CLIENT_IDENTIFIER_REDIS_KEY.':'.env('APP_ENV');
        $action = $this->normalizeAction($this->argument('action'));

        if (!$this->isValidAction($action)) {
            $this->displayActionHelp();

            return 0;
        }

        if ('get_access_token' == $action) {
            $access_token = $this->crbClient->_getAuthToken();
            dd($access_token);
        }

        if ('get_customer_list' == $action) {
            $list = $this->crbClient->fetchCustomerList();
            dd($list);
        }

        if ('get_customer_list_by_type' == $action) {
            $type = CrossRiverBankCustomerEnum::CLASSIFICATION_TYPE_PERSONAL;
            $list = $this->crbClient->fetchCustomerList($type);
            dd($list);
        }

        if ('create_subledger' == $action) {
            $jsonData = $this->promptAndLoadPayload();
            $clientIdentifier = $this->setClientIdentifier($action);
            Redis::set($this->redisKey, $clientIdentifier);

            // if ($isDryRun) {
            //     return $jsonData;
            // }

            if (isset($jsonData['beneficiary']['firstName']) && isset($jsonData['beneficiary']['lastName'])) {
                $classification = CrossRiverBankCustomerEnum::CLASSIFICATION_TYPE_PERSONAL;
                $subledgerProfile = [
                    'firstName' => $jsonData['beneficiary']['firstName'],
                    'lastName' => $jsonData['beneficiary']['lastName'],
                    'countryCode' => $jsonData['beneficiary']['countryCode'],
                    'state' => $jsonData['beneficiary']['state'],
                    'city' => $jsonData['beneficiary']['city'],
                    'streetAddress1' => $jsonData['beneficiary']['streetAddress1'],
                ];
            }
            if (
                isset($jsonData['beneficiary']['entityName'])
                && !isset($jsonData['beneficiary']['firstName'])
                && !isset($jsonData['beneficiary']['lastName'])
            ) {
                $classification = CrossRiverBankCustomerEnum::CLASSIFICATION_TYPE_BUSINESS;
                $subledgerProfile = [
                    'entityName' => $jsonData['beneficiary']['entityName'],
                    'countryCode' => $jsonData['beneficiary']['countryCode'],
                    'state' => $jsonData['beneficiary']['state'],
                    'city' => $jsonData['beneficiary']['city'],
                    'streetAddress1' => $jsonData['beneficiary']['streetAddress1'],
                ];
            }

            $createSubledger = CreateSubledger::new($classification, [
                'customerId' => $jsonData['customerId'],
                'title' => $jsonData['title'],
                'clientIdentifier' => $jsonData['clientIdentifier'] ?? $clientIdentifier,
                'subledgerProfile' => $subledgerProfile,
            ]);

            $rst = $this->crbClient->createSubledger($classification, $createSubledger);
            dd($rst);
        }

        if ('add_beneficiary_profile_of_subledger' == $action) {
            $subledgerAccountNumber = trim($this->ask('Given the subledger account number: 329870033282'));
            $subledgerProfile = SubledgerProfile::new(
                CrossRiverBankCustomerEnum::CLASSIFICATION_TYPE_PERSONAL,
                [
                    'firstName' => 'R5User911Au',
                    'lastName' => 'OwlPayCash',
                    'streetAddress2' => 'streetAddress2',
                ]
            );
            // $rst = $this->crbClient->updateBeneficiaryProfileOfSubledger($subledgerAccountNumber, $subledgerProfile);
            // dd($rst);
        }

        if ('close_subledger' == $action) {
            $subledgerAccountNumber = trim($this->ask('Given the subledger account number: 329870033282'));
            $rst = $this->crbClient->closeSubledger($subledgerAccountNumber);
            dd($rst);
        }

        if ('reopen_subledger' == $action) {
            $subledgerAccountNumber = trim($this->ask('Given the subledger account number: 329870033282'));
            $rst = $this->crbClient->reopenSubledger($subledgerAccountNumber);
            dd($rst);
        }

        if ('update_title_of_subledger' == $action) {
            $rst = $this->crbClient->updateTitleOfSubledger('303496555960', 'owlpay_user_001');
            dd($rst);
        }

        if ('remove_restriction_of_subledger' == $action) {
            $restrictionId = trim($this->ask('Given the restriction id: '));
            $rst = $this->crbClient->removeRestrictionOfSubledger($restrictionId);
            dd($rst);
        }

        if ('add_restriction_of_subledger' == $action) {
            $subledgerAccountNumber = trim($this->ask('Given the subledger account number: 329870033282'));
            $title = trim($this->ask('Given the subledger title: R5User91Au OwlPayCash'));
            $subledgerRestriction = SubledgerRestriction::new([
                // 'subAccountNumber' => '342229037037',
                'subAccountNumber' => $subledgerAccountNumber,
                'appliesTo' => 'Account', // when subAccountNumber is included in the body, appliesTo should be set to "Account"
                'amountThreshold' => new Money(0, new Currency('USD')),
                'reason' => "Account Freeze $title",
            ]);

            // $subledgerRestriction = SubledgerRestriction::new([
            //     // 'subAccountNumber' => '342229037037',
            //     'subAccountNumber' => $subledgerAccountNumber,
            //     'appliesTo' => 'Account', // when subAccountNumber is included in the body, appliesTo should be set to "Account"
            //     'amountThreshold' => new Money(0, new Currency('USD')),
            //     'reason' => 'Account Freeze R5User911B OwlPayCash',
            // ]);

            $rst = $this->crbClient->addRestrictionOfSubledger($subledgerRestriction);
            dd($rst);
        }

        if ('add_customer_address' == $action) {
            $customerId = trim($this->ask('Given the customer id: 6e8bdc9f-17b1-4e79-8b0f-b35500967295'));
            $address = PrimaryAddress::new(
                [
                    'addressType' => 'Home',
                    'classification' => 'Residential',
                    'isPrimary' => true,
                    'street1' => '123 Main St',
                    'street2' => 'Apt 4B',
                    'street3' => '',
                    'city' => 'Anytown',
                    'state' => 'CA',
                    'postalCode' => '12345',
                    'countryCode' => 'US',
                ]
            );

            // $rst = $this->crbClient->addCustomerAddress($customerId, $address);

            // dd($rst);
        }

        if ('add_customer_email' == $action) {
            $customerId = trim($this->ask('Given the customer id: 6e8bdc9f-17b1-4e79-8b0f-b35500967295'));
            $email = PrimaryEmail::new(
                [
                    'isPrimary' => true,
                    'emailType' => 'Personal',
                    'emailAddress' => 'r5user001_2@owlting.com',
                ]
            );

            // $rst = $this->crbClient->addCustomerEmail($customerId, $email);

            // dd($rst);
        }

        if ('add_customer_phone_number' == $action) {
            $customerId = trim($this->ask('Given the customer id: 6e8bdc9f-17b1-4e79-8b0f-b35500967295'));
            $phone = PrimaryPhone::new([
                'isPrimary' => false,
                'phoneType' => 'Home',
                'phoneNumber' => '1234123412341234',
                'extension' => '123',
                'notes' => 'home phone 2',
            ]);
            // $rst = $this->crbClient->addCustomerPhoneNumber($customerId, $phone);
            // dd($rst);
        }

        if ('update_customer_email' == $action) {
            $email = trim($this->ask('Given the email.'));

            // $rst = $this->crbClient->updateCustomerEmail('6e8bdc9f-17b1-4e79-8b0f-b35500967295', $individualName);
            // dd($rst);
        }

        if ('update_customer_name' == $action) {
            $firstName = trim($this->ask('Given the first name.'));
            $lastName = trim($this->ask('Given the last name.'));
            $individualName = IndividualName::new(
                [
                    'firstName' => 'R5User911Au',
                    'lastName' => 'OwlPayCash',
                ]
            );
            $rst = $this->crbClient->updateCustomerName('6e8bdc9f-17b1-4e79-8b0f-b35500967295', $individualName);
            dd($rst);
        }

        if ('add_customer_identification' == $action) {
            $customerId = trim($this->ask('Given the customer id: 6e8bdc9f-17b1-4e79-8b0f-b35500967295'));
            $primaryIdentification = PrimaryIdentification::new(
                [
                    // "isPrimary" => true11,
                    'idNumber' => 'D11112',
                    'idType' => 'DriversLicense',
                    // "issuedDate" => "2002-04-14",
                    // "verifiedDate" => "2023-08-18",
                    'issuingAuthority' => 'Department of Motor Vehicles',
                    'issuingStateOrProvince' => 'NJ',
                    'issuingCountryCode' => 'US',
                ]
            );

            // $rst = $this->crbClient->addCustomerIdentification($customerId, $primaryIdentification);

            // dd($rst);
        }

        if ('update_customer_identification' == $action) {
            $primaryIdentification = PrimaryIdentification::new(
                [
                    'id' => '3341fb09-420f-4b1c-8496-b3550096729a',
                    // "isPrimary" => true,
                    'idNumber' => 'D22222',
                    'idType' => 'DriversLicense',
                    // "issuedDate" => "2002-04-14",
                    // "verifiedDate" => "2023-08-18",
                    'issuingAuthority' => 'Department of Motor Vehicles',
                    'issuingStateOrProvince' => 'NJ',
                    'issuingCountryCode' => 'US',
                ]
            );

            $rst = $this->crbClient->updateCustomerIdentification('6e8bdc9f-17b1-4e79-8b0f-b35500967295', $primaryIdentification);

            dd($rst);
        }

        if ('update_customer_profile' == $action) {
            $individualProfile = IndividualProfile::new(
                [
                    'regO' => false,
                    // 'citizenshipCountryCode' => 'US',
                    'politicallyExposedPerson' => false,
                    'enableBackupWithholding' => false,
                    // 'backupWithholdingPercent' => 0,
                    'taxIdType' => 'Ssn',
                    'taxId' => '101447799',
                    'birthDate' => '1980-03-06',
                    // 'dateFormed' => '1999-01-01',
                    // 'entityType' => 'Individual',
                    'riskRating' => 'Low',
                    // 'ownershipType' => 'Individual',
                    // 'primaryOwnerCustomerId' => 'e3ca3a96-9663-4f19-9d7b-b3440092682a',
                    // 'parentEntityId' => null,
                    // 'dateOfDeath' => null,
                    // 'privacyOptOut' => false,
                ]
            );

            // $rst = $this->crbClient->updateCustomerProfile('e3ca3a96-9663-4f19-9d7b-b3440092682a', $individualProfile);

            // dd($rst);
        }

        if ('fetch_customer_by_id' == $action) {
            $id = trim($this->ask('Given customer id: ', 'aaaa-bbbb-ccc-dddd-eeee-fffff-ggggg'));
            // $id = 'c06d6958-6f15-400f-9d1b-b2a400c393f4'; // business
            $rst = $this->crbClient->fetchCustomerInfoById('aaaa-bbbb-ccc-dddd-eeee-fffff-ggggg', $id);
            dd($rst);
        }

        if ('onboard_c_customer' == $action) {
            $jsonData = $this->promptAndLoadPayload();
            $clientIdentifier = $this->setClientIdentifier($action);
            Redis::set($this->redisKey, $clientIdentifier);

            $customer = IndividualCustomer::new([
                'partnerId' => config('payoutchannel.crb.owlting_usa_info.partner_id'),
                'clientIdentifier' => $jsonData['clientIdentifier'] ?? $clientIdentifier,
                'name' => [
                    'firstName' => $jsonData['name']['firstName'],
                    'lastName' => $jsonData['name']['lastName'],
                ],
                'classification' => CrossRiverBankCustomerEnum::CLASSIFICATION_TYPE_PERSONAL,
                'profile' => [
                    'taxIdType' => $jsonData['profile']['taxIdType'],
                    'taxId' => $jsonData['profile']['taxId'], // 變動 /<----------
                    'riskRating' => $jsonData['profile']['riskRating'],
                    'regO' => $jsonData['profile']['regO'],
                    'politicallyExposedPerson' => $jsonData['profile']['politicallyExposedPerson'],
                    'birthDate' => $jsonData['profile']['birthDate'],
                ],
                'primaryAddress' => [
                    'addressType' => $jsonData['primaryAddress']['addressType'],
                    'classification' => $jsonData['primaryAddress']['classification'],
                    'isPrimary' => $jsonData['primaryAddress']['isPrimary'],
                    'street1' => $jsonData['primaryAddress']['street1'],
                    'street2' => $jsonData['primaryAddress']['street2'] ?? null,
                    'city' => $jsonData['primaryAddress']['city'] ?? null,
                    'state' => $jsonData['primaryAddress']['state'] ?? null,
                    'postalCode' => $jsonData['primaryAddress']['postalCode'] ?? null,
                    'countryCode' => $jsonData['primaryAddress']['countryCode'],
                ],
                'primaryPhone' => [
                    'isPrimary' => $jsonData['primaryPhone']['isPrimary'],
                    'phoneType' => $jsonData['primaryPhone']['phoneType'],
                    'phoneNumber' => $jsonData['primaryPhone']['phoneNumber'],
                ],
                'primaryEmail' => [
                    'isPrimary' => $jsonData['primaryEmail']['isPrimary'],
                    'emailType' => $jsonData['primaryEmail']['emailType'],
                    'emailAddress' => $jsonData['primaryEmail']['emailAddress'],
                ],
                'primaryIdentification' => [
                    'isPrimary' => $jsonData['primaryIdentification']['isPrimary'],
                    'idNumber' => $jsonData['primaryIdentification']['idNumber'],
                    'idType' => $jsonData['primaryIdentification']['idType'],
                    'issuingAuthority' => $jsonData['primaryIdentification']['issuingAuthority'],
                    'issuingCountryCode' => $jsonData['primaryIdentification']['issuingCountryCode'],
                ],
            ]);

            $rst = $this->crbClient->onboardIndividualCustomer($customer);

            dd($rst);

            // $createSubledger = CreateSubledger::new([
            //     'customerId' => 'd091f404-177a-4eef-80f4-b01200fc8b40',
            //     'title' => 'owlpay_user_001',
            //     'uuid' => '09e931d2-6f3a-4f30-8aa8-bc33933969c3',
            //     'subledgerProfile' => [
            //         'firstName' => 'EE',
            //         'lastName' => 'Lin',
            //     ],
            // ]);
            // $rst = $this->crbClient->createSubledger(1234123, CrossRiverBankCustomerEnum::CLASSIFICATION_TYPE_PERSONAL, $createSubledger);
            // dd($rst);
        }

        if ('onboard_b_customer' == $action) {
            $jsonData = $this->promptAndLoadPayload();
            $clientIdentifier = $this->setClientIdentifier($action);
            Redis::set($this->redisKey, $clientIdentifier);

            $customer = BusinessCustomer::new([
                'partnerId' => config('payoutchannel.crb.owlting_usa_info.partner_id'),
                'clientIdentifier' => $jsonData['clientIdentifier'] ?? $clientIdentifier,
                'name' => [
                    'entityName' => $jsonData['name']['entityName'],
                ],
                'classification' => CrossRiverBankCustomerEnum::CLASSIFICATION_TYPE_BUSINESS,
                'profile' => [
                    'taxIdType' => $jsonData['profile']['taxIdType'],
                    'taxId' => $jsonData['profile']['taxId'],
                    'riskRating' => $jsonData['profile']['riskRating'],
                    'dateFormed' => $jsonData['profile']['dateFormed'],
                    'primaryOwnerCustomerId' => isset($jsonData['profile']['primaryOwnerCustomerId']) ? $jsonData['profile']['primaryOwnerCustomerId'] : null,
                ],
                'primaryAddress' => [
                    'addressType' => $jsonData['primaryAddress']['addressType'],
                    'classification' => $jsonData['primaryAddress']['classification'],
                    'isPrimary' => true,
                    'street1' => $jsonData['primaryAddress']['street1'],
                    'street2' => $jsonData['primaryAddress']['street2'] ?? null,
                    'city' => $jsonData['primaryAddress']['city'] ?? null,
                    'state' => $jsonData['primaryAddress']['state'] ?? null,
                    'postalCode' => $jsonData['primaryAddress']['postalCode'] ?? null,
                    'countryCode' => $jsonData['primaryAddress']['countryCode'],
                ],
                'primaryPhone' => [
                    'isPrimary' => true,
                    'phoneType' => $jsonData['primaryPhone']['phoneType'],
                    'phoneNumber' => $jsonData['primaryPhone']['phoneNumber'],
                ],
                'primaryEmail' => [
                    'isPrimary' => true,
                    'emailType' => $jsonData['primaryEmail']['emailType'],
                    'emailAddress' => $jsonData['primaryEmail']['emailAddress'],
                ],
                'primaryIdentification' => [
                    'isPrimary' => null,
                    'idNumber' => isset($jsonData['primaryIdentification']) ? $jsonData['primaryIdentification']['idNumber'] : null,
                    'idType' => isset($jsonData['primaryIdentification']) ? $jsonData['primaryIdentification']['idType'] : null,
                    'issuingAuthority' => isset($jsonData['primaryIdentification']) ? $jsonData['primaryIdentification']['issuingAuthority'] : null,
                    'issuingCountryCode' => isset($jsonData['primaryIdentification']) ? $jsonData['primaryIdentification']['issuingCountryCode'] : null,
                ],
            ]);

            $rst = $this->crbClient->onboardBusinessCustomer($customer);

            dd($rst);
            // $createSubledger = CreateSubledger::new([
            //     'customerId' => 'd091f404-177a-4eef-80f4-b01200fc8b40',
            //     'title' => 'owlpay_user_001',
            //     'uuid' => '09e931d2-6f3a-4f30-8aa8-bc33933969c3',
            //     'subledgerProfile' => [
            //         'firstName' => 'EE',
            //         'lastName' => 'Lin',
            //     ],
            // ]);
            // $rst = $this->crbClient->createSubledger(1234123, CrossRiverBankCustomerEnum::CLASSIFICATION_TYPE_PERSONAL, $createSubledger);
            // dd($rst);
        }

        if ('fetch_subledger' == $action) {
            $accountNumber = trim($this->ask('Given the account number. 323040457976'));
            // $accountNumber = '356647663695';
            $subledgerFilter = SubledgerFilter::new([]);
            $list = $this->crbClient->fetchSpecificSubledger($accountNumber, $subledgerFilter);

            dd($list);
            // $availableAmount = $list->availableBalance->getAmount();
            // var_dump($list->currentBalance->getAmount());
            // var_dump($list->availableBalance->getAmount());
            // var_dump($list->holdAmount->getAmount());
        }

        if ('fetch_subledger_list' == $action) {
            $pageNumber = (int) trim($this->ask('Given the pageNumber. 1'));
            $pageSize = (int) trim($this->ask('Given the pageSize. 2 ~ Max 50'));
            $subledgerFilter = SubledgerFilter::new([
                'pageNumber' => $pageNumber,
                'pageSize' => $pageSize,
            ]);

            $list = $this->crbClient->fetchSubledgerList($subledgerFilter);
            dd($list);
        }

        if ('book_transfer' == $action) {
            $isSubToMaster = Str::lower(value: trim($this->ask('If you would like to transfer from sub to master. Given Y')));
            $clientIdentifier = $this->setClientIdentifier($action);
            Redis::set($this->redisKey, $clientIdentifier);
            $amount = trim($this->ask('Given mount: 100000'));
            $title = trim($this->ask('Given title: R5User911Au OwlPayCash '));

            if ('y' === $isSubToMaster) {
                $sourceAccount = trim($this->ask('Given sourceAccount: 329870033282'));

                $this->info('Internal transfer from subledger to master.');
                // subledger account to master account
                $owlPayCashBookTransfer = BookTransfer::new([
                    'title' => $title,
                    'clientIdentifier' => $jsonData['clientIdentifier'] ?? $clientIdentifier,

                    'sourceAccountNumber' => $sourceAccount, // 拿TC-SM-001-PM建立的2帳號
                    'totalAmount' => new Money($amount, new Currency('USD')),
                    'transferDirection' => CrossRiverBankEnum::BOOK_TRANSFER_INTERNAL,
                ]);

                $rst = $this->crbClient->createTransferFromSubToMasterAccount($owlPayCashBookTransfer);
                dd($rst);
            } else {
                $isMasterToSub = Str::lower(trim($this->ask('If you would like to transfer from master to sub. Given Y')));
                if ('y' === $isMasterToSub) {
                    $destinationAccount = trim($this->ask('Given destination Account: 329870033282'));

                    $this->info('Internal transfer from master to subledger.');
                    // master account to subleger
                    $owlPayCashBookTransfer = BookTransfer::new([
                        'title' => $title,
                        'clientIdentifier' => $jsonData['clientIdentifier'] ?? $clientIdentifier,
                        'sourceAccountNumber' => null,
                        'destinationAccountNumber' => $destinationAccount,
                        'totalAmount' => new Money($amount, new Currency('USD')),
                        'transferDirection' => CrossRiverBankEnum::BOOK_TRANSFER_INTERNAL,
                    ]);

                    $list = $this->crbClient->createTransferFromMasterToSubAccount($owlPayCashBookTransfer);
                    dd($list);
                } else {
                    $this->info('Exist! Do nothing.');
                }
            }
        }

        if ('create_ach_pull' == $action) {
            $jsonData = $this->promptAndLoadPayload();
            $clientIdentifier = $this->setClientIdentifier($action);
            Redis::set($this->redisKey, $clientIdentifier);
            $amount = trim($this->ask('Please provide the amount'));

            $originatorUuid = $this->setOriginatorUuid($action);
            $externalUuid = $this->setExternalUuid($action);
            $purpose = trim($this->ask('Please provide the purpose. For example, ach pull $5'));

            $originatorInfo = ACHOriginatorInfo::new([
                'accountNumber' => $jsonData['accountNumber'], // 315861163337
                'uuid' => $jsonData['originator']['uuid'] ?? $originatorUuid,
            ]);

            $externalAccountInfo = ACHExternalAccountInfo::new([
                'accountNumber' => $jsonData['receiver']['accountNumber'],
                'uuid' => $jsonData['receiver']['uuid'] ?? $externalUuid ?? null,
                'routingNumber' => $jsonData['receiver']['routingNumber'],
                'accountType' => $jsonData['receiver']['accountType'],
                'accountName' => $jsonData['receiver']['accountName'],
                'identification' => $jsonData['receiver']['identification'],
            ]);

            $payoutDetails = ACHPayoutDetail::new([
                'secCode' => CrossRiverBankEnum::ACH_SEC_CODE_WEB,
                'transactionType' => CrossRiverBankEnum::ACH_TRANSACTION_TYPE_PULL,
                'totalAmount' => new Money($amount, new Currency('USD')),
                'description' => $jsonData['description'],
                'serviceType' => $jsonData['serviceType'],
                'clientIdentifier' => $jsonData['clientIdentifier'] ?? $clientIdentifier,
                'purpose' => $jsonData['purpose'] ?? $purpose,
            ]);

            $list = $this->crbClient->createAchPull($originatorInfo, $payoutDetails, $externalAccountInfo);
            dd($list);
        }

        if ('create_ach_push' == $action) {
            $jsonData = $this->promptAndLoadPayload();
            $clientIdentifier = $this->setClientIdentifier($action);
            Redis::set($this->redisKey, $clientIdentifier);
            $amount = trim($this->ask('Please provide the amount'));
            $purpose = trim($this->ask('Please provide the purpose. For example, ach push $5'));

            // $accountNumber = trim($this->ask('Given the account number.329870033282'));
            // $debtorName = trim($this->ask('Given the debtor name. R5User911Au OwlPayCash'));
            $originatorUuid = $this->setOriginatorUuid($action);
            $externalUuid = $this->setExternalUuid($action);

            $originatorInfo = ACHOriginatorInfo::new([
                'accountNumber' => $jsonData['originator']['accountNumber'], // 315861163337
                'uuid' => $jsonData['originator']['uuid'] ?? $originatorUuid,
                'routingNumber' => $jsonData['originator']['routingNumber'] ?? null,
                'accountType' => $jsonData['originator']['accountType'] ?? null,
                'accountName' => $jsonData['originator']['accountName'] ?? null,
            ]);

            $externalAccountInfo = ACHExternalAccountInfo::new([
                'accountNumber' => $jsonData['receiver']['accountNumber'],
                'uuid' => $jsonData['receiver']['uuid'] ?? $externalUuid ?? null,
                'routingNumber' => $jsonData['receiver']['routingNumber'],
                'accountType' => $jsonData['receiver']['accountType'],
                'accountName' => $jsonData['receiver']['accountName'],
                'identification' => $jsonData['receiver']['identification'],
            ]);

            $payoutDetails = ACHPayoutDetail::new([
                'secCode' => CrossRiverBankEnum::ACH_SEC_CODE_WEB,
                'transactionType' => CrossRiverBankEnum::ACH_TRANSACTION_TYPE_PUSH,
                'totalAmount' => new Money($amount, new Currency('USD')),
                'description' => $jsonData['description'],
                'serviceType' => $jsonData['serviceType'],
                'clientIdentifier' => $jsonData['clientIdentifier'] ?? $clientIdentifier,
                'purpose' => $jsonData['purpose'] ?? $purpose,
            ]);

            $list = $this->crbClient->createAchPush($originatorInfo, $payoutDetails, $externalAccountInfo);
            dd($list);
        }

        if ('fetch_ach_list' == $action) {
            $pageNumber = (int) trim($this->ask('Given the pageNumber. 1'));
            $pageSize = (int) trim($this->ask('Given the pageSize. 2 ~ Max 50'));
            $achListFilter = AchListFilter::new([
                'pageNumber' => $pageNumber,
                'pageSize' => $pageSize,
            ]);
            $list = $this->crbClient->fetchAchList($achListFilter);
            dd($list);
        }

        if ('fetch_ach_by_id' == $action) {
            $id = '40ffc8fc-1d98-43b0-b069-b22e0023f870';

            $rst = $this->crbClient->fetchAchById($id);
            dd($rst);
        }

        if ('cancel_ach' == $action) {
            $customerID = '1234';
            $ach_ID = trim($this->ask(question: 'Given the ach ID '));
            $type = trim($this->ask(question: 'Given the ach transaction type "Pull" or "Push" '));
            $list = $this->crbClient->cancelAch($customerID, $ach_ID, $type);
            dd($list);
        }

        if ('create_wire_payout' == $action) {
            $jsonData = $this->promptAndLoadPayload();
            $clientIdentifier = $this->setClientIdentifier($action);
            Redis::set($this->redisKey, $clientIdentifier);
            $amount = trim($this->ask('Please provide the amount'));
            $originatorUuid = $this->setOriginatorUuid($action);

            // $accountNumber = trim($this->ask('Given the account number.329870033282', '329870033282'));
            $purpose = trim($this->ask('Please provide the purpose. For example, wire outbound $5'));
            // $debtorName = trim($this->ask('Given the debtor name. R5User911Au OwlPayCash', 'R5User911Au OwlPayCash'));

            // remitter
            $owlPayCashRemitterInfo = WireRemitterInfo::new([
                'crbSubledgerAccountNumber' => $jsonData['accountNumber'], // subledger1
                'uuid' => $originatorUuid,
                'originator' => [
                    'idCode' => $jsonData['originator']['idCode'],
                    'identifier' => $jsonData['originator']['identifier'],
                    'name' => $jsonData['originator']['name'],
                ],
                'originatorToBeneficiary1' => $jsonData['originatorToBeneficiary1'],
                'originatorToBeneficiary2' => $jsonData['originatorToBeneficiary2'],
                'originatorToBeneficiary3' => $jsonData['originatorToBeneficiary3'],
                'originatorToBeneficiary4' => $jsonData['originatorToBeneficiary4'],
            ]);

            // // beneficiary
            $beneficiary = [
                'idCode' => $jsonData['beneficiary']['idCode'],
                'identifier' => $jsonData['beneficiary']['identifier'],
                'name' => $jsonData['beneficiary']['name'],
                'address1' => $jsonData['beneficiary']['address1'] ?? null,
                'address2' => $jsonData['beneficiary']['address2'] ?? null,
                'address3' => $jsonData['beneficiary']['address3'] ?? null,
                // 'address2' => '', ////  HTTP Client error, reason: [{"code":1000,"message":"Beneficiary.Address2 required when Address3 present"}]
                // 'address2' => 'city',
            ];

            // $iFI = [
            //     'idCode' => 'F',
            //     'identifier' => '021214891',
            //     'name' => 'Test user 0001',
            //     // 'address1' => 'IntermediaryFi St',
            // ];

            // $bFI = [
            //     'idCode' => 'F',
            //     'identifier' => '021214891',
            //     // 'idCode' => 'B',
            //     // 'identifier' => 'AAAABBCC123',
            //     'name' => 'Test user 0001',
            //     'address1' => 'address1',

            //     // 'address2' => 'address2',
            //     // 'address3' => 'address3',
            // ];

            $owlPayCashBeneficiaryInfo = WireBeneficiaryInfo::new([
                'routingNumber' => $jsonData['receiverRoutingNumber'],
                'accountNumber' => $jsonData['accountNumber'],
                'name' => $jsonData['beneficiary']['name'],
                'beneficiaryReference' => $jsonData['beneficiaryReference'] ?? null,
                'beneficiary' => $beneficiary,
                // 'beneficiaryFi' => $bFI,
                // 'intermediaryFi' => $iFI,
            ]);

            $payoutDetails = WirePayoutDetail::new(
                [
                    'totalAmount' => new Money($amount, new Currency('USD')),
                    'clientIdentifier' => $jsonData['clientIdentifier'] ?? $clientIdentifier,
                    'purpose' => $jsonData['purpose'] ?? $purpose,
                ]
            );

            $list = $this->crbClient->createWirePayout($owlPayCashRemitterInfo, $owlPayCashBeneficiaryInfo, $payoutDetails);

            dd($list);
        }

        if ('simulate_inbound_wire' == $action) {
            $beneficiaryName = trim($this->ask('Given the beneficiary name. R5User911Au OwlPayCash'));
            $accountNumber = trim($this->ask('Given the account number. 329870033282'));

            // R4User001 OwlPayCash
            // 322504599638

            // Run4User829 OwlPay
            // 376035058332

            // $list = $this->crbClient->simulateInboundWireDeposit(
            //      $accountNumber,
            //      $beneficiaryName,
            //      new Money(100000, new Currency('USD'))
            // );

            // dd($list);
        }

        if ('get_wire_payout_by_id' == $action) {
            $wire_ID = trim($this->ask('Given the wire ID 1d7352e8-976d-4276-b0dc-b237009643aa '));
            $customerID = '';
            $list = $this->crbClient->fetchWirePayoutById($customerID, $wire_ID);
            dd($list);
        }

        if ('get_wire_payouts' == $action) {
            $pageNumber = (int) trim($this->ask('Given the pageNumber. 1'));
            $pageSize = (int) trim($this->ask('Given the pageSize. 2 ~ Max 50'));
            $wireFilter = WirePayoutFilter::new([
                'pageNumber' => $pageNumber,
                'pageSize' => $pageSize,
            ]);
            $list = $this->crbClient->fetchWirePayouts($wireFilter);
            dd($list);
        }

        if ('revert_inbound_wire' == $action) {
            $clientIdentifier = trim($this->ask('Given the clientIdentifier. 1d56a0cc-2758-4c84-854d-b61f5189bf0d'));
            $name = trim($this->ask('Given the customer name'));
            $description1 = trim($this->ask('Given the description 1 for reverting the inbound wire'));
            $description2 = trim($this->ask('Given the description 2 for reverting the inbound wire'));

            $reason = [
                'description1' => $description1,
                'description2' => $description2,
            ];

            // $fee = new Money( 0, new Currency('USD')); // 這是手續費
            $fee = new Money(500, new Currency('USD')); // 這是手續費

            $rst = $this->crbClient->reverseInboundWire($clientIdentifier, $reason, $fee);
            dd($rst);
        }

        if ('release_balance_by_act' == $action) {
            $accountNumber = trim($this->ask('Given the accountNumber. 345002275971'));

            $amount = $this->ask('Given the hold amount to release');

            $rst = $this->crbClient->releaseTheHoldAmountWithAccountNumber($accountNumber, new Money($amount, new Currency('USD')));
            dd($rst);
        }

        if ('lock_balance_by_act' == $action) {
            $accountNumber = trim($this->ask('Given the accountNumber. 345002275971'));

            $amount = $this->ask('Given the hold amount to lock');

            $rst = $this->crbClient->lockTheHoldAmountWithAccountNumber($accountNumber, new Money($amount, new Currency('USD')));
            dd($rst);
        }

        if ('release_balance_by_cid' == $action) {
            $clientIdentifier = trim($this->ask('Given the clientIdentifier. 21'));

            $amount = $this->ask('Given the hold amount to release');

            $rst = $this->crbClient->releaseTheHoldAmountWithClientIdentifier($clientIdentifier, new Money($amount, new Currency('USD')));
            dd($rst);
        }

        if ('lock_balance_by_cid' == $action) {
            $clientIdentifier = trim($this->ask('Given the clientIdentifier. 21'));

            $amount = $this->ask('Given the hold amount to lock');

            $this->crbClient->lockTheHoldAmountWithClientIdentifier($clientIdentifier, new Money($amount, new Currency('USD')));
        }

        if ('cancel_wire_payout' == $action) {
            $wireId = trim($this->ask('Given the wire ID a09716ca-434e-411c-a649-b237009a8b9f'));
            $customerID = 'remitter_0001';
            $rst = $this->crbClient->cancelWirePayout($customerID, $wireId);
            dd($rst);
        }

        if ('fetch_master_account_activity' == $action) {
            // $filter = MasterAccountActivityFilter::new([
            //     'transactionType' => CrossRiverBankEnum::ACCOUNT_ACTIVITY_TRANSACTION_TYPE_DEBIT,
            //     'pageSize' => 2,
            // ]);
            $pageNumber = (int) trim($this->ask('Given the pageNumber. 1'));
            $pageSize = (int) trim($this->ask('Given the pageSize. 2 ~ Max 50'));
            $filter = MasterAccountActivityFilter::new([
                'pageNumber' => $pageNumber,
                'pageSize' => $pageSize,
                'maxAmount' => new Money('100', new Currency('USD')),
                'fromDate' => '2024-09-29',
            ]);
            $list = $this->crbClient->fetchMasterAccountActivity($filter);
            dd($list);
        }

        if ('fetch_master_balance_history' == $action) {
            // $filter = AccountBalanceFilter::new([
            //     'fromDate' => '2024-10-04',
            //     'page' => 20,
            // ]);
            $pageNumber = (int) trim($this->ask('Given the pageNumber. 1'));
            $pageSize = (int) trim($this->ask('Given the pageSize. 2 ~ Max 50'));
            $filter = AccountBalanceFilter::new([
                'pageNumber' => $pageNumber,
                'pageSize' => $pageSize,
            ]);
            $list = $this->crbClient->fetchMasterAccountBalanceHistory($filter);
            dd($list);
        }

        if ('create_rtp' == $action) {
            $jsonData = $this->promptAndLoadPayload();
            $clientIdentifier = $this->setClientIdentifier($action);
            Redis::set($this->redisKey, $clientIdentifier);
            $amount = trim($this->ask('Please provide the amount'));
            $externalUuid = $this->setExternalUuid($action);

            // $accountNumber = trim($this->ask('Given the account number.329870033282', '329870033282'));
            $purpose = trim($this->ask('Please provide the purpose. For example, rtp outbound $5'));

            // $debtorName = trim($this->ask('Given the debtor name. R5User911Au OwlPayCash', 'R5User911Au OwlPayCash'));
            // $amount = $this->ask('Please provide the amount', '1000');

            $remitter = RTPRemitterInfo::new(remitterInfo: [
                'debtor' => [
                    'accountNumber' => $jsonData['debtor']['accountNumber'], // '2418720054'  不要亂改
                    'name' => $jsonData['debtor']['name'],
                    'addressStreetName' => $jsonData['debtor']['addressStreetName'], //  'Broadway Avenue' // Street address name information
                    'addressLine' => $jsonData['debtor']['addressLine'], // 'Suite 1201, 12th Floor', // Additional address information
                    'addressBuildingNumber' => $jsonData['debtor']['addressBuildingNumber'] ?? null,  // '1201' // Street address building number
                    'addressCity' => $jsonData['debtor']['addressCity'] ?? null,  // 'New York' // City full name
                    'addressState' => $jsonData['debtor']['addressState'] ?? null, // 'NY' // 2-letter state code
                    'addressPostalCode' => $jsonData['debtor']['addressPostalCode'] ?? null,  // '10036' // Postal or ZIP code
                    'addressCountry' => $jsonData['debtor']['addressCountry'], // 'US', // 2-letter ISO country code
                ],
            ]);

            $beneficiaryInfo = RTPBeneficiaryInfo::new([
                'uuid' => $jsonData['creditor']['uuid'] ?? $externalUuid ?? null, // owlpay cash uuid
                'creditor' => [
                    'name' => $jsonData['creditor']['name'], // 'beneficiary 001',
                    'routingNumber' => $jsonData['creditor']['routingNumber'],  // '011000138' // 不要亂改
                    'accountNumber' => $jsonData['creditor']['accountNumber'],  // '456789000', // 不要亂改
                    'addressStreetName' => $jsonData['creditor']['addressStreetName'] ?? null, // 'Spooner St',
                    'addressBuildingNumber' => $jsonData['creditor']['addressBuildingNumber'] ?? null, // '34',
                    'addressLine' => $jsonData['creditor']['addressLine'] ?? null, // 'Apt 2B',
                    'addressCity' => $jsonData['creditor']['addressCity'] ?? null, // 'Quahog',
                    'addressState' => $jsonData['creditor']['addressState'] ?? null, // 'RI',
                    'addressPostalCode' => $jsonData['creditor']['addressPostalCode'] ?? null, // '00093',
                    'addressCountry' => $jsonData['creditor']['addressCountry'] ?? null, // 'US',
                ],
            ]);

            $owlPayCashPayoutInfo = RTPPayoutInfo::new([
                'totalAmount' => new Money($amount, new Currency('USD')),  // new Money(300100, new Currency('USD')),
                'remittanceData' => $jsonData['remittanceData'] ?? null,
                'clientIdentifier' => $jsonData['clientIdentifier'] ?? $clientIdentifier,
                'purpose' => $jsonData['purpose'] ?? $purpose,
            ]);

            $rst = $this->crbClient->createInstantPayment($remitter, $beneficiaryInfo, $owlPayCashPayoutInfo);

            dd($rst);
        }

        if ('create_micro_deposit' == $action) {
            $jsonData = $this->promptAndLoadPayload();
            $clientIdentifier = $this->setClientIdentifier($action);
            Redis::set($this->redisKey, $clientIdentifier);
            $amount = trim($this->ask('Please provide the amount'));
            // $remitter = RTPRemitterInfo::new([
            //     'debtor' => [
            //         'accountNumber' => '2418720054', // 不要亂改
            //         'name' => 'OwlTing USA',
            //     ],
            // ]);

            // $beneficiaryInfo = RTPBeneficiaryInfo::new([
            //     'uuid' => '1d56a0cc-2758-4c84-854d-b61f5189bf0d',
            //     'creditor' => [
            //         'name' => 'Beneficiary 001',
            //         'routingNumber' => '011000138', // 不要亂改
            //         'accountNumber' => '456789000', // 不要亂改
            //     ],
            //     'addressStreetName' => 'Spooner St',
            //     'addressBuildingNumber' => '34',
            //     'addressCity' => 'Quahog',
            //     'addressState' => 'RI',
            //     'addressPostalCode' => '00093',
            // ]);

            // $owlPayCashPayoutInfo = RTPPayoutInfo::new([
            //     'totalAmount' => new Money(1, new Currency('USD')),
            //     'remittanceData' => 'ABC',
            //     'clientIdentifier' => '1d56a0cc-2758-4c84-854d-b61f5189bf0d',
            // ]);

            // $remitterInfo = RTPRemitterInfo::new([
            //     'debtor' => [
            //         'accountNumber' => '315861163337', // 拿TC-SM-001-PM建立的第二個帳號
            //         'name' => 'Run4UserSandboxTesting002 OwlPayCash',
            //     ],

            //     'uuid' => 'org-r4-TC-RTP-005-API',
            // ]);

            // $beneficiaryInfo = RTPBeneficiaryInfo::new([
            //     'creditor' => [
            //         'name' => 'reject: Richard Robert',
            //         'routingNumber' => '011000138',
            //         'accountNumber' => '456789000',
            //     ],
            //     'uuid' => 'ben-r4-TC-RTP-005-API',
            // ]);

            // $owlPayCashPayoutInfo = RTPPayoutInfo::new([
            //     'totalAmount' => new Money(1000, new Currency('USD')),
            //     'remittanceData' => 'ABC',
            //     'clientIdentifier' => 'txn-r4-TC-RTP-005-API',
            // ]);

            //     $remitterInfo = RTPRemitterInfo::new([
            //        'debtor' => [
            //            'accountNumber' => $accountNumber, // 拿TC-SM-001-PM建立的第二個帳號
            //            'name' => $debtorName,
            //             'addressStreetName' => 'Broadway Avenue', // Street address name information
            //             'addressLine' => 'Suite 1201, 12th Floor', // Additional address information
            //             'addressBuildingNumber' => '1201', // Street address building number
            //             'addressCity' => 'New York', // City full name
            //             'addressState' => 'NY', // 2-letter state code
            //             'addressPostalCode' => '10036', // Postal or ZIP code
            //             'addressCountry' => 'US', // 2-letter ISO country code
            //        ],
            //        'uuid' => 'org-r5-TC-RTP-005-API ',
            //    ]);

            //     $beneficiaryInfo = RTPBeneficiaryInfo::new([
            //        'creditor' => [
            //         // RTP-001
            //         'name' => 'Richard Robert',
            //         'routingNumber' => '011000138',
            //          'accountNumber' => '456789000',
            //          'addressStreetName' => 'Spooner St',
            //             'addressBuildingNumber' => '34',
            //             'addressLine' => 'Apt 2B',
            //             'addressCity' => 'Quahog',
            //             'addressState' => 'RI',
            //             'addressPostalCode' => '00093',
            //             'addressCountry' => 'US',

            //         // RTP-002
            //         // 'name' => 'Richard Robert',
            //         // 'routingNumber' => '011000144', // Invalid routing number
            //         // 'accountNumber' => '456789000',

            //         // RTP-003
            //         // 'name' => 'Richard Robert',
            //         // 'routingNumber' => '011000144', // Invalid routing number
            //         // 'accountNumber' => '456789000',

            //         // RTP-004
            //         // 'name' => 'Richard Robert',
            //         // 'routingNumber' => '999999998',
            //         // 'accountNumber' => '9999999981111111',

            //         // RTP-005
            //         // 'name' => 'reject: Richard Robert',
            //         // 'routingNumber' => '011000138',
            //         // 'accountNumber' => '456789000',
            //        ],
            //        'uuid' => 'ben-r5-TC-RTP-005-API',
            //    ]);

            $remitter = RTPRemitterInfo::new(remitterInfo: [
                'debtor' => [
                    'accountNumber' => $jsonData['debtor']['accountNumber'], // '2418720054'  不要亂改
                    'name' => $jsonData['debtor']['name'],
                    'addressStreetName' => $jsonData['debtor']['addressStreetName'], //  'Broadway Avenue' // Street address name information
                    'addressLine' => $jsonData['debtor']['addressLine'], // 'Suite 1201, 12th Floor', // Additional address information
                    'addressBuildingNumber' => $jsonData['debtor']['addressBuildingNumber'],  // '1201' // Street address building number
                    'addressCity' => $jsonData['debtor']['addressCity'],  // 'New York' // City full name
                    'addressState' => $jsonData['debtor']['addressState'], // 'NY' // 2-letter state code
                    'addressPostalCode' => $jsonData['debtor']['addressPostalCode'],  // '10036' // Postal or ZIP code
                    'addressCountry' => $jsonData['debtor']['addressCountry'], // 'US', // 2-letter ISO country code
                ],
            ]);

            $beneficiaryInfo = RTPBeneficiaryInfo::new([
                'uuid' => '1d56a0cc-2758-4c84-854d-b61f5189bf0d', // owlpay cash uuid
                'creditor' => [
                    'name' => $jsonData['creditor']['name'], // 'beneficiary 001',
                    'routingNumber' => $jsonData['creditor']['routingNumber'],  // '011000138' // 不要亂改
                    'accountNumber' => $jsonData['creditor']['accountNumber'],  // '456789000', // 不要亂改
                    'addressStreetName' => $jsonData['creditor']['addressStreetName'], // 'Spooner St',
                    'addressBuildingNumber' => $jsonData['creditor']['addressBuildingNumber'], // '34',
                    'addressLine' => $jsonData['creditor']['addressLine'], // 'Apt 2B',
                    'addressCity' => $jsonData['creditor']['addressCity'], // 'Quahog',
                    'addressState' => $jsonData['creditor']['addressState'], // 'RI',
                    'addressPostalCode' => $jsonData['creditor']['addressPostalCode'], // '00093',
                    'addressCountry' => $jsonData['creditor']['addressCountry'], // 'US',
                ],
            ]);

            $owlPayCashPayoutInfo = RTPPayoutInfo::new([
                'totalAmount' => new Money(1, new Currency('USD')),  // new Money(300100, new Currency('USD')),
                'remittanceData' => $jsonData['remittanceData'] ?? null,
                'clientIdentifier' => $jsonData['clientIdentifier'] ?? $clientIdentifier,
            ]);

            $rst = $this->crbClient->createMicroDeposit($remitter, $beneficiaryInfo, $owlPayCashPayoutInfo);

            dd($rst);
        }

        if ('fetch_subledger_balance_history' == $action) {
            $subledgerNumber = '348184968116';
            $filter = AccountBalanceFilter::new([
                'fromDate' => '2024-10-04',
                'page' => 20,
            ]);

            $list = $this->crbClient->fetchSubledgerBalanceHistory($subledgerNumber, $filter);
            dd($list);
        }

        if ('fetch_rtp_list' == $action) {
            $pageNumber = (int) trim($this->ask('Given the pageNumber. 1'));
            $pageSize = (int) trim($this->ask('Given the pageSize. 2 ~ Max 50'));
            $filter = RTPFilter::new([
                'pageNumber' => $pageNumber,
                'pageSize' => $pageSize,
                'maxAmount' => new Money('200', new Currency('USD')),
            ]);

            $list = $this->crbClient->fetchInstantPaymentList($filter);
            dd($list);
        }

        if ('fetch_transaction_by_id' == $action) {
            $transactionId = trim($this->ask('Given the transaction ID a1c52b6d-e256-49c1-a302-b24b009c66a1'));
            $rst = $this->crbClient->fetchTransactionById($transactionId);
            dd($rst);
        }

        if ('fetch_micro_deposit_by_id' == $action) {
            $rtpTransferId = trim($this->ask('Given the rtp transaction ID a1c52b6d-e256-49c1-a302-b24b009c66a1'));
            $list = $this->crbClient->fetchInstantPaymentById($rtpTransferId);
            dd($list);
        }

        return 0;
    }

    private function normalizeAction(mixed $action): string
    {
        return strtolower(trim((string) $action));
    }

    private function isValidAction(string $action): bool
    {
        return in_array($action, self::VALID_ACTIONS, true);
    }

    private function displayActionHelp(): void
    {
        $this->error('Please input correct mode.');

        $this->info('--action');
        $this->info('    get_access_token: Fetch auth access token.');
        $this->info('    get_customer_list: Fetch customer list.');
        $this->info('    create_subledger: create subledger for user.');
        $this->info('    fetch_subledger: fetch a specific subledger');
        $this->info('    fetch_subledger_list: fetch subledger list');
        $this->info('    book_transfer: fetch subledger list');
        $this->info('    create_ach_pull: create an ach pull  ');
        $this->info('    cancel_ach: cancel an ach pull or push ');
        $this->info('    create_ach_push: cancel an ach pull ');
        $this->info('    create_wire_payout: create a wire payout to visa');
        $this->info('    simulate_inbound_wire: create an inbound wire payout to visa');

        $this->info('    cancel_wire_payout: cancel a wire payout');
        $this->info('    get_wire_payout_by_id: get a wire payout by id');
        $this->info('    get_wire_payouts: get wire payouts');
        $this->info('    revert_inbound_wire: revert an inbound wire deposit');
        $this->info('    release_inbound_wire: release an inbound wire deposit');
        $this->info('    lock_inbound_wire: lock an inbound wire deposit');

        $this->info('    get_balance_with_wire_hold: get balance on hold with inbound wire deposit');

        $this->info('    fetch_master_balance_history: fetch the balance of master account');
        $this->info('    fetch_master_account_activity: fetch the account activity of master account');
        $this->info('    fetch_subledger_balance_history: fetch the account activity of master account');
        $this->info('    create_micro_deposit: create micro deposit from master account');
        $this->info('    fetch_ach_list: fetch ach list');
        $this->info('    fetch_ach_by_id: fetch the account activity of master account');
        $this->info('    fetch_rtp_list: fetch the created micro-deposit');
        $this->info('    fetch_transaction_by_id: fetch the transaction by id');

        $this->info('    fetch_micro_deposit_by_id: fetch the created micro-deposit by id');
        $this->info('    create_rtp: create instant payment');

        $this->info('    fetch_customer_by_id: create instant payment');
        $this->info('    onboard_c_customer: onboard a customer');
        $this->info('    onboard_b_customer: onboard a business user');
        $this->info('    update_customer_name: update customer name');
        $this->info('    update_customer_profile: add customer profile');
        $this->info('    add_customer_address: ');
        $this->info('    add_customer_email: ');
        $this->info('    add_customer_phone_number: ');
        $this->info('    update_customer_name: ');
        $this->info('    add_customer_identification: ');
        $this->info('    update_customer_identification: ');
        $this->info('    add_restriction_of_subledger: ');
    }

    private function setClientIdentifier(string $action)
    {
        $this->info('Last client reference id: '.Redis::get($this->redisKey));

        // 產生預設值：test-create-subledger-20260115-0001
        $actionSlug = str_replace('_', '-', $action);
        $defaultClientIdentifier = sprintf('test-%s-%s-0001', $actionSlug, date('Ymd'));

        return trim($this->ask('Please provide the Idempotency Key (Client Identifier)', $defaultClientIdentifier));
    }

    private function setOriginatorUuid(string $action)
    {
        $actionSlug = str_replace('_', '-', $action);
        $defaultOriginatorUuid = sprintf('org-r5-%s-%s-0001', $actionSlug, date('Ymd'));

        return trim($this->ask('Please provide the originator user uuid', $defaultOriginatorUuid));
    }

    private function setExternalUuid(string $action)
    {
        $actionSlug = str_replace('_', '-', $action);
        $defaultExternalUuid = sprintf('ext-ben-r5-%s-%s-0001', $actionSlug, date('Ymd'));

        return trim($this->ask('Please provide the external user uuid', $defaultExternalUuid));
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

    //     private function promptAndLoadPayload(string $file): array
    // {
    //     $this->listExistingFiles();

    //     return $this->loadPayloadFromFile($file);
    // }

    protected function listExistingFiles(string $path = self::CRB_PAYLOAD_RELATIVE_PATH)
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

    protected function loadPayloadFromFile(string $file, ?string $path = self::CRB_PAYLOAD_RELATIVE_PATH): ?array
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
