import { createApp } from 'vue';
import OrderSuccessPage from './components/checkout/OrderSuccessPage.js';

const mountEl = document.getElementById('app-catalog');
if (mountEl) {
    createApp(OrderSuccessPage).mount(mountEl);
}
