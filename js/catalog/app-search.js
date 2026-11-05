import { createApp } from 'vue';
import SearchResultsPage from './components/catalog/SearchResultsPage.js';

const mountEl = document.getElementById('app-catalog');
if (mountEl) {
    createApp(SearchResultsPage).mount(mountEl);
}
