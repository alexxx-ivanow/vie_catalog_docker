import PersonalNav from './PersonalNav.js';
import { fetchAccountOrder } from '../../account-api.js';
import { formatCatalogPrice } from '../../format-catalog-price.js';

export default {
    name: 'PersonalOrderDetailPage',
    components: { PersonalNav },
    data() {
        const orderId = Number(window.__PAGE_DATA__?.order?.data?.id) || 0;
        return {
            orderId,
            loading: true,
            error: '',
            order: null,
        };
    },
    mounted() {
        if (this.orderId <= 0) {
            this.error = 'Некорректный заказ';
            this.loading = false;
            return;
        }
        this.loadOrder();
    },
    methods: {
        formatPrice(price, currency) {
            return formatCatalogPrice(price, currency || 'RUB');
        },
        async loadOrder() {
            this.loading = true;
            this.error = '';
            try {
                const { res, data } = await fetchAccountOrder(this.orderId);
                if (!res.ok || !data.success) {
                    this.error = data.error || 'Заказ не найден';
                    return;
                }
                this.order = data.order;
            } catch {
                this.error = 'Ошибка сети';
            } finally {
                this.loading = false;
            }
        },
    },
    template: `
        <div class="personal-layout">
            <h1 class="personal-layout__title">Заказ</h1>
            <PersonalNav active="orders" />
            <p v-if="loading" class="personal-message">Загрузка…</p>
            <p v-else-if="error" class="auth-form__error">{{ error }}</p>
            <template v-else-if="order">
                <div class="personal-order-detail">
                    <dl class="personal-order-detail__meta">
                        <div><dt>Номер</dt><dd>№ {{ order.accountNumber }}</dd></div>
                        <div><dt>Дата</dt><dd>{{ order.dateFormatted }}</dd></div>
                        <div><dt>Статус</dt><dd>{{ order.statusName }}</dd></div>
                        <div><dt>Сумма</dt><dd>{{ formatPrice(order.price, order.currency) }}</dd></div>
                        <div><dt>Доставка</dt><dd>{{ order.deliveryName || '—' }}</dd></div>
                        <div><dt>Оплата</dt><dd>{{ order.paymentName || '—' }}</dd></div>
                        <div><dt>Статус оплаты</dt><dd>{{ order.paidLabel }}</dd></div>
                    </dl>
                    <section v-if="order.properties && order.properties.length" class="personal-order-detail__props">
                        <h2 class="personal-order-detail__subtitle">Данные получателя</h2>
                        <dl class="personal-order-detail__props-list">
                            <div v-for="prop in order.properties" :key="prop.code + prop.name">
                                <dt>{{ prop.name }}</dt>
                                <dd>{{ prop.value }}</dd>
                            </div>
                        </dl>
                    </section>
                    <section class="personal-order-detail__items">
                        <h2 class="personal-order-detail__subtitle">Товары</h2>
                        <ul class="personal-order-items">
                            <li v-for="item in order.items" :key="item.productId + '-' + item.name" class="personal-order-item">
                                <a :href="item.url" class="personal-order-item__preview-wrap">
                                    <img :src="item.preview" :alt="item.name" class="personal-order-item__preview" width="72" height="72" loading="lazy">
                                </a>
                                <div class="personal-order-item__info">
                                    <a :href="item.url" class="personal-order-item__name">{{ item.name }}</a>
                                    <p class="personal-order-item__qty">{{ item.quantity }} шт.</p>
                                </div>
                                <div class="personal-order-item__sum">{{ formatPrice(item.sum, order.currency) }}</div>
                            </li>
                        </ul>
                    </section>
                    <p><a href="/personal/orders/" class="personal-back-link">← К списку заказов</a></p>
                </div>
            </template>
        </div>
    `,
};
