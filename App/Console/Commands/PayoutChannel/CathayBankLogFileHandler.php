<?php

namespace App\Console\Commands\PayoutChannel;

use App\Services\Payout\CathayBankService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CathayBankLogFileHandler extends Command
{
    protected $signature = 'cathay_bank:log_file_handler
                            {action : Action to perform (move, archive...).}
                            {--dateBefore=20251001}
                            {--dryRun=true}
                            ';
    protected $description = 'Fetch Cathay Bank log files from S3, parse dates and move into date folders.';

    public const LOG_FILE_EXT = '.xml';

    private string $env;

    public function __construct(private CathayBankService $cathayBankPayoutService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // $this->env = config('app.env', env('APP_ENV', 'local'));
        $this->env = 'production';

        $action = strtolower($this->argument('action'));
        $dryRun = filter_var(strtolower($this->option('dryRun')), FILTER_VALIDATE_BOOLEAN);
        $dateBefore = (int) ($this->option('dateBefore')) ?? Carbon::now()->format('Ymd');

        $diskName = 'cathay_bank_debug_files';
        $rootFolder = config('payoutchannel.cathay.aws_debug_root_folder');
        $s3 = Storage::disk($diskName);

        $sourcePrefix = $rootFolder.'/'.$this->env.'/';
        $this->info("Scanning S3 disk '{$diskName}' under '{$sourcePrefix}'");

        $files = $s3->allFiles($sourcePrefix);
        if (empty($files)) {
            $this->info('No files found.');

            return 0;
        }

        if ('move' == $action) {
            $moved = 0;
            $errors = [];

            foreach ($files as $filePath) {
                // skip directories files
                if (preg_match('#/(\d{8})/#', $filePath, $matches)) {
                    $date = $matches[1];
                    // $this->line("Skipping file {$filePath} already in {$date}");
                    continue;
                }

                // skip non-xml files
                if (!Str::endsWith($filePath, self::LOG_FILE_EXT)) {
                    continue;
                }

                $filename = basename($filePath);
                // Try to extract date from filename: look for YYYYMMDD pattern
                $date = $this->extractDateFromFilename($filename);

                // Handle files with date before dateBefore
                // If the dateBefore = 20250101, then handle files before 20250101
                if ((int) $date > $dateBefore) {
                    $this->line("Ignoring file {$filename} (date: {$date} is before {$dateBefore})");
                    continue;
                }

                $destFolder = $rootFolder.'/'.$this->env.'/'.$date.'/';
                $destPath = $destFolder.$filename;

                try {
                    // copy then delete (safe for S3)
                    $this->info(($dryRun ? '[Dry Run]' : '')."Moved {$filename} -> {$destFolder}");

                    if (!$dryRun) {
                        $s3->move($filePath, $destPath);
                    }
                    ++$moved;
                } catch (\Throwable $e) {
                    $errors[] = "Failed to move {$filePath}: {$e->getMessage()}";
                    Log::error("[Cathay] Failed moving file {$filePath} to {$destPath}", ['exception' => $e]);
                }
            }

            $this->info("Total moved: {$moved}");
            if (!empty($errors)) {
                foreach ($errors as $err) {
                    $this->error($err);
                }
            }
        }

        return 0;
    }

    private function extractDateFromFilename(string $filename): ?string
    {
        // 先去掉副檔名
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // 用底線分割
        $parts = explode('_', $name);

        // 確保至少有兩個底線（倒數第二個存在）
        if (count($parts) < 2) {
            return null;
        }

        if (Str::startsWith($filename, 'queryresult') && Str::contains($filename, '_all')) {
            // 取倒數第二個元素
            $datePart = $parts[count($parts) - 3];

            // 只取前 8 個字（避免後面有時間碼）
            return substr($datePart, 0, 8);
        } else {
            // 取倒數第二個元素
            $datePart = $parts[count($parts) - 2];

            // 只取前 8 個字（避免後面有時間碼）
            return substr($datePart, 0, 8);
        }
    }
}
