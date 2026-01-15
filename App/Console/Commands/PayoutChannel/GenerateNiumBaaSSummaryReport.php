<?php

namespace App\Console\Commands\PayoutChannel;

use App\Services\FinanceReportService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GenerateNiumBaaSSummaryReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nium_baas:summary_finance_report {--during_date=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Quickly generate summary for finance report';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(
        private FinanceReportService $financeReportService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $during_date = $this->option('during_date');

        if (!empty($during_date)) {
            $during_date = explode(',', $during_date);
        }

        $data = $this->financeReportService->getNiumBaasSummaryReport(
            filterQuery: [
                'start_at' => !empty($during_date[0]) ? Carbon::parse($during_date[0]) : null,
                'end_at' => !empty($during_date[1]) ? Carbon::parse($during_date[1])->addDay()->subSecond() : null,
            ]
        );

        $filename = 'nium_baas_summary_'.time().'.csv';
        $file = fopen($filename, 'w');

        if (!empty($during_date[0]) && !empty($during_date[0])) {
            fputcsv($file, ['(UTC)'.Carbon::parse($during_date[0]).' ~ '.Carbon::parse($during_date[1])->addDay()->subSecond()]);
        }
        fputcsv($file, array_keys($data[0]));
        fputcsv($file, array_values($data[0]));

        fclose($file);

        return Command::SUCCESS;
    }
}
