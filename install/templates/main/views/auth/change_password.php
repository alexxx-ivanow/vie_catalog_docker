<?php
$state = is_array($data_auth_change_password ?? null) ? $data_auth_change_password : [];
$valid = !empty($state['valid']);
$error = trim((string)($state['error'] ?? ''));
if (!$valid && $error === '') {
    $error = 'Неверная или устаревшая ссылка';
}
$canonicalLogin = (string)($state['login'] ?? '');
$checkwordValue = (string)($state['checkword'] ?? '');
?>
<div class="auth-page personal-page">
    <?php if (!$valid): ?>
        <div class="auth-page-layout">
            <h1 class="personal-layout__title">Восстановление пароля</h1>
            <p class="auth-form__error"><?= htmlspecialcharsbx($error) ?></p>
            <p class="auth-page-layout__links">
                <a href="/auth/forgot/">Запросить ссылку снова</a>
                <a href="/">На главную</a>
            </p>
        </div>
    <?php else: ?>
        <div class="auth-page-layout">
            <h1 class="personal-layout__title">Новый пароль</h1>
            <div id="auth-change-password-success" class="personal-message personal-message--ok" hidden></div>
            <p id="auth-change-password-success-actions" hidden>
                <a href="/?auth=1" class="btn btn--primary">Войти в личный кабинет</a>
            </p>
            <form
                id="auth-change-password-form"
                class="personal-form auth-form"
                data-login="<?= htmlspecialcharsbx($canonicalLogin) ?>"
                data-checkword="<?= htmlspecialcharsbx($checkwordValue) ?>"
            >
                <label class="auth-form__field">
                    <span class="auth-form__label">Новый пароль</span>
                    <input type="password" name="password" class="auth-form__input" required autocomplete="new-password">
                </label>
                <label class="auth-form__field">
                    <span class="auth-form__label">Повтор пароля</span>
                    <input type="password" name="confirmPassword" class="auth-form__input" required autocomplete="new-password">
                </label>
                <p id="auth-change-password-error" class="auth-form__error" hidden></p>
                <button type="submit" class="btn btn--primary" id="auth-change-password-submit">Сохранить пароль</button>
            </form>
            <p class="auth-page-layout__links" id="auth-change-password-footer-links">
                <a href="/">На главную</a>
            </p>
        </div>
    <?php endif; ?>
</div>
