<?php
declare(strict_types=1);
namespace Modules\Network\Controllers;
use App\Foundation\Request;
use App\Foundation\Response;
use Modules\Network\Services\IpAllocatorService;

class NetworkController {
    private IpAllocatorService $ips;
    public function __construct(IpAllocatorService $ips) { $this->ips = $ips; }

    public function index(): Response {
        $agentError = '';
        try {
            $r = app('provisioning')->execute('net.addr.list', []);
            $bound = self::parseAddrList((string) ($r['output'] ?? ''));
        } catch (\Throwable $e) {
            $bound = [];
            $agentError = 'Live address list unavailable: ' . $e->getMessage();
            app('logger')->warning('Network page degraded: ' . $e->getMessage());
        }
        $content = view('admin/network/index', [
            'title' => 'IP Management',
            'enabled' => $this->ips->enabled(),
            'bound' => $bound,
            'agentError' => $agentError,
            'pool' => $this->ips->pool(),
            'sharedIp' => (string) env('PANEL_PUBLIC_IP', ''),
            'appNetns' => app(\Modules\Network\Services\AppNetnsService::class)->enabled(),
        ], 'layouts/admin');
        return new Response((string)$content);
    }

    public function saveSettings(Request $request): Response {
        $enable = (bool) $request->input('enabled');
        try {
            $wasOff = !$this->ips->enabled();
            $this->ips->setEnabled($enable);
            // L-14 app network namespaces (independent toggle).
            $appNetns = app(\Modules\Network\Services\AppNetnsService::class);
            $netnsOn = (bool) $request->input('app_netns');
            $netnsWas = $appNetns->enabled();
            $appNetns->setEnabled($netnsOn);
            audit('network.settings.saved', null, null, ['enabled' => $enable, 'app_netns' => $netnsOn]);
            // Freshly enabling assigns nothing by itself (backfill is an
            // explicit admin action) — but the daemon state must converge.
            if ($netnsOn !== $netnsWas) {
                app('session')->flash('success', $netnsOn ? 'App network namespaces enabled. Restart each app (or deploy) to join its namespace.' : 'App network namespaces disabled. Restart each app to return it to loopback.');
                return Response::redirect('/admin/network');
            }
            if ($enable && $wasOff) {
                app('session')->flash('success', 'Dedicated IPv4 pool enabled. Assign pool addresses per website.');
            } else {
                app('session')->flash('success', 'Network settings saved.');
            }
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/network');
    }

    public function poolAdd(Request $request): Response {
        try {
            $this->ips->poolAdd(
                (string) $request->input('address', ''),
                max(1, min(32, (int) $request->input('prefixlen', 32))),
                (string) $request->input('note', '')
            );
            app('session')->flash('success', 'Address added to the pool and bound.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/network');
    }

    public function poolDelete(int $id): Response {
        try {
            $this->ips->poolDelete($id);
            app('session')->flash('success', 'Pool address removed.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/network');
    }

    /**
     * Parse `ip -o addr show` into rows (family/ifname/address). Panel-side
     * parse: the agent returns raw text (no schema to version).
     *
     * @return array<int, array{family:string, ifname:string, address:string}>
     */
    public static function parseAddrList(string $output): array {
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^\d+:\s+(\S+)\s+(inet6?)\s+(\S+)/', trim($line), $m)) {
                $rows[] = [
                    'family' => $m[2] === 'inet6' ? 'IPv6' : 'IPv4',
                    'ifname' => $m[1],
                    'address' => explode('/', $m[3])[0],
                ];
            }
        }
        return $rows;
    }
}
