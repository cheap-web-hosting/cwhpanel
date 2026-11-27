<?php

declare(strict_types=1);

namespace App\Foundation\Navigation;

class Icon
{
    private array $icons;

    public function __construct()
    {
        $this->icons = $this->definitions();
    }

    public function render(string $name, int $size = 18): string
    {
        $paths = $this->icons[$name] ?? $this->icons['circle'];

        $svg = '<svg class="icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
            . 'aria-hidden="true" focusable="false">';

        foreach ($paths as $path) {
            $svg .= '<path d="' . $path . '"/>';
        }

        return $svg . '</svg>';
    }

    public function has(string $name): bool
    {
        return isset($this->icons[$name]);
    }

    private function definitions(): array
    {
        return [
            'circle' => ['M12 12m-8 0a8 8 0 1 0 16 0a8 8 0 1 0 -16 0'],

            'layout-dashboard' => [
                'M3 3h8v8H3z',
                'M13 3h8v5h-8z',
                'M13 10h8v11h-8z',
                'M3 13h8v8H3z',
            ],

            'server' => [
                'M3 4h18v6H3z',
                'M3 14h18v6H3z',
                'M7 7h.01',
                'M7 17h.01',
            ],

            'users' => [
                'M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20',
                'M9 4a4 4 0 1 0 0 8a4 4 0 0 0 0-8',
                'M22 20v-1.5a4 4 0 0 0-3-3.87',
                'M16 3.13a4 4 0 0 1 0 7.75',
            ],

            'user-circle' => [
                'M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0',
                'M12 13a4 4 0 1 0 0-8a4 4 0 0 0 0 8',
                'M6.5 19a6 6 0 0 1 11 0',
            ],

            'package' => [
                'M12 3l8 4.5v9L12 21l-8-4.5v-9z',
                'M12 3v9',
                'M12 12l8-4.5',
                'M12 12l-8-4.5',
                'M4 7.5l8 4.5v9',
            ],

            'globe' => [
                'M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0',
                'M3 12h18',
                'M12 3a13.5 13.5 0 0 1 0 18a13.5 13.5 0 0 1 0-18',
            ],

            'globe-2' => [
                'M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0',
                'M3 12h18',
                'M12 3a13.5 13.5 0 0 1 0 18a13.5 13.5 0 0 1 0-18',
            ],

            'network' => [
                'M6 9a3 3 0 1 0 0-6a3 3 0 0 0 0 6',
                'M18 21a3 3 0 1 0 0-6a3 3 0 0 0 0 6',
                'M18 9a3 3 0 1 0 0-6a3 3 0 0 0 0 6',
                'M6 21a3 3 0 1 0 0-6a3 3 0 0 0 0 6',
                'M9 6v6a3 3 0 0 0 3 3h0a3 3 0 0 0 3-3V6',
            ],

            'code-2' => [
                'M8 7l-5 5l5 5',
                'M16 7l5 5l-5 5',
                'M13 4l-2 16',
            ],

            'database' => [
                'M4 6c0 1.7 3.6 3 8 3s8-1.3 8-3s-3.6-3-8-3s-8 1.3-8 3',
                'M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6',
                'M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6',
            ],

            'mail' => [
                'M3 5h18v14H3z',
                'M3 7l9 6l9-6',
            ],

            'folder-up' => [
                'M3 5h6l2 2h10v12H3z',
                'M12 12v6',
                'M9.5 14.5L12 12l2.5 2.5',
            ],

            'folder' => [
                'M3 5h6l2 2h10v12H3z',
            ],

            'lock' => [
                'M6 11h12v9H6z',
                'M8 11V8a4 4 0 0 1 8 0v3',
            ],

            'archive' => [
                'M3 4h18v5H3z',
                'M5 9v11h14V9',
                'M10 13h4',
            ],

            'shield-check' => [
                'M12 3l8 3v5c0 5-3.5 8-8 10c-4.5-2-8-5-8-10V6z',
                'M9 12l2 2l4-4',
            ],

            'gauge' => [
                'M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0',
                'M12 12l4-4',
                'M7 13h.01',
            ],

            'boxes' => [
                'M3 3h8v8H3z',
                'M13 3h8v8h-8z',
                'M3 13h8v8H3z',
                'M13 13h8v8h-8z',
            ],

            'list-checks' => [
                'M4 6h2l1.5 2L9 4.5',
                'M4 12h2l1.5 2L9 10.5',
                'M4 18h2l1.5 2L9 16.5',
                'M12 7h8',
                'M12 13h8',
                'M12 19h8',
            ],

            'scroll-text' => [
                'M7 3h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3',
                'M10 8h6',
                'M10 12h6',
            ],

            'bell' => [
                'M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8',
                'M10.3 21a2 2 0 0 0 3.4 0',
            ],

            'braces' => [
                'M8 4c-2 0-3 1-3 3v2c0 1-1 2-2 2c1 0 2 1 2 2v2c0 2 1 3 3 3',
                'M16 4c2 0 3 1 3 3v2c0 1 1 2 2 2c-1 0-2 1-2 2v2c0 2-1 3-3 3',
            ],

            'settings' => [
                'M12 8.5a3.5 3.5 0 1 0 0 7a3.5 3.5 0 0 0 0-7',
                'M19.4 13a7.5 7.5 0 0 0 0-2l2-1.5-2-3.5-2.3 1a7.5 7.5 0 0 0-1.7-1L15 3.5h-4L10.6 6A7.5 7.5 0 0 0 8.9 7l-2.3-1l-2 3.5l2 1.5a7.5 7.5 0 0 0 0 2l-2 1.5l2 3.5l2.3-1a7.5 7.5 0 0 0 1.7 1L11 20.5h4l.4-2.5a7.5 7.5 0 0 0 1.7-1l2.3 1l2-3.5z',
            ],

            'clock-3' => [
                'M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0',
                'M12 7v5l3 2',
            ],

            'chart-no-axes-combined' => [
                'M4 4v16h16',
                'M8 16v-4',
                'M13 16V8',
                'M18 16v-6',
            ],

            'activity' => [
                'M3 12h4l3-8l4 16l3-8h4',
            ],

            'cpu' => [
                'M5 5h14v14H5z',
                'M9 9h6v6H9z',
                'M9 2v3',
                'M15 2v3',
                'M9 19v3',
                'M15 19v3',
                'M2 9h3',
                'M2 15h3',
                'M19 9h3',
                'M19 15h3',
            ],

            'hard-drive' => [
                'M4 4h16v16H4z',
                'M4 15h16',
                'M7 18h.01',
            ],

            'log-out' => [
                'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4',
                'M16 17l5-5l-5-5',
                'M21 12H9',
            ],

            'search' => [
                'M11 4a7 7 0 1 0 0 14a7 7 0 0 0 0-14',
                'M20 20l-3.5-3.5',
            ],

            'plus' => ['M12 5v14', 'M5 12h14'],

            'chevron-right' => ['M9 6l6 6l-6 6'],

            'chevron-down' => ['M6 9l6 6l6-6'],

            'menu' => ['M4 6h16', 'M4 12h16', 'M4 18h16'],

            'x' => ['M6 6l12 12', 'M18 6L6 18'],

            'check' => ['M5 13l4 4L19 7'],

            'alert-triangle' => [
                'M12 3l10 18H2z',
                'M12 10v4',
                'M12 17h.01',
            ],

            'key' => [
                'M12 12m-8 0a8 8 0 1 0 16 0a8 8 0 1 0 -16 0',
                'M15 9l5 5',
                'M18 12l2 2',
            ],

            'shield' => [
                'M12 3l8 3v5c0 5-3.5 8-8 10c-4.5-2-8-5-8-10V6z',
            ],

            'refresh' => [
                'M4 12a8 8 0 0 1 14-4.5L21 10',
                'M21 4v6h-6',
                'M20 12a8 8 0 0 1-14 4.5L3 14',
                'M3 20v-6h6',
            ],
        ];
    }
}
