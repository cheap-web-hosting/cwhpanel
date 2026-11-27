<?php

declare(strict_types=1);

namespace Modules\Admin\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;

class ModuleController
{
    private const SYSTEM_MODULES = ['core', 'authentication', 'authorization', 'admin', 'customer'];

    public function index(): Response
    {
        $manager = app()->moduleManager();
        $discovered = $manager->discover();
        $loaded = $manager->all();
        $skipped = $manager->skipped();
        $degraded = $manager->degraded();

        $modules = [];

        foreach ($discovered as $slug => $info) {
            $manifest = $info['manifest'];
            $enabled = isset($loaded[$slug]);

            $modules[] = [
                'id' => $slug,
                'name' => $manifest->name() ?? $slug,
                'version' => $manifest->version() ?? '0.0.0',
                'enabled' => $enabled,
                'reason' => $skipped[$slug] ?? $degraded[$slug] ?? null,
                'system' => in_array($slug, self::SYSTEM_MODULES, true),
                'requires' => $manifest->requires(),
                'dependents' => $enabled ? $this->enabledDependents($slug, $discovered, $manager) : [],
                'admin' => $manifest->admin(),
                'customer' => $manifest->customer(),
            ];
        }

        usort($modules, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $content = view('admin/settings/modules', [
            'title' => 'Modules',
            'modules' => $modules,
        ], 'layouts/admin');

        return new Response((string) $content);
    }

    public function toggle(string $id, Request $request): Response
    {
        if (in_array($id, self::SYSTEM_MODULES, true)) {
            app('session')->flash('error', 'This is a required system module and cannot be disabled.');
            return Response::redirect('/admin/settings/modules');
        }

        $manager = app()->moduleManager();
        $discovered = $manager->discover();

        if (!isset($discovered[$id])) {
            app('session')->flash('error', 'Unknown module.');
            return Response::redirect('/admin/settings/modules');
        }

        $currentlyEnabled = $manager->isEnabled($id);
        $newState = !$currentlyEnabled;

        // Refuse to orphan enabled dependents: bootstrap cascades a disable to
        // them now, but silently dropping half the panel is not acceptable —
        // require an explicit cascade confirmation.
        if (!$newState) {
            $blockers = $this->enabledDependents($id, $discovered, $manager);

            if ($blockers !== [] && !$request->input('cascade')) {
                app('session')->flash(
                    'error',
                    'Cannot disable ' . $id . ': required by enabled module(s) '
                    . implode(', ', $blockers)
                    . '. Disable those first, or repeat with cascade to disable them together.'
                );
                return Response::redirect('/admin/settings/modules');
            }
        }

        $targets = [$id];
        if (!$newState) {
            $targets = array_merge($targets, $this->enabledDependents($id, $discovered, $manager));
        }

        $db = app('db');

        foreach ($targets as $target) {
            $this->persistState($db, $target, $discovered, $newState);
        }

        foreach ($targets as $target) {
            audit($newState ? 'module.enabled' : 'module.disabled', 'module', $target);
        }

        $flash = 'Module ' . ($newState ? 'enabled' : 'disabled') . '.';
        if (count($targets) > 1) {
            $flash .= ' Also disabled (required it): ' . implode(', ', array_slice($targets, 1)) . '.';
        }
        app('session')->flash('success', $flash . ' Changes take effect on the next request.');

        return Response::redirect('/admin/settings/modules');
    }

    /**
     * Enabled modules that declare a hard requirement on $id. Disabling $id
     * without them would cascade-disable half the panel at next bootstrap.
     *
     * @return array<int,string>
     */
    private function enabledDependents(string $id, array $discovered, $manager): array
    {
        $dependents = [];

        foreach ($discovered as $slug => $info) {
            if ($slug === $id || !$manager->isEnabled($slug)) {
                continue;
            }

            $module = $manager->get($slug);
            $requires = $module !== null ? $module->requires() : [];
            $requires = array_unique(array_merge($requires, $info['manifest']->requires()));

            if (in_array($id, $requires, true)) {
                $dependents[] = $slug;
            }
        }

        sort($dependents);

        return $dependents;
    }

    /** Upsert one module's enable flag (insert on first toggle). */
    private function persistState(\App\Foundation\Database\Connection $db, string $id, array $discovered, bool $state): void
    {
        $existing = $db->selectOne('SELECT `id` FROM `modules` WHERE `id` = ?', [$id]);

        if ($existing !== null) {
            $db->update('modules', [
                'is_enabled' => $state ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ], '`id` = ?', [$id]);

            return;
        }

        $db->insert('modules', [
            'id' => $id,
            'name' => $discovered[$id]['manifest']->name() ?? $id,
            'version' => $discovered[$id]['manifest']->version() ?? '0.0.0',
            'is_enabled' => $state ? 1 : 0,
            'is_system' => in_array($id, self::SYSTEM_MODULES, true) ? 1 : 0,
            'installed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
