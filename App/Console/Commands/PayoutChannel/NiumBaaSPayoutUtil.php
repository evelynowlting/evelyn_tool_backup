<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\PayoutChannel\NiumBaaSEnum;
use App\Models\Application;
use App\Models\NiumBaaSBeneficiaryInfo;
use App\Models\NiumBaaSClientInfo;
use App\Models\NiumBaaSCustomerInfo;
use App\Models\Payout;
use App\PayoutGateways\Onboard\NiumBaaS\ApplicationOnboard;
use App\Services\Payout\NiumBaaSOnboardService;
use App\Services\Payout\NiumBaaSPayoutService;
use Illuminate\Console\Command;
use Illuminate\Http\Response;

class NiumBaaSPayoutUtil extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium-baas-payout:util
                {--dst_cur=""}
                {--sell_currency=USD}
                {--buy_currency=SGD}
                {--pid=""}
                {--ref=""}
                {--rfi_id=""}
                {--mode=""}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nium BaaS tool for testing API.';

    // 固定值，為OwlPay一個client專用
    // protected $client_key = '547444Hkf18NT3t779711ryxMyUNp2F';
    // protected $client_secret = '117d2870-7430-11ec-b7df-5b4999dbcda3';
    // protected $client_id = '61dfb5da3628921b925eadfe';

    protected $niumBaaSPayoutService;

    protected $niumBaaSOnboardService;

    protected $applicationOnboard;

    public function __construct(
        NiumBaaSPayoutService $niumBaaSPayoutService,
        NiumBaaSOnboardService $niumBaaSOnboardService,
        ApplicationOnboard $applicationOnboard,
    ) {
        parent::__construct();
        $this->niumBaaSPayoutService = $niumBaaSPayoutService;
        $this->niumBaaSOnboardService = $niumBaaSOnboardService;
        $this->applicationOnboard = $applicationOnboard;
    }

    public function handle()
    {
        $a = Response::HTTP_CONFLICT;

        $modes = [
            'fetch_config',
            'fetch_client_detail',
            'fetch_customer_detail_v2',
            'fetch_payout_details',
            'fetch_wallet_balance',
            'send_payout',
            'add_beneficiary',
            'delete_beneficiary',
            'fetch_onboarding_rfi_details',
            'fetch_payout_details_by_rn',
            'fetch_payout_life_cycle',
            'onboard_corporate_customer',
            'upload_corporate_customer_document',
            'base64_validation',
            'update_van_tags',
            'delete_van_tags',
        ];
        $mode = strtolower(trim($this->option('mode')));

        if (!in_array($mode, $modes)) {
            $this->error('Please input correct mode.');

            $this->info('For fetch customer detail, please enter --mode=fetch_customer_detail_v2');
            $this->info('For fetch the configuration, please enter --mode=fetch_config');
            $this->info('For fetch payout details, please enter --mode=fetch_payout_details');
            $this->info('For fetch client details, please enter --mode=fetch_client_detail');
            $this->info('For fetch wallet balance, please enter --mode=fetch_wallet_balance');
            $this->info('For send payout, please enter --mode=send_payout');
            $this->info('For add beneficiary, please enter --mode=add_beneficiary');
            $this->info('For delete beneficiary, please enter --mode=delete_beneficiary');
            $this->info('For fetch onboarding RFI details, please enter --mode=fetch_onboarding_rfi_details');
            $this->info('For fetch payout details by reference number, please enter --mode=fetch_payout_details_by_rn');
            $this->info('For fetch payout life cycle, please enter --mode=fetch_payout_life_cycle');
            $this->info('For onboard corporate details, please enter --mode=onboard_corporate_customer');
            $this->info('For upload corporate customer document, please enter --mode=upload_corporate_customer_document');
            $this->info('For validate base64 on onboarding information, please enter --mode=base64_validation');
            $this->info('For update van tag on existing customer, please enter --mode=update_van_tags');
            $this->info('For delete van tag on existing customer, please enter --mode=delete_van_tags');

            return;
        }

        if ('update_van_tags' == $mode) {
            // US - Evelyn Holdings
            $nium_baas_client_info = NiumBaaSClientInfo::find(2);
            $customer_info = NiumBaaSCustomerInfo::find(2);
            $customer_id = $customer_info->customer_id;
            $wallet_id = $customer_info->wallet_id;
            $currency = 'USD';
            $uniquePaymentId = '85086325412';
            $key_tags = [
                // ['' => ''],
                ['name' => 'ee'],
                // ['status' => 'active'],
            ];

            $rst = $this->niumBaaSPayoutService->updateVANTags($nium_baas_client_info, $customer_id, $wallet_id, $currency, $uniquePaymentId, $key_tags);
            dd($rst);
        }

        if ('delete_van_tags' == $mode) {
            // US - Evelyn Holdings
            $nium_baas_client_info = NiumBaaSClientInfo::find(2);
            $customer_info = NiumBaaSCustomerInfo::find(2);
            $customer_id = $customer_info->customer_id;
            $wallet_id = $customer_info->wallet_id;
            $currency = 'USD';
            $uniquePaymentId = '85086325412';
            $key_tags = [
                // ['' => ''],
                ['note' => '6/30expire'],
                // ['status' => 'active'],
            ];

            $rst = $this->niumBaaSPayoutService->deleteVANTags($nium_baas_client_info, $customer_id, $wallet_id, $currency, $uniquePaymentId, $key_tags);
            dd($rst);
        }

        if ('base64_validation' == $mode) {
            $file_content = file_get_contents(__DIR__.'/upload_document.json');
            $content = json_decode($file_content, true);
            $base64_document = $content['payload']['businessDetails']['stakeholders'][0]['stakeholderDetails']['documentDetails'][0]['document']['document'];
            if (base64_decode($base64_document, true)) {
                $this->info('This document is valid.');
            } else {
                $this->info('This document is NOT valid.');
            }
        }

        if ('onboard_corporate_customer' == $mode) {
            $nium_client_info = NiumBaaSClientInfo::find(1);
            $application = Application::find(203);
            $kyb_data = [
                'region' => 'SG',
                'riskAssessmentInfo' => [
                    'totalEmployees' => 'EM001',
                    'annualTurnover' => 'SG001',
                    'industrySector' => 'IS001',
                    'countryOfOperation' => [
                        'SG',
                    ],
                    'transactionCountries' => [
                        'HK',
                    ],
                    'intendedUseOfAccount' => 'IU002',
                ],
                'businessDetails' => [
                    'businessName' => 'SG Company Trust 003', // 不能重覆
                    'businessRegistrationNumber' => 'F3234260003', // 不能重覆
                    'tradeName' => 'Trust260',
                    'businessType' => 'TRUST',
                    'description' => null,
                    'stockSymbol' => null,
                    'associationDetails' => [
                        'associationName' => null,
                        'associationNumber' => null,
                        'associationChairPerson' => null,
                    ],
                    'legalDetails' => [
                        'registeredDate' => '1990-01-01',
                        'registeredCountry' => 'SG',
                    ],
                    'addresses' => [
                        'registeredAddress' => [
                            'addressLine1' => '中華路weqwe',
                            'addressLine2' => '123123',
                            'city' => '樹林區',
                            'state' => '新北市',
                            'country' => 'SG',
                            'postcode' => '238',
                        ],
                        'businessAddress' => [
                            'addressLine1' => '中華路weqwe',
                            'addressLine2' => '123123',
                            'city' => '樹林區',
                            'state' => '新北市',
                            'country' => 'SG',
                            'postcode' => '238',
                        ],
                    ],
                    'regulatoryDetails' => [
                        'unregulatedTrustType' => [
                            'TT002',
                        ],
                    ],
                    'documentDetails' => [
                        [
                            'documentType' => 'TRUST_DEED',
                            'document' => [
                                [
                                    'fileName' => '2016_833.png',
                                    'fileType' => 'image/png',
                                    'document' => '{{base64_doc}}',
                                ],
                                [
                                    'fileName' => '2016_834.png',
                                    'fileType' => 'image/png',
                                    'document' => '{{base64_doc}}',
                                ],
                            ],
                        ],
                    ],
                    'stakeholders' => [
                        [
                            'stakeholderDetails' => [
                                'kycMode' => 'MANUAL_KYC',
                                'firstName' => 'C',
                                'lastName' => 'CC',
                                'nationality' => 'SG',
                                'dateOfBirth' => '2000-01-01',
                                'countryOfResidence' => 'SG',
                                'address' => [
                                    'addressLine1' => 'Tucson',
                                    'addressLine2' => 'AZ 85721',
                                    'city' => null,
                                    'state' => '00000',
                                    'country' => 'SG',
                                    'postcode' => '11111',
                                ],
                                'professionalDetails' => [
                                    [
                                        'position' => 'SETTLOR',
                                    ],
                                ],
                                'documentDetails' => [
                                    [
                                        'documentType' => 'NATIONAL_ID',
                                        'documentNumber' => 'K0000000E',
                                        'documentIssuanceCountry' => 'SG',
                                        'documentExpiryDate' => null,
                                        'document' => [
                                            [
                                                'fileName' => '2016_831.png',
                                                'fileType' => 'image/png',
                                                'document' => '{{base64_doc}}',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'applicantDetails' => [
                        'kycMode' => 'MANUAL_KYC',
                        'firstName' => 'EEEEEE',
                        'lastName' => 'EE',
                        'nationality' => 'SG',
                        'dateOfBirth' => '2000-01-01',
                        'countryOfResidence' => 'SG',
                        'address' => [
                            'addressLine1' => 'singapore',
                            'addressLine2' => 'Staten Island',
                            'city' => 'SG',
                            'state' => '00000',
                            'country' => 'SG',
                            'postcode' => '12809',
                        ],
                        'contactDetails' => [
                            'email' => 'evelyn003@owlting.com', // 不能重覆
                            'countryCode' => 'SG',
                            'contactNo' => '2222233303', // 不能重覆
                        ],
                        'professionalDetails' => [
                            [
                                'position' => 'SETTLOR',
                            ],
                        ],
                        'documentDetails' => [
                            [
                                'documentType' => 'NATIONAL_ID',
                                'documentNumber' => 'K0000000E',
                                'documentIssuanceCountry' => 'SG',
                                'documentExpiryDate' => null,
                                'document' => [
                                    [
                                        'fileName' => '2016_832.png',
                                        'fileType' => 'image/png',
                                        'document' => '{{base64_doc}}',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            //  dd($nium_client_info);
            $rst = $this->applicationOnboard->onboardCorporateCustomer(
                $nium_client_info,
                $application,
                $kyb_data
            );
            dd($rst);
        }

        if ('upload_corporate_customer_document' == $mode) {
            $nium_client_info = NiumBaaSClientInfo::find(2);
            $nium_customer_id = 'cca39bbf-d950-43ae-aa16-beb143c61164';
            $kyb_data = [
                'region' => 'US',
                'businessDetails' => [
                    'stakeholders' => [
                        [
                            'stakeholderDetails' => [
                                'documentDetails' => [
                                    [
                                        'document' => [
                                            [
                                                'document' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAAXNSR0IArs4c6QAAAA1JREFUGFdjYGBg+A8AAQQBAHAgZQsAAAAASUVORK5CYII=',
                                                'fileName' => 'filename.jpg',
                                                'fileType' => 'jpg',
                                            ],
                                        ],
                                        'documentExpiryDate' => '2025-02-02',
                                        'documentHolderName' => 'documentHolderName',
                                        'documentIssuanceCountry' => 'US',
                                        'documentIssuanceState' => 'CA',
                                        'documentIssuedDate' => '2012-03-24',
                                        'documentIssuingAuthority' => 'documentIssuingAuthority',
                                        'documentNumber' => 'documentNumber',
                                        'documentType' => 'PASSPORT',
                                    ],
                                ],
                            ],
                            'referenceId' => '5b4ce1c9-0745-42ff-9810-a135fb7f218c',
                        ],
                    ],
                ],
            ];

            $rst = $this->niumBaaSPayoutService->uploadCorporateCustomerDocument($nium_client_info, 'US', $nium_customer_id, $kyb_data);
            dd($rst);
        }

        if ('fetch_customer_detail_v2' == $mode) {
            $nium_client_info = NiumBaaSClientInfo::find(1);
            $nium_customer_id = '1e1c9875-d7cf-4364-a614-f9cd58a8246e'; // valid
            // $nium_customer_id = '1e1c9875-d7cf-4364-a614-f9cd58a8246e11'; // invalid

            $niumBaasCustomerDetails = $this->niumBaaSPayoutService->fetchCustomerDetail($nium_client_info, $nium_customer_id);
            if (isset($niumBaasCustomerDetails['customerHashId'])) {
                dd($niumBaasCustomerDetails);
            } else {
                $this->error('customer not found.');
            }

            // $businessDetails = [
            //     'businessName' => 'a',
            //     'businessRegistrationNumber' => 'a',
            //     'businessType' => 'a',
            //     'description' => 'a',
            //     'stockSymbol' => 'a',

            //     'addresses' => [
            //         'registeredAddress' => [
            //             'addressLine1' => 'b',
            //             'addressLine2' => 'b',
            //             'city' => 'b',
            //             'state' => 'b',
            //             'country' => 'b',
            //             'postcode' => 'b',
            //         ],
            //         'businessAddress' => [
            //             'addressLine1' => 'b',
            //             'city' => 'c',
            //         ],
            //     ],
            // ];

            // $registered_city = $businessDetails['addresses']['registeredAddress']['city'];
            // $business_city = $businessDetails['addresses']['businessAddress']['city'];

            // if (!isset($registered_city)) {
            //     $businessDetails['addresses']['registeredAddress']['city'] = 'Singapore';
            // }

            // if (!isset($business_city)) {
            //     $businessDetails['addresses']['businessAddress']['city'] = 'Singapore';
            // }

            // dd($businessDetails);
        }

        if ('fetch_config' == $mode) {
            // $nium_client_info = null;

            // $rst = $this->niumBaaSPayoutService->fetchFeeConfiguration($nium_client_info);
            // dd($rst);

            $rst = $this->niumBaaSPayoutService->getNiumBaaSClientInfoByRegion('SG');
            dd($rst);
        }

        if ('fetch_payout_details' == $mode) {
            $nium_client_info = NiumBaaSClientInfo::find(3);
            $customer_id = '2b4decb1-f7f1-40ca-99f7-c43c51fa99a5';
            $wallet_id = '42078837-c7d1-4fc8-9dd5-52a03e1addd9';

            // FW: third party prefunding
            // https://apisandbox.spend.nium.com/api/v1/client/66ec6e47-bceb-437f-bf09-c7537dad6b6b/customer/2b4decb1-f7f1-40ca-99f7-c43c51fa99a5/wallet/42078837-c7d1-4fc8-9dd5-52a03e1addd9/transactions?authCode=FW5501674439
            // https://apisandbox.spend.nium.com/api/v1/client/66ec6e47-bceb-437f-bf09-c7537dad6b6b/customer/2b4decb1-f7f1-40ca-99f7-c43c51fa99a5/wallet/42078837-c7d1-4fc8-9dd5-52a03e1addd9/transactions
            $rst = $this->niumBaaSPayoutService->fetchPayoutDetails($nium_client_info, $customer_id, $wallet_id);
            dd($rst);
        }

        if ('fetch_client_detail' == $mode) {
            $nium_client_info = NiumBaaSClientInfo::find(1);
            $rst = $this->niumBaaSPayoutService->fetchClientDetail($nium_client_info);
            dd($rst);
        }

        if ('fetch_wallet_balance' == $mode) {
            $nium_client_info = NiumBaaSClientInfo::find(2);
            // $rst = $this->niumBaaSPayoutService->fetchClientDetail($nium_client_info);
            $rst = $this->niumBaaSPayoutService->fetchWalletBalance($nium_client_info, 'cca39bbf-d950-43ae-aa16-beb143c61164', '74a14d0e-5db9-4018-9787-c7574ec20edd');
            dd($rst);
        }

        if ('send_payout' == $mode) {
            $nium_client_info = NiumBaaSClientInfo::find(1);
            $rst = $this->niumBaaSPayoutService->createPayout(Application::find(3), Payout::find(9), []);
            dd($rst);
        }

        if ('add_beneficiary' == $mode) {
            $nium_client_info = NiumBaaSClientInfo::find(1);
            $customer_info = NiumBaaSCustomerInfo::find(1);
            $beneficiary = NiumBaaSBeneficiaryInfo::find(1);

            $beneficiary_info['beneficiaryAccountType'] = 'Company';
            // TODO錯誤
            $rst = $this->niumBaaSOnboardService->addBeneficiaryDetails($nium_client_info, $customer_info->id, $beneficiary_info, null);
            dd($rst);
        }

        if ('delete_beneficiary' == $mode) {
            $nium_client_info = NiumBaaSClientInfo::find(2);
            $customer_id = 'f6a10648-2a64-4ace-b949-beba8732a6e9';
            // $customer_id = '22bef48f-6b39-42cd-823a-bde67f3ab3ee';
            $beneficiary_id = '6833f01d6b2717020bacffb3';

            $rst = $this->niumBaaSOnboardService->deleteBeneficiary($nium_client_info, $customer_id, $beneficiary_id);
            dd($rst);
        }

        if ('fetch_onboarding_rfi_details' == $mode) {
            $nium_baas_client_info = NiumBaaSClientInfo::find(1);
            $region = 'SG';
            $onboarding_client_id = 'NIM1682495420975';
            $case_id = '0561954f-abb0-4c5e-96cd-6769b9277655';
            $rfi_details = $this->niumBaaSPayoutService->fetchCorporateCustomerRfiDetails($nium_baas_client_info, $region, $onboarding_client_id, $case_id);

            $requested_rfi_details = array_filter($rfi_details, function ($rfi_details) {
                return NiumBaaSEnum::RFI_STATUS_REQUESTED == $rfi_details['status'];
            });

            dd($requested_rfi_details);
        }

        if ('fetch_payout_details_by_rn' == $mode) {
            // // SG Testing
            // $nium_baas_client_info = NiumBaaSClientInfo::find(1);
            // $nium_customer_info = NiumBaaSCustomerInfo::find(3);
            // $system_reference_number = 'RT3063782806';

            // // HK Testing
            // $nium_baas_client_info = NiumBaaSClientInfo::find(3);
            // $nium_customer_info = NiumBaaSCustomerInfo::find(3);
            // $system_reference_number = 'FW5501674439';

            // US Testing
            $nium_baas_client_info = NiumBaaSClientInfo::find(2);
            $nium_customer_info = NiumBaaSCustomerInfo::find(4);
            // $system_reference_numbers = [
            //     'FW2215567100',
            //     'FW1645077903',
            //     'FW9059993625',
            //     'FW8472619032',
            //     'FW6382521040',
            //     'FW9958216957'
            // ];

            $system_reference_numbers = [
                'FW4409403770',
                'FW6439308277',
                'FW9477664139',
            ];

            $rst = [];
            foreach ($system_reference_numbers as $rn) {
                $payout_details = $this->niumBaaSPayoutService->fetchTransactionDetailBySystemReferenceNumber($nium_baas_client_info, $nium_customer_info, $rn);
                // dd($payout_details);
                $rst = array_merge($rst, $payout_details);
            }

            // dd($rst);
            file_put_contents('nium_baas_txn_details.json', json_encode($rst));

            // $requested_rfi_details = array_filter($rfi_details, function ($rfi_details) {
            //     return NiumBaaSEnum::RFI_STATUS_REQUESTED == $rfi_details['status'];
            // });

            // dd($requested_rfi_details);
        }

        if ('fetch_payout_life_cycle' == $mode) {
            $nium_customer_info = NiumBaaSCustomerInfo::find(2);
            // dd($nium_customer_info->nium_baas_client_info);
            $system_reference_number = 'RT3752729868';
            $payout_life_cycle = $this->niumBaaSPayoutService->fetchPayoutLifeCycle($nium_customer_info, $system_reference_number);
            // dd($payout_life_cycle);

            foreach ($payout_life_cycle as $payout) {
                if ('RETURN' != $payout['status']) {
                    continue;
                }
                $returned_reason = $payout['statusDetails'];
            }

            // Returned reason=Transaction was returned. Reason - Modulus check failed. BBAN: | Modulus check failed. BBAN:HBUK40190012345679,UnexecutableCode.InboundSchemeNotAccepted; Inbound scheme not accepted

            dd($returned_reason);
            // $requested_rfi_details = array_filter($rfi_details, function ($rfi_details) {
            //     return NiumBaaSEnum::RFI_STATUS_REQUESTED == $rfi_details['status'];
            // });

            // dd($requested_rfi_details);
        }
    }
}
