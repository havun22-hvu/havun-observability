<?php

namespace Havun\Observability\Tests;

use Havun\Observability\HavunCore;
use Havun\Observability\Reporter;
use Illuminate\Support\Facades\File;
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

    /**
     * A site whose storage/app is not git-ignored showed the breaker flag as a dirty checkout
     * (JudoToernooi, 23-10-2026). The package keeps its files in a folder that ignores itself.
     */
    public function test_by_default_the_state_lives_in_a_self_ignoring_folder_and_an_old_spool_moves_along(): void
    {
        config(['observability.state_path' => null]);
        File::deleteDirectory(storage_path('app/observability'));
        $oud = storage_path('app/error-spool.jsonl');
        file_put_contents($oud, '{"oud":1}'."\n");

        $pad = HavunCore::statePath('error-spool.jsonl');

        $this->assertSame(storage_path('app/observability'), dirname($pad));
        $this->assertSame("*\n", file_get_contents(storage_path('app/observability/.gitignore')));
        $this->assertFileDoesNotExist($oud);
        $this->assertSame('{"oud":1}'."\n", file_get_contents($pad));

        @unlink($pad);
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
