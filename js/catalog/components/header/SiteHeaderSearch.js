import SearchModal from './SearchModal.js';

export default {
    name: 'SiteHeaderSearch',
    components: { SearchModal },
    data() {
        return {
            searchOpen: false,
        };
    },
    methods: {
        openSearch() {
            this.searchOpen = true;
        },
        closeSearch() {
            this.searchOpen = false;
        },
    },
    template: `
        <div class="site-header-search">
            <button
                type="button"
                class="site-nav__link site-nav__link--button site-nav__search"
                aria-label="Поиск"
                @click="openSearch"
            >
                <svg class="site-nav__search-icon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/>
                    <path d="M16.5 16.5L21 21" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
            <SearchModal :open="searchOpen" @close="closeSearch" />
        </div>
    `,
};
