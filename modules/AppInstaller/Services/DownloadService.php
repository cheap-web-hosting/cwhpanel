<?php
declare(strict_types=1);
namespace Modules\AppInstaller\Services;

/**
 * Secure downloader: HTTPS-only, size + timeout caps, streaming to a temp file,
 * and SHA-256 verification. Only administrator-approved URLs (stored in the
 * app manifest) are ever fetched — never arbitrary customer URLs, so SSRF is
 * not reachable through this service.
 */
class DownloadService
{
    // 512 MiB / 5 min: flagship archives are big (Nextcloud ~300 MB) and
    // staging streams straight to disk, so the caps only bound abuse, not
    // legitimate catalog downloads.
    public const MAX_BYTES = 512 * 1024 * 1024;   // 512 MiB
    public const TIMEOUT = 300;                  // seconds
    public const MAX_REDIRECTS = 3;

    /**
     * Shared staging directory, writable by the panel (nginx) and readable by
     * the privileged agent. NOTE: must NOT be under /tmp or /var/tmp — the agent
     * runs with PrivateTmp and cannot see those.
     */
    public const STAGING_DIR = '/var/lib/cwhpanel/appinstaller';

    /**
     * Download a package to /var/tmp and verify its checksum.
     *
     * @return string absolute path to the downloaded archive
     * @throws \RuntimeException
     */
    public function download(string $url, string $sha256, string $ext): string
    {
        // One bounded retry: upstream mirrors intermittently abort long
        // transfers (cacti.net: HTTP/2 INTERNAL_ERROR mid-stream). A second
        // attempt covers that without unbounded retry loops.
        try {
            return $this->downloadOnce($url, $sha256, $ext);
        } catch (\RuntimeException $e) {
            if (!preg_match('/^Download (failed|returned)/', $e->getMessage())) {
                throw $e;
            }
            return $this->downloadOnce($url, $sha256, $ext);
        }
    }

    private function downloadOnce(string $url, string $sha256, string $ext): string
    {
        if (!preg_match('#^https://#i', $url)) {
            throw new \RuntimeException('Only HTTPS download sources are allowed.');
        }

        // Ensure the shared staging dir exists and is writable.
        if (!is_dir(self::STAGING_DIR)) {
            @mkdir(self::STAGING_DIR, 0770, true);
        }
        @chmod(self::STAGING_DIR, 0770);

        $tmp = tempnam(self::STAGING_DIR, 'appdl-');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create temp download file.');
        }

        $fp = fopen($tmp, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Cannot open temp download file.');
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FILE => $fp,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // HTTPS end-to-end: follow redirects only to other https URLs so a
            // malicious/misconfigured host cannot downgrade the download.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Wget/1.21',
            // Force HTTP/1.1: several upstream mirrors (cacti.net proven)
            // abort long h2 streams with INTERNAL_ERROR, and the download
            // is a large sequential file transfer where h2 buys nothing.
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);
        // Cap downloaded size.
        $received = 0;
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$received, $fp) {
            $received += strlen($data);
            if ($received > self::MAX_BYTES) {
                return 0; // abort
            }
            return fwrite($fp, $data);
        });

        $ok = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);

        if ($ok === false) {
            @unlink($tmp);
            throw new \RuntimeException('Download failed: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            @unlink($tmp);
            throw new \RuntimeException("Download returned HTTP {$code}.");
        }
        if ($received > self::MAX_BYTES) {
            @unlink($tmp);
            throw new \RuntimeException('Download exceeds size limit.');
        }

        // Verify SHA-256.
        if ($sha256 !== '') {
            $actual = hash_file('sha256', $tmp);
            if (!hash_equals(strtolower($sha256), strtolower($actual))) {
                @unlink($tmp);
                throw new \RuntimeException('Checksum mismatch.');
            }
        }

        // Rename to a stable archive path with the right extension.
        $final = $tmp . '.' . $ext;
        rename($tmp, $final);
        return $final;
    }

    public function cleanup(string $path): void
    {
        @unlink($path);
    }
}