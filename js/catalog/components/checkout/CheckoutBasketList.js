export default {
    name: 'CheckoutBasketList',
    props: {
        items: { type: Array, default: () => [] },
    },
    methods: {
        formatPrice(value) {
            const num = Number(value) || 0;
            return num.toLocaleString('ru-RU', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2,
            }) + ' ₽';
        },
    },
    template: `
        <section class="checkout_section">
            <h2 class="checkout_section__title">Состав заказа</h2>
            <div v-for="item in items" :key="item.id" class="checkout_basket_item">
                <a :href="item.url" class="checkout_basket_item__preview">
                    <img :src="item.preview" :alt="item.name" width="72" height="72" />
                </a>
                <div class="checkout_basket_item__info">
                    <a :href="item.url">{{ item.name }}</a>
                    <p v-if="item.article" class="checkout_option__meta">Артикул: {{ item.article }}</p>
                    <p class="checkout_option__meta">Кол-во: {{ item.quantity }}</p>
                    <p class="checkout_basket_item__price">
                        <span v-if="item.discount > 0" class="checkout_basket_item__price-old">{{ formatPrice(item.basePrice) }}</span>
                        <span>{{ formatPrice(item.price) }} / шт.</span>
                    </p>
                    <p v-if="item.discount > 0" class="checkout_basket_item__discount">
                        Скидка: −{{ formatPrice(item.discount) }}
                        <template v-if="item.quantity > 1"> (всего −{{ formatPrice(item.discountSum) }})</template>
                    </p>
                </div>
                <div class="checkout_basket_item__sum">
                    <div v-if="item.discountSum > 0" class="checkout_basket_item__sum-old">{{ formatPrice(item.sumBase) }}</div>
                    <div>{{ formatPrice(item.sum) }}</div>
                </div>
            </div>
        </section>
    `,
};
