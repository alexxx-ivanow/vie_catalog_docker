import { createApp } from 'vue';
import SiteHeaderNav from './components/header/SiteHeaderNav.js';

const mountEl = document.getElementById('app-site-header');
if (mountEl) {
    createApp(SiteHeaderNav).mount(mountEl);
}
