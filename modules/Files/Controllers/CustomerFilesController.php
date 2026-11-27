<?php
declare(strict_types=1);
namespace Modules\Files\Controllers;

use App\Foundation\Request;
use App\Foundation\Response;
use Modules\Files\Services\FileService;

class CustomerFilesController
{
    private FileService $files;

    public function __construct(FileService $files)
    {
        $this->files = $files;
    }

    private function account(): ?array
    {
        $user = app('auth')->user();
        if ($user === null) return null;
        $row = app('db')->selectOne('SELECT * FROM `accounts` WHERE `user_id` = ?', [$user->id]);
        return $row ?: null;
    }

    public function index(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel');

        return $this->browse($account['username'], (string) $request->query('path', ''), '/panel/files');
    }

    public function edit(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel/files');

        $username = $account['username'];
        $path = (string) $request->query('path', '');
        if (trim($path, '/') === '') {
            return Response::redirect('/panel/files');
        }
        try {
            $content = $this->files->read($username, $path);
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/files');
        }

        $html = view('customer/files/edit', [
            'title' => 'Edit: ' . basename($path),
            'account' => $username,
            'path' => $path,
            'content' => $content,
            'basePath' => '/panel/files',
        ], 'layouts/customer');

        return new Response((string) $html);
    }

    public function save(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel/files');

        $path = (string) $request->input('path', '');
        if (trim($path, '/') === '') {
            app('session')->flash('error', 'Nothing selected to save.');
            return Response::redirect('/panel/files');
        }
        try {
            $content = (string) $request->input('content', '');
            $ok = $this->files->write($account['username'], $path, $content);
            app('session')->flash($ok ? 'success' : 'error', $ok ? 'File saved.' : 'Could not save file.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/files/edit?path=' . urlencode($path));
    }

    public function mkdir(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel/files');
        $path = (string) $request->input('path', '');
        if (trim($path, '/') === '') {
            app('session')->flash('error', 'Enter a directory name.');
            return Response::redirect('/panel/files?path=' . urlencode((string) $request->input('parent', '')));
        }
        try {
            $ok = $this->files->mkdir($account['username'], $path);
            app('session')->flash($ok ? 'success' : 'error', $ok ? 'Directory created.' : 'Could not create directory.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/files?path=' . urlencode((string) $request->input('parent', '')));
    }

    public function delete(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel/files');
        $path = (string) $request->input('path', '');
        if (trim($path, '/') === '') {
            app('session')->flash('error', 'Nothing selected to delete.');
            return Response::redirect('/panel/files?path=' . urlencode((string) $request->input('parent', '')));
        }
        try {
            $ok = $this->files->delete($account['username'], $path);
            app('session')->flash($ok ? 'success' : 'error', $ok ? 'Deleted.' : 'Could not delete.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/files?path=' . urlencode((string) $request->input('parent', '')));
    }

    public function rename(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel/files');
        $from = (string) $request->input('from', '');
        $to = (string) $request->input('to', '');
        if (trim($from, '/') === '' || trim($to, '/') === '') {
            app('session')->flash('error', 'Enter both the current and the new name.');
            return Response::redirect('/panel/files?path=' . urlencode((string) $request->input('parent', '')));
        }
        try {
            $ok = $this->files->rename($account['username'], $from, $to);
            app('session')->flash($ok ? 'success' : 'error', $ok ? 'Renamed.' : 'Could not rename.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/files?path=' . urlencode((string) $request->input('parent', '')));
    }

    public function copy(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel/files');
        try {
            $this->files->copy($account['username'], (string) $request->input('from', ''), (string) $request->input('to', ''));
            app('session')->flash('success', 'Copied.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/files?path=' . urlencode((string) $request->input('parent', '')));
    }

    public function chmod(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel/files');
        try {
            $this->files->chmod($account['username'], (string) $request->input('path', ''), (string) $request->input('mode', ''));
            app('session')->flash('success', 'Permissions updated.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/files?path=' . urlencode((string) $request->input('parent', '')));
    }

    public function extract(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel/files');
        try {
            $name = basename((string) $request->input('name', ''));
            $format = strtolower((string) $request->input('format', 'tar')) === 'zip' ? 'zip' : 'tar';
            $this->files->extract($account['username'], $name, (string) $request->input('path', ''), $format);
            app('session')->flash('success', 'Archive extracted.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/files?path=' . urlencode((string) $request->input('parent', '')));
    }

    public function create(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel/files');
        $name = trim((string) $request->input('name', ''), '/');
        $parent = (string) $request->input('parent', '');
        if ($name === '' || str_contains($name, '/')) {
            app('session')->flash('error', 'Enter a plain file name (no slashes).');
            return Response::redirect('/panel/files?path=' . urlencode($parent));
        }
        $full = trim($parent . '/' . $name, '/');
        $this->files->write($account['username'], $full, '');
        return Response::redirect('/panel/files/edit?path=' . urlencode($full));
    }

    public function upload(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel/files');

        $parent = (string) $request->input('path', '');
        $file = $request->file('file');

        if ($file && $file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
            $content = file_get_contents($file['tmp_name']);
            if ($content !== false) {
                $target = trim($parent . '/' . $file['name'], '/');
                $this->files->write($account['username'], $target, $content);
                app('session')->flash('success', 'Uploaded ' . $file['name']);
            }
        }

        return Response::redirect('/panel/files?path=' . urlencode($parent));
    }

    public function archive(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel/files');
        $path = (string) $request->input('path', '');
        if (trim($path, '/') === '') {
            app('session')->flash('error', 'Nothing selected to archive.');
            return Response::redirect('/panel/files?path=' . urlencode((string) $request->input('parent', '')));
        }
        try {
            $ok = $this->files->archive($account['username'], $path, (string) $request->input('name', 'archive'), (string) $request->input('format', 'tar'));
            app('session')->flash($ok ? 'success' : 'error', $ok ? 'Archive created.' : 'Could not create archive.');
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
        }
        return Response::redirect('/panel/files?path=' . urlencode((string) $request->input('parent', '')));
    }

    public function download(Request $request): Response
    {
        $account = $this->account();
        if ($account === null) return Response::redirect('/panel/files');
        $path = (string) $request->query('path', '');
        if (trim($path, '/') === '') {
            return Response::redirect('/panel/files');
        }
        try {
            $content = $this->files->read($account['username'], $path);
        } catch (\Throwable $e) {
            app('session')->flash('error', $e->getMessage());
            return Response::redirect('/panel/files');
        }
        return (new Response($content))
            ->header('Content-Type', 'application/octet-stream')
            ->header('Content-Disposition', 'attachment; filename=\"' . \App\Foundation\Response::safeFilename($path) . '\"');
    }

    private function browse(string $username, string $path, string $basePath): Response
    {
        try {
            $data = $this->files->list($username, $path);
        } catch (\Throwable $e) {
            $data = ['path' => $path, 'entries' => []];
            app('session')->flash('error', $e->getMessage());
        }

        $q = strtolower(trim((string) app('request')->query('q', '')));
        $entries = $data['entries'];
        if ($q !== '') {
            $entries = array_values(array_filter($entries, fn ($e) => str_contains(strtolower((string) ($e['name'] ?? '')), $q)));
        }

        $html = view('customer/files/index', [
            'title' => 'File Manager',
            'account' => $username,
            'path' => $data['path'],
            'entries' => $entries,
            'q' => $q,
            'basePath' => $basePath,
        ], 'layouts/customer');

        return new Response((string) $html);
    }
}