<?php $breadcrumbs = $breadcrumbs ?? []; ?>
<?php if ($breadcrumbs): ?>
  <nav class="breadcrumbs" aria-label="Breadcrumb">
    <ol>
      <?php $last = count($breadcrumbs) - 1; ?>
      <?php foreach ($breadcrumbs as $i => $crumb): ?>
        <li<?= $i === $last ? ' aria-current="page"' : '' ?>>
          <?php if ($crumb['url'] && $i !== $last): ?>
            <a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a>
          <?php else: ?>
            <span><?= e($crumb['label']) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </nav>
<?php endif; ?>
