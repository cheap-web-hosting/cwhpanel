<?php

declare(strict_types=1);

namespace App\Foundation;

class View
{
    private string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR);
    }

    public function make(string $template, array $data = [], ?string $layout = null): string
    {
        $data['view'] = $this;

        $content = $this->render($template, $data);

        if ($layout !== null) {
            $data['content'] = $content;
            return $this->render($layout, $data);
        }

        return $content;
    }

    public function render(string $template, array $data = []): string
    {
        $file = $this->baseDir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $template) . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("View [{$template}] not found at [{$file}].");
        }

        extract($data, EXTR_SKIP);

        ob_start();

        include $file;

        return (string) ob_get_clean();
    }

    public function partial(string $template, array $data = []): string
    {
        return $this->render($template, $data);
    }

    public function exists(string $template): bool
    {
        $file = $this->baseDir . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $template) . '.php';
        return is_file($file);
    }
}
