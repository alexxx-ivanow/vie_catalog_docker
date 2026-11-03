import AppPreloader from '../common/AppPreloader.js';
import CheckoutPersonType from './CheckoutPersonType.js';
import CheckoutLocationAutocomplete from './CheckoutLocationAutocomplete.js';
import CheckoutDeliveryList from './CheckoutDeliveryList.js';
import CheckoutPaymentList from './CheckoutPaymentList.js';
import CheckoutPropertiesForm, {
    isCheckoutEmailProperty,
    isCheckoutPhoneProperty,
    isValidEmail,
    isValidRuPhone,
} from './CheckoutPropertiesForm.js';
import CheckoutBasketList from './CheckoutBasketList.js';
import CheckoutTotalsSidebar from './CheckoutTotalsSidebar.js';
import { apiGet, apiPost, catalogApiUrl } from '../../api-client.js';
import { smoothScrollToElement } from '../../scroll-with-sticky-header.js';

function isCheckoutNavigationLocked() {
    return window.__MYAPP_CHECKOUT_NAV_LOCK__ === true;
}

function lockCheckoutNavigation() {
    window.__MYAPP_CHECKOUT_NAV_LOCK__ = true;
}

function unlockCheckoutNavigation() {
    window.__MYAPP_CHECKOUT_NAV_LOCK__ = false;
}

