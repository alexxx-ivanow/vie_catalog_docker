import { apiGet, apiPost, catalogApiUrl } from './api-client.js';

export async function fetchAccountSession() {
    const { res, data } = await apiGet(catalogApiUrl('account/session'), { cache: 'no-store' });
    return { res, data };
}

export async function accountLogin(login, password, remember = true) {
    return apiPost(catalogApiUrl('account/login'), { login, password, remember });
}

export async function accountLogout() {
    return apiPost(catalogApiUrl('account/logout'), {});
}

export async function fetchAccountProfile() {
    return apiGet(catalogApiUrl('account/profile'));
}

export async function updateAccountProfile(payload) {
    return apiPost(catalogApiUrl('account/profile'), payload);
}

export async function accountRegister(payload) {
    return apiPost(catalogApiUrl('account/register'), payload);
}

export async function accountForgotPassword(email) {
    return apiPost(catalogApiUrl('account/forgot-password'), { email });
}

export async function accountChangePassword(payload) {
    return apiPost(catalogApiUrl('account/change-password'), payload);
}

export async function fetchAccountOrders(page = 1, limit = 10) {
    const url = `${catalogApiUrl('account/orders')}?page=${page}&limit=${limit}`;
    return apiGet(url);
}

export async function fetchAccountOrder(orderId) {
    return apiGet(catalogApiUrl(`account/orders/${orderId}`));
}
