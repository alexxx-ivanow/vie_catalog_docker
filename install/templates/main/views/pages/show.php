<?php

/** @var array<string, mixed> $data_page */
$data_page = is_array($data_page ?? null) ? $data_page : [];
$view = is_array($data_page['view'] ?? null) ? $data_page['view'] : [];
$title = (string)($view['title'] ?? '');
$body = (string)($view['body'] ?? '');
?>
<div class="content-page">
    <?php if ($title !== ''): ?>
        <h1 class="content-page__title"><?= htmlspecialcharsbx($title) ?></h1>
    <?php endif; ?>
    <?php if ($body !== ''): ?>
        <div class="content-page__body"><?= $body ?></div>
    <?php endif; ?>
</div>
