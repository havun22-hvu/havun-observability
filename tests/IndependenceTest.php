<?php

namespace Havun\Observability\Tests;

use Havun\Observability\HavunCore;
use Havun\Observability\Reporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Henk, 22-10-2026: "is HavunCore uit de lucht, dan moet een toernooi, herdenking of
 * download/licentie-aanvraag door kunnen gaan." The site never waits on HavunCore.
 */
class IndependenceTest extends TestCase
{
    public function test_a_page_works_while_havuncore_is_down(): void
    {
        Route::get('toernooi', fn () => 'weegkaart');
        $this->takeHavunCoreDown();

        $this->get('toernooi')->assertOk()->assertSee('weegkaart');
    }

    public function test_an_error_while_havuncore_is_down_is_spooled_and_sent_later(): void
    {
        $this->takeHavunCoreDown();

        app(Reporter::class)->report(new RuntimeException('eerste'));
        app(Reporter::class)->report(new RuntimeException('tweede'));

        $this->assertTrue(HavunCore::isOpen(), 'one failure opens the breaker');
        $this->assertCount(2, file(storage_path('app/error-spool.jsonl'), FILE_SKIP_EMPTY_LINES));

        $this->bringHavunCoreBack();
        $this->artisan('observability:flush-spool')->assertSuccessful();

        $this->assertSame(2, $this->havuncore('error_logs')->count());
        $this->assertFileDoesNotExist(storage_path('app/error-spool.jsonl'));
    }

    public function test_an_open_breaker_leaves_havuncore_alone_for_a_while(): void
    {
        $this->takeHavunCoreDown();
        app(Reporter::class)->report(new RuntimeException('opent de stroomonderbreker'));

        // HavunCore is reachable again, but within the breaker period nothing is sent to it:
        // a flapping HavunCore costs a request its timeout at most once per period.
        DB::purge('havuncore');
        config(['database.connections.havuncore.database' => $this->havuncoreDb]);
        Route::get('herdenking', fn () => 'ok');
        $this->get('herdenking')->assertOk();
        app(Reporter::class)->report(new RuntimeException('nog een'));

        $this->assertSame(0, $this->havuncore('error_logs')->count());
        $this->assertSame(0, $this->havuncore('request_metrics')->count());
        $this->assertCount(2, file(storage_path('app/error-spool.jsonl'), FILE_SKIP_EMPTY_LINES));

        $this->travel(61)->seconds();
        app(Reporter::class)->report(new RuntimeException('na de pauze'));
        $this->assertSame(1, $this->havuncore('error_logs')->count());
    }

    public function test_request_metrics_are_written_after_the_response_without_ip_or_secret(): void
    {
        Route::get('toegang/{code}', fn () => (string) $this->havuncore('request_metrics')->count());

        $response = $this->get('toegang/X9Y8Z7', ['REMOTE_ADDR' => '203.0.113.7']);

        $this->assertSame('0', $response->getContent(), 'nothing written while the page was built');
        $row = $this->havuncore('request_metrics')->sole();
        $this->assertSame('toegang/{code}', $row->path);
        $this->assertNull($row->ip_address);
        $this->assertSame(200, (int) $row->status_code);
    }

    public function test_slow_queries_are_written_after_the_response_as_a_template(): void
    {
        Route::get('zoek', function () {
            DB::select('select ? as email', ['jan@example.nl']);

            return (string) $this->havuncore('slow_queries')->count();
        });

        $response = $this->get('zoek');

        $this->assertSame('0', $response->getContent());
        $row = $this->havuncore('slow_queries')->where('query', 'like', 'select ? as email%')->sole();
        $this->assertStringNotContainsString('jan@example.nl', $row->query);
        $this->assertSame('zoek', $row->request_path);
    }
}
