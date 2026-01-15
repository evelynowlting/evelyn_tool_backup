<?php

namespace App\Console\Commands\PayoutChannel;

use App\Models\NiumBaaSClientInfo;
use App\Models\NiumBaaSCustomerInfo;
use App\Services\Payout\NiumBaaSUsAchService;
use Illuminate\Console\Command;

class NiumBaaSUsAchUtil extends Command
{
    // /**
    //  * The name and signature of the console command.
    //  *
    //  * @var string
    //  */
    protected $signature = 'nium-baas-ach:util
                     {--mode=""}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Nium BaaS ach tool to test the APIs of direct debit.';

    // protected $niumBaaSUsAchService;

    // public function __construct(NiumBaaSUsAchService $niumBaaSUsAchService)
    // {
    //     parent::__construct();
    //     $this->niumBaaSUsAchService = $niumBaaSUsAchService;
    // }

    public function handle()
    {
        //     $modes = [
    //         'add_fi',
    //         'delete_fi',
    //         'get_fi_details',
    //         'fetch_fi_details',
    //         'fetch_fi_list',
    //         'confirm_fi',
    //         'fund_wallet',
    //         'get_fi_by_customer',
    //         'get_fi_by_status',
    //         'simulate_md_funding',
    //     ];
    //     $mode = strtolower(trim($this->option('mode')));

    //     if (!in_array($mode, $modes)) {
    //         $this->error('Please input correct mode.');

    //         $this->info('For adding fi, please enter --mode=add_fi');
    //         $this->info('For deleting fu, please enter --mode=delete_fi');
    //         $this->info('For fetching fi details from Nium, please enter --mode=fetch_fi_details');
    //         $this->info('For getting fi details, please enter --mode=get_fi_details');
    //         $this->info('For getting fi list, please enter --mode=fetch_fi_list');
    //         $this->info('For confirming fi, please enter --mode=confirm_fi');
    //         $this->info('For payin, please enter --mode=fund_wallet');
    //         $this->info('For getting fi by customer, please enter --mode=get_fi_by_customer');
    //         $this->info('For getting fi by status, please enter --mode=get_fi_by_status');
    //         $this->info('For simulating micro-deposit funding on sandbox, please enter --mode=simulate_md_funding');

    //         return;
    //     }

    //     if ('add_fi' == $mode) {
    //         $client_info = NiumBaaSClientInfo::find(2);
    //         $customer_info = NiumBaaSCustomerInfo::find(2);
    //         $customer_id = $customer_info->customer_id;

    //         $funding_instrument = $this->niumBaaSUsAchService->addFundingInstrument($customer_info);
    //         $funding_instrument_id = $funding_instrument['fundingInstrumentId'];
    //         $this->niumBaaSUsAchService->updateOrCreateFundingInstrument($customer_id, $funding_instrument, '');
    //         $this->info("client id={$client_info->client_id}, customer id={$customer_info->customer_id} and funding instrument id={$funding_instrument_id}");
    //         dd($funding_instrument);
    //     }

    //     if ('get_fi_details' == $mode) {
    //         $funding_instrument_id = strtolower(trim($this->ask('Please provide the fi id: ')));

    //         $client_info = NiumBaaSClientInfo::find(2);
    //         $customer_info = NiumBaaSCustomerInfo::find(5);

    //         // delete already
    //         // $funding_instrument_id = 'e8aa5009-9371-495b-b3dd-c0db5d7bbd07'; customer 2
    //         $funding_instrument_id = '151c67aa-8362-44ec-9c9c-add9a918da48'; // customer 5

    //         $rst = $this->niumBaaSUsAchService->fetchFundingInstrumentDetails($customer_info, $funding_instrument_id);
    //         $this->info("client id={$client_info->client_id}, customer id={$customer_info->customer_id} and funding instrument id={$funding_instrument_id}");
    //         dd($rst);
    //     }

    //     if ('simulate_md_funding' == $mode) {
    //         // $funding_instrument_id = strtolower(trim($this->ask('Please provide the fi id: ')));

    //         // $client_info = NiumBaaSClientInfo::find(2);
    //         // $customer_info = NiumBaaSCustomerInfo::find(2);

    //         // $rst = $this->niumBaaSUsAchService->simulateMicrodepositFunding($customer_info, $funding_instrument_id);
    //         // $this->info("client id={$client_info->client_id}, customer id={$customer_info->customer_id} and funding instrument id={$funding_instrument_id}");
    //         // dd($rst);
    //     }

    //     if ('fetch_fi_list' == $mode) {
    //         $customer_info = NiumBaaSCustomerInfo::find(2);

    //         $rst = $this->niumBaaSUsAchService->fetchFundingInstrumentList($customer_info);
    //         dd($rst);
    //     }

    //     if ('get_fi_by_customer' == $mode) {
    //         $customer_info = NiumBaaSCustomerInfo::find(2);
    //         $customer_id = $customer_info->customer_id;

    //         $rst = $this->niumBaaSUsAchService->getFundingInstrumentByCustomerId($customer_id);
    //         dd($rst);
    //     }

    //     if ('get_fi_by_status' == $mode) {
    //         $customer_info = NiumBaaSCustomerInfo::find(2);
    //         $customer_id = $customer_info->customer_id;

    //         // $rst = $this->niumBaaSUsAchService->getFundingInstrumentByCustomerAndStatus($customer_id, ['DeLinked']);
    //         $rst = $this->niumBaaSUsAchService->getFundingInstrumentByCustomerAndStatus($customer_id, ['PENDING']);
    //         dd($rst);
    //     }

    //     if ('delete_fi' == $mode) {
    //         $customer_info = NiumBaaSCustomerInfo::find(2);

    //         $funding_instrument_id = 'e8aa5009-9371-495b-b3dd-c0db5d7bbd07';

    //         $rst = $this->niumBaaSUsAchService->deleteFundingInstrument($customer_info, $funding_instrument_id);
    //         dd($rst);

    //         // Delete delted instrument
    //         // array:3 [
    //         //     "status" => "BAD_REQUEST"
    //         //     "message" => "fundingInstrumentId 5b7096d9-eeff-488d-99bd-603a0ef9f7c0 has been previously deleted"
    //         //     "errors" => array:1 [
    //         //       0 => "fundingInstrumentId 5b7096d9-eeff-488d-99bd-603a0ef9f7c0 has been previously deleted"
    //         //     ]
    //         //  ]

    //         // Delete pending instrument
    //         // array:4 [
    //         //     "status" => "OK"
    //         //     "message" => "Success"
    //         //     "code" => "200 OK"
    //         //     "body" => "fundingInstrument with id 1e87f675-141f-42a6-8402-49677ca1dea9 deleted successfully"
    //         //  ]
    //     }

    //     if ('confirm_fi' == $mode) {
    //         $customer_info = NiumBaaSCustomerInfo::find(5);
    //         $otp = '';

    //         $funding_instrument_id = strtolower(trim($this->ask('Please provide the fi id: ')));

    //         $funding_instrument_id = '151c67aa-8362-44ec-9c9c-add9a918da48';
    //         $rst = $this->niumBaaSUsAchService->confirmFundingInstrument($customer_info, $funding_instrument_id);

    //         if (!isset($confirmDetail['redirectURL'])) {
    //             $funding_instrument = $this->niumBaaSUsAchService->fetchFundingInstrumentDetails($customer_info, $funding_instrument_id);
    //             $status = $funding_instrument['status'];
    //             $statusDescription = $funding_instrument['statusDescription'];

    //             $this->error(sprintf('[Nium BaaS ACH]fetch ach confirm redirect URL failed. Funding instrument id %s, status %s and status description %s', $funding_instrument_id, $status, $statusDescription));

    //             dd(['redirectUrl' => null]);
    //         }

    //         dd($rst);
    //     }

    //     if ('fund_wallet' == $mode) {
    //         $customer_info = NiumBaaSCustomerInfo::find(2);
    //         $funding_instrument_id = '';
    //         $payin_details = [
    //             'destination_amount' => 100,
    //             'destination_currency_code' => 'USD',
    //             'source_currency_code' => 'USD',
    //             'payin_narrative' => 'narrative',
    //         ];

    //         $rst = $this->niumBaaSUsAchService->FundWallet(
    //             $customer_info,
    //             $funding_instrument_id,
    //             $payin_details
    //         );
    //         var_dump($rst);

    //         // {
    //         //     "status": "BAD_REQUEST",
    //         //     "message": "funding Instrument Id is not Linked",
    //         //     "errors": [
    //         //         "funding Instrument Id is not Linked"
    //         //     ]
    //         // }
    //     }
    }
}
