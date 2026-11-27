<?php

declare(strict_types=1);

namespace App\Foundation\Navigation;

class NavigationRegistry
{
    /**
     * @var array<string, array<string, array>> [area => [moduleId => items]]
     */
    private array $modules = [
        'admin' => [],
        'customer' => [],
    ];

    public function addModuleNav(string $moduleId, array $nav): void
    {
        foreach (['admin', 'customer'] as $area) {
            if (!isset($nav[$area]) || !is_array($nav[$area])) {
                continue;
            }

            foreach ($nav[$area] as $item) {
                $item['module'] = $moduleId;
                $this->modules[$area][$moduleId][] = $item;
            }
        }
    }

    public function tree(string $area): array
    {
        $items = [];

        foreach ($this->modules[$area] ?? [] as $moduleItems) {
            $items = array_merge($items, $moduleItems);
        }

        usort($items, fn ($a, $b) => ($a['order'] ?? 100) <=> ($b['order'] ?? 100));

        foreach ($items as &$item) {
            if (!empty($item['children'])) {
                usort($item['children'], fn ($a, $b) => ($a['order'] ?? 100) <=> ($b['order'] ?? 100));
            }
        }
        unset($item);

        return $items;
    }

    public function flat(string $area): array
    {
        $out = [];

        foreach ($this->tree($area) as $item) {
            if (empty($item['children'])) {
                if (isset($item['url'])) {
                    $out[] = $this->flattenItem($item, null);
                }
                continue;
            }

            foreach ($item['children'] as $child) {
                $out[] = $this->flattenItem($child, $item);
            }
        }

        return $out;
    }

    public function breadcrumbs(string $area, string $path): array
    {
        $path = '/' . trim($path, '/');
        $breadcrumbs = [];
        $bestLength = -1;

        foreach ($this->tree($area) as $item) {
            if (empty($item['children'])) {
                if ($this->matches($item, $path) && strlen($item['url']) > $bestLength) {
                    $bestLength = strlen($item['url']);
                    $breadcrumbs = [['label' => $item['label'], 'url' => $item['url']]];
                }
                continue;
            }

            foreach ($item['children'] as $child) {
                if ($this->matches($child, $path) && strlen($child['url']) > $bestLength) {
                    $bestLength = strlen($child['url']);
                    $breadcrumbs = [
                        ['label' => $item['label'], 'url' => $item['url'] ?? null],
                        ['label' => $child['label'], 'url' => $child['url'] ?? null],
                    ];
                }
            }

            if (isset($item['url']) && $this->matches($item, $path) && strlen($item['url']) > $bestLength) {
                $bestLength = strlen($item['url']);
                $breadcrumbs = [['label' => $item['label'], 'url' => $item['url']]];
            }
        }

        return $breadcrumbs;
    }

    public function isActive(array $item, string $path): bool
    {
        $path = '/' . trim($path, '/');

        if ($this->matches($item, $path)) {
            return true;
        }

        if (!empty($item['children'])) {
            foreach ($item['children'] as $child) {
                if ($this->matches($child, $path)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function matches(array $item, string $path): bool
    {
        $url = $item['url'] ?? null;

        if ($url === null) {
            return false;
        }

        $url = '/' . trim((string) $url, '/');

        return $url === $path || (!in_array($url, ['/admin', '/panel'], true) && str_starts_with($path, $url . '/'));
    }

    private function flattenItem(array $item, ?array $parent): array
    {
        return [
            'id' => $item['id'],
            'label' => $item['label'],
            'icon' => $item['icon'] ?? null,
            'url' => $item['url'] ?? null,
            'permission' => $item['permission'] ?? null,
            'group' => $parent['label'] ?? null,
        ];
    }
}
