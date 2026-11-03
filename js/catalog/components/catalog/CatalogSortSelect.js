export default {
    name: 'CatalogSortSelect',
    props: {
        modelValue: { type: String, default: 'default' },
        disabled: { type: Boolean, default: false },
    },
    emits: ['update:modelValue'],
    methods: {
        onChange(event) {
            this.$emit('update:modelValue', event.target.value);
        },
    },
    template: `
        <select
            class="catalog__select"
            :value="modelValue"
            :disabled="disabled"
            aria-label="Сортировка товаров"
            @change="onChange"
        >
            <option value="default">По умолчанию</option>
            <option value="name">По алфавиту</option>
            <option value="price_asc">По возрастанию цены</option>
            <option value="price_desc">По убыванию цены</option>
        </select>
    `,
};
