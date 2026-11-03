import AppPreloader from '../common/AppPreloader.js';
import { catalogApiUrl, getReadHeaders } from '../../api-client.js';
import { setCartCount } from '../../cart-counter.js';
import { formatCatalogPrice } from '../../format-catalog-price.js';

export default {
    name: 'CartPage',
    components: { AppPreloader },
    data() {
        return {
            items: [],
            totals: {
                itemsSum: 0,
                discount: 0,
                total: 0,
                basketCount: 0,
                currency: 'RUB',
            },
            loading: true,
            loadError: '',
            updatingId: null,
        };
    },
    computed: {
        isEmpty() {
            return !this.loading && !this.loadError && this.items.length === 0;
        },
    },
    mounted() {
        this.loadCart();
    },
    methods: {
        getSessid() {
            return window.__BX__?.sessid || '';
        },
        cartHeaders() {
            return {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Bitrix-Csrf-Token': this.getSessid(),
            };
        },
        formatPrice(value, currency) {
            return formatCatalogPrice(value, currency || this.totals.currency);
        },
        itemQuantity(item) {
            return Number(item?.quantity) || 0;
        },
        applyCartData(data) {
            this.items = Array.isArray(data?.items) ? data.items : [];
            this.totals = {
                itemsSum: Number(data?.totals?.itemsSum) || 0,
                discount: Number(data?.totals?.discount) || 0,
                total: Number(data?.totals?.total) || 0,
                basketCount: Number(data?.totals?.basketCount) || 0,
                currency: data?.totals?.currency || 'RUB',
            };
            setCartCount(this.totals.basketCount);
        },
        async loadCart() {
            this.loading = true;
            this.loadError = '';

            try {
                const res = await fetch(catalogApiUrl('catalog/cart'), {
                    headers: getReadHeaders(),
                });
                const data = await res.json();

                if (!res.ok || data?.error) {
                    this.loadError = data?.error || 'Не удалось загрузить корзину';
                    this.items = [];
                    return;
                }

                this.applyCartData(data);
            } catch (error) {
                this.loadError = 'Ошибка загрузки корзины';
                this.items = [];
            } finally {
                this.loading = false;
            }
        },
        async updateQuantity(item, quantity) {
            const nextQuantity = Math.floor(Number(quantity));
            if (!Number.isFinite(nextQuantity)) {
                return;
            }

            if (nextQuantity < 1) {
                return this.removeItem(item);
            }

            if (this.updatingId === item.id) {
                return;
            }

            this.updatingId = item.id;
            this.loadError = '';

            try {
                const res = await fetch(catalogApiUrl('catalog/cart/update'), {
                    method: 'POST',
                    headers: this.cartHeaders(),
                    body: JSON.stringify({
                        basketItemId: item.id,
                        quantity: nextQuantity,
                        sessid: this.getSessid(),
                    }),
                });
                const data = await res.json();

                if (!res.ok || data?.error || data?.success === false) {
                    this.loadError = data?.error || 'Не удалось изменить количество';
                    return;
                }

                this.applyCartData(data);
            } catch (error) {
                this.loadError = 'Ошибка изменения количества';
            } finally {
                this.updatingId = null;
            }
        },
        async removeItem(item) {
            if (this.updatingId === item.id) {
                return;
            }

            this.updatingId = item.id;
            this.loadError = '';

            try {
                const res = await fetch(catalogApiUrl('catalog/cart/remove'), {
                    method: 'POST',
                    headers: this.cartHeaders(),
                    body: JSON.stringify({
                        basketItemId: item.id,
                        sessid: this.getSessid(),
                    }),
                });
                const data = await res.json();

                if (!res.ok || data?.error || data?.success === false) {
                    this.loadError = data?.error || 'Не удалось удалить товар';
                    return;
                }

                this.applyCartData(data);
            } catch (error) {
                this.loadError = 'Ошибка удаления товара';
            } finally {
                this.updatingId = null;
            }
        },
        decreaseQuantity(item) {
            const quantity = this.itemQuantity(item);
            if (quantity <= 1) {
                this.removeItem(item);
                return;
            }
            this.updateQuantity(item, quantity - 1);
        },
        increaseQuantity(item) {
            this.updateQuantity(item, this.itemQuantity(item) + 1);
        },
        onQuantityInput(item, event) {
            const value = Number(event.target.value);
            if (!Number.isFinite(value)) {
                event.target.value = this.itemQuantity(item);
                return;
            }
            const nextQuantity = Math.floor(value);
            if (nextQuantity === this.itemQuantity(item)) {
                return;
            }
            this.updateQuantity(item, nextQuantity);
        },
    },
    template: `
        <div class="cart">
            <AppPreloader v-if="loading" :show="true" :center-page="true" text="Загрузка корзины..." />

            <div v-else-if="loadError && items.length === 0" class="catalog-empty">{{ loadError }}</div>

            <template v-else>
                <h1 class="cart__title">Корзина</h1>

                <div v-if="isEmpty" class="cart-empty">
                    <p>В корзине пока нет товаров</p>
                    <a href="/catalog/" class="cart__link">Перейти в каталог</a>
                </div>

                <template v-else>
                    <p v-if="loadError" class="cart__error">{{ loadError }}</p>

                    <div class="cart_layout">
                        <div class="cart_list">
                            <div
                                v-for="item in items"
                                :key="item.id"
                                class="cart_item"
                                :class="{ 'cart_item--busy': updatingId === item.id }"
                            >
                                <a :href="item.url" class="cart_item__preview">
                                    <img :src="item.preview" :alt="item.name" width="96" height="96" />
                                </a>

                                <div class="cart_item__info">
                                    <a :href="item.url" class="cart_item__name">{{ item.name }}</a>
                                    <p v-if="item.article" class="cart_item__article">Артикул: {{ item.article }}</p>
                                    <p class="cart_item__price">
                                        <span v-if="item.discount > 0" class="cart_item__price-old">{{ formatPrice(item.basePrice, item.currency) }}</span>
                                        <span>{{ formatPrice(item.price, item.currency) }}</span>
                                    </p>
                                    <p v-if="item.discount > 0" class="cart_item__discount">
                                        Скидка: −{{ formatPrice(item.discount, item.currency) }}
                                        <template v-if="item.quantity > 1"> (всего −{{ formatPrice(item.discountSum, item.currency) }})</template>
                                    </p>
                                </div>

                                <div class="cart_item__qty">
                                    <button
                                        type="button"
                                        class="cart_item__qty-btn"
                                        :disabled="updatingId === item.id"
                                        @click.prevent="decreaseQuantity(item)"
                                    >−</button>
                                    <input
                                        class="cart_item__qty-input"
                                        type="number"
                                        min="1"
                                        step="1"
                                        :value="item.quantity"
                                        :disabled="updatingId === item.id"
                                        @change="onQuantityInput(item, $event)"
                                    />
                                    <button
                                        type="button"
                                        class="cart_item__qty-btn"
                                        :disabled="updatingId === item.id"
                                        @click.prevent="increaseQuantity(item)"
                                    >+</button>
                                </div>

                                <div class="cart_item__sum">
                                    <div v-if="item.discountSum > 0" class="cart_item__sum-old">{{ formatPrice(item.sumBase, item.currency) }}</div>
                                    <div>{{ formatPrice(item.sum, item.currency) }}</div>
                                </div>

                                <button
                                    type="button"
                                    class="cart_item__remove"
                                    :disabled="updatingId === item.id"
                                    @click.prevent="removeItem(item)"
                                >Удалить</button>
                            </div>
                        </div>

                        <aside class="cart_totals">
                            <h2 class="cart_totals__title">Итого</h2>
                            <div class="cart_totals__row">
                                <span>Сумма товаров</span>
                                <strong>{{ formatPrice(totals.itemsSum) }}</strong>
                            </div>
                            <div v-if="totals.discount > 0" class="cart_totals__row cart_totals__row--discount">
                                <span>Скидка</span>
                                <strong>−{{ formatPrice(totals.discount) }}</strong>
                            </div>
                            <div class="cart_totals__row cart_totals__row--total">
                                <span>К оплате</span>
                                <strong>{{ formatPrice(totals.total) }}</strong>
                            </div>
                            <a href="/order/" class="cart_totals__checkout">Оформить заказ</a>
                        </aside>
                    </div>
                </template>
            </template>
        </div>
    `,
};
