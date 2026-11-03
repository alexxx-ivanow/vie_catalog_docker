import { createApp } from 'vue';
import AuthConfirmPage from './components/auth/AuthConfirmPage.js';

const mountEl = document.getElementById('app-catalog');
if (mountEl) {
    createApp(AuthConfirmPage).mount(mountEl);
}
