import { accountChangePassword } from '../../account-api.js';

export default {
    name: 'AuthChangePasswordPage',
    data() {
        const state = window.__PAGE_DATA__?.authChangePassword || {};
        return {
            login: typeof state.login === 'string' ? state.login : '',
            checkword: typeof state.checkword === 'string' ? state.checkword : '',
            password: '',
            confirmPassword: '',
            loading: false,
            error: '',
            message: '',
            done: false,
            redirectUrl: '',
        };
    },
    methods: {
        async onSubmit() {
            this.loading = true;
            this.error = '';
            this.message = '';
            try {
                const { res, data } = await accountChangePassword({
                    login: this.login,
                    checkword: this.checkword,
                    password: this.password,
                    confirmPassword: this.confirmPassword,
                });
                if (!res.ok || !data.success) {
                    this.error = data.error || 'Не удалось изменить пароль';
                    return;
                }
                this.done = true;
                this.message = data.message || 'Пароль успешно изменён';
                this.redirectUrl = typeof data.redirect === 'string' ? data.redirect : '/?auth=1';
                this.password = '';
                this.confirmPassword = '';
            } catch {
                this.error = 'Ошибка сети';
            } finally {
                this.loading = false;
            }
        },
    },
    template: `
        <div class="auth-page-layout">
            <h1 class="personal-layout__title">Новый пароль</h1>
            <template v-if="done">
                <p class="personal-message personal-message--ok">{{ message }}</p>
                <p>
                    <a :href="redirectUrl" class="btn btn--primary">Войти в личный кабинет</a>
                </p>
            </template>
            <form v-else class="personal-form auth-form" @submit.prevent="onSubmit">
                <label class="auth-form__field">
                    <span class="auth-form__label">Новый пароль</span>
                    <input v-model="password" type="password" class="auth-form__input" required autocomplete="new-password">
                </label>
                <label class="auth-form__field">
                    <span class="auth-form__label">Повтор пароля</span>
                    <input v-model="confirmPassword" type="password" class="auth-form__input" required autocomplete="new-password">
                </label>
                <p v-if="error" class="auth-form__error">{{ error }}</p>
                <button type="submit" class="btn btn--primary" :disabled="loading">
                    {{ loading ? 'Сохранение…' : 'Сохранить пароль' }}
                </button>
            </form>
            <p v-if="!done" class="auth-page-layout__links">
                <a href="/">На главную</a>
            </p>
        </div>
    `,
};
