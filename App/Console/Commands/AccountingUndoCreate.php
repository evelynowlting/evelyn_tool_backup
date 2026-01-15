<?php

namespace App\Console\Commands;

use App\Cores\Platform\AccountingCore;
use App\Events\Order\AccountingDeletedEvent;
use App\Models\Accounting;
use Illuminate\Console\Command;

class AccountingUndoCreate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'accounting:undo_create {accounting_uuid}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove accounting uuid';

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
        /** @var AccountingCore $accountingCore */
        $accountingCore = app(AccountingCore::class);

        $accounting_uuid = $this->argument('accounting_uuid');

        /** @var Accounting $accounting */
        $accounting = Accounting::with(['order_transfers'])->where('uuid', $accounting_uuid)->first();

        $application = $accounting->application;
        $is_test = $accounting->is_test;

        $this->info("Application id: $application->id");
        $this->info("Application uuid: $application->uuid");
        $this->info("Application name: $application->name");
        $this->info("Accounting uuid: $accounting->uuid");
        $this->info("Accounting description: $accounting->description");
        $this->info("Accounting status: $accounting->status");
        $this->info("Accounting currency: $accounting->currency");
        $this->info('Accounting total: '.$accounting->order_transfers->sum('settled_total'));

        if ($this->confirm('Are you sure to delete the accounting?') && !empty($accounting)) {
            $deleted_success = $accountingCore->deleteAccounting($application, $accounting_uuid, $is_test);

            event(new AccountingDeletedEvent($application, $accounting));

            if ($deleted_success) {
                $this->info('Success deleted');
            }
        }
    }
}
