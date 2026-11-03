export default {
    name: 'OrderSuccessPage',
    data() {
        return {
            order: window.__PAGE_DATA__?.order?.data || {},
        };
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
        <div class="checkout_success">
            <h1 class="checkout__title">Заказ оформлен</h1>
            <p class="checkout_success__lead">Спасибо! Ваш заказ принят в обработку.</p>

            <dl class="checkout_success__details">
                <template v-if="order.accountNumber">
                    <dt>Номер заказа</dt>
                    <dd><strong>{{ order.accountNumber }}</strong></dd>
                </template>
                <template v-if="order.dateFormatted || order.dateInsert">
                    <dt>Дата</dt>
                    <dd>{{ order.dateFormatted || order.dateInsert }}</dd>
                </template>
                <template v-if="order.price">
                    <dt>Сумма</dt>
                    <dd><strong>{{ formatPrice(order.price) }}</strong></dd>
                </template>
            </dl>

            <div class="checkout_success__actions">
                <a
                    v-if="order.showPaymentLink && order.paymentUrl"
                    :href="order.paymentUrl"
                    class="checkout_success__btn checkout_success__btn--primary"
                >
                    Оплатить заказ
                </a>                
            </div>

            <nav class="checkout_success__nav" aria-label="Дальнейшие действия">
                <a href="/" class="cart__link">На главную</a>
                <a href="/catalog/" class="cart__link">В каталог</a>
            </nav>
        </div>
    `,
};
