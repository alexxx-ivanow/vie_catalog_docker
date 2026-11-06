import ProductCard from './ProductCard.js';
import CatalogSortSelect from './CatalogSortSelect.js';
import { catalogApiUrl, getReadHeaders } from '../../api-client.js';
import { smoothScrollToElement } from '../../scroll-with-sticky-header.js';
import { buildPaginationPageList } from '../../pagination-pages.js';

const DEFAULT_LIMIT = 12;

function readUrlState() {
    const params = new URLSearchParams(window.location.search);
    const page = Math.max(1, Number(params.get('page')) || 1);
    let limit = Number(params.get('limit')) || DEFAULT_LIMIT;
    if (!Number.isFinite(limit) || limit <= 0) {
        limit = DEFAULT_LIMIT;
    }
    limit = Math.max(1, Math.min(48, limit));

    return {
        query: (params.get('q') || '').trim(),
        page,
        limit,
        sort: (params.get('sort') || 'default').trim() || 'default',
    };
}

function readInitialFromPage(urlState) {
    const data = window.__PAGE_DATA__?.search;
    if (window.__PAGE_DATA__?.page?.type !== 'search' || !data || typeof data !== 'object') {
        return null;
    }

    return {
        query: String(data.query ?? urlState.query),
        total: Number(data.total || 0),
        page: Number(data.page || urlState.page),
        pages: Number(data.pages || 1),
        limit: Number(data.limit || urlState.limit),
        items: Array.isArray(data.items) ? data.items : [],
        indexReady: data.index_ready !== false,
        message: String(data.message || ''),
        suggestions: Array.isArray(data.suggestions) ? data.suggestions : [],
        searchMode: String(data.searchMode || 'strict'),
        sort: urlState.sort,
    };
}

