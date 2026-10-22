<?php

namespace Havun\Observability\Tests;

use Havun\Observability\HavunCore;
use Havun\Observability\Reporter;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ConfigTest extends TestCase
{
    public function test_spool_and_breaker_live_under_state_path(): void
    {
        $dir = sys_get_temp_dir().'/obs-proces-'.uniqid();
        mkdir($dir);
        config(['observability.state_path' => $dir]);
        $this->takeHavunCoreDown();

        app(Reporter::class)->report(new RuntimeException('naar de spool'));

        $this->assertFileExists($dir.'/error-spool.jsonl');
        $this->assertFileExists($dir.'/observability-breaker');
        $this->assertFileDoesNotExist(storage_path('app/error-spool.jsonl'));
    }

    public function test_enabling_after_boot_takes_effect(): void
    {
        config(['observability.enabled' => false]);
        Log::error('telt niet');
        config(['observability.enabled' => true]);
        Log::error('telt wel');

        $this->assertSame(['telt wel'], $this->havuncore('error_logs')->pluck('message')->all());
    }
}
