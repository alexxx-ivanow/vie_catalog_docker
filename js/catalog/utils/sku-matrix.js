export function normalizeSkuValue(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value;
    }
    const asInt = parseInt(value, 10);
    if (Number.isFinite(asInt) && String(asInt) === String(value).trim()) {
        return asInt;
    }

    return String(value);
}

export function skuValuesEqual(a, b) {
    return normalizeSkuValue(a) === normalizeSkuValue(b);
}

export function propsFromOfferId(offers, offerId) {
    const id = parseInt(offerId, 10);
    if (!Array.isArray(offers) || !Number.isFinite(id) || id <= 0) {
        return {};
    }
    const offer = offers.find((row) => parseInt(row?.id, 10) === id);
    if (!offer || !offer.props || typeof offer.props !== 'object') {
        return {};
    }
    const props = {};
    for (const [code, value] of Object.entries(offer.props)) {
        const normalized = normalizeSkuValue(value);
        if (normalized !== null) {
            props[code] = normalized;
        }
    }

    return props;
}

export function applyOfferSelection(state, activeOfferId, selectedSkuPropsFromApi) {
    const next = { ...state };
    if (selectedSkuPropsFromApi && typeof selectedSkuPropsFromApi === 'object') {
        const props = {};
        for (const [code, value] of Object.entries(selectedSkuPropsFromApi)) {
            const normalized = normalizeSkuValue(value);
            if (normalized !== null) {
                props[code] = normalized;
            }
        }
        next.selectedProps = props;
    } else {
        next.selectedProps = propsFromOfferId(next.offers || [], activeOfferId);
    }
    next.activeOfferId = parseInt(activeOfferId, 10) || 0;

    return next;
}

export function findOfferByProps(offers, selectedProps, treeProps) {
    if (!Array.isArray(offers) || !selectedProps) {
        return null;
    }
    const dimensions = Array.isArray(treeProps) ? treeProps : [];
    if (dimensions.length === 0) {
        return offers[0] || null;
    }
    for (const dim of dimensions) {
        const code = String(dim?.code || '');
        if (code === '' || selectedProps[code] === undefined || selectedProps[code] === null) {
            return null;
        }
    }

    return (
        offers.find((offer) => {
            const props = offer?.props || {};

            return dimensions.every((dim) => {
                const code = String(dim.code || '');

                return skuValuesEqual(props[code], selectedProps[code]);
            });
        }) || null
    );
}

/**
 * После клика по чипу подбирает полный набор свойств и оффер (без «тупика» матрицы).
 */
export function resolveSelectionAfterPick(offers, treeProps, selectedProps, propCode, valueId) {
    const code = String(propCode || '');
    const picked = normalizeSkuValue(valueId);
    if (code === '' || picked === null || !Array.isArray(offers) || offers.length === 0) {
        return null;
    }

    const dimensions = Array.isArray(treeProps) ? treeProps : [];
    const candidates = offers.filter((offer) =>
        skuValuesEqual(offer?.props?.[code], picked)
    );
    if (candidates.length === 0) {
        return null;
    }

    let best = candidates[0];
    let bestScore = -1;
    for (const offer of candidates) {
        let score = 0;
        for (const dim of dimensions) {
            const dimCode = String(dim?.code || '');
            if (dimCode === '' || dimCode === code) {
                continue;
            }
            if (
                selectedProps?.[dimCode] !== undefined
                && selectedProps?.[dimCode] !== null
                && skuValuesEqual(offer?.props?.[dimCode], selectedProps[dimCode])
            ) {
                score++;
            }
        }
        if (score > bestScore) {
            bestScore = score;
            best = offer;
        } else if (score === bestScore) {
            const priceA = parseFloat(best?.price) || 0;
            const priceB = parseFloat(offer?.price) || 0;
            if (priceB < priceA || (priceB === priceA && parseInt(offer?.id, 10) < parseInt(best?.id, 10))) {
                best = offer;
            }
        }
    }

    const nextProps = {};
    for (const dim of dimensions) {
        const dimCode = String(dim?.code || '');
        if (dimCode === '') {
            continue;
        }
        const value = normalizeSkuValue(best?.props?.[dimCode]);
        if (value !== null) {
            nextProps[dimCode] = value;
        }
    }

    return { props: nextProps, offer: best };
}

export function isValueDisabled(offers, treeProps, selectedProps, propCode, valueId) {
    const code = String(propCode || '');
    const picked = normalizeSkuValue(valueId);
    if (code === '' || picked === null) {
        return true;
    }

    return !offers.some((offer) => skuValuesEqual(offer?.props?.[code], picked));
}
