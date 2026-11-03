import { createApp } from 'vue';
import PersonalProfilePage from './components/personal/PersonalProfilePage.js';

const mountEl = document.getElementById('app-catalog');
if (mountEl) {
    createApp(PersonalProfilePage).mount(mountEl);
}
