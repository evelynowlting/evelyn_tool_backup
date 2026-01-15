<?php

namespace App\Console\Commands\PayoutChannel;

use App\Services\Payout\NiumBaaSPayoutService;
use gnupg;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class NiumBaaSDailyReportFetcher extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium_baas:daily_report_fetcher';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This tool fetches daily reports from Nium BaaS SFTP server.';

    public const REPORT_FILE_EXT = '.csv';
    public const SUMMARY_FILE_EXT = '.txt';
    public const ENCRYPTED_REPORT_FILE_EXT = '.csv.pgp';

    private $env = '';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(private NiumBaaSPayoutService $niumBaaSPayoutService)
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
        $this->env = env('APP_ENV');

        $sftp = Storage::disk('nium_baas_sftp');
        $s3 = Storage::disk('nium_baas_daily_report');
        $fingerprint = config('payoutchannel.niumBaaS.gpg_fingerprint');
        $decrypted_file_failed_list = [];
        $encrypted_file_failed_list = [];

        // Retrieve the existing files from S3
        $existing_files_on_s3 = [];
        $s3_files = $s3->allFiles(config('payoutchannel.niumBaaS.aws_report_root_folder').'/'.$this->env);

        $gpg = new gnupg();
        // $gpg->seterrormode(gnupg::ERROR_EXCEPTION);
        $res = $gpg->adddecryptkey($fingerprint, '');
        if (!$res) {
            $this->error('[Nium BaaS] Added decrypt key failed.');

            return;
        }

        // Retrieve Nium supported client list
        $client_info_list = $this->niumBaaSPayoutService->getNiumBaaSClientInfoByAllRegions();

        $sftp_reports = [];

        foreach ($client_info_list as $client_info) {
            $file_counts_by_date = [];
            $client_id = $client_info['client_id'];
            $region = $client_info['country_code'];

            $this->info("[Nium BaaS] Get report list for region $region on Nium SFTP server.");
            if (!$sftp->exists($client_id)) {
                $this->error("[Nium BaaS] $client_id folder NOT found on Nium SFTP server.");
                continue;
            }

            $this->info('[Nium BaaS] Filter reports by region on S3.');
            foreach ($s3_files as $s3_file) {
                $file_path_arr = explode('/', $s3_file);
                if (!in_array($region, $file_path_arr)) {
                    continue;
                }

                // filter summary files and decrypted files
                if (self::SUMMARY_FILE_EXT == substr($s3_file, -4) || self::REPORT_FILE_EXT == substr($s3_file, -4)) {
                    continue;
                }
                $existing_files_on_s3[] = basename($s3_file);
            }

            $this->info("[Nium BaaS] Region $region has ".count($existing_files_on_s3).' reports on S3.');

            // Fetch all reports from SFTP
            $sftp_reports = $sftp->files($client_id);
            $existing_files_on_sftp = [];
            foreach ($sftp_reports as $report) {
                $existing_files_on_sftp[] = basename($report);
            }

            // Compare reports on SFTP to S3, only retrieve the latest reports on SFTP
            $this->info(sprintf('[Nium BaaS] Total # of reports on s3 is %d and Nium SFTP is %d', count($existing_files_on_s3), count($existing_files_on_sftp)));
            $remote_new_sftp_files = array_fill_keys(array_diff($existing_files_on_sftp, $existing_files_on_s3), true);
            $this->info("[Nium BaaS] $region has ".count($remote_new_sftp_files).' reports should be updated back to S3.');
            if (_isEmpty($remote_new_sftp_files)) {
                $this->info("[Nium BaaS] No $region reports on Nium SFTP should be updated.");

                continue;
            }

            // Decrypt file and move to S3 decrypted folders
            // $this->info("[Nium BaaS] Decrypt file $org_filename and upload to s3 bucket.");
            foreach ($sftp_reports as $sftp_report) {
                $org_sftp_filename = basename($sftp_report);
                if (!array_key_exists($org_sftp_filename, $remote_new_sftp_files)) {
                    continue;
                }

                $file_path_arr = explode('/', $sftp_report);
                $client_id = $file_path_arr[0];
                $id_and_date_arr = explode('_', $org_sftp_filename);
                $date = basename(end($id_and_date_arr), self::ENCRYPTED_REPORT_FILE_EXT);
                $new_filename = substr($org_sftp_filename, 0, -4);

                if (!array_key_exists($date, $file_counts_by_date)) {
                    $file_counts_by_date[$date]['total'] = 1;
                    $file_counts_by_date[$date]['fetched_files'] = [$org_sftp_filename];
                } else {
                    ++$file_counts_by_date[$date]['total'];
                    $file_counts_by_date[$date]['fetched_files'][] = $org_sftp_filename;
                }

                // Move SFTP report from Nium To S3
                // Download file from SFTP
                $content = $sftp->get($sftp_report);
                $encrypted_folder = config('payoutchannel.niumBaaS.aws_report_root_folder').'/'.$this->env."/$date"."/$region".'/encrypt';
                $encrypted_file_path = $encrypted_folder.'/'.$org_sftp_filename;
                $this->info("[Nium BaaS] Fetching daily report $org_sftp_filename for $region and update report in $encrypted_folder.");
                // Upload file from SFTP
                $s3->write($encrypted_file_path, $content);

                $decrypted_file = $gpg->decrypt($content);
                if (!$decrypted_file) {
                    $this->error("[Nium BaaS] Decrypted file $org_sftp_filename failed.");
                    $decrypted_file_failed_list[$date][] = $org_sftp_filename;
                    continue;
                } else {
                    $encrypted_file_failed_list[$date][] = $org_sftp_filename;
                }

                $decrypted_folder = config('payoutchannel.niumBaaS.aws_report_root_folder').'/'.$this->env."/$date"."/$region".'/decrypt';
                $decrypted_file_path = $decrypted_folder.'/'.$new_filename;
                $this->info("[Nium BaaS] Upload decrypted $region report $org_sftp_filename to $decrypted_folder.");

                // Upload decrypted files to related folders
                $s3 = Storage::disk('nium_baas_daily_report');
                $s3->write($decrypted_file_path, $decrypted_file, $new_filename);
            }

            $datetime = now()->timezone('UTC')->format('Ymd_His');
            $debug_file_name = sprintf('report_summary_%s_%s_%s.txt', $region, $this->env, $datetime);
            $log_folder_path = config('payoutchannel.niumBaaS.aws_report_root_folder')."/$this->env/".$debug_file_name;
            $file_counts_by_date['decrypted_failed_files'] = $decrypted_file_failed_list;
            $file_counts_by_date['encrypted_files'] = $encrypted_file_failed_list;
            $s3->write($log_folder_path, json_encode($file_counts_by_date));
            Log::info(sprintf("[Nium BaaS]Generated debug file $debug_file_name on S3"));

            if (!empty($decrypted_file_failed_list)) {
                $this->info('[Nium BaaS] Decrypted failed reports are: '.json_encode($decrypted_file_failed_list));
            }
        }

        return 0;
    }
}
