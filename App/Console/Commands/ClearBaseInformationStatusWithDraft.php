<?php

namespace App\Console\Commands;

use App\Repositories\BaseInformationRepository;
use Illuminate\Console\Command;

class ClearBaseInformationStatusWithDraft extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clear:base_information_status_draft';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete Base Information remit info status with draft.';
    /**
     * @var BaseInformationRepository
     */
    private $baseInformationRepository;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->baseInformationRepository = app(BaseInformationRepository::class);
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $deleted = $this->baseInformationRepository->deleteRemitInfoWithDraft();

        $this->info('[Success] Base_information deleted: '.$deleted);
    }
}
