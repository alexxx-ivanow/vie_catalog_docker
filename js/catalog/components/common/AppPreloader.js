export default {
    name: 'AppPreloader',
    props: {
        show: { type: Boolean, default: false },
        text: { type: String, default: 'Загрузка...' },
        centerPage: { type: Boolean, default: false },
    },
    template: `
        <div
            v-if="show"
            class="app-preloader"
            :class="{ 'app-preloader--page': centerPage }"
            role="status"
            aria-live="polite"
        >
            <svg class="app-preloader__spinner" viewBox="0 0 50 50" aria-hidden="true">
                <circle class="app-preloader__track" cx="25" cy="25" r="20"></circle>
                <circle class="app-preloader__stroke" cx="25" cy="25" r="20"></circle>
            </svg>
            <span class="app-preloader__text">{{ text }}</span>
        </div>
    `,
};
