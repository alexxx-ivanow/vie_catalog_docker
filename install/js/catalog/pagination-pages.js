/**
 * Компактная пагинация: первая/последняя страница, окно вокруг current, многоточие при разрывах.
 * Не создаёт массив длиной totalPages — только несколько элементов.
 *
 * @param {number} currentPage 1-based
 * @param {number} totalPages
 * @param {{ delta?: number }} [opts] — сколько страниц слева/справа от current (по умолчанию 2)
 * @returns {Array<{ type: 'page', page: number } | { type: 'ellipsis', key: string }>}
 */
export function buildPaginationPageList(currentPage, totalPages, opts = {}) {
    const total = Math.max(0, Math.floor(Number(totalPages) || 0));
    if (total <= 1) {
        return [];
    }

    const delta = Math.max(1, Math.min(4, Math.floor(opts.delta ?? 2)));
    let current = Math.floor(Number(currentPage) || 1);
    if (current < 1) {
        current = 1;
    }
    if (current > total) {
        current = total;
    }

    const pages = new Set();
    pages.add(1);
    pages.add(total);
    for (let i = current - delta; i <= current + delta; i++) {
        if (i >= 1 && i <= total) {
            pages.add(i);
        }
    }

    const sorted = [...pages].sort((a, b) => a - b);
    /** @type {Array<{ type: 'page', page: number } | { type: 'ellipsis', key: string }>} */
    const out = [];
    let prev = 0;
    let ellipsisSeq = 0;
    for (const p of sorted) {
        if (prev > 0 && p - prev > 1) {
            ellipsisSeq += 1;
            out.push({ type: 'ellipsis', key: `e-${ellipsisSeq}-${prev}-${p}` });
        }
        out.push({ type: 'page', page: p });
        prev = p;
    }

    return out;
}
