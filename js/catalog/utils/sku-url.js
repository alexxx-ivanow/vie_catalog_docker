export function readUidFromLocation() {
    const params = new URLSearchParams(window.location.search);
    const raw = params.get('uid');
    if (raw === null || raw === '') {
        return null;
    }
    const id = parseInt(raw, 10);
    return Number.isFinite(id) && id > 0 ? id : null;
}

export function writeUidToLocation(offerId, { replace = true } = {}) {
    const id = parseInt(offerId, 10);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }
    const url = new URL(window.location.href);
    url.searchParams.set('uid', String(id));
    const method = replace ? 'replaceState' : 'pushState';
    window.history[method](null, '', url.toString());
}

export function clearUidFromLocation() {
    const url = new URL(window.location.href);
    if (!url.searchParams.has('uid')) {
        return;
    }
    url.searchParams.delete('uid');
    window.history.replaceState(null, '', url.toString());
}

export function syncUidWithActiveOffer(requestedUid, activeOfferId) {
    const active = parseInt(activeOfferId, 10);
    if (!Number.isFinite(active) || active <= 0) {
        clearUidFromLocation();
        return;
    }
    if (requestedUid !== null && requestedUid === active) {
        return;
    }
    writeUidToLocation(active, { replace: true });
}
