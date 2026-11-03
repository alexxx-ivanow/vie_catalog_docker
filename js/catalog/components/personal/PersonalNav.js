import { accountLogout } from '../../account-api.js';
import { guestUser, dispatchAuthState } from '../../auth-events.js';

export default {
    name: 'PersonalNav',
    props: {
        active: { type: String, default: 'profile' },
    },
    methods: {
        async logout() {
            try {
                await accountLogout();
            } catch {
                // ignore
            }
            const user = guestUser();
            if (window.__BX__) {
                window.__BX__.userId = 0;
                window.__BX__.isAuthorized = false;
                window.__BX__.userName = '';
            }
            dispatchAuthState(user);
            window.location.href = '/';
        },
    },
    template: `
        <nav class="personal-nav" aria-label="Личный кабинет">
            <a href="/personal/profile/" class="personal-nav__link" :class="{ 'personal-nav__link--active': active === 'profile' }">Профиль</a>
            <a href="/personal/orders/" class="personal-nav__link" :class="{ 'personal-nav__link--active': active === 'orders' }">Заказы</a>
            <button type="button" class="personal-nav__logout" @click="logout">Выйти</button>
        </nav>
    `,
};
