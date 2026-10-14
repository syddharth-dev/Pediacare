<?php

declare(strict_types=1);

use Pediacare\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function test_T04_globals_keep_query_and_post_separate_and_strip_query_from_path(): void
    {
        $server = $_SERVER;
        $get = $_GET;
        $post = $_POST;

        try {
            $_SERVER['REQUEST_METHOD'] = 'post';
            $_SERVER['REQUEST_URI'] = '/children?search=FAKE';
            $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.99';
            $_GET = ['search' => 'FAKE'];
            $_POST = ['weight' => '12.5'];
            $request = Request::fromGlobals();

            self::assertSame('POST', $request->method);
            self::assertSame('/children', $request->path);
            self::assertSame(['search' => 'FAKE'], $request->query);
            self::assertSame(['weight' => '12.5'], $request->post);
            self::assertSame('192.0.2.1', $request->ipAddress);
        } finally {
            $_SERVER = $server;
            $_GET = $get;
            $_POST = $post;
        }
    }

    public function test_T04_invalid_path_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Request('GET', "/children\r\nInjected");
    }
}
