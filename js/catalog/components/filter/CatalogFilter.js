import { isFullPriceRange, normalizePriceBounds } from '../../filter-price-bounds.js';

export default {
    name: 'CatalogFilter',

    props: {
        meta: { type: Object, default: () => ({}) },
        modelValue: { type: Object, default: () => ({}) },
        applying: { type: Boolean, default: false },
    },

    emits: ['update:modelValue', 'apply'],

    data() {
        return {
            openSections: {
                price: true,
            },
            openSectionsInitialized: false,
        };
    },

    mounted() {
        this.syncPriceValuesToSliderBounds();
        this.applyOpenSectionsFromActiveFilters();
    },

    watch: {
        modelValue: {
            deep: true,
            handler() {
                this.applyOpenSectionsFromActiveFilters();
            },
        },
        meta: {
            deep: true,
            handler() {
                this.syncPriceValuesToSliderBounds();
                this.applyOpenSectionsFromActiveFilters();
            },
        },
    },

    computed: {
        properties() {
            return Array.isArray(this.meta.properties) ? this.meta.properties : [];
        },
        priceMeta() {
            const source = this.meta.price || {};
            const min = Number(source.min ?? 0);
            const max = Number(source.max ?? min);
            return {
                min: Number.isFinite(min) ? min : 0,
                max: Number.isFinite(max) ? max : 0,
            };
        },
        priceSlider() {
            return normalizePriceBounds(this.meta);
        },
        localFilter: {
            get() {
                const source = this.modelValue || {};
                const { min, max } = this.priceSlider;
                const properties = source.properties && typeof source.properties === 'object' && !Array.isArray(source.properties)
                    ? source.properties
                    : {};
                return {
                    properties,
                    price_from: source.price_from ?? min,
                    price_to: source.price_to ?? max,
                };
            },
            set(value) {
                this.$emit('update:modelValue', value);
            },
        },
        priceRangeSpan() {
            const { min, max } = this.priceSlider;
            return max - min;
        },
        priceRangeUsable() {
            return this.priceRangeSpan > 0;
        },
        priceRangeFillStyle() {
            if (!this.priceRangeUsable) {
                return { left: '0%', width: '0%' };
            }
            const { min } = this.priceSlider;
            const span = this.priceRangeSpan;
            const from = Math.round(Number(this.localFilter.price_from));
            const to = Math.round(Number(this.localFilter.price_to));
            const leftRatio = Math.max(0, Math.min(1, (from - min) / span));
            const widthRatio = Math.max(0, Math.min(1 - leftRatio, (to - from) / span));
            return {
                left: `calc(var(--range-thumb) / 2 + (100% - var(--range-thumb)) * ${leftRatio})`,
                width: `calc((100% - var(--range-thumb)) * ${widthRatio})`,
            };
        },
    },
    methods: {
        syncPriceValuesToSliderBounds() {
            const bounds = this.priceSlider;
            const { min, max } = bounds;
            if (max <= min) {
                return;
            }

            const source = this.modelValue || {};
            let from = Math.round(Number(source.price_from));
            let to = Math.round(Number(source.price_to));

            if (!Number.isFinite(from) || from <= 0) {
                from = min;
            }
            if (!Number.isFinite(to) || to <= 0) {
                to = max;
            }

            from = Math.max(min, Math.min(from, max));
            to = Math.max(from, Math.min(to, max));

            if (isFullPriceRange(source, bounds) && to < max) {
                to = max;
            }

            if (from === source.price_from && to === source.price_to) {
                return;
            }

            this.localFilter = {
                ...this.localFilter,
                price_from: from,
                price_to: to,
            };
        },
        applyOpenSectionsFromActiveFilters() {
            if (this.openSectionsInitialized) {
                return;
            }

            const filter = this.localFilter;
            const properties = filter.properties || {};
            const hasCheckbox = Object.values(properties).some((list) => Array.isArray(list) && list.length > 0);
            const metaPriceReady = this.priceRangeUsable;

            if (!metaPriceReady && !hasCheckbox && !this.properties.length) {
                return;
            }

            const openSections = { price: true };
            this.properties.forEach((property) => {
                const list = properties[property.code];
                openSections[property.code] = Array.isArray(list) && list.length > 0;
            });
            this.openSections = openSections;
            this.openSectionsInitialized = true;
        },
        toggleSection(key) {
            if (key === 'price') {
                return;
            }
            this.openSections[key] = !this.isSectionOpen(key);
        },
        isSectionOpen(key) {
            if (key === 'price') {
                return true;
            }
            return Boolean(this.openSections[key]);
        },
        toggleProperty(code, rawValue) {
            const value = String(rawValue);
            const next = { ...this.localFilter, properties: { ...(this.localFilter.properties || {}) } };
            const list = Array.isArray(next.properties[code]) ? [...next.properties[code]] : [];
            const idx = list.findIndex((item) => String(item) === value);
            if (idx >= 0) {
                list.splice(idx, 1);
            } else {
                list.push(value);
            }
            next.properties[code] = list;
            this.localFilter = next;
        },
        onPriceInput(field, rawValue) {
            const { min, max } = this.priceSlider;
            const value = Number(rawValue);
            const next = { ...this.localFilter };
            let parsed = Number.isFinite(value) ? Math.round(value) : min;
            parsed = Math.max(min, Math.min(max, parsed));
            next[field] = parsed;
            if (field === 'price_from' && next.price_from > next.price_to) {
                next.price_to = next.price_from;
            }
            if (field === 'price_to' && next.price_to < next.price_from) {
                next.price_from = next.price_to;
            }
            this.localFilter = next;
        },
        apply() {
            this.$emit('apply');
        },
        collapseAllSections() {
            const openSections = { price: true };
            this.properties.forEach((property) => {
                openSections[property.code] = false;
            });
            this.openSections = openSections;
        },
        reset() {
            this.localFilter = {
                properties: {},
                price_from: this.priceSlider.min,
                price_to: this.priceSlider.max,
            };
            this.collapseAllSections();
            this.$emit('apply');
        },
        isChecked(code, rawValue) {
            const list = (this.localFilter.properties || {})[code] || [];
            const value = String(rawValue);
            return list.some((item) => String(item) === value);
        },
        propertyValueKey(property, item) {
            return property.valueKind === 'enum' ? item.id : item.value;
        },
    },
    template: `
        <div class="catalog-filter">
            <h3 class="catalog-filter__title">Фильтр</h3>

            <div
                class="catalog-filter__field"
                :class="{ 'catalog-filter__field--open': isSectionOpen('price') }"
            >
                <button
                    type="button"
                    class="catalog-filter__field-head"
                    :aria-expanded="isSectionOpen('price')"
                    @click="toggleSection('price')"
                >
                    <span class="catalog-filter__label">Цена</span>
                </button>
                <div v-show="isSectionOpen('price')" class="catalog-filter__field-body">
                    <div class="catalog-filter__price-inputs">
                        <input
                            type="number"
                            :min="priceSlider.min"
                            :max="priceSlider.max"
                            :value="localFilter.price_from"
                            @input="onPriceInput('price_from', $event.target.value)"
                            placeholder="От"
                        />
                        <input
                            type="number"
                            :min="priceSlider.min"
                            :max="priceSlider.max"
                            :value="localFilter.price_to"
                            @input="onPriceInput('price_to', $event.target.value)"
                            placeholder="До"
                        />
                    </div>
                    <div class="catalog-filter__price-display">
                        <span>От {{ localFilter.price_from }} ₽</span>
                        <span>До {{ localFilter.price_to }} ₽</span>
                    </div>
                    <div v-if="priceRangeUsable" class="catalog-filter__range-dual">
                        <div class="catalog-filter__range-track" aria-hidden="true"></div>
                        <div
                            class="catalog-filter__range-fill"
                            aria-hidden="true"
                            :style="priceRangeFillStyle"
                        ></div>
                        <input
                            type="range"
                            class="catalog-filter__range-input catalog-filter__range-input--from"
                            :min="priceSlider.min"
                            :max="priceSlider.max"
                            step="1"
                            :value="localFilter.price_from"
                            aria-label="Цена от"
                            @input="onPriceInput('price_from', $event.target.value)"
                        />
                        <input
                            type="range"
                            class="catalog-filter__range-input catalog-filter__range-input--to"
                            :min="priceSlider.min"
                            :max="priceSlider.max"
                            step="1"
                            :value="localFilter.price_to"
                            aria-label="Цена до"
                            @input="onPriceInput('price_to', $event.target.value)"
                        />
                    </div>
                </div>
            </div>

            <div
                v-for="property in properties"
                :key="property.code"
                class="catalog-filter__field"
                :class="{ 'catalog-filter__field--open': isSectionOpen(property.code) }"
            >
                <button
                    type="button"
                    class="catalog-filter__field-head"
                    :aria-expanded="isSectionOpen(property.code)"
                    @click="toggleSection(property.code)"
                >
                    <span class="catalog-filter__label">{{ property.name }}</span>
                    <span class="catalog-filter__toggle" aria-hidden="true"></span>
                </button>
                <div v-show="isSectionOpen(property.code)" class="catalog-filter__field-body">
                    <label
                        class="catalog-filter__check"
                        v-for="item in property.values"
                        :key="property.code + '-' + propertyValueKey(property, item)"
                    >
                        <input
                            type="checkbox"
                            :checked="isChecked(property.code, propertyValueKey(property, item))"
                            @change="toggleProperty(property.code, propertyValueKey(property, item))"
                        />
                        <span>{{ item.value }}</span>
                    </label>
                </div>
            </div>

            

            <div class="catalog-filter__buttons">
                <button
                    type="button"
                    class="catalog_item__btn catalog-filter__apply"
                    :disabled="applying"
                    :aria-busy="applying"
                    @click="apply"
                >
                    <svg
                        v-if="applying"
                        class="app-preloader__spinner catalog-filter__apply-spinner"
                        viewBox="0 0 50 50"
                        aria-hidden="true"
                    >
                        <circle class="app-preloader__track" cx="25" cy="25" r="20"></circle>
                        <circle class="app-preloader__stroke" cx="25" cy="25" r="20"></circle>
                    </svg>
                    <span v-else>Применить</span>
                </button>
                <button type="button" class="catalog-filter__reset" :disabled="applying" @click="reset">Сбросить</button>
            </div>
        </div>
    `,
};
