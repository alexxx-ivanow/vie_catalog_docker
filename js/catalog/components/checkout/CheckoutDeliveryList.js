import CheckoutDeliveryExtra from './CheckoutDeliveryExtra.js';

export default {
    name: 'CheckoutDeliveryList',
    components: { CheckoutDeliveryExtra },
    props: {
        deliveries: { type: Array, default: () => [] },
        modelValue: { type: Number, default: 0 },
        deliveryExtra: { type: Object, default: () => ({}) },
        disabled: { type: Boolean, default: false },
    },
    emits: ['update:modelValue', 'update:deliveryExtra'],
    computed: {
        selectedDelivery() {
            return this.deliveries.find((item) => item.id === this.modelValue) || null;
        },
    },
    template: `
        <section class="checkout_section" :class="{ 'checkout_section--disabled': disabled }">
            <h2 class="checkout_section__title">Доставка</h2>
            <div v-if="!deliveries.length" class="checkout_option__meta">Выберите местоположение, чтобы увидеть способы доставки.</div>
            <div v-else class="checkout_options">
                <label
                    v-for="delivery in deliveries"
                    :key="delivery.id"
                    class="checkout_option"
                    :class="{ 'checkout_option--active': modelValue === delivery.id }"
                >
                    <input
                        type="radio"
                        name="delivery"
                        :value="delivery.id"
                        :checked="modelValue === delivery.id"
                        @change="$emit('update:modelValue', delivery.id)"
                    />
                    <span>
                        <strong>{{ delivery.name }}</strong>
                        <span class="checkout_option__meta" v-if="delivery.description"><br />{{ delivery.description }}</span>
                        <span class="checkout_option__meta"><br />Стоимость: {{ delivery.price }} {{ delivery.currency }}</span>
                    </span>
                </label>
            </div>
            <CheckoutDeliveryExtra
                v-if="selectedDelivery"
                :extra="selectedDelivery.extra"
                :delivery-extra="deliveryExtra"
                @update:deliveryExtra="$emit('update:deliveryExtra', $event)"
            />
        </section>
    `,
};
