import { createApp } from 'vue';
import AuthRegisterPage from './components/auth/AuthRegisterPage.js';

const mountEl = document.getElementById('app-catalog');
if (mountEl) {
    createApp(AuthRegisterPage).mount(mountEl);
}