export default {
    name: 'SearchResultsPage',
    components: { ProductCard, CatalogSortSelect },
    computed: {
        paginationItems() {
            return buildPaginationPageList(this.page, this.pages);
        },
    },
    data() {
        const urlState = readUrlState();
        const initial = readInitialFromPage(urlState);

        return {
            queryInput: initial ? initial.query : urlState.query,
            query: initial ? initial.query : urlState.query,
            total: initial ? initial.total : 0,
            page: initial ? initial.page : urlState.page,
            pages: initial ? initial.pages : 1,
            limit: initial ? initial.limit : urlState.limit,
            items: initial ? initial.items : [],
            sort: initial ? initial.sort : urlState.sort,
            loading: !initial,
            loadError: '',
            indexReady: initial ? initial.indexReady : true,
            unavailableMessage: initial ? initial.message : '',
            suggestions: initial ? initial.suggestions : [],
            searchMode: initial ? initial.searchMode : 'strict',
            paginationLoading: false,
            hydratedFromPage: Boolean(initial),
        };
    },
    async mounted() {
        if (this.hydratedFromPage) {
            this.syncUrl(false);
            return;
        }
        await this.fetchResults(false);
    },
    methods: {
        buildApiParams(page) {
            const params = new URLSearchParams();
            if (this.query) {
                params.set('q', this.query);
            }
            params.set('page', String(page));
            params.set('limit', String(this.limit));
            if (this.sort && this.sort !== 'default') {
                params.set('sort', this.sort);
            }

            return params;
        },
        syncUrl(replace) {
            const params = this.buildApiParams(this.page);
            const url = '/catalog/search/?' + params.toString();
            if (replace) {
                window.history.replaceState({}, '', url);
            } else {
                window.history.pushState({}, '', url);
            }
        },
        async fetchResults(scrollAfter) {
            this.loading = true;
            this.loadError = '';
            try {
                const params = this.buildApiParams(this.page);
                const res = await fetch(catalogApiUrl('catalog/search') + '?' + params.toString(), {
                    headers: getReadHeaders(),
                });
                const data = await res.json();
                if (!res.ok) {
                    throw new Error(data?.error || 'Ошибка поиска');
                }

                this.query = String(data.query || this.queryInput.trim());
                this.total = Number(data.total || 0);
                this.page = Number(data.page || this.page);
                this.pages = Number(data.pages || 1);
                this.limit = Number(data.limit || this.limit);
                this.items = Array.isArray(data.items) ? data.items : [];
                this.indexReady = data.index_ready !== false;
                this.unavailableMessage = String(data.message || '');
                this.suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
                this.searchMode = String(data.searchMode || 'strict');
                this.syncUrl(true);

                if (scrollAfter) {
                    this.$nextTick(() => {
                        const content = this.$el?.querySelector('.catalog-page__content');
                        smoothScrollToElement(content);
                    });
                }
            } catch (error) {
                this.loadError = error?.message || 'Не удалось выполнить поиск';
            } finally {
                this.loading = false;
            }
        },
        async onSearchSubmit() {
            this.query = this.queryInput.trim();
            this.page = 1;
            await this.fetchResults(false);
        },
        async onSortChange(nextSort) {
            this.sort = nextSort;
            this.page = 1;
            await this.fetchResults(false);
        },
        suggestionHref(text) {
            const params = new URLSearchParams();
            params.set('q', text);
            params.set('page', '1');
            params.set('limit', String(this.limit));
            return '/catalog/search/?' + params.toString();
        },
        async goToPage(page) {
            if (this.paginationLoading || page < 1 || page > this.pages || page === this.page) {
                return;
            }
            this.paginationLoading = true;
            this.page = page;
            try {
                await this.fetchResults(true);
            } finally {
                this.paginationLoading = false;
            }
        },
    },
    template: `
        <div class="catalog-page catalog-page--search">
            <h1 class="catalog-page__title">Поиск по каталогу</h1>

            <form class="catalog-search-form" @submit.prevent="onSearchSubmit">
                <input
                    v-model="queryInput"
                    type="search"
                    class="catalog-search-form__input"
                    name="q"
                    placeholder="Что ищем?"
                    autocomplete="off"
                >
                <button type="submit" class="catalog-search-form__submit" :disabled="loading">Найти</button>
            </form>

            <div v-if="!indexReady" class="catalog-empty catalog-empty--info">
                {{ unavailableMessage || 'Поиск временно недоступен' }}
            </div>

            <section v-else class="catalog-page__content">
                <div v-if="searchMode === 'fuzzy'" class="catalog-search-hint">
                    Показаны похожие результаты
                </div>

                <div v-if="suggestions.length" class="catalog-search-suggestions">
                    <span class="catalog-search-suggestions__label">Возможно, вы имели в виду:</span>
                    <a
                        v-for="(item, index) in suggestions"
                        :key="'sg-' + index"
                        :href="suggestionHref(item)"
                        class="catalog-search-suggestions__link"
                    >{{ item }}</a>
                </div>

                <div class="catalog-page__toolbar">
                    <span v-if="loading" class="catalog-skeleton-line catalog-skeleton-line--toolbar" aria-hidden="true"></span>
                    <span v-else class="catalog-page__toolbar-count">
                        <template v-if="query">Найдено: {{ total }}</template>
                        <template v-else>Введите запрос</template>
                    </span>
                    <CatalogSortSelect
                        :model-value="sort"
                        :disabled="loading || !query"
                        @update:model-value="onSortChange"
                    />
                </div>

                <div v-if="loading" class="catalog_list" aria-busy="true">
                    <div v-for="n in 8" :key="'sk-' + n" class="catalog_item catalog-skeleton-card" aria-hidden="true">
                        <div class="catalog-skeleton-card__image"></div>
                        <div class="catalog-skeleton-card__line catalog-skeleton-card__line--title"></div>
                        <div class="catalog-skeleton-card__line catalog-skeleton-card__line--text"></div>
                    </div>
                </div>
                <template v-else>
                    <div v-if="loadError" class="catalog-empty">{{ loadError }}</div>
                    <div v-else-if="unavailableMessage && query" class="catalog-empty">{{ unavailableMessage }}</div>
                    <div v-else-if="items.length" class="catalog_list">
                        <ProductCard v-for="product in items" :key="product.ID" :product="product" />
                    </div>
                    <div v-else-if="query" class="catalog-empty">Ничего не найдено</div>
                </template>

                <div v-if="!loading && pages > 1" class="catalog-pagination">
                    <button
                        class="catalog-pagination__btn"
                        :disabled="page === 1 || paginationLoading"
                        @click="goToPage(page - 1)"
                    >←</button>
                    <template v-for="item in paginationItems" :key="item.type === 'page' ? 'p-' + item.page : item.key">
                        <span
                            v-if="item.type === 'ellipsis'"
                            class="catalog-pagination__dots"
                            aria-hidden="true"
                        >...</span>
                        <button
                            v-else
                            class="catalog-pagination__btn"
                            :class="{ 'catalog-pagination__btn--active': item.page === page }"
                            :disabled="paginationLoading"
                            @click="goToPage(item.page)"
                        >{{ item.page }}</button>
                    </template>
                    <button
                        class="catalog-pagination__btn"
                        :disabled="page === pages || paginationLoading"
                        @click="goToPage(page + 1)"
                    >→</button>
                </div>
            </section>
        </div>
    `,
};
