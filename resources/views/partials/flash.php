<?php $success = app('session')->get('success'); $error = app('session')->get('error'); $errors = app('session')->errors(); ?>
<?php if ($success): ?>
  <div class="flash flash-success" role="status"><?= e($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="flash flash-error" role="alert" tabindex="-1"><?= e($error) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="flash flash-error" role="alert" tabindex="-1">
    <ul class="flash-list">
      <?php foreach ($errors as $field => $messages): ?>
        <?php foreach ($messages as $msg): ?>
           <li data-error-field="<?= e($field) ?>"><?= e($msg) ?></li>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
