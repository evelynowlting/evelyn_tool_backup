<?php

namespace App\Console\Commands\PayoutChannel;

use App\Services\Payout\Visa\VisaVirtualAccountService;
use App\Services\Payout\Visa\VisaVPAReconciliationService;
use Illuminate\Console\Command;

class VisaVPAReconciliation extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visa:reconciliation';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'VISA VPA reconciliation from VISA files';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        VisaVPAReconciliationService $reconciliation_service,
        VisaVirtualAccountService $visa_virtual_account_service
    ) {
        parent::__construct();
        $this->reconciliation_service = $reconciliation_service;
        $this->visa_virtual_account_service = $visa_virtual_account_service;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        /**
         * There are some VISA VPA reconciliation file in their FTP.
         * The data is update frequently. Infra team config S3 SFTP to retrieve
         * the files in our S3, then we can exectued this batch to retrive the file and parse the result.
         */
        $file_list = $this->reconciliation_service->getReconciliationFiles();
        if (0 == count($file_list)) {
            return 0;
        }

        foreach ($file_list as $file) {
            $encrypt_content = $this->reconciliation_service->retrieveReonciliationFile($file);
            $decrypt_content = $this->reconciliation_service->decryptFile($encrypt_content);
            $this->reconciliation_service->storeReconciliation($decrypt_content);
            $this->reconciliation_service->moveFileToRead($file);
        }

        return 0;
    }
}
