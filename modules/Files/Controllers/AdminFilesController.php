<?php
declare(strict_types=1);
namespace Modules\Files\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use Modules\Files\Services\FileService;

class AdminFilesController
{
    private FileService $files;

    public function __construct(FileService $files)
    {
        $this->files = $files;
    }

    public function index(Request $request): Response
    {
        // If no account selected, show the account picker.
        $account = (string) $request->query('account', '');
        if ($account === '') {
            $accounts = app('db')->select('SELECT `username` FROM `accounts` WHERE `status` = ? ORDER BY `username`', ['active']);
            $html = view('admin/files/accounts', [
                'title' => 'File Manager',
                'accounts' => $accounts,
            ], 'layouts/admin');
            return new Response((string) $html);
        }

        return $this->browse($account, (string) $request->query('path', ''));
    }

    private function browse(string $account, string $path): Response
    {
        try {
            $data = $this->files->list($account, $path);
        } catch (\Throwable $e) {
            $data = ['path' => $path, 'entries' => []];
            app('session')->flash('error', $e->getMessage());
        }

        $q = strtolower(trim((string) app('request')->query('q', '')));
        $entries = $data['entries'];
        if ($q !== '') {
            $entries = array_values(array_filter($entries, fn ($e) => str_contains(strtolower((string) ($e['name'] ?? '')), $q)));
        }

        $html = view('admin/files/index', [
            'title' => 'File Manager: ' . $account,
            'account' => $account,
            'path' => $data['path'],
            'entries' => $entries,
            'q' => $q,
            'basePath' => '/admin/files',
        ], 'layouts/admin');

        return new Response((string) $html);
    }

