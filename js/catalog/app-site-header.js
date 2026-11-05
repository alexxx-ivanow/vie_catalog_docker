import { createApp } from 'vue';
import SiteHeaderNav from './components/header/SiteHeaderNav.js';
import SiteHeaderSearch from './components/header/SiteHeaderSearch.js';

const searchEl = document.getElementById('app-site-search');
if (searchEl) {
    createApp(SiteHeaderSearch).mount(searchEl);
}

const mountEl = document.getElementById('app-site-header');
if (mountEl) {
    createApp(SiteHeaderNav).mount(mountEl);
}
