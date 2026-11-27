<?php
$email = $email ?? '';
$to = $to ?? '';
$cc = $cc ?? '';
$bcc = $bcc ?? '';
$subject = $subject ?? '';
$body = $body ?? '';
?>
<div class="panel">
  <div class="panel-header"><h2 class="panel-title">Compose</h2></div>
  <form method="post" action="<?= e(url('panel/webmail/send')) ?>" enctype="multipart/form-data" style="padding:16px">
    <?= csrf_field() ?>
    <label class="field-label" for="mail-from">From</label>
    <input class="input" id="mail-from" type="text" value="<?= e($email) ?>" disabled style="width:100%;margin-bottom:12px">
    <label class="field-label" for="mail-to">To</label>
    <input class="input" id="mail-to" type="text" name="to" value="<?= e($to) ?>" placeholder="recipient@example.com (comma-separated for several)" spellcheck="false" style="width:100%;margin-bottom:12px" required>
    <div style="display:flex;gap:12px">
      <div style="flex:1">
        <label class="field-label" for="mail-cc">Cc</label>
        <input class="input" id="mail-cc" type="text" name="cc" value="<?= e($cc) ?>" placeholder="optional" spellcheck="false" style="width:100%;margin-bottom:12px">
      </div>
      <div style="flex:1">
        <label class="field-label" for="mail-bcc">Bcc</label>
        <input class="input" id="mail-bcc" type="text" name="bcc" value="<?= e($bcc) ?>" placeholder="optional" spellcheck="false" style="width:100%;margin-bottom:12px">
      </div>
    </div>
    <label class="field-label" for="mail-subject">Subject</label>
    <input class="input" id="mail-subject" type="text" name="subject" value="<?= e($subject) ?>" style="width:100%;margin-bottom:12px" required>
    <label class="field-label" for="mail-body">Message</label>
    <textarea class="input" id="mail-body" name="body" rows="14" style="width:100%;margin-bottom:12px;font-family:inherit" required><?= e($body) ?></textarea>
    <label class="field-label" for="mail-files">Attachments <span class="text-muted">(max 5, 8 MB each, 10 MB total)</span></label>
    <input id="mail-files" type="file" name="attachments[]" multiple style="margin-bottom:16px">
    <div>
      <button type="submit" class="btn btn-primary">Send message</button>
      <a class="btn btn-ghost" href="<?= e(url('panel/webmail/inbox')) ?>">Cancel</a>
    </div>
  </form>
</div>
