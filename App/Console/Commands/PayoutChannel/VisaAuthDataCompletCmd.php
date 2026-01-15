<?php

namespace App\Console\Commands\PayoutChannel;

use App\Services\Payout\Visa\VisaDataV2Service;
use App\Services\Payout\Visa\VisaVirtualAccountAuthorizationV2Service;
use App\Services\Payout\Visa\VisaVirtualAccountService;
use Illuminate\Console\Command;

class VisaAuthDataCompletCmd extends Command
{
    // private const AUTH_DATA_FILE1 = '/home/ubuntu/visa_data/visa_auth_data_20221205_160519.json';
    // private const AUTH_DATA_FILE2 = '/home/ubuntu/visa_data/visa_auth_data_20221205_160519.json';
    // private const TRANSACTION_DATA_FILE1 = '/home/ubuntu/visa_data/visa_transaction_data_20221202_163120.json';
    // private const TRANSACTION_DATA_FILE2 = '/home/ubuntu/visa_data/visa_transaction_data_20221215_105729.json';
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visa:auth_confirm {--mode=} {--application_id=} {--file=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import auth/transaction data and mark as success';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private VisaVirtualAccountAuthorizationV2Service $visa_virtual_account_authorization_service,
        private VisaVirtualAccountService $visa_virtual_account_service,
        private VisaDataV2Service $visa_data_service
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $mode = $this->option('mode');
        $file = $this->option('file');
        $application_id = $this->option('application_id');
        match ($mode) {
            'auth' => $this->authData($application_id, $file),
            'transaction' => $this->transactionData($application_id, $file),
        };

        return 0;
    }

    public function authData($application_id, $file)
    {
        $auth_data = json_decode(file_get_contents($file));
        $this->authDataParse($auth_data);
        $encrypt_content = $this->visa_data_service->encryptFile(json_encode($auth_data));
        $file_name = sprintf('authorization_pull_file_%s_%s.json', $application_id, time());
        $this->visa_data_service->uploadAuthorizationDataToS3($encrypt_content, $file_name);
    }

    private function authDataParse($auth_data)
    {
        $auth_list = $auth_data->AuthData->authDataRecords;
        foreach ($auth_list as $auth) {
            $this->visa_virtual_account_authorization_service->storeAuthorizationData($auth);
        }
    }

    public function transactionData($application_id, $file)
    {
        $transaction_data = json_decode(file_get_contents($file));
        $this->transactionDataParse($transaction_data);
        $encrypt_content = $this->visa_data_service->encryptFile(json_encode($transaction_data));
        $file_name = sprintf('transaction_file_%s_%s_%s.json', $application_id, time(), 1);
        $this->visa_data_service->storeTransactionDataToS3($encrypt_content, $file_name);
    }

    private function transactionDataParse($transaction_data)
    {
        $trans_list = $transaction_data->transactionData->transactionRecords;
        foreach ($trans_list as $auth) {
            $this->visa_data_service->updateReconciliationData($auth);
        }
    }
}
