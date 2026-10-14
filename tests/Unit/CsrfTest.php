<?php

declare(strict_types=1);

use Pediacare\Core\Csrf;
use Pediacare\Core\Request;
use Pediacare\Core\Response;
use Pediacare\Middleware\CsrfMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/Support/helpers.php';

final class CsrfTest extends TestCase
{
    public function test_T07_token_is_random_stable_and_bound_to_its_session(): void
    {
        $firstSession = [];
        $secondSession = [];
        $first = new Csrf($firstSession);
        $second = new Csrf($secondSession);

        self::assertFalse($first->verify(null));
        self::assertFalse($first->verify(''));
        self::assertSame([], $firstSession);
        $token = $first->token();
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $token);
        self::assertSame($token, $first->token());
        self::assertNotSame($token, $second->token());
        self::assertTrue($first->verify($token));
        self::assertFalse($second->verify($token));
        $firstSession = [];
        self::assertFalse($first->verify($token));
    }

    #[DataProvider('invalidPosts')]
    public function test_T07_invalid_post_never_reaches_handler(array $post): void
    {
        $session = [];
        $csrf = new Csrf($session);
        $csrf->token();
        $middleware = new CsrfMiddleware($csrf);

        $response = $middleware->handle(new Request('POST', '/fake-form', [], $post), function (): Response {
            self::fail('Invalid POST reached the handler.');
        });

        self::assertSame(403, $response->status);
        self::assertStringNotContainsString($csrf->token(), $response->body);
    }

    public static function invalidPosts(): array
    {
        return [
            'missing' => [[]],
            'empty' => [['_csrf_token' => '']],
            'mismatched' => [['_csrf_token' => 'fake-invalid-token']],
            'array' => [['_csrf_token' => ['fake-invalid-token']]],
        ];
    }

    public function test_T07_valid_post_and_get_reach_handler(): void
    {
        $session = [];
        $csrf = new Csrf($session);
        $middleware = new CsrfMiddleware($csrf);
        $response = new Response('accepted');
        $next = static fn (Request $request): Response => $response;

        self::assertSame($response, $middleware->handle(
            new Request('POST', '/fake-form', [], ['_csrf_token' => $csrf->token()]),
            $next
        ));
        self::assertSame($response, $middleware->handle(new Request('GET', '/fake-form'), $next));
    }

    public function test_T07_hidden_field_escapes_token(): void
    {
        self::assertSame(
            '<input type="hidden" name="_csrf_token" value="&quot;&lt;fake&gt;">',
            csrf_field('"<fake>')
        );
    }
}
