export default {
    name: 'CheckoutDeliveryExtra',
    props: {
        extra: { type: Object, default: () => ({}) },
        deliveryExtra: { type: Object, default: () => ({}) },
    },
    emits: ['update:deliveryExtra'],
    methods: {
        openPicker() {
            const point = window.prompt('Укажите пункт выдачи (ПВЗ) или адрес:', this.deliveryExtra.deliveryPoint || '');
            if (point === null) {
                return;
            }
            this.$emit('update:deliveryExtra', {
                ...this.deliveryExtra,
                deliveryPoint: point,
                pickupPointId: point,
            });
        },
    },
    template: `
        <div v-if="extra && extra.type && extra.type !== 'none'" class="checkout_delivery_extra">
            <button type="button" class="checkout_extra_btn" @click="openPicker">
                {{ extra.label || 'Выбрать пункт выдачи' }}
            </button>
            <p v-if="deliveryExtra.deliveryPoint" class="checkout_option__meta">
                Выбрано: {{ deliveryExtra.deliveryPoint }}
            </p>
            <p v-else-if="extra.type === 'widget'" class="checkout_option__meta">
                Для этой доставки доступны дополнительные параметры в модуле службы доставки.
            </p>
        </div>
    `,
};
