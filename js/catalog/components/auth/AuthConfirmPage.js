export default {
    name: 'AuthConfirmPage',
    data() {
        const payload = window.__PAGE_DATA__?.authConfirm || {};
        return {
            success: !!payload.success,
            message: typeof payload.message === 'string' ? payload.message : '',
            error: typeof payload.error === 'string' ? payload.error : '',
        };
    },
    computed: {
        errorText() {
            if (this.error) {
                return this.error;
            }
            if (!this.success) {
                return 'Не удалось подтвердить регистрацию';
            }
            return '';
        },
        successText() {
            if (this.message) {
                return this.message;
            }
            return 'Регистрация подтверждена. Вы можете войти в личный кабинет.';
        },
    },
    template: `
        <div class="auth-page-layout">
            <h1 class="personal-layout__title">Подтверждение регистрации</h1>
            <template v-if="success">
                <p class="personal-message personal-message--ok">{{ successText }}</p>
                <p>
                    <a href="/?auth=1" class="btn btn--primary">Войти в личный кабинет</a>
                </p>
            </template>
            <template v-else>
                <p class="auth-form__error">{{ errorText }}</p>
            </template>
            <p class="auth-page-layout__links">
                <a href="/auth/register/">Регистрация</a>
                <a href="/">На главную</a>
            </p>
        </div>
    `,
};
