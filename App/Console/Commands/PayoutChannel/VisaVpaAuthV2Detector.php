<?php

namespace App\Console\Commands\PayoutChannel;

use App\Jobs\S3UploadJob;
use App\Jobs\VisaAuthEventUpdateJob;
use App\Services\Payout\Visa\VisaDataV2Service;
use App\Services\Payout\Visa\VisaVirtualAccountAuthorizationV2Service;
use App\Services\Payout\Visa\VisaVirtualAccountService;
use App\Services\Payout\Visa\VisaVPAReconciliationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Tests\Traits\MockData\VisaVpaMockTrait;

class VisaVpaAuthV2Detector extends Command
{
    use VisaVpaMockTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visa:auth_detect_v2 {--start_date=} {--end_date=} {--application_ids=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'VISA VPA card authorization detect';

    public const VPA_AUTHORIZATION_DATA_STORAGE = 'visa_auth_data';
    public const UPLOAD_S3_QUEUE_NAME = 'visa_pull_auth_data_v2_s3_upload';
    public const AUTH_UPDATE_QUEUE_NAME = 'visa_pull_auth_data_update_upload';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private VisaVirtualAccountService $visa_virtual_account_service,
        private VisaDataV2Service $visa_data_service,
        private VisaVirtualAccountAuthorizationV2Service $visa_virtual_account_auth_service,
        private VisaVPAReconciliationService $visa_vpa_reconciliation_service,
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
        $start_date = $this->option('start_date');
        $end_date = $this->option('end_date');
        $application_ids = $this->option('application_ids');

        if (!empty($start_date)) {
            $start_date = Carbon::parse($start_date);
        } else {
            $start_date = Carbon::yesterday();
        }

        if (!empty($end_date)) {
            $end_date = Carbon::parse($end_date);
        } else {
            $end_date = Carbon::today();
        }

        if (!empty($application_ids)) {
            $application_ids = explode(',', $application_ids);
        } else {
            $application_ids = $this->visa_virtual_account_service->getApplicationIdOfUnauthAccounts();
        }

        foreach ($application_ids as $application_id) {
            // get auth data
            $auth_data_set = $this->visa_data_service->getV2AuthorizationDataByApplicationId($application_id, $start_date, $end_date);
            if (empty($auth_data_set)) {
                Log::info('[VISA AUTH] Cannot get auth data by application ID: '.$application_id);
                continue;
            }
            $records = $auth_data_set->metaData->totalMatchedRecords;
            $total_page = ceil($records / VisaDataV2Service::VPA_AUTHORIZATION_DATA_PAGE_SIZE);

            // update auth status
            $this->updateAuthStatus($application_id, $auth_data_set);

            // update other pages auth data
            foreach (range(2, $total_page) as $page) {
                if ($page > $total_page) {
                    break;
                }
                $auth_data_set = $this->visa_data_service->getV2AuthorizationDataByApplicationId($application_id, $start_date, $end_date, $page);
                if (empty($auth_data_set)) {
                    Log::info('[VISA AUTH] Cannot get auth data by application ID: '.$application_id);
                    continue;
                }
                // update auth status
                $this->updateAuthStatus($application_id, $auth_data_set);
            }
        }

        return 0;
    }

    private function updateAuthStatus($application_id, $data)
    {
        $file_name = sprintf('visa_authorization_pull_file_%s_%s_%s.json', $application_id, 1, time());
        $remote_filename = config('payoutchannel.visa.vpa_authorization_data_archived_path').'/'.$file_name;
        $encrypt_content = $this->visa_data_service->encryptFile(json_encode($data));
        S3UploadJob::dispatch(
            $remote_filename,
            $encrypt_content,
            VisaDataV2Service::VPA_AUTHORIZATION_DATA_STORAGE,
        )->onQueue(self::UPLOAD_S3_QUEUE_NAME);

        if (empty($data?->authTransactions)) {
            return;
        }

        // update event status
        VisaAuthEventUpdateJob::dispatch(
            $data->authTransactions,
            'pull'
        )->onQueue(self::AUTH_UPDATE_QUEUE_NAME);
    }
}
