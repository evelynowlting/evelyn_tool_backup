<?php

namespace App\Console\Commands\PayoutChannel;

use App\Enums\AccountingStatusEnum;
use App\Enums\PayoutChannel\CathayBankEnum;
use App\Enums\PayoutStatusEnum;
use App\Events\Payout\AccountingPayoutFailedEvent;
use App\Events\Payout\CathayAccountingPayoutSucceedEvent;
use App\Helpers\Base62Helper;
use App\Models\Accounting;
use App\Models\Application;
use App\Models\Payout;
use App\PayoutGateways\Objects\DTO\PayoutFinishedDTO;
use App\Services\AccountingService;
use App\Services\Payout\CathayBankService;
use App\Services\PayoutService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CathayPayoutStaff extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cathay-payout
                            {--mode=update_status}
                            ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command line tool is to query cathay payouts via API and update status.';

    public const CATHAY_BANK_DATE_FORMAT = 'Ymd';
    public const FILTER_STATUS_LIST = [
        PayoutStatusEnum::STATUS_FINISH,
        PayoutStatusEnum::STATUS_FAILED,
    ];
    public const FILE_TYPE_ATM = 'atm_remit';
    public const CATHAY_9270_PREFIX = CathayBankEnum::CATHAY_9270_PREFIX;
    public const CATHAY_QUERY_DURATION = '+6 day';
    public const CATHAY_TXT_QUERY_HOUR = '17';
    public const CATHAY_TXT_QUERY_MIN = '00';
    public const CATHAY_TXT_QUERY_SEC = '00';

    /**
     * @var CathayBankService
     */
    private $cathayBankService;

    /**
     * @var AccountingService
     */
    private $accountingService;

    /**
     * @var PayoutService
     */
    private $payoutService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        CathayBankService $cathayBankService,
        AccountingService $accountingService,
        PayoutService $payoutService
    ) {
        parent::__construct();
        $this->cathayBankService = $cathayBankService;
        $this->accountingService = $accountingService;
        $this->payoutService = $payoutService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $mode = $this->option('mode');

        if ('update_status' == $mode) {
            // Retrieve the uncompleted payouts from payout table.
            $payouts = $this->cathayBankService->getPayoutsWithoutStatus(self::FILTER_STATUS_LIST);

            if (0 == $payouts->count()) {
                $this->info('[CUB]No new payouts should be handled.');

                return 0;
            }

            $payouts->loadMissing(['accounting', 'application', 'receiver_model', 'meta_data_list']);
            $empty_bank_order_transfers = collect([]);

            $payouts = $payouts->filter(function ($payout) use (&$empty_bank_order_transfers) {
                $payoutData = $payout->meta_data_list->pluck('value', 'key');

                // if (config('app.env') == 'testing'){
                //     return true;
                // }

                if (empty($payoutData['bank_code#target']) || empty($payoutData['account#target']) || empty($payoutData['branch_code#target']) || empty($payoutData['account_name#target'])) {
                    if (!isset($empty_bank_order_transfers[$payout->accounting->id])) {
                        $empty_bank_order_transfers[$payout->accounting->id] = collect();
                    }
                    $empty_bank_order_transfers[$payout->accounting->id] = $empty_bank_order_transfers[$payout->accounting->id]->merge($payout->accounting_details->pluck('order_transfer'));

                    return false;
                }

                return true;
            })->values();

            $expectedAccountingPayouts = $payouts->groupBy('accounting_id');

            $zero_success_order_transfers = $this->getZeroFinishedOrderTransfers($payouts);

            $working_payouts = $payouts->unique('external_payment_uuid')->values();

            if (0 == $working_payouts->count()) {
                $this->info('[CUB]No Cathay payout should be updated.');

                return 0;
            }

            $checked = 0;
            $success_count = 0;
            $scheduled_count = 0;
            $pending_count = 0;
            $failed_count = 0;
            $deleted_count = 0;

            foreach ($working_payouts as $payout) {
                $accounting = $payout->accounting;

                if (!isset($payout_finished_DTO_list[$accounting->id])) {
                    $payout_finished_DTO_list[$accounting->id] = [];
                }

                if (!isset($payouts_fee_list[$accounting->id])) {
                    $payouts_fee_list[$accounting->id] = [];
                }

                if (!isset($success_order_transfers[$accounting->id])) {
                    $success_order_transfers[$accounting->id] = collect();
                }

                if (!isset($failed_order_transfers[$accounting->id])) {
                    if (isset($empty_bank_order_transfers[$accounting->id])) {
                        $failed_order_transfers[$accounting->id] = [
                            'reason_code' => 'OWLPAY_0000',
                            'reason_message' => 'EMPTY_REMIT_INFO_ON_OWLPAY',
                            'order_transfer' => $empty_bank_order_transfers[$accounting->id],
                        ];
                    } else {
                        $failed_order_transfers[$accounting->id] = collect();
                    }
                }

                $accounting_order_transfers = $accounting->order_transfers;
                $application = $payout->application;

                if (!isset($actualAccountingPayoutCount[$accounting->id])) {
                    $actualAccountingPayoutCount[$accounting->id] = 0;
                }

                if (isset($zero_success_order_transfers[$payout->id])) {
                    $zero_order_transfer_uuids = $zero_success_order_transfers[$payout->id]->pluck('uuid')->toArray();
                    _owlPayLog('mark_zero_payout_success', [
                        'zero_order_transfer_uuids' => $zero_order_transfer_uuids,
                        'application_uuid' => $application->uuid,
                        'payout_uuid' => $payout->uuid,
                    ], 'system');
                    $success_order_transfers[$accounting->id]->push($zero_success_order_transfers[$payout->id]);
                }

                // sample external_payment_uuid:  59210397#auto_remit
                if (1 != preg_match("/^(\d+)#(.+)$/", $payout->external_payment_uuid, $matches)) {
                    $this->info('[CUB]Remit type is empty.');
                    continue;
                }

                $batch_payout_id = $matches[1];  // should like "59210397"
                // file type is to determinate the remittance type is ATM mode or general remittance mode.
                $file_type = CathayBankService::CATHAY_BATCH_REMIT_FILE_TYPE;
                if (self::FILE_TYPE_ATM == $matches[2]) {
                    $file_type = CathayBankService::CATHAY_BATCH_ATM_REMIT_FILE_TYPE;
                }

                /* 查詢國泰世華交易結果 */
                // 開始時間：如果有設定預定交易日期，則查詢時間從預定交易日期開始算，否則為當天的日期
                // 結束的時間：如果遇到銀行暫停營業，則查詢時間延長為一週，否則為當天的日期
                // 交易付出去後約兩週還查詢得到結果，所以如果查詢不到就是國泰那邊刪除交易明細
                $payout_date = Carbon::parse($accounting->payout_date)->format(self::CATHAY_BANK_DATE_FORMAT);
                $from_date = empty($payout_date) ? $payout->created_at->format(self::CATHAY_BANK_DATE_FORMAT) : $payout_date;
                $to_date = (self::CATHAY_9270_PREFIX != $batch_payout_id[0]) ? $from_date : date(self::CATHAY_BANK_DATE_FORMAT,
                    strtotime("$from_date ".self::CATHAY_QUERY_DURATION));
                $cathay_bank_info = $application->cathay_bank_info;
                Log::info("[CUB]Start querying remit result from date $from_date to end date $to_date with batch number $batch_payout_id.");

                // 確認檔案上傳進度
                // 注意，傳入日期必須為批次匯款建檔日期，不可以為(預約)交易日期
                $upload_date = Carbon::parse($payout->created_at)->setTimezone('Asia/Taipei')->format(self::CATHAY_BANK_DATE_FORMAT);
                Log::info("[CUB]Start querying upload progress with upload load date $upload_date with batch number $batch_payout_id.");
                $uploadProgressXml = $this->cathayBankService->queryUploadProgress($cathay_bank_info, $upload_date, $batch_payout_id);
                if (is_null($uploadProgressXml)) {
                    Log::info('[CUB]Upload progress API return null.');
                    continue;
                }

                $upload_progress_return_code = $uploadProgressXml->HEADER->RETURN_CODE->__toString();
                $uploadProgressXmlDatas = null;
                // 9997 查詢無資料
                if (CathayBankEnum::DATA_NOT_EXISTED == $upload_progress_return_code) {
                    AccountingPayoutFailedEvent::dispatch($application, $accounting);
                    Log::info("[CUB]Reconciliation file NOT found with batch number $batch_payout_id.");
                    ++$failed_count;
                    continue;
                }

                // 查詢不ＯＫ
                if (CathayBankEnum::QUERY_OK != $upload_progress_return_code) {
                    AccountingPayoutFailedEvent::dispatch($application, $accounting);
                    Log::info("[CUB]UploadProgress not query OK with return code {$upload_progress_return_code} with batch number $batch_payout_id.");
                    ++$failed_count;
                    continue;
                }

                // 查詢ＯＫ
                if (CathayBankEnum::QUERY_OK == $upload_progress_return_code) {
                    Log::info("[CUB]UploadProgress query OK with return code {$upload_progress_return_code} with batch number $batch_payout_id.");
                    $uploadProgressXmlDatas = $uploadProgressXml
                    ->children()
                    ->BODY
                    ->DATAS
                    ->children();
                }

                $uploadProgressList = [];
                foreach ($uploadProgressXmlDatas as $uploadProgressXmlData) {
                    $batch_no = $uploadProgressXmlData->BATCH_NO->__toString();
                    $return_id = $uploadProgressXmlData->RETURN_ID->__toString();
                    $uploadProgressList[$batch_no] = $return_id;
                }
                $return_id = \Arr::first($uploadProgressList);

                // 確認檔案是否已刪除
                if (CathayBankEnum::RM_FILE_DELETED == $return_id) {
                    $failed_message = "[CUB]Reconciliation file is deleted from global myb2b by platform with batch number {$batch_payout_id}.";
                    AccountingPayoutFailedEvent::dispatch($application, $accounting, null, $failed_message);
                    Log::info($failed_message);
                    ++$failed_count;
                    continue;
                }

                Log::info("[CUB]Start querying RMT result with batch_payout_id {$batch_payout_id} and remit type {$file_type}.");
                $result = $this->cathayBankService->queryResult($from_date, $to_date, $batch_payout_id, $cathay_bank_info, $file_type);
                $batch_return_code = $result->children()->HEADER->RETURN_CODE;
                $datas = $result->children()->BODY->DATAS->DATA;

                if (CathayBankEnum::NO_DATA_FOUND == $batch_return_code) {
                    $this->info('[CUB]Batch number not found.');

                    _owlPayLog('payout_batch_no_not_found', [
                        'payout_uuid' => $payout->uuid,
                        'cathay_batch_no' => $batch_payout_id,
                        'payout_date' => $payout_date,
                        'from_date' => $from_date,
                        'to_date' => $to_date,
                        'cathay_return_code' => $batch_return_code,
                    ], 'cathay_b2b', 'error');

                    continue;
                }

                // 詢問結果會不同的就要分開處理
                if (CathayBankEnum::QUERY_OK != $batch_return_code) {
                    $this->info('[CUB]Remittance is NOT success.');
                    // TODO: AccountingPayoutFailedEvent   event($account_log_id, 失敗狀態);
                    // TODO: PayoutFailedEvent 下一輪不在檢查列表
                    // 根據return code來釐清此筆轉帳的問題，比方說
                    // 4001:檢查xml format問題
                    // 4009: AES Decode失敗
                    // 4024: LOGON身份跟簽章不符合
                    continue;
                }

                $success_count = 0;
                $failed_count = 0;
                $deleted_count = 0;
                $pending_count = 0;
                $scheduled_count = 0;
                // Check the status of each single remittance under the same batch payout from Cathay bank.
                Log::info("[CUB]Start checking Cathay payout with batch number: {$batch_payout_id}");

                $payout_id_map = [];
                foreach ($datas as $key => $data) {
                    $remark = trim($data->Remark->__toString());
                    $payout_id_map[$remark] = $this->getPayoutIdByRemark($remark);
                }

                $mapping_payouts = $this->payoutService->getPayoutsByPayoutIds(array_values($payout_id_map), [
                    'accounting_details.order_transfer',
                    'receiver_model',
                ]);

                foreach ($datas as $key => $data) {
                    $finished_time_at = null;
                    $data_status = trim($data->Trasfer_Status->__toString());
                    $error_code = trim($data->ERROR_CODE->__toString());
                    $return_code = trim($data->Rtn_Code->__toString());
                    $error_code = trim($data->Error_Code->__toString());
                    $error_message = trim($data->Error_Msg->__toString());
                    $reason = trim($data->FISC_RefundmentReason->__toString());
                    $remark = trim($data->Remark->__toString());
                    $beneficiary_bank_code = trim($data->Beneficiary_BankCode->__toString());
                    $beneficiary_bank_account_no = trim($data->Beneficiary_AccountNo->__toString());
                    $beneficiary_account_name = trim($data->Beneficiary_Name->__toString());
                    $currency = trim($data->Currency->__toString());
                    $service_charge = trim($data->Service_Charge->__toString());
                    $amount = (float) trim($data->Amount->__toString());
                    $tx_date = trim($data->TxDate->__toString());  // <BODY>/<DATAS>/<DATA>/<TxDate> 檔案上傳日期
                    $pay_date = trim($data->PayDate->__toString()); // <BODY>/<DATAS>/<DATA>/<TxDate> 預定交易日期
                    $tr_date = trim($data->Tr_Date->__toString());
                    $tr_time = trim($data->Tr_Time->__toString());

                    if (!empty($tr_date) && !empty($tr_time)) {
                        $finished_time_at = Carbon::parse("$tr_date $tr_time", $application->timezone)->setTimezone('UTC');
                        Log::info("[CUB]Finished time at {$finished_time_at}.");
                    }

                    // remark: limit 50 bytes
                    if (empty($remark)) {
                        $this->info(sprintf('%s-%s-%s %s',
                            $beneficiary_account_name,
                            $beneficiary_bank_code,
                            $beneficiary_bank_account_no,
                            'remark is empty.')
                        );

                        continue;
                    }

                    $payout_id = $payout_id_map[$remark];

                    if (empty($payout_id)) {
                        continue;
                    }

                    $target_payout = $mapping_payouts->filter(function ($mapping_payout) use ($payout_id) {
                        return $mapping_payout->id == $payout_id;
                    })->first();

                    if (empty($target_payout)) {
                        continue;
                    }

                    $vendor = $target_payout->receiver_model;

                    $order_transfers = $target_payout->accounting_details->pluck('order_transfer');

                    if (empty($order_transfers)) {
                        Log::error('[CUB]Payout order transfers not found', [
                            'payout_id' => $payout_id,
                            'remark' => $remark,
                            'error_code' => $error_code,
                            'error_message' => $error_message,
                        ]);
                        continue;
                    }

                    $settled_total = $order_transfers->sum('settled_total');

                    // if amount > order transfer settled total, maybe the vendor has more order transfers
                    if ($amount != $settled_total) {
                        $order_transfers = $accounting_order_transfers->filter(function ($order_transfer) use ($vendor
                        ) {
                            return $order_transfer->vendor_id == $vendor->id;
                        });

                        $settled_total = $this->payoutService->calculatePayoutTotalByApplication(
                            $application,
                            $order_transfers->sum('settled_total'),
                            $currency
                        );
                    }

                    // check order transfer settled_total sum == response sum
                    if ($amount < $settled_total) {
                        Log::error(
                            '[CUB]Cathay bank amount < OwlPay settled_total',
                            compact('amount', 'settled_total', 'payout_id')
                        );
                        continue;
                    }

                    $target_payout_uuid = $target_payout->uuid;
                    foreach ($order_transfers as $order_transfer) {
                        // Error handling for cases with different Trasfer_Status
                        switch ($data_status) {
                            case CathayBankEnum::STATUS_TXN_IN_QUEUE:   // Trasfer_Status=0
                                /*
                                * case1: 等待建檔中，需財務人員勾選'不檢核戶名'後，就能進行建檔。
                                *        Error_Code='EB0064' , Error_Msg='(上傳行號)[收款人戶名]與銀行不符！'
                                * case2: 財務建檔前。
                                *        Error_Code='' , Error_Msg=''
                                * case3: 財務建檔後，待財務主管覆核。
                                *        Error_Code='' , Error_Msg='待覆核'
                                */
                                if (empty($error_code) && strtotime($pay_date) > strtotime($tx_date)) {
                                    Log::info("[CUB]Payout with uuid {$target_payout_uuid} mark to SCHEDULED in data status $data_status.");
                                    ++$scheduled_count;
                                } elseif (empty($error_code) || CathayBankEnum::BANK_NAME_MISMATCH == $error_code) {
                                    Log::info("[CUB]Payout with uuid {$target_payout_uuid} mark to PENDING.");
                                    ++$pending_count;
                                } else {
                                    Log::info("[CUB]Payout with uuid {$target_payout_uuid} mark to FAILED, because: $error_message.");
                                    $failed_order_transfers[$accounting->id]->push([
                                        'reason_code' => $error_code.':'.$error_message,
                                        'reason_message' => CathayBankEnum::BUILD_FAILED,
                                        'order_transfer' => $order_transfer,
                                    ]);
                                    ++$failed_count;
                                }
                                break;
                            case CathayBankEnum::STATUS_TXN_SUCCESS:   // Trasfer_Status=1
                                if (empty($payout_date)) {
                                    $payout_date = now();
                                } else {
                                    $payout_date = Carbon::parse($pay_date);
                                }

                                $now = now()->timezone(CathayBankService::DOMESTIC_PAYOUT_TIMEZONE);
                                $final_time = $payout_date->timezone(CathayBankService::DOMESTIC_PAYOUT_TIMEZONE)
                                    ->hour(self::CATHAY_TXT_QUERY_HOUR)
                                    ->minute(self::CATHAY_TXT_QUERY_MIN)
                                    ->second(self::CATHAY_TXT_QUERY_SEC);
                                if ($now < $final_time) {
                                    Log::info("[CUB]Payout with uuid {$target_payout_uuid} mark to PENDING.");
                                    ++$pending_count;
                                    break;
                                }
                                Log::info("[CUB]Payout with uuid {$target_payout_uuid} mark to SUCCESS.");

                                $payout_finished_DTO_list[$accounting->id][$target_payout_uuid] = (
                                    new PayoutFinishedDTO(
                                        fee_currency: 'TWD',
                                        fee_total: $service_charge,
                                        finished_time_at: $finished_time_at
                                    )
                                );

                                $success_order_transfers[$accounting->id]->push($order_transfer);
                                ++$success_count;
                                break;
                            case CathayBankEnum::STATUS_TXN_SCHEDULED:   // Trasfer_Status=2
                                Log::info("[CUB]Payout with uuid {$target_payout_uuid} mark to SCHEDULED in data status $data_status.");
                                ++$scheduled_count;

                                // 目前沒遇過，只是以防萬一的log
                                if (!empty($error_code)) {
                                    Log::info("[CUB]Payout with uuid {$target_payout_uuid} mark to SCHEDULED with error {$error_code} and message {$error_message}.");
                                }
                                break;
                            case CathayBankEnum::STATUS_TXN_DELETED:   // Trasfer_Status=254
                                Log::info("[CUB]Payout with uuid {$target_payout_uuid} mark to DELETED.");
                                ++$deleted_count;
                                break;
                            case CathayBankEnum::STATUS_TXN_FAILED:   // Trasfer_Status=255
                            case CathayBankEnum::STATUS_TXN_RETURN:   // Transfer_status=201
                                if (empty($return_code)) {
                                    Log::info("[CUB]Payout with uuid {$target_payout_uuid} is failed with empty return code.");
                                    // break;
                                }
                                /*  銀行暫停營業 */
                                // 處理銀行暫停營業所造成的暫時匯款失敗 例如：颱風天停班
                                // BODY/DATAS/DATA/Rtn_Code=9207
                                if (CathayBankEnum::CODE_BANK_TEMP_CLOSED == $return_code) {
                                    Log::info(sprintf(
                                        '[CUB] Payout with UUID %s is marked as IN_PROCESS due to temporary bank closure. Batch number: %s. [Code: %s]',
                                        $target_payout_uuid,
                                        $batch_payout_id,
                                        $return_code
                                    ));

                                    ++$pending_count;

                                    // 當銀行暫停營業要重新匯款時，批號的第一碼會改成9，所以Payout table裡面的external payment uuid必須更新批號的第一碼
                                    $new_external_payment_uuid = substr_replace($payout->external_payment_uuid,
                                        self::CATHAY_9270_PREFIX, 0, 1);
                                    Log::info("[CUB]External payment uuid has been changed from {$payout->external_payment_uuid} to {$new_external_payment_uuid}.");
                                    $this->payoutService->updatePaymentUUID($payout, $new_external_payment_uuid);
                                } else {
                                    Log::info("[CUB]Payout with uuid {$target_payout_uuid} mark to FAILED.");
                                    ++$failed_count;
                                    /*  退匯 */
                                    // 處理銀行暫停營業所造成的暫時匯款失敗 例如：颱風天停班
                                    // BODY/DATAS/DATA/Rtn_Code=M323
                                    // 退匯可能的原因有 3:帳號錯誤 4:姓名不符 9:滯納
                                    if (CathayBankEnum::CODE_REFUND == $return_code) {
                                        switch ($reason) {
                                            case CathayBankEnum::M323_BANK_ACCOUNT_ERROR:
                                                // 需要發送通知給財務或是後台管理員，並檢查收款方的帳號
                                                $failed_order_transfers[$accounting->id]->push([
                                                    'reason_code' => CathayBankEnum::CODE_REFUND.':'.$reason,
                                                    'reason_message' => CathayBankEnum::BANK_ACCOUNT_ERROR,
                                                    'order_transfer' => $order_transfer,
                                                ]);
                                                break;
                                            case CathayBankEnum::M323_BANK_ACCOUNT_NAME_ERROR:
                                                // 需要發送通知給財務或是後台管理員，並檢查收款方的姓名
                                                $failed_order_transfers[$accounting->id]->push([
                                                    'reason_code' => CathayBankEnum::CODE_REFUND.':'.$reason,
                                                    'reason_message' => CathayBankEnum::BANK_ACCOUNT_NAME_ERROR,
                                                    'order_transfer' => $order_transfer,
                                                ]);
                                                break;
                                            case CathayBankEnum::M323_DEFAULT_ERROR:
                                                // 需要發送通知給財務或是後台管理員，並通知國泰世華銀行，並且請銀行端釐清財金那邊遇到哪些問題
                                                $failed_order_transfers[$accounting->id]->push([
                                                    'reason_code' => CathayBankEnum::CODE_REFUND.':'.$reason,
                                                    'reason_message' => CathayBankEnum::DEFAULT_ERROR,
                                                    'order_transfer' => $order_transfer,
                                                ]);
                                                break;
                                            default:
                                                Log::info("[CUB]Payout with uuid {$target_payout_uuid} with unknown refund reason: {$reason}.");
                                        }
                                    } else {
                                        // 存款不足
                                        // BODY/DATAS/DATA/Rtn_Code=M911
                                        switch ($return_code) {
                                            case CathayBankEnum::CODE_BALANCE_NOT_ENOUGH:
                                                // 需要發送通知給財務，國泰世華銀行帳戶應補足存款
                                                $failed_order_transfers[$accounting->id]->push([
                                                    'reason_code' => $return_code,
                                                    'reason_message' => 'CODE_BALANCE_NOT_ENOUGH',
                                                    'order_transfer' => $order_transfer,
                                                ]);
                                                break;
                                            default:
                                                Log::info("[CUB]Payout with uuid {$target_payout_uuid} with balance not enough, code={$return_code}.");
                                                $failed_order_transfers[$accounting->id]->push([
                                                    'reason_code' => $return_code,
                                                    'reason_message' => 'RETURN',
                                                    'order_transfer' => $order_transfer,
                                                ]);
                                                break;
                                        }
                                    }
                                }
                                break;
                            default:
                                Log::info("[CUB]Payout with uuid {$target_payout_uuid} unknown status: {$data_status}");
                        }
                    }
                }

                $actualAccountingPayoutCount[$accounting->id] += count($datas);

                if ($success_count > 0) {
                    if (0 == $pending_count + $deleted_count) {
                        $status = AccountingStatusEnum::STATUS_IN_FINISH;
                    } else {
                        $status = AccountingStatusEnum::STATUS_IN_PROCESS;
                    }
                } elseif ($pending_count > 0) {
                    $status = AccountingStatusEnum::STATUS_IN_PROCESS;
                } elseif ($scheduled_count > 0) {
                    $status = AccountingStatusEnum::STATUS_SCHEDULED;
                } else {
                    $status = AccountingStatusEnum::STATUS_FAILED;
                }

                $this->info("[CUB]Total accounting with payout $payout->external_payment_uuid: ".$actualAccountingPayoutCount[$accounting->id]);

                if ($expectedAccountingPayouts[$accounting->id]->count() <= $actualAccountingPayoutCount[$accounting->id]) {
                    $this->info("[CUB]Finish accounting payouts: $accounting->uuid");

                    $this->finishAccounting(
                        $application,
                        $accounting,
                        $success_order_transfers[$accounting->id],
                        $failed_order_transfers[$accounting->id],
                        $status,
                        $payout_finished_DTO_list[$accounting->id]
                    );
                }

                ++$checked;
            }

            Log::info("[CUB]Finished checking Cathay bank payout with accounting uuid $accounting->uuid.");
            $this->info("[CUB]# of Cathay remittance are checked: {$checked}");
            $this->info("[CUB]# of accounting are mark to SUCCESS: {$success_count}");
            $this->info("[CUB]# of accounting are mark to SCHEDULED: {$scheduled_count}");
            $this->info("[CUB]# of accounting are mark to PENDING: {$pending_count}");
            $this->info("[CUB]# of accounting are mark to FAILED: {$failed_count}");
            $this->info("[CUB]# of accounting are mark to DELETED: {$deleted_count}");
        } else {
            $this->error("[CUB]Mode $mode in Cathay payout staff tool not found!");
        }

        return 0;
    }

    private function getPayoutIdByRemark(string $remark)
    {
        // Parse the Payout log id from remark field from the queryRMT API response.
        $remark = mb_convert_kana($remark, 'a', 'UTF-8');

        if (1 != preg_match('/^([0-9a-zA-Z]{5}):/', $remark, $matches)) {
            $this->info('Payout id is empty.');

            return null;
        }

        $payout_id = Base62Helper::decode($matches[1]);

        Log::info("[CUB]Checking the status of payout log id {$payout_id}, base62 code $matches[1].");

        return $payout_id;
    }

    public function finishAccounting(
        Application $application,
        Accounting $accounting,
        $success_order_transfers,
        $failed_order_transfers,
        $status,
        ?array $payout_finished_DTO_list = []
    ) {
        DB::beginTransaction();

        Log::info("[CUB]Starting to finish accounting with accounting uuid {$accounting->uuid} with status {$accounting->status} and application id {$accounting->application_id}.");
        if ($accounting->status != $status) {
            if (AccountingStatusEnum::STATUS_IN_FINISH != $accounting->status) {
                Log::info("[CUB]Update the accounting status from $accounting->status to $status");
                $accounting->update([
                    'status' => $status,
                ]);
            }

            if (in_array($status, [AccountingStatusEnum::STATUS_IN_FINISH, AccountingStatusEnum::STATUS_FAILED])) {
                $changed_order_transfers = $failed_order_transfers
                    ->pluck('order_transfer')
                    ->merge($success_order_transfers)
                    ->values();

                $non_change_order_transfers_uuid = $accounting
                    ->order_transfers()
                    ->whereNotIn('orders_transfer.id', $changed_order_transfers->pluck('id')->toArray())
                    ->pluck('orders_transfer.uuid')
                    ->toArray();

                Log::info('[CUB]Start sending CathayAccountingPayoutSucceedEvent.');
                event(
                    new CathayAccountingPayoutSucceedEvent(
                        $application,
                        $accounting,
                        $non_change_order_transfers_uuid,
                        $failed_order_transfers,
                        $payout_finished_DTO_list
                    )
                );
            }
        }

        DB::commit();
    }

    private function getZeroFinishedOrderTransfers($payouts): array
    {
        $zero_total_payouts = $payouts->filter(function ($payout) {
            return 0 == $payout->total;
        });

        $zero_success_order_transfers = [];

        $zero_total_payouts->map(function ($payout) use (&$zero_success_order_transfers) {
            $accounting = $payout->accounting;

            $vendor = $payout->receiver_model ?? null;

            if (empty($payout->accounting) || empty($vendor)) {
                return null;
            }

            if (!isset($zero_success_order_transfers[$payout->id])) {
                $zero_success_order_transfers[$payout->id] = collect([]);
            }

            $zero_success_order_transfers[$payout->id] = $zero_success_order_transfers[$payout->id]
                ->merge($this->accountingService->getOrderTransfersByAccountingAndVendor($accounting, $vendor))
                ->unique('id');
        });

        return $zero_success_order_transfers;
    }
}