export default {
    name: 'CheckoutPage',
    components: {
        AppPreloader,
        CheckoutPersonType,
        CheckoutLocationAutocomplete,
        CheckoutDeliveryList,
        CheckoutPaymentList,
        CheckoutPropertiesForm,
        CheckoutBasketList,
        CheckoutTotalsSidebar,
    },
    data() {
        return {
            loading: true,
            calculating: false,
            submitting: false,
            errors: [],
            personTypes: [],
            deliveries: [],
            paySystems: [],
            properties: [],
            basket: { items: [], totals: {} },
            totals: {
                itemsSum: 0,
                delivery: 0,
                discount: 0,
                total: 0,
                currency: 'RUB',
            },
            selection: {
                personTypeId: 0,
                locationCode: '',
                locationLabel: '',
                deliveryId: 0,
                paySystemId: 0,
                properties: {},
                deliveryExtra: {},
            },
            calcTimer: null,
            calcRequestId: 0,
            calcAbortController: null,
            redirectingAfterSubmit: false,
            suppressSelectionWatch: false,
        };
    },
    computed: {
        hasLocation() {
            return Boolean(this.selection.locationCode);
        },
        canShowDelivery() {
            return this.selection.personTypeId > 0 && this.hasLocation;
        },
        canShowPayment() {
            return this.canShowDelivery && this.selection.deliveryId > 0;
        },
        canShowProperties() {
            return this.canShowPayment && this.selection.paySystemId > 0;
        },
        canSubmit() {
            return this.canShowProperties && !this.calculating && !this.loading;
        },
    },
    watch: {
        'selection.personTypeId'(value, oldValue) {
            if (this.suppressSelectionWatch || value === oldValue) return;
            this.selection.deliveryId = 0;
            this.selection.paySystemId = 0;
            this.scheduleCalculate();
        },
        'selection.locationCode'(value, oldValue) {
            if (this.suppressSelectionWatch || value === oldValue) return;
            this.selection.deliveryId = 0;
            this.selection.paySystemId = 0;
            this.scheduleCalculate();
        },
        'selection.deliveryId'(value, oldValue) {
            if (this.suppressSelectionWatch || value === oldValue) return;
            this.selection.paySystemId = 0;
            this.selection.deliveryExtra = {};
            this.scheduleCalculate();
        },
        'selection.paySystemId'(value, oldValue) {
            if (this.suppressSelectionWatch || value === oldValue) return;
            this.scheduleCalculate();
        },
        'selection.deliveryExtra': {
            deep: true,
            handler() {
                this.scheduleCalculate(false);
            },
        },
    },
    mounted() {
        this.loadInit();
    },
    methods: {
        buildPayload() {
            return {
                personTypeId: this.selection.personTypeId,
                locationCode: this.selection.locationCode,
                locationLabel: this.selection.locationLabel,
                deliveryId: this.selection.deliveryId,
                paySystemId: this.selection.paySystemId,
                properties: this.selection.properties,
                deliveryExtra: this.selection.deliveryExtra,
            };
        },
        scheduleCalculate(resetErrors = true) {
            if (isCheckoutNavigationLocked() || this.redirectingAfterSubmit || this.submitting) {
                return;
            }
            clearTimeout(this.calcTimer);
            this.calcTimer = setTimeout(() => this.calculate(resetErrors), 350);
        },
        cancelCalculateTimer() {
            clearTimeout(this.calcTimer);
            this.calcTimer = null;
        },
        abortInflightCalculate() {
            if (this.calcAbortController) {
                this.calcAbortController.abort();
                this.calcAbortController = null;
            }
        },
        stopCalculateActivity() {
            this.cancelCalculateTimer();
            this.abortInflightCalculate();
            this.calcRequestId += 1;
        },
        shouldRedirectEmptyBasketToCart() {
            return !isCheckoutNavigationLocked()
                && !this.redirectingAfterSubmit
                && !this.submitting;
        },
        redirectAfterSubmit(url) {
            const target = String(url ?? '').trim();
            if (!target) {
                return;
            }

            lockCheckoutNavigation();
            this.redirectingAfterSubmit = true;
            this.stopCalculateActivity();
            window.location.replace(target);
        },
        clearErrors() {
            this.errors = [];
        },
        setErrors(errors, fallback = 'Произошла ошибка') {
            const list = Array.isArray(errors) ? errors : [errors];
            this.errors = list.map((item) => String(item ?? '').trim()).filter(Boolean);
            if (!this.errors.length) {
                this.errors = [fallback];
            }
            this.scrollToErrors();
        },
        extractApiErrors(data, fallback) {
            if (Array.isArray(data?.errors) && data.errors.length) {
                return data.errors;
            }
            if (Array.isArray(data?.propertyErrors) && data.propertyErrors.length) {
                return data.propertyErrors;
            }
            if (data?.error) {
                return String(data.error)
                    .split(/;\s*/)
                    .map((item) => item.trim())
                    .filter(Boolean);
            }
            return [fallback];
        },
        scrollToErrors() {
            this.$nextTick(() => {
                const el = this.$refs.errorBlock;
                if (!el) {
                    return;
                }
                smoothScrollToElement(el);
                if (typeof el.focus === 'function') {
                    el.focus({ preventScroll: true });
                }
            });
        },
        syncSelectionFromResponse(data) {
            const sel = data?.selection || {};
            this.suppressSelectionWatch = true;
            try {
                if (Object.prototype.hasOwnProperty.call(sel, 'deliveryId')) {
                    this.selection.deliveryId = Number(sel.deliveryId) || 0;
                }
                if (Object.prototype.hasOwnProperty.call(sel, 'paySystemId')) {
                    this.selection.paySystemId = Number(sel.paySystemId) || 0;
                }
            } finally {
                queueMicrotask(() => {
                    this.suppressSelectionWatch = false;
                });
            }
        },
        applyCalculateResponse(data) {
            this.deliveries = data.deliveries || [];
            this.paySystems = data.paySystems || [];
            this.properties = data.properties || [];
            this.basket = data.basket || this.basket;
            this.totals = data.totals || this.totals;
            this.pruneStaleProperties(this.properties);
            this.mergeIncomingProperties(this.properties);
            this.syncSelectionFromResponse(data);
        },
        normalizePropertyValue(value) {
            if (Array.isArray(value)) {
                const found = value.map((item) => this.normalizePropertyValue(item)).find((item) => item !== '');
                return found || '';
            }
            return String(value ?? '').trim();
        },
        pruneStaleProperties(properties) {
            const allowedKeys = new Set();
            for (const property of properties) {
                if (property.code) {
                    allowedKeys.add(String(property.code));
                }
                if (property.id) {
                    allowedKeys.add(String(property.id));
                }
            }

            const next = {};
            let changed = false;
            for (const [key, value] of Object.entries(this.selection.properties)) {
                if (allowedKeys.has(String(key))) {
                    next[key] = value;
                } else {
                    changed = true;
                }
            }
            if (changed) {
                this.selection.properties = next;
            }
        },
        mergeIncomingProperties(properties) {
            const next = { ...this.selection.properties };
            let changed = false;
            for (const property of properties) {
                const keys = [property.code, property.id ? String(property.id) : ''].filter(Boolean);
                const hasValue = keys.some((key) => this.normalizePropertyValue(next[key]) !== '');
                if (hasValue) {
                    continue;
                }
                if (isCheckoutEmailProperty(property)) {
                    continue;
                }
                const incoming = this.normalizePropertyValue(property.value);
                if (!incoming) {
                    continue;
                }
                for (const key of keys) {
                    next[key] = incoming;
                }
                changed = true;
            }
            if (changed) {
                this.selection.properties = next;
            }
        },
        syncPropertyFields() {
            const flushed = this.$refs.propertiesForm?.flushToModel?.();
            if (flushed && typeof flushed === 'object') {
                this.selection.properties = flushed;
                return flushed;
            }
            return this.selection.properties;
        },
        async loadInit() {
            this.loading = true;
            this.clearErrors();
            try {
                const { res, data } = await apiGet(catalogApiUrl('checkout/init'));
                if (!res.ok || !data.success) {
                    if (data.code === 'EMPTY_BASKET') {
                        if (this.shouldRedirectEmptyBasketToCart()) {
                            window.location.replace('/cart/');
                        }
                        return;
                    }
                    this.setErrors(this.extractApiErrors(data, 'Не удалось загрузить оформление заказа'));
                    return;
                }

                this.personTypes = data.personTypes || [];
                this.basket = data.basket || { items: [], totals: {} };
                const basketTotals = data.basket?.totals || {};
                this.totals = {
                    ...this.totals,
                    itemsSum: Number(basketTotals.itemsSum) || 0,
                    discount: Number(basketTotals.discount) || 0,
                    total: Number(basketTotals.total) || 0,
                };
                this.selection = {
                    ...this.selection,
                    ...(data.selection || {}),
                    properties: {},
                    deliveryExtra: {},
                };

                await this.calculate(false);
            } catch (e) {
                this.setErrors('Ошибка загрузки оформления заказа');
            } finally {
                this.loading = false;
            }
        },
        async calculate(resetErrors = true) {
            if (
                isCheckoutNavigationLocked()
                || this.redirectingAfterSubmit
                || this.submitting
                || this.selection.personTypeId <= 0
            ) {
                return;
            }

            this.abortInflightCalculate();
            const requestId = ++this.calcRequestId;
            const controller = new AbortController();
            this.calcAbortController = controller;

            this.calculating = true;
            if (resetErrors) {
                this.clearErrors();
            }

            try {
                const { res, data } = await apiPost(
                    catalogApiUrl('checkout/calculate'),
                    this.buildPayload(),
                    { signal: controller.signal }
                );
                if (
                    requestId !== this.calcRequestId
                    || isCheckoutNavigationLocked()
                    || this.redirectingAfterSubmit
                    || this.submitting
                ) {
                    return;
                }
                if (!res.ok || !data.success) {
                    if (data.code === 'EMPTY_BASKET') {
                        return;
                    }
                    this.setErrors(this.extractApiErrors(data, 'Не удалось пересчитать заказ'));
                    return;
                }

                this.applyCalculateResponse(data);
            } catch (e) {
                if (e?.name === 'AbortError') {
                    return;
                }
                if (!this.redirectingAfterSubmit && !this.submitting) {
                    this.setErrors('Ошибка пересчёта заказа');
                }
            } finally {
                if (this.calcAbortController === controller) {
                    this.calcAbortController = null;
                }
                if (requestId === this.calcRequestId) {
                    this.calculating = false;
                }
            }
        },
        validateClient() {
            const errors = [];

            if (this.selection.personTypeId <= 0) {
                errors.push('Выберите тип покупателя');
            }
            if (!this.selection.locationCode) {
                errors.push('Выберите местоположение');
            }
            if (!this.selection.deliveryId) {
                errors.push('Выберите способ доставки');
            }
            if (!this.selection.paySystemId) {
                errors.push('Выберите способ оплаты');
            }

            const propertiesState = this.syncPropertyFields();
            for (const property of this.properties) {
                const keys = [property.code, property.id ? String(property.id) : ''].filter(Boolean);
                const value = keys
                    .map((key) => propertiesState[key])
                    .find((item) => String(item ?? '').trim() !== '');

                if (property.required && !String(value ?? '').trim()) {
                    errors.push(`Заполните поле: ${property.name}`);
                    continue;
                }

                if (isCheckoutEmailProperty(property) && String(value ?? '').trim() !== '') {
                    if (!isValidEmail(value)) {
                        errors.push(`Укажите корректный e-mail в поле: ${property.name}`);
                    }
                }

                if (isCheckoutPhoneProperty(property) && String(value ?? '').trim() !== '') {
                    if (!isValidRuPhone(value)) {
                        errors.push(`Укажите корректный телефон в поле: ${property.name}`);
                    }
                }
            }

            if (errors.length) {
                this.setErrors(errors);
                return false;
            }

            this.clearErrors();
            return true;
        },
        async submitOrder() {
            if (!this.validateClient()) {
                return;
            }

            const syncedProperties = this.syncPropertyFields();
            if (syncedProperties && typeof syncedProperties === 'object') {
                this.selection.properties = { ...syncedProperties };
            }

            lockCheckoutNavigation();
            this.submitting = true;
            this.redirectingAfterSubmit = true;
            this.clearErrors();
            this.stopCalculateActivity();

            let navigated = false;

            try {
                const { res, data } = await apiPost(catalogApiUrl('checkout/submit'), this.buildPayload());
                if (!res.ok || !data.success) {
                    this.setErrors(this.extractApiErrors(data, 'Не удалось оформить заказ'));
                    return;
                }

                const paymentUrl = String(data.redirectUrl ?? '').trim();
                const successUrl = String(data.successUrl ?? '').trim();
                const target = paymentUrl || successUrl;

                if (target) {
                    this.redirectAfterSubmit(target);
                    navigated = true;
                }
            } catch (e) {
                this.setErrors('Ошибка оформления заказа');
            } finally {
                if (!navigated) {
                    unlockCheckoutNavigation();
                    this.redirectingAfterSubmit = false;
                    this.submitting = false;
                }
            }
        },
    },
    template: `
        <div class="checkout">
            <AppPreloader v-if="loading" :show="true" :center-page="true" text="Загрузка оформления..." />

            <template v-else>
                <h1 class="checkout__title">Оформление заказа</h1>
                <div
                    v-if="errors.length"
                    ref="errorBlock"
                    class="checkout_error"
                    tabindex="-1"
                    role="alert"
                    aria-live="assertive"
                >
                    <p class="checkout_error__title">Исправьте ошибки:</p>
                    <ul class="checkout_error__list">
                        <li v-for="(message, index) in errors" :key="index">{{ message }}</li>
                    </ul>
                </div>

                <div class="checkout_layout">
                    <div class="checkout_main">
                        <CheckoutPersonType
                            v-model="selection.personTypeId"
                            :person-types="personTypes"
                        />

                        <CheckoutLocationAutocomplete
                            v-model="selection.locationCode"
                            v-model:label-value="selection.locationLabel"
                            :disabled="selection.personTypeId <= 0"
                        />

                        <CheckoutDeliveryList
                            v-model="selection.deliveryId"
                            v-model:delivery-extra="selection.deliveryExtra"
                            :deliveries="deliveries"
                            :disabled="!canShowDelivery"
                        />

                        <CheckoutPaymentList
                            v-model="selection.paySystemId"
                            :pay-systems="paySystems"
                            :disabled="!canShowPayment"
                        />

                        <CheckoutPropertiesForm
                            ref="propertiesForm"
                            v-model="selection.properties"
                            :properties="canShowProperties ? properties : []"
                            :disabled="!canShowProperties"
                        />

                        <CheckoutBasketList :items="basket.items || []" />
                    </div>

                    <CheckoutTotalsSidebar
                        :totals="totals"
                        :submitting="submitting"
                        :disabled="!canSubmit"
                        @submit="submitOrder"
                    />
                </div>
            </template>
        </div>
    `,
};
