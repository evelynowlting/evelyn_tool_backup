<?php

namespace App\Console\Commands\Domain\Cash;

use Domain\Cash\Actions\GetVisaExchangeRateAction;
use Illuminate\Console\Command;

class SyncExchangeRate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cash:sync_exchange_rate
                            {--currency= : Specify currency to sync exchange rate for (default: all currencies)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Cash Exchange Rate utility';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $currency = $this->option('currency');

        /** @var GetVisaExchangeRateAction $action */
        $action = app(GetVisaExchangeRateAction::class);

        return $action->syncToDatabase($currency);
    }
}
