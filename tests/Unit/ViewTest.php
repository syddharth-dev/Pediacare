<?php

declare(strict_types=1);

use Pediacare\Core\View;
use PHPUnit\Framework\TestCase;

final class ViewTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/pediacare-view-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        file_put_contents(
            $this->directory . '/page.php',
            '<?php declare(strict_types=1); ?><p><?= e($value) ?></p>'
        );
        file_put_contents(
            $this->directory . '/broken.php',
            '<?php declare(strict_types=1); echo "partial output"; throw new RuntimeException("FAKE_FAILURE");'
        );
    }

    protected function tearDown(): void
    {
        unlink($this->directory . '/page.php');
        unlink($this->directory . '/broken.php');
        rmdir($this->directory);
    }

    public function test_T05_escapes_template_data_without_escaping_markup(): void
    {
        $html = (new View($this->directory))->render('page', ['value' => '<script>FAKE</script>'], null);

        self::assertSame('<p>&lt;script&gt;FAKE&lt;/script&gt;</p>', $html);
    }

    public function test_T05_template_name_cannot_escape_view_directory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('View name is invalid.');

        (new View($this->directory))->render('../config/config.local', [], null);
    }

    public function test_T05_data_cannot_replace_the_selected_template_file(): void
    {
        $html = (new View($this->directory))->render('page', [
            'value' => 'FAKE_SAFE_VALUE',
            'file' => $this->directory . '/broken.php',
        ], null);

        self::assertSame('<p>FAKE_SAFE_VALUE</p>', $html);
    }

    public function test_T05_render_failure_restores_output_buffer_without_leaking_partial_output(): void
    {
        $level = ob_get_level();
        ob_start();

        try {
            try {
                (new View($this->directory))->render('broken', [], null);
                self::fail('Expected rendering to fail.');
            } catch (RuntimeException $error) {
                self::assertSame('FAKE_FAILURE', $error->getMessage());
            }

            self::assertSame($level + 1, ob_get_level());
            self::assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function test_T05_missing_template_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('View was not found.');

        (new View($this->directory))->render('missing', [], null);
    }

    public function test_T05_base_layout_and_partials_escape_dynamic_values(): void
    {
        $view = new View(dirname(__DIR__, 2) . '/views');
        $html = $view->render('partials/flash', [
            'title' => '<script>FAKE_TITLE</script>',
            'userName' => '<b>FAKE_STAFF</b>',
            'navigation' => [['href' => '/children?name="FAKE"', 'label' => '<i>FAKE_NAV</i>', 'current' => true]],
            'messages' => [['type' => 'error', 'message' => '<img src=x onerror=FAKE>']],
            'content' => 'FAKE_CONTENT_OVERRIDE',
        ]);

        self::assertStringContainsString('&lt;script&gt;FAKE_TITLE&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;FAKE_STAFF&lt;/b&gt;', $html);
        self::assertStringContainsString('&lt;i&gt;FAKE_NAV&lt;/i&gt;', $html);
        self::assertStringContainsString('name=&quot;FAKE&quot;', $html);
        self::assertStringContainsString('&lt;img src=x onerror=FAKE&gt;', $html);
        self::assertStringNotContainsString('FAKE_CONTENT_OVERRIDE', $html);
        self::assertStringContainsString('aria-current="page"', $html);
        self::assertStringContainsString('role="alert"', $html);
        self::assertStringContainsString('href="#main-content"', $html);
        self::assertStringContainsString('/assets/css/app.css', $html);
    }
}
