import PersonalNav from './PersonalNav.js';
import { fetchAccountOrders } from '../../account-api.js';
import { formatCatalogPrice } from '../../format-catalog-price.js';

export default {
    name: 'PersonalOrdersPage',
    components: { PersonalNav },
    data() {
        return {
            loading: true,
            error: '',
            items: [],
            pagination: { page: 1, pages: 1, limit: 10, total: 0 },
        };
    },
    mounted() {
        const page = Number(new URLSearchParams(window.location.search).get('page')) || 1;
        this.loadOrders(page);
    },
    methods: {
        formatPrice(price, currency) {
            return formatCatalogPrice(price, currency || 'RUB');
        },
        async loadOrders(page) {
            this.loading = true;
            this.error = '';
            try {
                const { res, data } = await fetchAccountOrders(page, 10);
                if (!res.ok || !data.success) {
                    this.error = data.error || 'Не удалось загрузить заказы';
                    return;
                }
                this.items = data.items || [];
                this.pagination = data.pagination || this.pagination;
            } catch {
                this.error = 'Ошибка сети';
            } finally {
                this.loading = false;
            }
        },
        goPage(page) {
            if (page < 1 || page > this.pagination.pages) {
                return;
            }
            const url = new URL(window.location.href);
            url.searchParams.set('page', String(page));
            window.history.replaceState({}, '', url);
            this.loadOrders(page);
        },
    },
    template: `
        <div class="personal-layout">
            <h1 class="personal-layout__title">Мои заказы</h1>
            <PersonalNav active="orders" />
            <p v-if="loading" class="personal-message">Загрузка…</p>
            <p v-else-if="error" class="auth-form__error">{{ error }}</p>
            <p v-else-if="items.length === 0" class="personal-message">Заказов пока нет.</p>
            <div v-else class="personal-orders">
                <a
                    v-for="order in items"
                    :key="order.id"
                    :href="order.url"
                    class="personal-order-card"
                >
                    <span class="personal-order-card__number">№ {{ order.accountNumber }}</span>
                    <span class="personal-order-card__date">{{ order.dateFormatted }}</span>
                    <span class="personal-order-card__status">{{ order.statusName }}</span>
                    <span class="personal-order-card__sum">{{ formatPrice(order.price, order.currency) }}</span>
                    <span class="personal-order-card__paid" :class="{ 'personal-order-card__paid--yes': order.isPaid }">{{ order.paidLabel }}</span>
                </a>
            </div>
            <nav v-if="pagination.pages > 1" class="personal-pagination" aria-label="Страницы заказов">
                <button type="button" class="btn btn--ghost" :disabled="pagination.page <= 1" @click="goPage(pagination.page - 1)">Назад</button>
                <span class="personal-pagination__info">{{ pagination.page }} / {{ pagination.pages }}</span>
                <button type="button" class="btn btn--ghost" :disabled="pagination.page >= pagination.pages" @click="goPage(pagination.page + 1)">Вперёд</button>
            </nav>
        </div>
    `,
};
