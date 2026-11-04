import {
    findOfferByProps,
    isValueDisabled,
    resolveSelectionAfterPick,
    skuValuesEqual,
} from '../../utils/sku-matrix.js';

export default {
    name: 'SkuSelector',
    props: {
        tree: { type: Object, default: () => ({ props: [] }) },
        offers: { type: Array, default: () => [] },
        selectedProps: { type: Object, default: () => ({}) },
    },
    emits: ['update:selectedProps'],
    computed: {
        dimensions() {
            return Array.isArray(this.tree?.props) ? this.tree.props : [];
        },
        activeOffer() {
            return findOfferByProps(this.offers, this.selectedProps, this.dimensions);
        },
    },
    methods: {
        isSelected(propCode, valueId) {
            return skuValuesEqual(this.selectedProps[propCode], valueId);
        },
        isDisabled(propCode, valueId) {
            return isValueDisabled(this.offers, this.dimensions, this.selectedProps, propCode, valueId);
        },
        selectValue(propCode, valueId) {
            if (this.isDisabled(propCode, valueId)) {
                return;
            }
            const resolved = resolveSelectionAfterPick(
                this.offers,
                this.dimensions,
                this.selectedProps,
                propCode,
                valueId
            );
            if (!resolved?.props) {
                return;
            }
            this.$emit('update:selectedProps', { ...resolved.props });
        },
    },
    template: `
        <div class="product-detail__sku" v-if="dimensions.length">
            <div
                v-for="dim in dimensions"
                :key="dim.code"
                class="product-detail__sku-dimension"
            >
                <p class="product-detail__sku-label">{{ dim.name }}</p>
                <div class="product-detail__sku-values">
                    <button
                        v-for="val in dim.values"
                        :key="dim.code + '-' + val.id"
                        type="button"
                        class="product-detail__sku-chip"
                        :class="{
                            'product-detail__sku-chip--active': isSelected(dim.code, val.id),
                            'product-detail__sku-chip--disabled': isDisabled(dim.code, val.id),
                        }"
                        :disabled="isDisabled(dim.code, val.id)"
                        @click="selectValue(dim.code, val.id)"
                    >
                        {{ val.value }}
                    </button>
                </div>
            </div>
            <p v-if="!activeOffer" class="product-detail__sku-hint">Выберите все параметры</p>
        </div>
    `,
};
