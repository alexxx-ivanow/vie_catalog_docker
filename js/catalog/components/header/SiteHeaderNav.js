import { CART_COUNT_EVENT, fetchCartCount } from '../../cart-counter.js';
import { AUTH_STATE_EVENT, guestUser, readInitialUser } from '../../auth-events.js';
import { accountLogout, fetchAccountSession } from '../../account-api.js';
import LoginModal from '../auth/LoginModal.js';

export default {
    name: 'SiteHeaderNav',
    components: { LoginModal },
    data() {
        const initial = window.__BX__?.cartCount;
        return {
            count: typeof initial === 'number' ? initial : 0,
            user: readInitialUser(),
            loginOpen: false,
        };
    },
    computed: {
        isAuthorized() {
            return !!this.user.authorized && (this.user.id || 0) > 0;
        },
        cabinetLabel() {
            if (!this.isAuthorized) {
                return 'Личный кабинет';
            }
            const name = (this.user.name || window.__BX__?.userName || '').trim();
            return name !== '' ? name : 'Личный кабинет';
        },
    },
    mounted() {
        this.onCartCount = (event) => {
            const next = event?.detail?.count;
            if (typeof next === 'number' && Number.isFinite(next)) {
                this.count = Math.max(0, Math.floor(next));
            }
        };
        window.addEventListener(CART_COUNT_EVENT, this.onCartCount);

        this.onAuthState = (event) => {
            const user = event?.detail?.user;
            if (user && typeof user === 'object') {
                this.user = { ...this.user, ...user };
            }
        };
        window.addEventListener(AUTH_STATE_EVENT, this.onAuthState);

        const isCartPage = window.__PAGE_DATA__?.page?.type === 'cart';

        if (typeof window.__BX__?.cartCount === 'number') {
            this.count = Math.max(0, Math.floor(window.__BX__.cartCount));
        } else if (!isCartPage) {
            fetchCartCount().then((count) => {
                this.count = count;
            });
        }

        fetchAccountSession().then(({ data }) => {
            if (!data?.success || !data.user) {
                return;
            }
            if (data.user.authorized && (data.user.id || 0) > 0) {
                this.user = data.user;
            } else {
                this.user = guestUser();
            }
        }).catch(() => {});

        const params = new URLSearchParams(window.location.search);
        if (params.get('auth') === '1' && !this.isAuthorized) {
            this.loginOpen = true;
        }
    },
    beforeUnmount() {
        if (this.onCartCount) {
            window.removeEventListener(CART_COUNT_EVENT, this.onCartCount);
        }
        if (this.onAuthState) {
            window.removeEventListener(AUTH_STATE_EVENT, this.onAuthState);
        }
    },
    methods: {
        openLogin() {
            this.loginOpen = true;
        },
        closeLogin() {
            this.loginOpen = false;
        },
        async logout() {
            try {
                await accountLogout();
            } catch {
                // still reset client state
            }
            this.user = guestUser();
            if (window.__BX__) {
                window.__BX__.userId = 0;
                window.__BX__.isAuthorized = false;
                window.__BX__.userName = '';
            }
            window.location.href = '/';
        },
    },
    template: `
        <div class="site-header-nav">
            <template v-if="isAuthorized">
                <a href="/personal/profile/" class="site-nav__link">{{ cabinetLabel }}</a>
                <button type="button" class="site-nav__link site-nav__link--button" @click="logout">Выйти</button>
            </template>
            <button v-else type="button" class="site-nav__link site-nav__link--button" @click="openLogin">
                Личный кабинет
            </button>
            <a href="/cart/" class="site-nav__link site-nav__link--cart">Корзина ({{ count }})</a>
            <LoginModal :open="loginOpen" @close="closeLogin" />
        </div>
    `,
};
