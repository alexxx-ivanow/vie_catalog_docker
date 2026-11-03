import { accountForgotPassword } from '../../account-api.js';

export default {
    name: 'AuthForgotPage',
    data() {
        return {
            email: '',
            loading: false,
            error: '',
            message: '',
        };
    },
    methods: {
        async onSubmit() {
            this.loading = true;
            this.error = '';
            this.message = '';
            try {
                const { res, data } = await accountForgotPassword(this.email.trim());
                if (!res.ok || !data.success) {
                    this.error = data.error || 'Не удалось отправить письмо';
                    return;
                }
                this.message = data.message || 'Письмо отправлено';
            } catch {
                this.error = 'Ошибка сети';
            } finally {
                this.loading = false;
            }
        },
    },
    template: `
        <div class="auth-page-layout">
            <h1 class="personal-layout__title">Восстановление пароля</h1>
            <form class="personal-form auth-form" @submit.prevent="onSubmit">
                <label class="auth-form__field">
                    <span class="auth-form__label">E-mail</span>
                    <input v-model="email" type="email" class="auth-form__input" required autocomplete="email">
                </label>
                <p v-if="error" class="auth-form__error">{{ error }}</p>
                <p v-if="message" class="personal-message personal-message--ok">{{ message }}</p>
                <button type="submit" class="btn btn--primary" :disabled="loading">{{ loading ? 'Отправка…' : 'Отправить' }}</button>
            </form>
            <p class="auth-page-layout__links">
                <a href="/auth/register/">Регистрация</a>
                <a href="/">На главную</a>
            </p>
        </div>
    `,
};
