<?php

namespace Havun\Observability\Console;

use Havun\Observability\Reporter;
use Illuminate\Console\Command;

/** Sends error rows spooled while HavunCore was unreachable. Scheduled every 5 minutes. */
class FlushSpool extends Command
{
    protected $signature = 'observability:flush-spool';

    protected $description = 'Send error reports spooled while HavunCore was unreachable';

    public function handle(Reporter $reporter): int
    {
        ['sent' => $sent, 'left' => $left] = $reporter->flushSpool();

        if ($sent > 0 || $left > 0) {
            $this->info("{$sent} verstuurd, {$left} nog in de spool.");
        }

        return self::SUCCESS;
    }
}
