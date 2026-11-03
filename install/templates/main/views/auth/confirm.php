<?php
$result = is_array($data_auth_confirm ?? null) ? $data_auth_confirm : [];
$success = !empty($result['success']);
$message = trim((string)($result['message'] ?? ''));
$error = trim((string)($result['error'] ?? ''));
if ($success && $message === '') {
    $message = 'Регистрация подтверждена. Вы можете войти в личный кабинет.';
}
if (!$success && $error === '') {
    $error = 'Не удалось подтвердить регистрацию';
}
?>
<div class="auth-page personal-page">
    <div class="auth-page-layout">
        <h1 class="personal-layout__title">Подтверждение регистрации</h1>
        <?php if ($success): ?>
            <p class="personal-message personal-message--ok"><?= htmlspecialcharsbx($message) ?></p>
            <p>
                <a href="/?auth=1" class="btn btn--primary">Войти в личный кабинет</a>
            </p>
        <?php else: ?>
            <p class="auth-form__error"><?= htmlspecialcharsbx($error) ?></p>
        <?php endif; ?>
        <p class="auth-page-layout__links">
            <a href="/auth/register/">Регистрация</a>
            <a href="/">На главную</a>
        </p>
    </div>
</div>
