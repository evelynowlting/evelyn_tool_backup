<?php

namespace App\Console\Commands;

use App\Services\BankInfoService;
use App\Services\RedisService;
use Illuminate\Console\Command;

class FetchTWBankInfo extends Command
{
    public const LAST_VERSION_REDIS_KEY = 'bank:info:tw:md5';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fetch:bank-info';

    /**
     * The console command description.
     *
     *  <!--跨行通匯業務「總分支機構位置」-->
     *  <!--製表日期：110年08月12日-->
     *  https://www.fisc.com.tw/TC/OPENDATA/R2_Location.xml
     *
     *  <!--跨行通匯業務參加金融機構一覽表-->
     *  https://www.fisc.com.tw/TC/OPENDATA/R1_MEMBER.xml
     *
     * @var string
     */
    protected $description = 'This tool fetches Taiwan bank and branch code from FISC.';

    protected $bankInfoService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(BankInfoService $bankInfoService)
    {
        parent::__construct();
        $this->bankInfoService = $bankInfoService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $redisService = app(RedisService::class);

        // For debug purpose:
        // $startMemory = memory_get_usage();
        $bank_array = $this->bankInfoService->getLatestTWBankInfo();
        // $memorySize = round(((memory_get_usage() - $startMemory) / 1024 / 1024), 3).' MB';
        // dd($memorySize);

        $this->info(sprintf('[Fetch TW Bank Info]bank: %d entries', count($bank_array['bank'])));
        $this->info(sprintf('[Fetch TW Bank Info]branch: %d entries', count($bank_array['branch'])));

        // 0.002s , 64 bytes
        $last_update_md5 = $redisService->find(self::LAST_VERSION_REDIS_KEY);
        $current_md5 = md5(json_encode($bank_array));
        if ($current_md5 == $last_update_md5) {
            $this->info('[Fetch TW Bank Info]No difference from the previous version.');

            return;
        }

        if (empty($bank_array['bank']) && empty($bank_array['branch'])) {
            $this->info('[Fetch TW Bank Info]No bank and branch info.');

            return;
        }

        $rst = $this->bankInfoService->updateOrCreateBankInfo($bank_array);
        $redisService->set(self::LAST_VERSION_REDIS_KEY, $current_md5);

        if ($rst) {
            $this->info('[Fetch TW Bank Info]Bank and branch info updated successfully.');
        } else {
            $this->info('[Fetch TW Bank Info]Bank and branch info update failed.');
        }

        return 0;
    }
}
