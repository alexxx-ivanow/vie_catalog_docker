import { accountRegister } from '../../account-api.js';
import { formatRuPhoneInput } from '../../utils/phone-mask.js';

export default {
    name: 'AuthRegisterPage',
    data() {
        return {
            form: {
                name: '',
                lastName: '',
                email: '',
                phone: '',
                password: '',
                confirmPassword: '',
            },
            loading: false,
            error: '',
            message: '',
        };
    },
    methods: {
        onPhoneInput(event) {
            const input = event.target;
            const start = input.selectionStart ?? 0;
            const end = input.selectionEnd ?? start;
            const before = String(this.form.phone || '');
            const formatted = formatRuPhoneInput(input.value);
            this.form.phone = formatted;

            this.$nextTick(() => {
                if (!input || document.activeElement !== input) {
                    return;
                }
                const delta = formatted.length - before.length;
                let nextPos = Math.max(0, Math.min(formatted.length, start + delta));
                if (start === before.length && delta > 0) {
                    nextPos = formatted.length;
                }
                try {
                    input.setSelectionRange(nextPos, nextPos);
                } catch {
                    // некоторые mobile browsers не поддерживают setSelectionRange на tel
                }
            });
        },
        async onSubmit() {
            this.loading = true;
            this.error = '';
            this.message = '';
            try {
                const { res, data } = await accountRegister({
                    name: this.form.name.trim(),
                    lastName: this.form.lastName.trim(),
                    email: this.form.email.trim(),
                    phone: this.form.phone,
                    password: this.form.password,
                    confirmPassword: this.form.confirmPassword,
                });
                if (!res.ok || !data.success) {
                    this.error = data.error || 'Не удалось зарегистрироваться';
                    return;
                }
                this.message = data.message || 'Регистрация завершена';
                if (data.needsEmailConfirm) {
                    this.form.password = '';
                    this.form.confirmPassword = '';
                } else {
                    window.location.href = '/?auth=1';
                }
            } catch {
                this.error = 'Ошибка сети';
            } finally {
                this.loading = false;
            }
        },
    },
    template: `
        <div class="auth-page-layout">
            <h1 class="personal-layout__title">Регистрация</h1>
            <form class="personal-form auth-form" @submit.prevent="onSubmit">
                <label class="auth-form__field">
                    <span class="auth-form__label">Имя</span>
                    <input v-model="form.name" type="text" class="auth-form__input" required autocomplete="given-name">
                </label>
                <label class="auth-form__field">
                    <span class="auth-form__label">Фамилия</span>
                    <input v-model="form.lastName" type="text" class="auth-form__input" required autocomplete="family-name">
                </label>
                <label class="auth-form__field">
                    <span class="auth-form__label">E-mail</span>
                    <input v-model="form.email" type="email" class="auth-form__input" required autocomplete="email">
                </label>
                <label class="auth-form__field">
                    <span class="auth-form__label">Телефон</span>
                    <input
                        :value="form.phone"
                        type="tel"
                        class="auth-form__input"
                        required
                        autocomplete="tel"
                        name="register-phone"
                        placeholder="+7 (___) ___-__-__"
                        @input="onPhoneInput"
                    >
                </label>
                <label class="auth-form__field">
                    <span class="auth-form__label">Пароль</span>
                    <input v-model="form.password" type="password" class="auth-form__input" required autocomplete="new-password">
                </label>
                <label class="auth-form__field">
                    <span class="auth-form__label">Повтор пароля</span>
                    <input v-model="form.confirmPassword" type="password" class="auth-form__input" required autocomplete="new-password">
                </label>
                <p v-if="error" class="auth-form__error">{{ error }}</p>
                <p v-if="message" class="personal-message personal-message--ok">{{ message }}</p>
                <button type="submit" class="btn btn--primary" :disabled="loading">{{ loading ? 'Регистрация…' : 'Зарегистрироваться' }}</button>
            </form>
            <p class="auth-page-layout__links">
                <a href="/?auth=1">Уже есть аккаунт — войти</a>
            </p>
        </div>
    `,
};
