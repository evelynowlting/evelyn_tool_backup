<?php

namespace App\Console\Commands\Domain\Tink\Concerns;

use Illuminate\Support\Facades\Log;
use Shared\Concerns\GetLogHeader;

trait ShowMessage
{
    use GetLogHeader;

    private function showMessage(mixed $level, string $message)
    {
        $log_header = $this->getLogHeader(extra_backtrace_level: 1);
        echo $log_header.$message."\n";
        Log::log($level, $log_header.$message);
    }
}
