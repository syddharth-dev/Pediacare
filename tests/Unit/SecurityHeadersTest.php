<?php

declare(strict_types=1);

use Pediacare\Core\Response;
use Pediacare\Core\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function test_T12_responses_and_redirects_cannot_override_security_policy(): void
    {
        foreach ([new Response(), Response::redirect('/login'), new Response('denied', 403, [
            'x-frame-options' => 'SAMEORIGIN', 'Content-Security-Policy' => "default-src * 'unsafe-inline'",
            'Content-Type' => 'text/plain; charset=UTF-8',
        ])] as $response) {
            foreach (SecurityHeaders::VALUES as $name => $value) {
                self::assertSame($value, $response->headers[$name]);
            }

            self::assertArrayNotHasKey('x-frame-options', $response->headers);
        }
    }

    public function test_T12_apache_headers_match_php_policy_for_success_and_error_tables(): void
    {
        $configuration = file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');

        foreach (SecurityHeaders::VALUES as $name => $value) {
            self::assertStringContainsString('Header onsuccess unset ' . $name, $configuration);
            self::assertStringContainsString('Header always set ' . $name . ' "' . $value . '"', $configuration);
        }
    }
}
