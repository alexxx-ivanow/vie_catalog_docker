export default {
    name: 'CheckoutPersonType',
    props: {
        personTypes: { type: Array, default: () => [] },
        modelValue: { type: Number, default: 0 },
    },
    emits: ['update:modelValue'],
    template: `
        <section class="checkout_section">
            <h2 class="checkout_section__title">Тип покупателя</h2>
            <div class="checkout_options">
                <label
                    v-for="type in personTypes"
                    :key="type.id"
                    class="checkout_option"
                    :class="{ 'checkout_option--active': modelValue === type.id }"
                >
                    <input
                        type="radio"
                        name="personType"
                        :value="type.id"
                        :checked="modelValue === type.id"
                        @change="$emit('update:modelValue', type.id)"
                    />
                    <span>{{ type.name }}</span>
                </label>
            </div>
        </section>
    `,
};
