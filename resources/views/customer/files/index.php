<?php $account = $account ?? ''; $path = $path ?? ''; $entries = $entries ?? []; $q = $q ?? ''; $basePath = $basePath ?? '/panel/files'; ?>
<?php
$lk = fn ($p) => $basePath . '?path=' . urlencode($p) . ($q !== '' ? '&q=' . urlencode($q) : '');
echo $view->partial('partials/file-browser', [
    'account' => $account,
    'path' => $path,
    'entries' => $entries,
    'q' => $q,
    'lk' => $lk,
    'actBase' => $basePath,
    'editPrefix' => $basePath . '/edit?path=',
    'dlPrefix' => $basePath . '/download?path=',
    'extraQuery' => [],
]);
