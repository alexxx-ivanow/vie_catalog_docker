import PersonalNav from './PersonalNav.js';
import { fetchAccountProfile, updateAccountProfile } from '../../account-api.js';

export default {
    name: 'PersonalProfilePage',
    components: { PersonalNav },
    data() {
        return {
            loading: true,
            saving: false,
            error: '',
            message: '',
            form: {
                name: '',
                lastName: '',
                email: '',
                password: '',
                newPassword: '',
            },
        };
    },
    mounted() {
        this.loadProfile();
    },
    methods: {
        async loadProfile() {
            this.loading = true;
            this.error = '';
            try {
                const { res, data } = await fetchAccountProfile();
                if (!res.ok || !data.success) {
                    this.error = data.error || 'Не удалось загрузить профиль';
                    return;
                }
                const profile = data.profile || {};
                this.form.name = profile.name || '';
                this.form.lastName = profile.lastName || '';
                this.form.email = profile.email || '';
            } catch {
                this.error = 'Ошибка сети';
            } finally {
                this.loading = false;
            }
        },
        async onSubmit() {
            this.saving = true;
            this.error = '';
            this.message = '';
            try {
                const { res, data } = await updateAccountProfile({
                    name: this.form.name,
                    lastName: this.form.lastName,
                    email: this.form.email,
                    password: this.form.password,
                    newPassword: this.form.newPassword,
                });
                if (!res.ok || !data.success) {
                    this.error = data.error || 'Не удалось сохранить';
                    return;
                }
                if (data.profile) {
                    this.form.name = data.profile.name || this.form.name;
                    this.form.lastName = data.profile.lastName || this.form.lastName;
                    this.form.email = data.profile.email || this.form.email;
                }
                this.form.password = '';
                this.form.newPassword = '';
                this.message = data.message || 'Данные сохранены';
                if (data.emailChangePending) {
                    this.message = data.message || 'Подтвердите новый e-mail по ссылке из письма.';
                }
            } catch {
                this.error = 'Ошибка сети';
            } finally {
                this.saving = false;
            }
        },
    },
    template: `
        <div class="personal-layout">
            <h1 class="personal-layout__title">Профиль</h1>
            <PersonalNav active="profile" />
            <p v-if="loading" class="personal-message">Загрузка…</p>
            <form v-else class="personal-form auth-form" @submit.prevent="onSubmit">
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
                    <span class="auth-form__label">Текущий пароль</span>
                    <input v-model="form.password" type="password" class="auth-form__input" autocomplete="current-password">
                </label>
                <label class="auth-form__field">
                    <span class="auth-form__label">Новый пароль</span>
                    <input v-model="form.newPassword" type="password" class="auth-form__input" autocomplete="new-password">
                </label>
                <p v-if="error" class="auth-form__error">{{ error }}</p>
                <p v-if="message" class="personal-message personal-message--ok">{{ message }}</p>
                <button type="submit" class="btn btn--primary" :disabled="saving">{{ saving ? 'Сохранение…' : 'Сохранить' }}</button>
            </form>
        </div>
    `,
};
