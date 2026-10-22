<?php

namespace Havun\Observability\Tests;

use Havun\Observability\Reporter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;

class ReporterTest extends TestCase
{
    public function test_an_exception_reaches_error_logs_without_personal_data(): void
    {
        Route::get('inloglink/{token}', function () {
            throw new RuntimeException('Geen account voor jan@example.nl, zie https://x.nl/a?token=geheim');
        });

        $this->get('inloglink/abc123geheim?ref=mail');

        $row = $this->havuncore('error_logs')->sole();
        $this->assertSame('testsite', $row->project);
        $this->assertSame(RuntimeException::class, $row->exception_class);
        $this->assertSame('http://localhost/inloglink/{token}', $row->url, 'no secret in the path, no query');
        $this->assertNull($row->ip_address);
        $this->assertStringNotContainsString('jan@example.nl', $row->message);
        $this->assertStringNotContainsString('geheim', $row->message);
        $this->assertStringContainsString('[e-mail]', $row->message);
    }

    public function test_a_query_exception_keeps_the_sql_template_not_the_bindings(): void
    {
        $previous = new \PDOException("SQLSTATE[23000]: Duplicate entry 'jan@example.nl' for key 'users_email_unique'");
        $e = new QueryException('mysql', 'insert into users (email) values (?)', ['jan@example.nl'], $previous);

        app(Reporter::class)->report($e);

        $message = $this->havuncore('error_logs')->value('message');
        $this->assertStringContainsString('insert into users (email) values (?)', $message);
        $this->assertStringNotContainsString('jan@example.nl', $message);
    }

    public function test_log_error_in_a_catch_block_is_reported_at_its_call_site(): void
    {
        Log::error('Mollie gaf geen antwoord voor betaling', ['email' => 'jan@example.nl']);

        $row = $this->havuncore('error_logs')->sole();
        $this->assertSame('Log::error', $row->exception_class);
        $this->assertSame(__FILE__, $row->file);
        $this->assertStringNotContainsString('jan@example.nl', (string) $row->message.$row->trace, 'context is never stored');
    }

    public function test_an_exception_both_reported_and_logged_counts_once(): void
    {
        $e = new RuntimeException('kapot');

        report($e);
        Log::error('kapot', ['exception' => $e]);

        $this->assertSame(1, $this->havuncore('error_logs')->count());
    }

    public function test_info_and_warning_are_not_errors(): void
    {
        Log::info('gewoon');
        Log::warning('let op');

        $this->assertSame(0, $this->havuncore('error_logs')->count());
    }

    public function test_the_reference_is_the_start_of_the_fingerprint(): void
    {
        app(Reporter::class)->report(new RuntimeException('x'));

        $fingerprint = $this->havuncore('error_logs')->value('fingerprint');
        $this->assertSame(strtoupper(substr($fingerprint, 0, 8)), app(Reporter::class)->lastReference());
    }

    public function test_a_site_records_its_own_kind_through_record(): void
    {
        app(Reporter::class)->record(['exception_class' => 'Zelf gemeld', 'message' => 'van piet@example.nl', 'file' => '/fout-melden']);

        $row = $this->havuncore('error_logs')->sole();
        $this->assertSame('Zelf gemeld', $row->exception_class);
        $this->assertSame('van [e-mail]', $row->message);
    }

    public function test_disabled_writes_nothing(): void
    {
        config(['observability.enabled' => false]);

        app(Reporter::class)->report(new RuntimeException('x'));
        Log::error('y');

        $this->assertSame(0, $this->havuncore('error_logs')->count());
    }
}
