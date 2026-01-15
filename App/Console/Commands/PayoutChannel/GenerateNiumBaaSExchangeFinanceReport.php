<?php

namespace App\Console\Commands\PayoutChannel;

use App\Services\FinanceReportService;
use Illuminate\Console\Command;

class GenerateNiumBaaSExchangeFinanceReport extends Command
{
    protected $signature = 'nium_baas:fx_finance_report';

    protected $description = 'Quickly generate fx finance report';

    public function __construct(
        private FinanceReportService $financeReportService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $data = $this->financeReportService->getNiumBaasForeignExchange();

        $filename = 'nium_baas_fx_'.time().'.csv';
        $file = fopen($filename, 'w');

        fputcsv($file, array_keys($data[0]));

        // Loop through the data and write each row to the file
        for ($i = 0; $i < count($data); ++$i) {
            fputcsv($file, $data[$i]);
        }

        fclose($file);

        return Command::SUCCESS;
    }
}