    public function mkdir(string $account, Request $request): Response
    {
        $path = (string) $request->input('path', '');
        if (trim($path, '/') === '') {
            app('session')->flash('error', 'Enter a directory name.');
            return Response::redirect($this->back($account, $request));
        }
        try {
            $ok = $this->files->mkdir($account, $path);
            app('session')->flash($ok ? 'success' : 'error', $ok ? 'Directory created.' : 'Could not create directory.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/files?account=' . urlencode($account) . '&path=' . urlencode((string) $request->input('parent', '')));
    }

    public function delete(string $account, Request $request): Response
    {
        $path = (string) $request->input('path', '');
        if (trim($path, '/') === '') {
            app('session')->flash('error', 'Nothing selected to delete.');
            return Response::redirect($this->back($account, $request));
        }
        try {
            $ok = $this->files->delete($account, $path);
            app('session')->flash($ok ? 'success' : 'error', $ok ? 'Deleted.' : 'Could not delete.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/files?account=' . urlencode($account) . '&path=' . urlencode((string) $request->input('parent', '')));
    }

    public function rename(string $account, Request $request): Response
    {
        $from = (string) $request->input('from', '');
        $to = (string) $request->input('to', '');
        if (trim($from, '/') === '' || trim($to, '/') === '') {
            app('session')->flash('error', 'Enter both the current and the new name.');
            return Response::redirect($this->back($account, $request));
        }
        try {
            $ok = $this->files->rename($account, $from, $to);
            app('session')->flash($ok ? 'success' : 'error', $ok ? 'Renamed.' : 'Could not rename.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/files?account=' . urlencode($account) . '&path=' . urlencode((string) $request->input('parent', '')));
    }

    private function back(string $account, Request $request): string
    {
        return '/admin/files?account=' . urlencode($account) . '&path=' . urlencode((string) $request->input('parent', ''));
    }

    public function copy(string $account, Request $request): Response
    {
        try {
            $this->files->copy($account, (string) $request->input('from', ''), (string) $request->input('to', ''));
            app('session')->flash('success', 'Copied.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect($this->back($account, $request));
    }

    public function chmod(string $account, Request $request): Response
    {
        try {
            $this->files->chmod($account, (string) $request->input('path', ''), (string) $request->input('mode', ''));
            app('session')->flash('success', 'Permissions updated.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect($this->back($account, $request));
    }

    public function extract(string $account, Request $request): Response
    {
        try {
            $name = basename((string) $request->input('name', ''));
            $format = strtolower((string) $request->input('format', 'tar')) === 'zip' ? 'zip' : 'tar';
            $this->files->extract($account, $name, (string) $request->input('path', ''), $format);
            app('session')->flash('success', 'Archive extracted.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect($this->back($account, $request));
    }

    public function create(string $account, Request $request): Response
    {
        $name = trim((string) $request->input('name', ''), '/');
        $parent = (string) $request->input('parent', '');
        if ($name === '' || str_contains($name, '/')) {
            app('session')->flash('error', 'Enter a plain file name (no slashes).');
            return Response::redirect('/admin/files?account=' . urlencode($account) . '&path=' . urlencode($parent));
        }
        $full = trim($parent . '/' . $name, '/');
        $this->files->write($account, $full, '');
        return Response::redirect('/admin/files/' . urlencode($account) . '/edit?path=' . urlencode($full));
    }

    public function upload(string $account, Request $request): Response
    {
        $parent = (string) $request->input('path', '');
        $file = $request->file('file');
        if ($file && $file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
            $content = file_get_contents($file['tmp_name']);
            if ($content !== false) {
                $this->files->write($account, trim($parent . '/' . $file['name'], '/'), $content);
                app('session')->flash('success', 'Uploaded ' . $file['name']);
            }
        }
        return Response::redirect('/admin/files?account=' . urlencode($account) . '&path=' . urlencode($parent));
    }

    public function archive(string $account, Request $request): Response
    {
        $path = (string) $request->input('path', '');
        if (trim($path, '/') === '') {
            app('session')->flash('error', 'Nothing selected to archive.');
            return Response::redirect($this->back($account, $request));
        }
        try {
            $ok = $this->files->archive($account, $path, (string) $request->input('name', 'archive'), (string) $request->input('format', 'tar'));
            app('session')->flash($ok ? 'success' : 'error', $ok ? 'Archive created.' : 'Could not create archive.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/files?account=' . urlencode($account) . '&path=' . urlencode((string) $request->input('parent', '')));
    }

    public function edit(string $account, Request $request): Response
    {
        $path = (string) $request->query('path', '');
        if (trim($path, '/') === '') {
            return Response::redirect('/admin/files?account=' . urlencode($account));
        }
        try {
            $content = $this->files->read($account, $path);
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/admin/files?account=' . urlencode($account));
        }
        $html = view('admin/files/edit', [
            'title' => 'Edit: ' . basename($path),
            'account' => $account,
            'path' => $path,
            'content' => $content,
        ], 'layouts/admin');
        return new Response((string) $html);
    }

    public function save(string $account, Request $request): Response
    {
        $path = (string) $request->input('path', '');
        if (trim($path, '/') === '') {
            app('session')->flash('error', 'Nothing selected to save.');
            return Response::redirect('/admin/files?account=' . urlencode($account));
        }
        try {
            $ok = $this->files->write($account, $path, (string) $request->input('content', ''));
            app('session')->flash($ok ? 'success' : 'error', $ok ? 'File saved.' : 'Could not save file.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/files/' . urlencode($account) . '/edit?path=' . urlencode($path));
    }

    public function download(string $account, Request $request): Response
    {
        $path = (string) $request->query('path', '');
        if (trim($path, '/') === '') {
            return Response::redirect('/admin/files?account=' . urlencode($account));
        }
        try {
            $content = $this->files->read($account, $path);
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/admin/files?account=' . urlencode($account));
        }
        return (new Response($content))
            ->header('Content-Type', 'application/octet-stream')
            ->header('Content-Disposition', 'attachment; filename=\"' . \App\Foundation\Response::safeFilename($path) . '\"');
    }
}