<?php
declare(strict_types=1);
namespace Modules\Jobs\Services;

use App\Foundation\Database\Connection;

/**
 * Executes due jobs and delivers webhooks, with exponential backoff and a
 * configurable max-attempt ceiling.
 *
 * A job only runs when `available_at <= now`. On failure the next attempt is
 * scheduled `2^attempts` seconds out (capped), then marked failed after
 * `max_attempts` (webhook deliveries default to a higher ceiling).
 */
class JobRunner
{
    private Connection $db;

    private int $maxBackoff;      // seconds cap for exponential backoff

    public function __construct(Connection $db, int $maxBackoff = 300)
    {
        $this->db = $db;
        $this->maxBackoff = $maxBackoff;
    }

    /**
     * @return array{jobs:int, webhooks:int}
     */
    public function tick(): array
    {
        $jobsProcessed = $this->process("`type` != 'webhook.deliver'");
        $webhooksDelivered = $this->process("`type` = 'webhook.deliver'");

        return ['jobs' => $jobsProcessed, 'webhooks' => $webhooksDelivered];
    }

    private function process(string $typePredicate): int
    {
        $count = 0;
        $now = date('Y-m-d H:i:s');

        // Reaper: a crashed worker can leave a job 'running' forever. Requeue
        // anything stuck past the timeout so it is retried.
        $this->db->statement(
            "UPDATE `jobs` SET `status` = 'pending', `available_at` = ?, `updated_at` = ?
             WHERE `status` = 'running' AND `started_at` IS NOT NULL AND `started_at` < ?",
            [$now, $now, date('Y-m-d H:i:s', time() - 1800)]
        );

        $due = $this->db->select(
            "SELECT * FROM `jobs`
             WHERE `status` = 'pending'
               AND {$typePredicate}
               AND (`available_at` IS NULL OR `available_at` <= ?)
             ORDER BY `id` LIMIT 20",
            [$now]
        );

        foreach ($due as $job) {
            $attempts = (int) $job['attempts'] + 1;

            // Atomic claim: only the worker that flips pending -> running
            // (rowCount 1) executes the job; a second worker gets 0 and skips.
            $claimed = $this->db->update('jobs', [
                'status' => 'running',
                'attempts' => $attempts,
                'started_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], "`id` = ? AND `status` = 'pending'", [$job['id']]);

            if ($claimed === 0) {
                continue;
            }

            try {
                $result = $this->runOne((string) $job['type'], $job['payload']);
                $this->db->update('jobs', [
                    'status' => 'completed',
                    'result' => self::capResult($result),
                    'payload' => self::scrubPayload((string) $job['type'], $job['payload']),
                    'finished_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], '`id` = ?', [$job['id']]);
                $this->notifyJobOutcome($job, $result, false);
            } catch (\Throwable $e) {
                $maxAttempts = (int) ($job['max_attempts'] ?: 5);
                $failed = $attempts >= $maxAttempts;

                $this->db->update('jobs', [
                    'status' => $failed ? 'failed' : 'pending',
                    'result' => self::capResult($e->getMessage()),
                    // Scrub once terminal: retries still need the password,
                    // but a settled job must not keep it.
                    'payload' => $failed ? self::scrubPayload((string) $job['type'], $job['payload']) : $job['payload'],
                    'available_at' => $failed ? null : $this->schedule($attempts),
                    'finished_at' => $failed ? date('Y-m-d H:i:s') : null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ], '`id` = ?', [$job['id']]);
                $this->notifyJobOutcome($job, $e->getMessage(), true);
            }

            $count++;
        }

        return $count;
    }

    /**
     * Bell notifications for job outcomes: admin always hears about failures;
     * the account owner hears about app-type install/update completion/failure.
     */
    private function notifyJobOutcome(array $job, string $detail, bool $failed): void
    {
        try {
            $data = json_decode((string) ($job['payload'] ?? '{}'), true) ?: [];
            $account = is_string($data['account'] ?? null) ? $data['account'] : null;
            $type = (string) $job['type'];
            $svc = app(\Modules\Core\Services\NotificationService::class);

            if ($failed) {
                $svc->pushAdmin('job.failed', 'Background job failed: ' . $type, $detail, '/admin/jobs');
            }
            if ($account !== null && $account !== '') {
                $isAppJob = (bool) preg_match('/install|update|deploy|package|runtime/', $type);
                if (!$failed && $isAppJob) {
                    $svc->pushAccountOwner($account, 'app.done', 'Task completed', $detail, '/panel/applications');
                } elseif ($failed) {
                    $svc->pushAccountOwner($account, 'job.failed', 'A task for your account failed', $detail, '/panel');
                }
            }
        } catch (\Throwable $e) {
            app('logger')->warning('Job notification failed: ' . $e->getMessage());
        }
    }

    private function runOne(string $type, ?string $payload): string
    {
        $data = json_decode($payload ?: '{}', true) ?: [];

        if ($type === 'webhook.deliver') {
            $status = $this->post((string) ($data['url'] ?? ''), $data);
            return 'http ' . $status;
        }

        $result = JobRegistry::handle($type, $data);
        return is_scalar($result) ? (string) $result : json_encode($result);
    }

    /**
     * Remove transient secrets from a settled job's payload. Currently only
     * the WordPress setup password (appinstaller.install) travels this way:
     * the worker needs it once for wp_install, and afterwards no one should
     * be able to read it back out of the jobs history.
     */
    private static function scrubPayload(string $type, mixed $payload): mixed
    {
        if ($type !== 'appinstaller.install' || !is_string($payload) || $payload === '') {
            return $payload;
        }
        $data = json_decode($payload, true);
        if (!is_array($data) || !array_key_exists('admin_password', $data)) {
            return $payload;
        }
        unset($data['admin_password']);
        return json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    private function schedule(int $attempts): string
    {
        $delay = min(2 ** $attempts, $this->maxBackoff);
        return date('Y-m-d H:i:s', time() + $delay);
    }

    /**
     * Cap job result text before persisting: agent/exec output can exceed
     * the jobs.result TEXT column (a full unzip listing killed a Matomo
     * install AND its failure write, stranding the job `running` forever —
     * the failure-path UPDATE itself threw). 2000 chars matches the
     * install-log cap; full output stays in ai_install_logs.
     */
    private static function capResult(mixed $result): string
    {
        $text = is_scalar($result) ? (string) $result : json_encode($result);
        if (mb_strlen($text) > 2000) {
            $text = mb_substr($text, 0, 2000) . '… [truncated]';
        }
        return $text;
    }

    private function post(string $url, array $payload): int
    {
        // Re-check at send time (narrows DNS-rebinding windows); failures
        // ride the normal job backoff instead of delivering somewhere odd.
        $urlError = \Modules\Api\Services\WebhookPolicy::checkUrl($url);
        if ($urlError !== null) {
            throw new \RuntimeException('webhook url refused: ' . $urlError);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('webhook delivery failed (http ' . $status . ')');
        }

        return $status;
    }
}