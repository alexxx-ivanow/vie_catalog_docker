import { accountLogin } from '../../account-api.js';
import { dispatchAuthState } from '../../auth-events.js';

export default {
    name: 'LoginModal',
    props: {
        open: { type: Boolean, default: false },
    },
    emits: ['close', 'success'],
    data() {
        return {
            login: '',
            password: '',
            remember: true,
            loading: false,
            error: '',
        };
    },
    watch: {
        open(value) {
            if (value) {
                this.error = '';
            }
        },
    },
    methods: {
        onBackdropClick(event) {
            if (event.target === event.currentTarget) {
                this.$emit('close');
            }
        },
        async onSubmit() {
            this.error = '';
            this.loading = true;
            try {
                const { res, data } = await accountLogin(this.login.trim(), this.password, this.remember);
                if (!res.ok || !data.success) {
                    this.error = data.error || 'Не удалось войти';
                    return;
                }
                const user = data.user || { authorized: true, id: window.__BX__?.userId || 0 };
                if (window.__BX__) {
                    window.__BX__.userId = user.id || 0;
                    window.__BX__.isAuthorized = !!user.authorized;
                    window.__BX__.userName = [user.name, user.lastName].filter(Boolean).join(' ').trim();
                }
                dispatchAuthState(user);
                this.$emit('success', user);
                this.$emit('close');
                const params = new URLSearchParams(window.location.search);
                if (params.get('auth') === '1') {
                    window.location.replace('/personal/profile/');
                    return;
                }
                window.location.reload();
            } catch {
                this.error = 'Ошибка сети';
            } finally {
                this.loading = false;
            }
        },
    },
    template: `
        <div v-if="open" class="auth-modal" @click="onBackdropClick">
            <div class="auth-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="auth-modal-title">
                <button type="button" class="auth-modal__close" aria-label="Закрыть" @click="$emit('close')">×</button>
                <h2 id="auth-modal-title" class="auth-modal__title">Вход</h2>
                <form class="auth-form" @submit.prevent="onSubmit">
                    <label class="auth-form__field">
                        <span class="auth-form__label">Логин или e-mail</span>
                        <input v-model="login" type="text" class="auth-form__input" autocomplete="username" required>
                    </label>
                    <label class="auth-form__field">
                        <span class="auth-form__label">Пароль</span>
                        <input v-model="password" type="password" class="auth-form__input" autocomplete="current-password" required>
                    </label>
                    <p v-if="error" class="auth-form__error">{{ error }}</p>
                    <button type="submit" class="btn btn--primary auth-form__submit" :disabled="loading">
                        {{ loading ? 'Вход…' : 'Войти' }}
                    </button>
                </form>
                <p class="auth-modal__links">
                    <a href="/auth/forgot/">Забыли пароль?</a>
                    <a href="/auth/register/">Регистрация</a>
                </p>
            </div>
        </div>
    `,
};
