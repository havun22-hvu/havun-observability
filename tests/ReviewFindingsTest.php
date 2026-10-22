<?php

namespace Havun\Observability\Tests;

use Havun\Observability\Reporter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/** Findings of the Gemini review (22-10-2026), each pinned by a test. */
class ReviewFindingsTest extends TestCase
{
    public function test_an_error_during_a_request_is_written_after_the_response(): void
    {
        Route::get('licentie', function () {
            Log::error('Licentieserver gaf geen antwoord');

            return (string) $this->havuncore('error_logs')->count();
        });

        $this->assertSame('0', $this->get('licentie')->getContent());
        $this->assertSame(1, $this->havuncore('error_logs')->count());
    }

    public function test_an_unrouted_request_masks_token_like_segments(): void
    {
        app(Reporter::class)->report(new RuntimeException('x'));
        $this->get('reset-password/a8F3kQ9zX2mN7pL4wR6t');

        $url = $this->havuncore('request_metrics')->value('path');
        $this->assertSame('reset-password/{token}', $url);
    }

    public function test_a_secret_with_a_slash_is_masked_whole(): void
    {
        Route::get('download/{token}', fn () => 'ok')->where('token', '.*');

        $this->get('download/abc/def/geheim123');

        $this->assertSame('download/{token}', $this->havuncore('request_metrics')->value('path'));
    }

    public function test_the_trace_carries_no_argument_values(): void
    {
        // As on a server where php.ini keeps arguments in traces.
        $before = ini_set('zend.exception_ignore_args', '0');
        $e = $this->throwFrom('jan@example.nl');
        ini_set('zend.exception_ignore_args', (string) $before);
        $this->assertStringContainsString('jan@example.nl', $e->getTraceAsString(), 'precondition: the raw trace has the argument');

        app(Reporter::class)->report($e);

        $trace = (string) $this->havuncore('error_logs')->value('trace');
        $this->assertStringNotContainsString('jan@example.nl', $trace);
        $this->assertStringContainsString('throwFrom()', $trace);
    }

    public function test_scrub_covers_relative_urls_encoded_mail_and_ips_but_not_times(): void
    {
        $scrub = app(Reporter::class)->scrub(
            'Redirect naar /login?token=XYZ voor jan%40example.nl vanaf 203.0.113.7 en 2001:0db8:85a3:0000:0000:8a2e:0370:7334 om 08:27:01 in Foo::bar'
        );

        $this->assertSame('Redirect naar /login voor [e-mail] vanaf [ip] en [ip] om 08:27:01 in Foo::bar', $scrub);
    }

    private function throwFrom(string $email): RuntimeException
    {
        return new RuntimeException('mislukt');
    }
}
