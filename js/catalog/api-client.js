const DEFAULT_API_BASE = '/vue_catalog/api/v1/';

export function getApiBase() {
    const base = window.__BX__?.apiBase || DEFAULT_API_BASE;
    return base.endsWith('/') ? base : base + '/';
}

/** Путь от корня сайта, например catalog/sections */
export function catalogApiUrl(path) {
    const relative = String(path).replace(/^\//, '');
    return getApiBase() + relative;
}

export function getSessid() {
    return window.__BX__?.sessid || '';
}

export function getJsonHeaders() {
    return {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Bitrix-Csrf-Token': getSessid(),
    };
}

export function getReadHeaders() {
    return {
        'X-Requested-With': 'XMLHttpRequest',
    };
}

export async function apiGet(url, options = {}) {
    const res = await fetch(url, {
        headers: getReadHeaders(),
        credentials: 'same-origin',
        cache: options.cache || 'default',
    });
    const data = await res.json();
    return { res, data };
}

export async function apiPost(url, body, options = {}) {
    const payload = { ...(body || {}), sessid: body?.sessid ?? getSessid() };
    const fetchOptions = {
        method: 'POST',
        headers: getJsonHeaders(),
        body: JSON.stringify(payload),
        credentials: 'same-origin',
        cache: 'no-store',
    };
    if (options.signal) {
        fetchOptions.signal = options.signal;
    }

    const res = await fetch(url, fetchOptions);
    const data = await res.json();
    if (data?.sessid) {
        window.__BX__.sessid = data.sessid;
    }
    return { res, data };
}
