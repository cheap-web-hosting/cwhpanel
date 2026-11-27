<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Services;

use Modules\AppInstaller\Services\CatalogService;
use Modules\AppInstaller\Services\DownloadService;

/**
 * Reusable application installation engine.
 *
 * The install lifecycle (data-driven from the application manifest):
 *   download (HTTPS + sha256) -> agent extract+chown -> create DB/user ->
 *   write generated config -> register installation -> log.
 *
 * Security invariants:
 *   - only admin-approved manifest download URLs are fetched (no SSRF)
 *   - extraction is slip-safe (agent pre-check) and confined to account home
 *   - files are chowned to the account user (never left root/nginx-owned)
 *   - no shell; the agent runs strict argv sequences
 */
class InstallerService
{
    use \Modules\AppInstaller\Services\Installer\CreateExecuteTrait;
    use \Modules\AppInstaller\Services\Installer\DownloadResolveTrait;
    use \Modules\AppInstaller\Services\Installer\AppInstallTrait;
    use \Modules\AppInstaller\Services\Installer\PhpInstallTrait;
    use \Modules\AppInstaller\Services\Installer\SetupStepsTrait;
    use \Modules\AppInstaller\Services\Installer\ConfigLogTrait;
    use \Modules\AppInstaller\Services\Installer\UninstallTrait;

    private CatalogService $catalog;
    private DownloadService $download;

    public function __construct()
    {
        $this->catalog = app(CatalogService::class);
        $this->download = app(DownloadService::class);
    }
}