export function normalizePriceBounds(priceMeta) {
    const rawMin = Number(priceMeta?.price?.min ?? priceMeta?.min ?? 0);
    const rawMax = Number(priceMeta?.price?.max ?? priceMeta?.max ?? rawMin);
    const min = Math.floor(Number.isFinite(rawMin) ? rawMin : 0);
    let max = Math.ceil(Number.isFinite(rawMax) ? rawMax : min);
    if (max < min) {
        max = min;
    }

    return {
        min,
        max,
        span: max - min,
    };
}

export function clampFilterPrices(filter, priceMeta) {
    const { min: minPrice, max: maxPrice } = normalizePriceBounds(priceMeta);
    const next = { ...filter };

    if (!Number.isFinite(next.price_from) || next.price_from <= 0) {
        next.price_from = minPrice;
    } else {
        next.price_from = Math.max(minPrice, Math.min(Math.round(next.price_from), maxPrice));
    }

    if (!Number.isFinite(next.price_to) || next.price_to <= 0) {
        next.price_to = maxPrice;
    } else {
        next.price_to = Math.max(minPrice, Math.min(Math.round(next.price_to), maxPrice));
    }

    if (next.price_from > next.price_to) {
        next.price_from = next.price_to;
    }

    return next;
}

export function isFullPriceRange(filter, bounds) {
    const from = Math.round(Number(filter?.price_from));
    const to = Math.round(Number(filter?.price_to));

    if (!Number.isFinite(from) || from > bounds.min) {
        return false;
    }

    if (!Number.isFinite(to) || to <= 0 || to >= bounds.max) {
        return true;
    }

    // Диапазон «по умолчанию» после округления дробного max (например 149999 при max 150000).
    return bounds.max - to <= 1;
}
