import { createApp } from 'vue';
import PersonalOrdersPage from './components/personal/PersonalOrdersPage.js';

const mountEl = document.getElementById('app-catalog');
if (mountEl) {
    createApp(PersonalOrdersPage).mount(mountEl);
}
