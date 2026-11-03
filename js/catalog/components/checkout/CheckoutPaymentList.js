export default {
    name: 'CheckoutPaymentList',
    props: {
        paySystems: { type: Array, default: () => [] },
        modelValue: { type: Number, default: 0 },
        disabled: { type: Boolean, default: false },
    },
    emits: ['update:modelValue'],
    template: `
        <section class="checkout_section" :class="{ 'checkout_section--disabled': disabled }">
            <h2 class="checkout_section__title">Оплата</h2>
            <div v-if="!paySystems.length" class="checkout_option__meta">Выберите доставку, чтобы увидеть способы оплаты.</div>
            <div v-else class="checkout_options">
                <label
                    v-for="pay in paySystems"
                    :key="pay.id"
                    class="checkout_option"
                    :class="{ 'checkout_option--active': modelValue === pay.id }"
                >
                    <input
                        type="radio"
                        name="paySystem"
                        :value="pay.id"
                        :checked="modelValue === pay.id"
                        @change="$emit('update:modelValue', pay.id)"
                    />
                    <span>
                        <strong>{{ pay.name }}</strong>
                        <span class="checkout_option__meta" v-if="pay.description"><br />{{ pay.description }}</span>
                    </span>
                </label>
            </div>
        </section>
    `,
};
