import { createApp } from 'vue';
import CartPage from './components/cart/CartPage.js';

const mountEl = document.getElementById('app-catalog');
if (mountEl) {
    createApp(CartPage).mount(mountEl);
}
