<?php $account = $account ?? ''; $path = $path ?? ''; $entries = $entries ?? []; $q = $q ?? ''; $basePath = $basePath ?? '/admin/files'; ?>
<?php
$lk = fn ($p) => $basePath . '?account=' . urlencode($account) . '&path=' . urlencode($p) . ($q !== '' ? '&q=' . urlencode($q) : '');
$prefix = $basePath . '/' . urlencode($account);
echo $view->partial('partials/file-browser', [
    'account' => $account,
    'path' => $path,
    'entries' => $entries,
    'q' => $q,
    'lk' => $lk,
    'actBase' => $prefix,
    'editPrefix' => $prefix . '/edit?path=',
    'dlPrefix' => $prefix . '/download?path=',
    'extraQuery' => ['account' => $account],
    'headerExtra' => '<a href="' . e(url('admin/files')) . '" class="btn btn-sm" style="margin-left:auto">Switch account</a>',
]);
