<?php

namespace Havun\Observability\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * One ID per request, from nginx ($request_id) or generated, so an error in HavunCore links to
 * what the customer did in that request. Plan: HavunCore .claude/plans/request-id-correlatie.md
 */
class RequestIdTest extends TestCase
{
    private const NGINX_ID = '0123456789abcdef0123456789abcdef';

    public function test_the_nginx_id_is_reused_and_returned(): void
    {
        Route::get('x', fn () => Context::get('request_id'));

        $this->get('x', ['X-Request-Id' => self::NGINX_ID])
            ->assertSee(self::NGINX_ID)
            ->assertHeader('X-Request-Id', self::NGINX_ID);
    }

    public function test_an_invalid_header_is_replaced_by_a_generated_id(): void
    {
        Route::get('x', fn () => Context::get('request_id'));

        $response = $this->get('x', ['X-Request-Id' => "evil\nid"]);

        $id = $response->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
        $this->assertSame($id, $response->getContent());
    }

    public function test_an_error_in_the_request_carries_its_id(): void
    {
        Route::get('kapot', fn () => throw new RuntimeException('kapot'));

        $this->get('kapot', ['X-Request-Id' => self::NGINX_ID]);

        $this->assertSame(self::NGINX_ID, $this->havuncore('error_logs')->sole()->request_id);
    }

    public function test_a_queued_job_keeps_the_request_id(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.table' => 'jobs']);
        Schema::create('jobs', function (Blueprint $t): void {
            $t->id();
            $t->string('queue');
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        Route::get('later', function () {
            LaterJob::dispatch();

            return 'ok';
        });

        $this->get('later', ['X-Request-Id' => self::NGINX_ID]);

        $this->assertStringContainsString(self::NGINX_ID, DB::table('jobs')->value('payload'));
    }

    public function test_switched_off_there_is_no_id(): void
    {
        config(['observability.request_id' => false]);
        Route::get('x', fn () => (string) Context::get('request_id'));

        $this->get('x', ['X-Request-Id' => self::NGINX_ID])
            ->assertHeaderMissing('X-Request-Id')
            ->assertDontSee(self::NGINX_ID);
    }
}

class LaterJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void {}
}
