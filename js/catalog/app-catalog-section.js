import { createApp } from 'vue';
import SectionList from './components/catalog/SectionList.js';

const mountEl = document.getElementById('app-catalog');
if (mountEl) {
    createApp(SectionList).mount(mountEl);
}
