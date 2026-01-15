<?php

namespace App\Console\Commands;

use App\Enums\BaseInformationTypeEnum;
use App\Enums\PayoutStatusEnum;
use App\Models\Application;
use App\Models\MetaData;
use App\Models\Payout;
use App\Models\Vendor;
use Illuminate\Console\Command;

class GetPayoutInfoForVendor extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payout:vendor_info';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '取得 application payouts 的 供應商,匯款資訊等資訊';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $application_uuid = $this->ask('application id or uuid ?');

        $application = Application::where('id', $application_uuid)
            ->orWhere('uuid', $application_uuid)
            ->first();

        if (empty($application)) {
            $this->error('application not found.');
        }

        $payouts = Payout::where('application_id', $application->id)
            ->where('is_test', 0)
            ->where('status', PayoutStatusEnum::STATUS_FINISH)
            ->with(['receiver_model'])
            ->get();

        $vendor_ids = array_unique(data_get($payouts, '*.receiver_model_id'));

        $vendor_rows = Vendor::select([
                'vendors.*',
                'remit_info.id as remit_info_id',
                'vendor_info.id as vendor_info_id',
            ])
            ->leftJoin('base_information as remit_info', function ($join) {
                $join->on('vendors.id', '=', 'remit_info.model_id')
                    ->where('remit_info.type', BaseInformationTypeEnum::REMIT_INFO)
                    ->where('remit_info.model_type', 'vendor');
            })
            ->leftJoin('base_information as vendor_info', function ($join) {
                $join->on('vendors.id', '=', 'vendor_info.model_id')
                    ->where('vendor_info.type', BaseInformationTypeEnum::VENDOR_INFORMATION)
                    ->where('vendor_info.model_type', 'vendor');
            })
            ->whereIn('vendors.id', $vendor_ids)
            ->where('is_test', 0)
            ->get()
            ->keyBy('id');

        $remit_info_id = data_get($vendor_rows, '*.remit_info_id');
        $vendor_info_id = data_get($vendor_rows, '*.vendor_info_id');

        $remit_infos = MetaData::whereIn('model_id', $remit_info_id)
            ->where('model_type', 'base_information')
            ->get()
            ->groupBy('model_id');

        $vendor_infos = MetaData::whereIn('model_id', $vendor_info_id)
            ->where('model_type', 'base_information')
            ->get()
            ->groupBy('model_id');

        $bank_info = \DB::table('bank_info')->get()->keyBy('bank_code');

        $branch_infos = \DB::table('branch_info')->get();

        $branch_infos_has_bank_code = $branch_infos->keyBy(function ($item) {
            return $item->bank_code.$item->branch_code;
        });
        $branch_infos = $branch_infos->keyBy('branch_code');

        $result_data = [];

        foreach ($payouts as $payout) {
            $vendor_id = $payout->receiver_model;
            $vendor = $vendor_rows[$vendor_id];

            $remit_info = [];
            foreach ($remit_infos[$vendor->remit_info_id] as $meta_data) {
                $remit_info[$meta_data->key] = $meta_data->value;
            }

            $bank_code = $remit_info['bank_code'];
            $branch_code = $remit_info['branch_code'];

            $vendor_info = [];
            foreach ($vendor_infos[$vendor->vendor_info_id] as $meta_data) {
                $vendor_info[$meta_data->key] = $meta_data->value;
            }

            $branch_info = strlen($branch_code) > 4
                ? $branch_infos_has_bank_code[$branch_code]
                : $branch_infos[$branch_code];

            $result_data[] = [
                'Transaction ID' => $payout->external_payment_uuid,
                'Seq' => $payout->id,
                'Account Number' => '',
                'Customer ID' => $vendor->uuid,
                'Customer' => $vendor->name,
                'Transaction Type' => '',
                'CreditDebit' => '?',
                'Buy Sell Indicator' => '?',
                'Operation Type' => '?',
                'Amount' => $payout->total,
                'Amount2' => $payout->total,
                'Normalized Amount' => '?',
                'Currency' => $payout->currency,
                'Currency 2' => $payout->currency,
                'Gross Amount' => '?',
                'Gross Normalized Amount' => '?',
                'Net Amount' => '?',
                'Net Normalized Amount' => '?',
                'Product Volume' => '?',
                'Product Price' => '?',
                'Product Type' => '?',
                'Product ID' => '?',
                'Product ISN' => '?',
                'Source' => $application->name,
                'MessageType' => '?',
                'Payment Type' => '?',
                'Receipt ID' => '?',
                'Order Number' => $payout->id,
                'Market' => '?',
                'Segment' => '?',
                'Book' => '?',
                'Classification' => 'Payout',
                'External System Key' => $payout->uuid,
                'EntryDate' => $payout->created_at->format('c'),
                'Transaction Date' => $payout->finished_at,
                'Settlement Date' => $payout->finished_at,
                'Maturity Date' => '?',
                'Exercise Date' => '?',
                'Loan Start Date' => '?',
                'Swap Interest rate' => '?',
                'Narrative' => '?',
                'Branch Id' => '?',
                'Cheque Account Number' => '?',
                'Cheque Name' => '?',
                'Cheque Serial' => '?',
                'Cheque Sort' => '?',
                'Loan Principal' => '?',
                'Commission Count' => '?',
                'Commission Value' => '?',
                'Commission Currency' => '?',
                'Commission Normalized Value' => '?',
                'Fee Value' => '?',
                'UBO Group' => '?',
                'Originator ID' => $application->uuid,
                'Originator Account Number' => '263035081799',
                'Originator' => '奧丁丁旅行社',
                'Originator Address' => '北新路三段213號3樓',
                'Originator Street' => '?',
                'Originator City' => '新北市',
                'Originator Country' => 'TW',
                'Originator Bank Bic' => '?',
                'Originator Bank ID' => '?',
                'Originator Bank Name' => 'Cathay United Bank',
                'Originator Bank Branch' => '003 南京東路分行',
                'Originator Bank City' => '台北市',
                'Originator Bank Country' => 'TW',
                'Beneficiary ID' => $vendor->uuid,
                'Beneficiary Account Number' => $remit_info['account'],
                'Beneficiary' => $remit_info['account_name'],
                'Beneficiary Address' => $vendor_info['address'],
                'Beneficiary Street' => '?',
                'Beneficiary City' => $vendor_info['address_city'],
                'Beneficiary Country' => $vendor_info['address_country'],
                'Beneficiary Bank Bic' => '?',
                'Beneficiary Bank ID' => '?',
                'Beneficiary Bank Name' => $bank_info[$bank_code]->bank_name,
                'Beneficiary Bank Branch' => $branch_info->branch_name,
                'Beneficiary Bank City' => '?',
                'Beneficiary Bank Country' => $remit_info['country'],
                'Counterparty' => '?',
                'Inter Bank 1 Bic' => '?',
                'Inter Bank 1 Name' => '?',
                'Inter Bank 1 Branch' => '?',
                'Inter Bank 1 City' => '?',
                'Inter Bank 1 Country' => '?',
                'Inter Bank 2 Bic' => '?',
                'Inter Bank 2 Name' => '?',
                'Inter Bank 2 Branch' => '?',
                'Inter Bank 2 City' => '?',
                'Inter Bank 2 Country' => '?',
                'Inter Bank 3 Bic' => '?',
                'Inter Bank 3 Name' => '?',
                'Inter Bank 3 Branch' => '?',
                'Inter Bank 3 City' => '?',
                'Inter Bank 3 Country' => '?',
                'Original Message' => '?',
                'Additional Data 1' => '?',
                'Additional Data 2' => '?',
                'Additional Data 3' => '?',
            ];
        }

        $dir_path = storage_path('app/temp/');

        if (!file_exists($dir_path)) {
            mkdir($dir_path);
        }

        $file_name = "application_{$application->id}_payouts_".time().'.csv';

        $file_path = $dir_path.$file_name;

        $fp = fopen($file_path, 'wb');

        $first_line = array_keys($result_data[0]);

        fputcsv($fp, $first_line);

        foreach ($result_data as $row) {
            fputcsv($fp, $row);
        }

        $stat = fstat($fp);
        ftruncate($fp, $stat['size'] - 1);
        fclose($fp);

        return 0;
    }
}
