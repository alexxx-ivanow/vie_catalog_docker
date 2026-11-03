import { apiGet, catalogApiUrl } from './api-client.js';

export const CART_COUNT_EVENT = 'myapp:catalog:cart-count';

export function extractBasketCount(data) {
    if (!data || typeof data !== 'object') {
        return 0;
    }
    const fromTotals = data.totals?.basketCount;
    if (fromTotals !== undefined && fromTotals !== null) {
        return Math.max(0, Math.floor(Number(fromTotals) || 0));
    }
    if (data.basketCount !== undefined && data.basketCount !== null) {
        return Math.max(0, Math.floor(Number(data.basketCount) || 0));
    }
    return 0;
}

export function setCartCount(count) {
    const value = Math.max(0, Math.floor(Number(count) || 0));
    if (!window.__BX__) {
        window.__BX__ = {};
    }
    window.__BX__.cartCount = value;
    window.dispatchEvent(
        new CustomEvent(CART_COUNT_EVENT, { detail: { count: value } })
    );
    return value;
}

export function syncCartCountFromResponse(data) {
    return setCartCount(extractBasketCount(data));
}

export async function fetchCartCount() {
    try {
        const { data } = await apiGet(catalogApiUrl('catalog/cart'));
        return setCartCount(extractBasketCount(data));
    } catch {
        return setCartCount(0);
    }
}
