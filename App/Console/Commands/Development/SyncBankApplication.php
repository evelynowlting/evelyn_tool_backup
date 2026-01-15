<?php

namespace App\Console\Commands\Development;

use App\Services\ApplicationService;
use App\Services\BankService;
use Illuminate\Console\Command;

class SyncBankApplication extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:bank_application {bank_id} {application_id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync application to bank';
    /**
     * @var BankService
     */
    private $bankService;
    /**
     * @var ApplicationService
     */
    private $applicationService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        BankService $bankService,
        ApplicationService $applicationService)
    {
        parent::__construct();
        $this->bankService = $bankService;
        $this->applicationService = $applicationService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $bank_id = $this->argument('bank_id');

        $application_id = $this->argument('application_id');

        $bank = $this->bankService->getBankById($bank_id);

        $application = $this->applicationService->getApplicationById($application_id);

        $this->bankService->bindApplicationsOnBank($bank_id, $application_id);

        $this->info("Sync $application->name($application->id) belongs to $bank->name($bank->id)");
    }
}
