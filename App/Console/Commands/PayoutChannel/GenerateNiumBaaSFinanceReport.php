<?php

namespace App\Console\Commands\PayoutChannel;

use App\Services\FinanceReportService;
use Illuminate\Console\Command;

class GenerateNiumBaaSFinanceReport extends Command
{
    protected $signature = 'nium_baas:tx_finance_report';

    protected $description = 'Quickly generate tx finance report';

    public function __construct(
        private FinanceReportService $financeReportService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $data = $this->financeReportService->getNiumBaasTransactionReport();

        $filename = 'nium_baas_tx_'.time().'.csv';
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
