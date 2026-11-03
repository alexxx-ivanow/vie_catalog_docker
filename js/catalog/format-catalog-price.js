const CURRENCY_SYMBOLS = {
    RUB: '₽',
    USD: '$',
    EUR: '€',
};

export function formatCatalogPrice(price, currency = 'RUB') {
    const code = String(currency || 'RUB').toUpperCase();
    const amount = Number(price);
    const safeAmount = Number.isFinite(amount) ? amount : 0;

    if (typeof Intl !== 'undefined' && Intl.NumberFormat) {
        try {
            return new Intl.NumberFormat('ru-RU', {
                style: 'currency',
                currency: code,
                maximumFractionDigits: 2,
            }).format(safeAmount);
        } catch {
            // unknown currency code
        }
    }

    const symbol = CURRENCY_SYMBOLS[code] || code;
    return `${safeAmount} ${symbol}`;
}
