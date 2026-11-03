const STICKY_HEADER_SELECTOR = '.site-header';
const DEFAULT_EXTRA_OFFSET = 16;

export function getStickyHeaderHeight() {
    const header = document.querySelector(STICKY_HEADER_SELECTOR);
    if (!header) {
        return 0;
    }
    return header.getBoundingClientRect().height;
}

export function getScrollTopForElement(element, extraOffset = DEFAULT_EXTRA_OFFSET) {
    if (!element) {
        return 0;
    }
    const top = element.getBoundingClientRect().top
        + window.scrollY
        - getStickyHeaderHeight()
        - extraOffset;
    return Math.max(0, top);
}

export function smoothScrollToElement(element, { behavior = 'smooth', extraOffset = DEFAULT_EXTRA_OFFSET } = {}) {
    if (!element) {
        return;
    }
    window.scrollTo({
        top: getScrollTopForElement(element, extraOffset),
        behavior,
    });
}
