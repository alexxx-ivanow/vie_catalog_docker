// /local/templates/main/js/catalog/app-catalog.js
import { createApp } from 'vue';
import ProductDetail from './components/catalog/ProductDetail.js';

const mountEl = document.getElementById('app-catalog');
if (mountEl) {
    createApp(ProductDetail).mount(mountEl);
}