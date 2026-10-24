<?php

namespace Havun\Observability\Tests;

use Havun\Observability\HavunCore;
use Havun\Observability\ObservabilityServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $havuncoreDb;

    protected function getPackageProviders($app): array
    {
        return [ObservabilityServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->havuncoreDb = sys_get_temp_dir().'/havuncore-'.uniqid().'.sqlite';
        touch($this->havuncoreDb);

        $app['config']->set('database.connections.havuncore', ['driver' => 'sqlite', 'database' => $this->havuncoreDb, 'prefix' => '']);
        $app['config']->set('observability.project', 'testsite');
        $app['config']->set('observability.slow_query_threshold_ms', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();
        HavunCore::reset();
        @unlink(HavunCore::statePath('error-spool.jsonl'));

        $schema = Schema::connection('havuncore');
        $schema->create('error_logs', function (Blueprint $t): void {
            $t->id();
            foreach (['project', 'exception_class', 'file', 'severity', 'url', 'method', 'ip_address', 'fingerprint', 'request_id'] as $c) {
                $t->string($c)->nullable();
            }
            $t->text('message')->nullable();
            $t->text('trace')->nullable();
            $t->integer('line')->nullable();
            $t->timestamp('last_occurred_at')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        $schema->create('request_metrics', function (Blueprint $t): void {
            $t->id();
            foreach (['project', 'method', 'path', 'route_name', 'ip_address', 'tenant', 'user_agent'] as $c) {
                $t->string($c)->nullable();
            }
            $t->integer('status_code')->nullable();
            $t->integer('response_time_ms')->nullable();
            $t->integer('memory_usage_kb')->nullable();
            $t->timestamp('created_at')->nullable();
        });
        $schema->create('slow_queries', function (Blueprint $t): void {
            $t->id();
            foreach (['project', 'connection', 'request_path'] as $c) {
                $t->string($c)->nullable();
            }
            $t->text('query')->nullable();
            $t->float('time_ms')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('havuncore');
        parent::tearDown();
        @unlink($this->havuncoreDb);
    }

    protected function havuncore(string $table): \Illuminate\Database\Query\Builder
    {
        return DB::connection('havuncore')->table($table);
    }

    /** HavunCore unreachable: its database file sits in a folder that does not exist. */
    protected function takeHavunCoreDown(): void
    {
        DB::purge('havuncore');
        config(['database.connections.havuncore.database' => sys_get_temp_dir().'/geen-map-'.uniqid().'/havuncore.sqlite']);
    }

    protected function bringHavunCoreBack(): void
    {
        DB::purge('havuncore');
        config(['database.connections.havuncore.database' => $this->havuncoreDb]);
        HavunCore::reset();
    }
}
