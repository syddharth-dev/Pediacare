<?php

declare(strict_types=1);

use Pediacare\Core\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_T04_redirect_uses_303_for_post_redirect_get(): void
    {
        $response = Response::redirect('/children/123?tab=growth');

        self::assertSame(303, $response->status);
        self::assertSame('/children/123?tab=growth', $response->headers['Location']);
        self::assertSame('', $response->body);
    }

    #[DataProvider('unsafeRedirectProvider')]
    public function test_T04_unsafe_redirects_are_rejected(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::redirect($path);
    }

    public static function unsafeRedirectProvider(): array
    {
        return [
            ['https://example.invalid'],
            ['//example.invalid'],
            ['/\\example.invalid'],
            ["/children\r\nX-Injected: true"],
        ];
    }

    public function test_T04_header_injection_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Response('', 200, ['X-Test' => "safe\r\nInjected: true"]);
    }

    public function test_T04_invalid_status_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Response('', 999);
    }

    public function test_T04_send_sets_status_and_head_omits_body(): void
    {
        $previousStatus = http_response_code();
        ob_start();

        try {
            (new Response('body', 201, []))->send();
            self::assertSame(201, http_response_code());
            self::assertSame('body', ob_get_contents());
            ob_clean();
            (new Response('body', 200, []))->send(true);
            self::assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
            http_response_code(is_int($previousStatus) ? $previousStatus : 200);
        }
    }
}
