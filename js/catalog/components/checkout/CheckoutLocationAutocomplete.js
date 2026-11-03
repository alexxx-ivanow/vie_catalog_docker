import { apiGet, catalogApiUrl } from '../../api-client.js';

export default {
    name: 'CheckoutLocationAutocomplete',
    props: {
        modelValue: { type: String, default: '' },
        labelValue: { type: String, default: '' },
        disabled: { type: Boolean, default: false },
    },
    emits: ['update:modelValue', 'update:labelValue', 'selected'],
    data() {
        return {
            query: this.labelValue || '',
            items: [],
            open: false,
            notFound: false,
            loading: false,
            debounceTimer: null,
        };
    },
    watch: {
        labelValue(value) {
            if (value !== this.query) {
                this.query = value;
            }
        },
    },
    methods: {
        onInput() {
            this.$emit('update:labelValue', this.query);
            clearTimeout(this.debounceTimer);
            this.debounceTimer = setTimeout(() => this.search(), 300);
        },
        async search() {
            const q = this.query.trim();
            if (q.length < 2) {
                this.items = [];
                this.open = false;
                this.notFound = false;
                return;
            }

            this.loading = true;
            this.notFound = false;
            try {
                const params = new URLSearchParams({ q, limit: '10' });
                const { data } = await apiGet(catalogApiUrl('checkout/locations') + '?' + params.toString());
                if (this.query.trim() !== q) {
                    return;
                }
                this.items = Array.isArray(data.items) ? data.items : [];
                this.notFound = this.items.length === 0;
                this.open = true;
            } catch (e) {
                this.items = [];
                this.notFound = true;
                this.open = true;
            } finally {
                this.loading = false;
            }
        },
        selectItem(item) {
            this.query = item.displayName;
            this.open = false;
            this.$emit('update:modelValue', item.code);
            this.$emit('update:labelValue', item.displayName);
            this.$emit('selected', item);
        },
        clearQuery() {
            clearTimeout(this.debounceTimer);
            this.query = '';
            this.items = [];
            this.open = false;
            this.notFound = false;
            this.loading = false;
            this.$emit('update:modelValue', '');
            this.$emit('update:labelValue', '');
            this.$nextTick(() => {
                this.$refs.locationInput?.focus();
            });
        },
    },
    template: `
        <section class="checkout_section" :class="{ 'checkout_section--disabled': disabled }">
            <h2 class="checkout_section__title">Местоположение</h2>
            <div class="checkout_location">
                <div class="checkout_location__field">
                    <input
                        ref="locationInput"
                        class="checkout_location__input"
                        type="text"
                        placeholder="Начните вводить город"
                        v-model="query"
                        :disabled="disabled"
                        @input="onInput"
                        @focus="search"
                    />
                    <button
                        v-if="query"
                        type="button"
                        class="checkout_location__clear"
                        aria-label="Очистить"
                        :disabled="disabled"
                        @mousedown.prevent
                        @click="clearQuery"
                    >
                        <svg viewBox="0 0 16 16" width="16" height="16" aria-hidden="true">
                            <path d="M3.2 3.2l9.6 9.6M12.8 3.2l-9.6 9.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
                <div v-if="open && !loading" class="checkout_location__dropdown">
                    <p v-if="notFound" class="checkout_location__empty">Не найдено совпадений</p>
                    <button
                        v-for="item in items"
                        :key="item.code + '-' + item.id"
                        type="button"
                        class="checkout_location__item"
                        @click="selectItem(item)"
                    >{{ item.displayName }}</button>
                </div>
                <p v-if="loading" class="checkout_option__meta">Поиск...</p>
            </div>
        </section>
    `,
};
