<?php
declare(strict_types=1);
namespace Modules\Applications\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use Modules\Applications\Services\AppService;

/**
 * Inbound push-to-deploy receiver for git forges (GitHub / GitLab).
 *
 * Public route by necessity (forges POST without a panel session); the
 * global CSRF gate already skips /api/* paths, and authentication here is
 * the per-app HMAC secret, verified constant-time before anything else.
 * Nothing is written to the database before the signature verifies (no
 * DB-fill oracle), and disabled/secretless/unknown apps all answer the
 * same 404. Deployments run async through the jobs table so a push storm
 * can never exhaust PHP workers — duplicate pending deploys dedupe.
 */
class WebhookController {
    public function receive(int $id, Request $request): Response
    {
        $app = app('db')->selectOne('SELECT * FROM `applications` WHERE `id` = ?', [$id]);
        $secret = (string) ($app['webhook_secret'] ?? '');
        if ($app === null || empty($app['webhook_enabled']) || $secret === '') {
            return Response::json(['error' => 'Not found.'], 404);
        }

        $raw = file_get_contents('php://input') ?: '';
        $provider = AppService::verifyWebhookSignature(
            $secret,
            $raw,
            $request->header('X-Hub-Signature-256'),
            $request->header('X-Gitlab-Token')
        );
        if ($provider === null) {
            try {
                app('logger')->warning("app webhook {$id}: signature mismatch from " . $request->ip());
            } catch (\Throwable $ignored) {}
            return Response::json(['error' => 'Invalid signature.'], 401);
        }

        // Handshake deliveries (GitHub "ping") verify the secret only.
        if (($request->header('X-GitHub-Event') ?? '') === 'ping') {
            return Response::json(['ok' => true, 'pong' => true], 200);
        }

        // Replay protection depends on the forge delivery id, which is
        // OUTSIDE the HMAC signature. Refuse deliveries that do not carry it:
        // a body-only replay (no delivery id) must not be queueable. This is
        // the enforcement half of the dedupe below.
        $deliveryHeader = $provider === 'github'
            ? $request->header('X-GitHub-Delivery')
            : ($provider === 'gitlab' ? $request->header('X-Gitlab-Event-UUID') : null);
        if ($deliveryHeader === null || trim((string) $deliveryHeader) === '') {
            return Response::json(['error' => 'Missing delivery identifier.'], 422);
        }

        // Replay protection: a captured delivery (valid signature) must not be
        // replayable. Dedupe on the forge's delivery id.
        $delivery = (string) $deliveryHeader;
        if ($delivery !== '') {
            try {
                $db = app('db');
                if ($db->selectOne('SELECT `id` FROM `webhook_deliveries` WHERE `app_id` = ? AND `delivery_id` = ?', [$id, $delivery]) !== null) {
                    return Response::json(['ok' => true, 'deduped' => true, 'replay' => true], 200);
                }
                $db->insert('webhook_deliveries', ['app_id' => $id, 'delivery_id' => substr($delivery, 0, 191), 'created_at' => date('Y-m-d H:i:s')]);
                // Opportunistic prune (no dedicated cron needed). Retention is
                // long (1 year) so a captured delivery cannot be replayed just
                // after a 7-day prune, and bounded per app so the table cannot
                // grow without bound under a push storm.
                $db->delete('webhook_deliveries', '`created_at` < ?', [date('Y-m-d H:i:s', time() - 31536000)]);
                $db->delete(
                    'webhook_deliveries',
                    '`app_id` = ? AND `id` NOT IN (SELECT `id` FROM (SELECT `id` FROM `webhook_deliveries` WHERE `app_id` = ? ORDER BY `id` DESC LIMIT 5000) AS keep)',
                    [$id, $id]
                );
            } catch (\Throwable $e) {
                app('logger')->warning("webhook replay-check failed for app {$id}: " . $e->getMessage());
            }
        }

        if (empty($app['git_url'])) {
            return Response::json(['error' => 'No git remote configured for this app.'], 422);
        }

        $res = app(AppService::class)->queueDeploy($id, $provider);
        return Response::json(['ok' => true, 'queued' => $res['queued'], 'deduped' => $res['deduped']], 200);
    }
}
