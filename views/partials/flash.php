<?php

declare(strict_types=1);

?>
<?php foreach ($messages as $message): ?>
    <div class="notice notice-<?= e($message['type']) ?>" role="<?= e($message['type'] === 'error' ? 'alert' : 'status') ?>">
        <p><?= e($message['message']) ?></p>
    </div>
<?php endforeach; ?>
