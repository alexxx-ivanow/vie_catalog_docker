import { ref, computed } from 'vue';
import { catalogApiUrl, getJsonHeaders } from '../../api-client.js';
import { syncCartCountFromResponse } from '../../cart-counter.js';
import { formatCatalogPrice } from '../../format-catalog-price.js';

export default {
    name: 'ProductCard',

    props: {
        product: { type: Object, required: true },
    },
    emits: ['add-to-cart'],
    setup(props, { emit }) {
        const adding = ref(false);
        const added = ref(false);

        const hasOffers = computed(() => Boolean(props.product?.hasOffers));

        const detailHref = computed(() => {
            const id = props.product?.ID;
            return id ? '/catalog/element/' + id : '#';
        });

        function displayPrice(product) {
            if (product?.hasOffers) {
                const min = product.PRICE_MIN ?? product.PRICE;
                const formatted = formatCatalogPrice(min, product?.CURRENCY);
                return 'от ' + formatted;
            }
            return formatCatalogPrice(product?.PRICE, product?.CURRENCY);
        }

        async function handleAddToCart() {
            if (hasOffers.value) {
                window.location.href = detailHref.value;
                return;
            }

            adding.value = true;

            try {
                const res = await fetch(catalogApiUrl('catalog/cart/add'), {
                    method: 'POST',
                    headers: getJsonHeaders(),
                    body: JSON.stringify({
                        productId: props.product.ID,
                        sessid: window.__BX__.sessid,
                    }),
                });

                const data = await res.json();

                if (data.success) {
                    added.value = true;
                    if (data.sessid) window.__BX__.sessid = data.sessid;
                    syncCartCountFromResponse(data);
                    setTimeout(() => (added.value = false), 2000);
                }

                emit('add-to-cart', props.product);
            } catch (error) {
                console.log('Ошибка добавления товара: ', error);
            } finally {
                adding.value = false;
            }
        }

        return {
            adding,
            added,
            hasOffers,
            detailHref,
            handleAddToCart,
            displayPrice,
        };
    },

    template: `
        <div class="catalog_item" :class="{ 'catalog_item--sku': hasOffers }">
            <a :href="detailHref" class="catalog_item__link">
                <p class="img_wrap">
                    <img :src="product.DETAIL_PICTURE" :alt="product.NAME" width="200" height="200"/>
                </p>
                <p class="catalog_item__name">{{ product.NAME }}</p>
                <p class="catalog_item__desc">{{ product.PREVIEW_TEXT }}</p>
                <p class="catalog_item__price">{{ displayPrice(product) }}</p>
            </a>
            <button
                class="catalog_item__btn"
                :disabled="adding"
                @click="handleAddToCart"
            >
                {{
                    hasOffers
                        ? 'Выбрать вариант'
                        : added
                          ? '✓ Добавлено'
                          : adding
                            ? 'Добавляем...'
                            : 'В корзину'
                }}
            </button>
        </div>
    `,
};
