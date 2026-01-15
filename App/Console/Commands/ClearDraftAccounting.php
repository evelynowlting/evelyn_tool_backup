<?php

namespace App\Console\Commands;

use App\Enums\AccountingStatusEnum;
use App\Models\Accounting;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ClearDraftAccounting extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clear:draft_accountings';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clear on draft accounting from last 5 days';

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
        $deleted_count = Accounting::where('status', AccountingStatusEnum::STATUS_DRAFT)
            ->where('created_at', '<', Carbon::now()->subDays(5))
            ->delete();

        $this->info("Deleted draft accounting count: $deleted_count");
    }
}
