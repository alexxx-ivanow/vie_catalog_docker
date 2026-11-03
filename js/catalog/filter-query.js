export const CATALOG_SORT_VALUES = ['default', 'name', 'price_asc', 'price_desc'];

export function readSortFromSearch(params) {
    const raw = String(params.get('sort') ?? '').trim();
    if (CATALOG_SORT_VALUES.includes(raw)) {
        return raw;
    }
    return 'default';
}

export function appendCatalogQuery(params, requestParams) {
    Object.entries(requestParams || {}).forEach(([key, value]) => {
        if (value && typeof value === 'object' && !Array.isArray(value)) {
            Object.entries(value).forEach(([subKey, subValue]) => {
                const list = Array.isArray(subValue) ? subValue : [subValue];
                list.forEach((entry) => {
                    if (entry !== null && entry !== undefined && entry !== '') {
                        params.append(`${key}[${subKey}][]`, String(entry));
                    }
                });
            });
            return;
        }

        if (Array.isArray(value)) {
            value.forEach((entry) => {
                if (entry !== null && entry !== undefined && entry !== '') {
                    params.append(`${key}[]`, String(entry));
                }
            });
            return;
        }

        if (value !== null && value !== undefined && value !== '') {
            params.set(key, String(value));
        }
    });
}

export function readPropertiesFromSearch(params) {
    const properties = {};
    params.forEach((value, key) => {
        const match = /^props\[([A-Za-z0-9_]+)\](?:\[\])?$/.exec(key);
        if (!match) {
            return;
        }
        const code = match[1];
        const str = String(value ?? '').trim();
        if (str === '') {
            return;
        }
        if (!properties[code]) {
            properties[code] = [];
        }
        if (!properties[code].includes(str)) {
            properties[code].push(str);
        }
    });
    return properties;
}
