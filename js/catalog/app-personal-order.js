import { createApp } from 'vue';
import PersonalOrderDetailPage from './components/personal/PersonalOrderDetailPage.js';

const mountEl = document.getElementById('app-catalog');
if (mountEl) {
    createApp(PersonalOrderDetailPage).mount(mountEl);
}
