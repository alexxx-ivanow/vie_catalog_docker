import { CART_COUNT_EVENT, fetchCartCount } from '../../cart-counter.js';

export default {
    name: 'SiteCartNav',
    data() {
        const initial = window.__BX__?.cartCount;
        return {
            count: typeof initial === 'number' ? initial : 0,
        };
    },
    mounted() {
        this.onCartCount = (event) => {
            const next = event?.detail?.count;
            if (typeof next === 'number' && Number.isFinite(next)) {
                this.count = Math.max(0, Math.floor(next));
            }
        };
        window.addEventListener(CART_COUNT_EVENT, this.onCartCount);

        if (typeof window.__BX__?.cartCount === 'number') {
            this.count = Math.max(0, Math.floor(window.__BX__.cartCount));
        } else {
            fetchCartCount().then((count) => {
                this.count = count;
            });
        }
    },
    beforeUnmount() {
        if (this.onCartCount) {
            window.removeEventListener(CART_COUNT_EVENT, this.onCartCount);
        }
    },
    template: `
        <a href="/cart/" class="site-nav__link site-nav__link--cart">Корзина ({{ count }})</a>
    `,
};
