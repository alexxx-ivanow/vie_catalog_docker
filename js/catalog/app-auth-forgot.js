import { createApp } from 'vue';
import AuthForgotPage from './components/auth/AuthForgotPage.js';

const mountEl = document.getElementById('app-catalog');
if (mountEl) {
    createApp(AuthForgotPage).mount(mountEl);
}
