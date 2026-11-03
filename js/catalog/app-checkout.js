import { createApp } from 'vue';
import CheckoutPage from './components/checkout/CheckoutPage.js';

const mountEl = document.getElementById('app-catalog');
if (mountEl) {
    createApp(CheckoutPage).mount(mountEl);
}
