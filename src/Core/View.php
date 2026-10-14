<?php

declare(strict_types=1);

namespace Pediacare\Core;

use RuntimeException;
use Throwable;

final class View
{
    public function __construct(private readonly string $directory)
    {
        require_once dirname(__DIR__) . '/Support/helpers.php';
    }

    public function render(string $template, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $level = ob_get_level();
        ob_start();

        try {
            if ($layout === null) {
                $this->partial($template, $data);
            } else {
                // Render trusted templates directly; all scalar values are escaped inside them.
                $data['content'] = function () use ($template, $data): void {
                    $this->partial($template, $data);
                };
                $this->partial($layout, $data);
            }

            return (string) ob_get_clean();
        } catch (Throwable $error) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }

            throw $error;
        }
    }

    public function partial(string $template, array $data = []): void
    {
        if (preg_match('/\A[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*\z/', $template) !== 1) {
            throw new RuntimeException('View name is invalid.');
        }

        $root = realpath($this->directory);
        $file = realpath($this->directory . '/' . $template . '.php');

        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('View was not found.');
        }

        $view = $this;
        extract($data, EXTR_SKIP);
        require $file;
    }
}
