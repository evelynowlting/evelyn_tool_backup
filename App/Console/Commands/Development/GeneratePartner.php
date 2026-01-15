<?php

namespace App\Console\Commands\Development;

use App\Services\BankService;
use App\Services\PartnerService;
use Illuminate\Console\Command;

class GeneratePartner extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:partner {owlting_id} {uuid} {partner_name} {partner_email}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate Partner';
    /**
     * @var PartnerService
     */
    private $partnerService;

    /**
     * Create a new command instance.
     *
     * @return void
     *
     * @param BankService $bankService
     */
    public function __construct(PartnerService $partnerService)
    {
        parent::__construct();
        $this->partnerService = $partnerService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $partner = $this->partnerService->updateOrCreatePartner([
            'owltingId' => $this->argument('owlting_id'),
            'uuid' => $this->argument('uuid'),
            'name' => $this->argument('partner_name'),
            'email' => $this->argument('partner_email'),
        ]);

        $this->info("Generated Partner id: $partner->id");
    }
}
