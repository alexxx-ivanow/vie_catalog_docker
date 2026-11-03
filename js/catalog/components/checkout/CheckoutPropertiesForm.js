import { formatRuPhoneInput } from '../../utils/phone-mask.js';

const EMAIL_CODES = ['EMAIL', 'E_MAIL', 'MAIL', 'E-MAIL'];
const PHONE_CODES = ['PHONE', 'TEL', 'PHONE_NUMBER', 'MOBILE'];

export function isCheckoutEmailProperty(property) {
    if (property?.isEmail) {
        return true;
    }
    const code = String(property?.code || '').toUpperCase();
    return EMAIL_CODES.includes(code);
}

export function isCheckoutPhoneProperty(property) {
    if (property?.isPhone) {
        return true;
    }
    const code = String(property?.code || '').toUpperCase();
    return PHONE_CODES.includes(code);
}

export function isValidEmail(value) {
    const normalized = String(value ?? '').trim();
    if (normalized === '') {
        return false;
    }
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized);
}

export { isValidRuPhone } from '../../utils/phone-mask.js';

export default {
    name: 'CheckoutPropertiesForm',
    props: {
        properties: { type: Array, default: () => [] },
        modelValue: { type: Object, default: () => ({}) },
        disabled: { type: Boolean, default: false },
    },
    emits: ['update:modelValue'],
    data() {
        return {
            localById: {},
            focusedId: null,
        };
    },
    watch: {
        properties: {
            handler() {
                this.mergeLocalFromSources();
            },
            immediate: true,
        },
        modelValue: {
            deep: true,
            handler() {
                this.mergeLocalFromSources();
            },
        },
    },
    methods: {
        isEmailProperty: isCheckoutEmailProperty,
        isPhoneProperty: isCheckoutPhoneProperty,
        fieldKeys(property) {
            const keys = [];
            if (property.code) {
                keys.push(String(property.code));
            }
            if (property.id) {
                keys.push(String(property.id));
            }
            return keys;
        },
        normalizeValue(value) {
            if (Array.isArray(value)) {
                const found = value.map((item) => this.normalizeValue(item)).find((item) => item !== '');
                return found || '';
            }
            return String(value ?? '');
        },
        isEmptyValue(value) {
            return this.normalizeValue(value).trim() === '';
        },
        fieldValue(property) {
            for (const key of this.fieldKeys(property)) {
                const current = this.normalizeValue(this.modelValue[key]);
                if (!this.isEmptyValue(current)) {
                    return current;
                }
            }
            if (isCheckoutEmailProperty(property) || isCheckoutPhoneProperty(property)) {
                return '';
            }
            return this.normalizeValue(property.value);
        },
        mergeLocalFromSources() {
            for (const property of this.properties) {
                const id = property.id;
                if (id === undefined || id === null) {
                    continue;
                }
                if (this.focusedId === id) {
                    continue;
                }
                const resolved = this.fieldValue(property);
                if (this.localById[id] !== resolved) {
                    this.localById[id] = resolved;
                }
            }
        },
        autocompleteForProperty(property) {
            const code = String(property.code || '').toUpperCase();
            if (['FIO', 'NAME', 'CONTACT_PERSON', 'FULL_NAME', 'USER_NAME'].includes(code)) {
                return 'name';
            }
            if (isCheckoutEmailProperty(property)) {
                return 'email';
            }
            if (isCheckoutPhoneProperty(property)) {
                return 'tel';
            }
            if (code === 'ZIP' || code === 'INDEX') {
                return 'postal-code';
            }
            return 'off';
        },
        inputType(property) {
            if (isCheckoutEmailProperty(property)) {
                return 'email';
            }
            if (isCheckoutPhoneProperty(property)) {
                return 'tel';
            }
            return 'text';
        },
        phonePlaceholder(property) {
            return isCheckoutPhoneProperty(property) ? '+7 (___) ___-__-__' : '';
        },
        fieldName(property) {
            return `checkout-prop-${property.id}`;
        },
        onFieldFocus(property) {
            this.focusedId = property.id;
        },
        onFieldBlur(property) {
            if (this.focusedId === property.id) {
                this.focusedId = null;
            }
            this.commitField(property);
        },
        onTextInput(property, event) {
            if (this.isPhoneProperty(property)) {
                this.onPhoneInput(property, event);
            }
        },
        onPhoneInput(property, event) {
            const input = event.target;
            const start = input.selectionStart ?? 0;
            const before = String(this.localById[property.id] || '');
            const formatted = formatRuPhoneInput(input.value);
            this.localById[property.id] = formatted;

            this.$nextTick(() => {
                if (!input || document.activeElement !== input) {
                    return;
                }
                const delta = formatted.length - before.length;
                let nextPos = Math.max(0, Math.min(formatted.length, start + delta));
                if (start === before.length && delta > 0) {
                    nextPos = formatted.length;
                }
                try {
                    input.setSelectionRange(nextPos, nextPos);
                } catch {
                    // ignore on some mobile browsers
                }
            });
        },
        commitField(property) {
            const id = property.id;
            let normalized = this.normalizeValue(this.localById[id]).trim();
            if (isCheckoutPhoneProperty(property) && normalized !== '') {
                normalized = formatRuPhoneInput(normalized);
            }
            this.localById[id] = normalized;

            const next = { ...this.modelValue };
            for (const key of this.fieldKeys(property)) {
                next[key] = normalized;
            }
            this.$emit('update:modelValue', next);
        },
        flushToModel() {
            const next = { ...this.modelValue };
            for (const property of this.properties) {
                const keys = this.fieldKeys(property);
                let value = this.normalizeValue(this.localById[property.id]);
                if (this.isEmptyValue(value)) {
                    value = keys
                        .map((key) => this.normalizeValue(next[key]))
                        .find((item) => !this.isEmptyValue(item)) || '';
                }
                if (this.isEmptyValue(value)) {
                    if (isCheckoutEmailProperty(property) || isCheckoutPhoneProperty(property)) {
                        continue;
                    }
                    value = this.normalizeValue(property.value);
                }
                if (this.isEmptyValue(value)) {
                    continue;
                }
                value = value.trim();
                if (isCheckoutPhoneProperty(property)) {
                    value = formatRuPhoneInput(value);
                }
                this.localById[property.id] = value;
                for (const key of keys) {
                    next[key] = value;
                }
            }
            this.$emit('update:modelValue', next);
            return next;
        },
    },
    template: `
        <section
            class="checkout_section"
            :class="{ 'checkout_section--disabled': disabled }"
            autocomplete="off"
        >
            <h2 class="checkout_section__title">Данные получателя</h2>
            <div v-if="disabled" class="checkout_option__meta">Выберите тип покупателя, местоположение, доставку и способ оплаты, чтобы увидеть поля получателя.</div>
            <div v-else-if="!properties.length" class="checkout_option__meta">Для выбранных условий дополнительные поля не требуются.</div>
            <div v-for="property in properties" :key="property.id" class="checkout_field">
                <label :for="'prop-' + property.id">
                    {{ property.name }}<span v-if="property.required"> *</span>
                </label>
                <select
                    v-if="property.type === 'ENUM'"
                    :id="'prop-' + property.id"
                    v-model="localById[property.id]"
                    :name="fieldName(property)"
                    :autocomplete="autocompleteForProperty(property)"
                    @change="commitField(property)"
                >
                    <option value="">Выберите</option>
                    <option v-for="opt in property.options" :key="opt.value" :value="opt.value">{{ opt.name }}</option>
                </select>
                <textarea
                    v-else-if="property.type === 'TEXT'"
                    :id="'prop-' + property.id"
                    rows="3"
                    v-model="localById[property.id]"
                    :name="fieldName(property)"
                    :autocomplete="autocompleteForProperty(property)"
                    @focus="onFieldFocus(property)"
                    @blur="onFieldBlur(property)"
                ></textarea>
                <input
                    v-else
                    :id="'prop-' + property.id"
                    :type="inputType(property)"
                    v-model="localById[property.id]"
                    :name="fieldName(property)"
                    :autocomplete="autocompleteForProperty(property)"
                    :placeholder="phonePlaceholder(property)"
                    @focus="onFieldFocus(property)"
                    @blur="onFieldBlur(property)"
                    @input="onTextInput(property, $event)"
                />
            </div>
        </section>
    `,
};
