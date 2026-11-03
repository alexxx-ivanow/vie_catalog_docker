export default {
    name: 'CheckoutTotalsSidebar',
    props: {
        totals: { type: Object, default: () => ({}) },
        submitting: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
    },
    emits: ['submit'],
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
        <aside class="checkout_totals">
            <h2 class="checkout_section__title">Итого</h2>
            <div class="checkout_totals__row">
                <span>Товары</span>
                <strong>{{ formatPrice(totals.itemsSum) }}</strong>
            </div>
            <div class="checkout_totals__row">
                <span>Доставка</span>
                <strong>{{ formatPrice(totals.delivery) }}</strong>
            </div>
            <div v-if="totals.discount > 0" class="checkout_totals__row checkout_totals__row--discount">
                <span>Скидка</span>
                <strong>−{{ formatPrice(totals.discount) }}</strong>
            </div>
            <div class="checkout_totals__row checkout_totals__row--total">
                <span>К оплате</span>
                <strong>{{ formatPrice(totals.total) }}</strong>
            </div>
            <button
                type="button"
                class="checkout_totals__submit"
                :disabled="disabled || submitting"
                @click="$emit('submit')"
            >{{ submitting ? 'Оформляем...' : 'Оформить заказ' }}</button>
        </aside>
    `,
};
