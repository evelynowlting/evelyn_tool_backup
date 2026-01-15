<?php

namespace App\Console\Commands\Development;

use App\Services\BankService;
use Illuminate\Console\Command;

class GenerateBank extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:bank';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Bank';
    /**
     * @var BankService
     */
    private $bankService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(BankService $bankService)
    {
        parent::__construct();
        $this->bankService = $bankService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // @temp: Maras owlting uuid
        $bank = $this->bankService->updateOrCreateBank([
            'owltingId' => '43651',
            'uuid' => '08842a50-9940-11e8-b24c-5f482ae31d14',
            'name' => 'Bank A',
            'email' => 'bank_test@owlting.com',
        ]);

        $this->info("Generated Bank id: $bank->id");
    }
}
