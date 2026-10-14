<?php

declare(strict_types=1);

use Pediacare\Core\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersHttpTest extends TestCase
{
    public function test_T12_headers_reach_http_clients_for_success_redirect_errors_and_head(): void
    {
        $directory = sys_get_temp_dir() . '/pediacare-headers-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $router = <<<'PHP'
<?php
declare(strict_types=1);
require AUTOLOAD;
use Pediacare\Core\ErrorHandler;
use Pediacare\Core\Response;
(new ErrorHandler(__DIR__ . '/error.log'))->register();
if ($_SERVER['REQUEST_URI'] === '/error') {
    throw new RuntimeException('FAKE_PRIVATE_EXCEPTION');
}
$response = match ($_SERVER['REQUEST_URI']) {
    '/redirect' => Response::redirect('/ok'),
    '/missing' => new Response('not found', 404),
    '/method' => new Response('method not allowed', 405),
    '/csrf' => new Response('forbidden', 403),
    default => new Response('FAKE response'),
};
$response->send($_SERVER['REQUEST_METHOD'] === 'HEAD');
PHP;
        file_put_contents($directory . '/router.php', str_replace('AUTOLOAD', $autoload, $router));
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $process = proc_open([PHP_BINARY, '-S', $address, $directory . '/router.php'], [
            0 => ['pipe', 'r'], 1 => ['file', $directory . '/stdout.log', 'a'],
            2 => ['file', $directory . '/stderr.log', 'a'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);

        try {
            $ready = false;

            for ($attempt = 0; $attempt < 100; $attempt++) {
                $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);

                if (is_resource($connection)) {
                    fclose($connection);
                    $ready = true;
                    break;
                }

                usleep(10000);
            }

            self::assertTrue($ready, 'Temporary PHP HTTP server did not start.');

            foreach ([['GET', '/ok', 200], ['GET', '/redirect', 303], ['GET', '/missing', 404],
                ['GET', '/method', 405], ['POST', '/csrf', 403], ['GET', '/error', 500], ['HEAD', '/ok', 200]] as [$method, $path, $status]) {
                $context = stream_context_create(['http' => [
                    'method' => $method, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5,
                ]]);
                $body = file_get_contents('http://' . $address . $path, false, $context);
                self::assertStringContainsString(' ' . $status . ' ', $http_response_header[0]);

                foreach (SecurityHeaders::VALUES as $name => $value) {
                    self::assertContains($name . ': ' . $value, $http_response_header);
                }

                self::assertStringNotContainsString('FAKE_PRIVATE_EXCEPTION', $body);

                if ($method === 'HEAD') {
                    self::assertSame('', $body);
                }
            }
        } finally {
            proc_terminate($process);
            proc_close($process);

            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }

            rmdir($directory);
        }
    }
}
