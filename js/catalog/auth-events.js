export const AUTH_STATE_EVENT = 'catalog:auth-state';

export function dispatchAuthState(user) {
    window.dispatchEvent(new CustomEvent(AUTH_STATE_EVENT, { detail: { user } }));
}

export function guestUser() {
    return {
        authorized: false,
        id: 0,
        name: '',
        lastName: '',
        email: '',
        login: '',
    };
}

export function readInitialUser() {
    const bx = window.__BX__ || {};
    const userId = Number(bx.userId) || 0;
    const isAuthorized = bx.isAuthorized === true && userId > 0;
    if (!isAuthorized) {
        return guestUser();
    }
    const fullName = typeof bx.userName === 'string' ? bx.userName.trim() : '';
    const nameParts = fullName.split(/\s+/).filter(Boolean);
    return {
        authorized: true,
        id: userId,
        name: nameParts[0] || '',
        lastName: nameParts.slice(1).join(' '),
        email: '',
        login: '',
    };
}
