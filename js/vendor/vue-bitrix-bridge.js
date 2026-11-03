/**
 * ESM-bridge: import map "vue" → API ядра Bitrix (BX.Vue3 / ui.vue3).
 * Требует предварительного Extension::load('ui.vue3') на странице.
 */

const api = globalThis.BX?.Vue3;

if (!api) {
    throw new Error(
        'BX.Vue3 is not available. Enable "use_bitrix_vue" and ensure ui.vue3 is loaded via Extension::load.'
    );
}

const createApp = api.createApp ?? api.BitrixVue?.createApp;
const ref = api.ref;

if (typeof createApp !== 'function') {
    throw new Error('BX.Vue3.createApp is not available.');
}

if (typeof ref !== 'function') {
    throw new Error('BX.Vue3.ref is not available.');
}

export { createApp, ref };
export default api;
