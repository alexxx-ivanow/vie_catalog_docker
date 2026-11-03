import { accountChangePassword } from './account-api.js';

const form = document.getElementById('auth-change-password-form');
if (form) {
const errorEl = document.getElementById('auth-change-password-error');
const successEl = document.getElementById('auth-change-password-success');
const successActions = document.getElementById('auth-change-password-success-actions');
const footerLinks = document.getElementById('auth-change-password-footer-links');
const submitBtn = document.getElementById('auth-change-password-submit');

const login = form.dataset.login || '';
const checkword = form.dataset.checkword || '';

function showError(message) {
    if (!errorEl) {
        return;
    }
    errorEl.textContent = message;
    errorEl.hidden = !message;
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    showError('');

    const password = form.password?.value || '';
    const confirmPassword = form.confirmPassword?.value || '';

    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Сохранение…';
    }

    try {
        const { res, data } = await accountChangePassword({
            login,
            checkword,
            password,
            confirmPassword,
        });

        if (!res.ok || !data.success) {
            showError(data?.error || 'Не удалось изменить пароль');
            return;
        }

        form.hidden = true;
        if (footerLinks) {
            footerLinks.hidden = true;
        }
        if (successEl) {
            successEl.textContent = data.message || 'Пароль успешно изменён';
            successEl.hidden = false;
        }
        if (successActions) {
            const link = successActions.querySelector('a');
            if (link && typeof data.redirect === 'string' && data.redirect !== '') {
                link.href = data.redirect;
            }
            successActions.hidden = false;
        }
    } catch {
        showError('Ошибка сети');
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Сохранить пароль';
        }
    }
});
}
