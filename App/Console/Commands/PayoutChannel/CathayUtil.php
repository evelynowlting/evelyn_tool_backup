<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\PayoutChannel\CathayBankEnum;
use App\Models\Application;
use App\PayoutGateways\CathayBankB2B;
use App\Services\Payout\CathayBankService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CathayUtil extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cathay:util
        {mode : Mode to perform (create_remit, show_file_type, qresult_w_err, qresult_wo_err, qupload...).}
        {--pay_date=}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This is a command line tool for creating and query remittance for CUB payout.';

    protected $cathayBankService;

    protected $cathayBankB2B;

    /**
     * Create a new command instance.
     *
     * @return void'
     */
    public function __construct(
        CathayBankService $cathayBankService,
        CathayBankB2B $cathayBankB2B,
    ) {
        parent::__construct();
        $this->cathayBankService = $cathayBankService;
        $this->cathayBankB2B = $cathayBankB2B;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $modes = ['create_remit', 'show_file_type', 'qresult_w_err', 'qresult_wo_err', 'qupload'];
        $mode = strtolower(trim($this->argument('mode')));
        $payout_option = 'manual';

        if (!in_array($mode, $modes)) {
            $this->error('Please input correct mode.');

            $this->info('--mode');
            $this->info('   create_remit: create a remittance');
            $this->info('   qresult_w_err: query a remittance result with error message.');
            $this->info('   qresult_wo_err: query a remittance result without error message.');
            $this->info('   qupload: query remittance file upload progress.');
            $this->info('   show_file_type: show the file type of remittance.');

            return 1;
        }

        // !!!!!! 以下為真實帳號 公司國泰世華
        // $hsm_formatted_data_cathay = [
        //     [
        //         'bank_code' => '0132631',
        //         'bank_account' => '263035004689',
        //         'payout_total' => '5',
        //         'bank_account_name' => '歐簿客科技股份有限公司',
        //         'vendor_id' => '1',
        //         'vendor_name' => 'Test Vendor Name',
        //         'payout_date' => Carbon::now()->timezone('Asia/Taipei')->format(CathayBankService::CATHAY_BANK_DATE_FORMAT),
        //         'description' => '全自動測試-國泰行內互轉',
        //     ],
        // ];
        $hsm_formatted_data_cathay = [
            [
                'bank_code' => '0132125',
                'bank_account' => '212506020813',
                'payout_total' => '5',
                'bank_account_name' => '众hotel',
                'vendor_id' => '1',
                'vendor_name' => 'Test Vendor Name',
                'payout_date' => Carbon::now()->timezone('Asia/Taipei')->format(CathayBankService::CATHAY_BANK_DATE_FORMAT),
                'description' => '全自動測試-國泰行內互轉',
            ],
        ];

        // !!!!!! 以下為真實帳號 公司花旗
        $hsm_formatted_data_citi = [
            [
                'bank_code' => '0210018',
                'bank_account' => '5831583012',
                'payout_total' => '5',
                'bank_account_name' => '歐簿客科技股份有限公司',
                'vendor_id' => '1',
                'vendor_name' => 'Test Vendor Name',
                'payout_date' => Carbon::now()->timezone('Asia/Taipei')->format(CathayBankService::CATHAY_BANK_DATE_FORMAT),
                'description' => '全自動測試-花旗跨行轉帳',
            ],
        ];

        $non_hsm_formatted_data = [
            [
                // 'bank_code' => '0132125',
                'bank_code' => '013',
                'branch_code' => '2125',
                'bank_account' => '212506020813',
                'payout_total' => '1',
                'bank_account_name' => '超級五星大飯店',
                'vendor_id' => '1',
                'vendor_name' => 'Test Vendor Name',
                'payout_date' => Carbon::now()->timezone('Asia/Taipei')->format(CathayBankService::CATHAY_BANK_DATE_FORMAT),
                'description' => '半自動測試',
            ],
            // 戶名含異體字的測試資料 請勿刪除
            //  [
            //     'bank_code' => '0073031',
            //     'bank_account' => '00019240067007',
            //     'payout_total' => '200',
            //     'bank_account_name' => '众樂股份有限公司',
            //     'name' => '2:Evelyn民宿',
            //     'vendor_id' => '2',
            //     'payout_date' => '20211018',
            //  ],
            // [
            //     'bank_code' => '0121234',
            //     'bank_account' => '1111111111111113',
            //     'payout_total' => '300',
            //     'bank_account_name' => '李欧媫',
            //     'name' => '3:台灣33大飯店',
            //     'vendor_id' => '3',
            //     'payout_date' => '20211018',
            //  ],
            //  [
            //     'bank_code' => '0121234',
            //     'bank_account' => '1111111111111114',
            //     'payout_total' => '400',
            //     'bank_account_name' => '李欧華',
            //     'name' => '4:台灣44大飯店',
            //     'vendor_id' => '4',
            //     'payout_date' => '20211018',
            //  ],
            //  [
            //     'bank_code' => '0121234',
            //     'bank_account' => '1111111111111115',
            //     'payout_total' => '500',
            //     'bank_account_name' => '陳小明',
            //     'name' => '5:台灣55大飯店',
            //     'vendor_id' => '5',
            //     'payout_date' => '20211018',
            //  ],
        ];

        $payouts = [
            1 => collect([
                (object) ['id' => 1],
            ]),
            2 => collect([
                (object) ['id' => 2],
            ]),
            3 => collect([
                (object) ['id' => 3],
            ]),
        ];

        // 發動國泰世華轉帳的參數設定
        if ('create_remit' == $mode) {
            $$payout_option = 'manual';
            // $payout_option = strtolower(trim($this->ask('Which is your payout option (manual or auto)?')));
            // $this->info('Your payout option is: '.$payout_option.' payout.');
            // if (!in_array($payout_option, ['manual', 'auto'])) {
            //     $this->error('Please check payout option!! (manual or auto)');

            //     return;
            // }
            // $formatted_data = ('manual' == $payout_option) ? $non_hsm_formatted_data : '';
            $formatted_data = $non_hsm_formatted_data;
            // if (('auto' == $payout_option)) {
            //     $bank_option = strtolower(trim($this->ask('Which is your remittance option (cathay or citi)?')));
            //     $this->info('The sender bank is: '.$bank_option);

            //     $formatted_data = ('cathay' == $bank_option) ? $hsm_formatted_data_cathay : $hsm_formatted_data_citi;
            // }
        }

        // TODO: 讀外檔案，放在configuration
        $cathay_bank_info_dev = (object) [
            'tax_id_no' => '53092632',
            'bank_code' => '0132631',
            'bank_account' => '001035077843',
            'branch_code' => '269',
            'bank_account_name' => '奧丁丁旅行社',
            'encrypt_key' => '1234123412341234',
            'user_name' => 'user01',
            'user_password' => 't123123',
            'private_key_name' => 'TEST_PRI_OwlPay_CUB_202110',
            'cert_name' => 'TEST_CER_OwlPay_CUB_202110',
            'payout_option' => $payout_option ?? 'manual',
            'fee_options' => CathayBankEnum::CLIENT_INFO_FEE_OPTIONS_OUR,
        ];

        $cathay_bank_info_prod = (object) [
            'tax_id_no' => '53092632',
            // 'bank_code' => '0132631',
            'bank_code' => '013',
            'bank_account_name' => '歐簿客科技股份有限公司',
            'bank_account' => '269035004695',
            // 'bank_account' => '263035081799',
            //    'bank_account_name' => '奧丁丁旅行社',
            // 'branch_code' => '2631',
            'branch_code' => '0031',
            // 'api_branch_code' => '263',
            'api_branch_code' => '003',
            'encrypt_key' => 'A01HCRP10XVFG38E',
            'user_name' => 'OWLPAY',
            'user_password' => 'owlpay21',
            'private_key_name' => 'PROD_PRI_OwlPay_CUB_202111',
            'cert_name' => 'PROD_CER_OwlPay_CUB_202111',
            'payout_option' => $payout_option ?? 'manual',
            'fee_options' => CathayBankEnum::CLIENT_INFO_FEE_OPTIONS_OUR,
        ];

        $env = strtolower(trim($this->ask('What is your env (dev or prod)?')));
        $cathay_bank_info = ('dev' == $env) ? $cathay_bank_info_dev : $cathay_bank_info_prod;

        // 發動國泰世華轉帳
        // The payout includes automatic and semi-automatic for money transfer
        if ('create_remit' == $mode) {
            if (empty($formatted_data)) {
                $this->error('Please check formatted_data.');

                return;
            }

            $today = now()->timezone('Asia/Taipei')->format(CathayBankService::CATHAY_BANK_DATE_FORMAT);
            $pay_date = $this->option('pay_date') ? $this->option('pay_date') : $today;
            if ($pay_date < $today) {
                $this->info('Pay date can NOT be less than today.');

                return;
            }

            $this->cathayBankB2B->__construct(Application::find(43));
            $this->cathayBankB2B->setCathayBankInfo($cathay_bank_info);
            $this->cathayBankB2B->setFormattedData($formatted_data);
            $content = $this->cathayBankB2B->generateRemitContent($payouts);
            $remit_content = null;
            $atm_content = $content['atm_remit']['information'];
            $auto_content = $content['auto_remit']['information'];
            $file_type = (!empty($auto_content)) ? CathayBankService::CATHAY_BATCH_REMIT_FILE_TYPE : CathayBankService::CATHAY_BATCH_ATM_REMIT_FILE_TYPE;

            $dry_run = strtolower(trim($this->ask('CUB payout dry run? (Y or N)')));

            if ('y' == $dry_run) {
                $this->info('CUB Payout dry run.');
                var_dump($cathay_bank_info);
                dd($content);
            } else {
                $this->info('CUB Payout NOT dry run.');
                $confirm = strtolower(trim($this->ask('Confirm sending '.$payout_option.' payout OK ? (ok or nok)')));
                // var_dump($content);
                if ('nok' == $confirm) {
                    $this->info('CUB payout do NOTHING.');

                    return;
                }
            }

            // TODO: 這邊的邏輯只是為了單一筆測試，實際上有可能auto_remit跟atm_remit都存在
            if (!empty($auto_content)) {
                $remit_content = $auto_content;
            } else {
                $remit_content = $atm_content;
            }

            $batch_no = $this->cathayBankService->createCathayBatchPayout($remit_content, $cathay_bank_info, $pay_date, $file_type);
            if (!empty($batch_no)) {
                $this->info('Remittance ID: '.$batch_no);
            } else {
                $this->info('Oooops! Something happened. Please check system log.');
            }
        }

        // 查詢國泰世華所有交易結果
        if ('qresult_w_err' == $mode || 'qresult_wo_err' == $mode) {
            $query_time = strtolower(trim($this->ask('How many numbers your would like to query? For example, 3')));
            if (!is_numeric($query_time)) {
                $this->error('Please input a numeric value.');

                return;
            }

            while (0 != $query_time) {
                // $file_type = trim($this->ask('What is your file_type atm or remit? (atm: BTRS/BRMT/0   remit: BMUL/BRMT/0,  BRMT/BRMT/0)'));
                $type = strtolower(trim($this->ask('What is your file_type atm or remit? (atm: BTRS/BRMT/0   remit: BMUL/BRMT/0,  BRMT/BRMT/0)')));
                $file_type = ('atm' == $type) ? 'BTRS/BRMT/0 ' : 'BMUL/BRMT/0';
                $from_date = trim($this->ask('What is your from_date ex: 20201023'));
                $to_date = trim($this->ask('What is your to_date(如果是預約請填預約付款日) ex: 20201023'));

                $batch_no = trim($this->ask('What is the batch_no you would like to look up? ex: 22009704'));

                if (empty($from_date) || empty($to_date) || empty($file_type)) {
                    $this->error('Please check payout details.');
                }

                if ('qresult_wo_err' == $mode) {
                    $batch_no = '';
                }

                $rst = $this->cathayBankService->queryResult($from_date, $to_date, $batch_no, $cathay_bank_info, $file_type);

                if (config('payoutchannel.cathay.debug')) {
                    $this->info('Debug file can be found in S3.');
                    $this->info('');
                }

                $response_code = $rst->HEADER->RETURN_CODE;
                $response_msg = $rst->HEADER->RETURN_DESC;
                $this->info($response_code);
                $this->info($response_msg);

                if (1 != $query_time) {
                    $quit = strtolower(trim($this->ask('Quit? Y or N')));
                    if ('y' == $quit) {
                        return;
                    }
                }
                --$query_time;
            }
        }

        // 查詢國泰轉帳建檔狀況
        if ('qupload' == $mode) {
            $type = strtolower(trim($this->ask('What is your file_type atm or remit? (atm or remit)')));
            $file_type = ('atm' == $type) ? 'BTRS/BRMT/0' : 'BMUL/BRMT/0';
            $st = strtolower(trim($this->ask('What status you want to query? (all or label:deleted, waiting, created)')));
            $status = ['all', 'deleted', 'waiting', 'created'];
            if (!in_array($st, $status)) {
                $this->error('Please input correct status.');
                $this->info('For query a remittance file with status deleted, please enter --mode=deleted');
                $this->info('For query a remittance file with status waiting for create, please enter --mode=waiting');
                $this->info('For query a remittance file with status created, please enter --mode=created');

                return;
            }
            $upload_date = trim($this->ask('What is your upload_date ex: 20211230'));
            $upload_batchno = trim($this->ask('What is your batch number ex: 53892515'));
            $batch_no = ('' != $upload_batchno) ? $upload_batchno : '';

            if ('deleted' == $st) {
                $label = CathayBankEnum::RM_FILE_DELETED;
            } elseif ('waiting' == $st) {
                $label = CathayBankEnum::RM_FILE_WAITING_FOR_CREATION;
            } elseif ('created' == $st) {
                $label = CathayBankEnum::RM_FILE_CREATED;
            } else {
                $label = '';
            }

            $rst = $this->cathayBankService->queryUploadProgress($cathay_bank_info, $upload_date, $batch_no);

            dd($rst);
            // if (config('payoutchannel.cathay.debug')) {
            //     $this->info('Debug file can be found in S3.');
            // }

            // if (!empty($rst)) {
            //     dd($rst);
            // }
        }

        // 查詢國泰世華的轉帳類別
        // file_type 轉帳 'BMUL/BRMT/0';
        // file_type ATM 'BTRS/BRMT/0';
        if ('show_file_type' == $mode) {
            $mode = $this->ask('What is your mode (ATM or Remit )');
            if (0 == strcmp($mode, 'ATM')) {
                $this->info('ATM mode is: BTRS/BRMT/0');
            } else {
                $this->info('Remit mode is: BMUL/BRMT/0');
            }
        }

        return 0;
    }
}
