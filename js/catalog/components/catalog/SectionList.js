import ProductCard from './ProductCard.js';
import CatalogPagination from './CatalogPagination.js';
import CatalogFilter from '../filter/CatalogFilter.js';
import { catalogApiUrl, getReadHeaders } from '../../api-client.js';
import { clampFilterPrices, normalizePriceBounds } from '../../filter-price-bounds.js';
import { appendCatalogQuery, readPropertiesFromSearch, readSortFromSearch } from '../../filter-query.js';
import CatalogSortSelect from './CatalogSortSelect.js';
import { smoothScrollToElement } from '../../scroll-with-sticky-header.js';

const SKELETON_PRODUCT_COUNT = 8;
const DEFAULT_LIMIT = 12;

function readInitialStateFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const page = Math.max(1, Number(params.get('page')) || 1);
    let limit = Number(params.get('limit')) || DEFAULT_LIMIT;
    if (!Number.isFinite(limit) || limit <= 0) {
        limit = DEFAULT_LIMIT;
    }
    limit = Math.max(1, Math.min(48, limit));

    const priceFromRaw = params.get('price_from');
    const priceToRaw = params.get('price_to');

    return {
        page,
        limit,
        sort: readSortFromSearch(params),
        filter: {
            properties: readPropertiesFromSearch(params),
            price_from: priceFromRaw !== null && priceFromRaw !== '' ? Number(priceFromRaw) : 0,
            price_to: priceToRaw !== null && priceToRaw !== '' ? Number(priceToRaw) : 0,
        },
    };
}

function normalizeFilterMeta(filterMeta) {
    const source = filterMeta && typeof filterMeta === 'object' && !Array.isArray(filterMeta)
        ? filterMeta
        : {};
    return {
        enabled: source.enabled !== false,
        properties: Array.isArray(source.properties) ? source.properties : [],
        price: normalizePriceBounds(source),
    };
}

function readInitialSectionFromPage(urlState) {
    const pageType = String(window.__PAGE_DATA__?.page?.type || '');
    const section = window.__PAGE_DATA__?.section;
    const goods = section?.data;
    const filterMeta = section?.filter;

    if (
        pageType !== 'section'
        || !goods
        || !Array.isArray(goods.items)
        || !filterMeta
        || typeof filterMeta !== 'object'
        || Array.isArray(filterMeta)
    ) {
        return null;
    }

    return {
        total: Number(goods.total || 0),
        limit: Number(goods.limit || urlState.limit || DEFAULT_LIMIT),
        page: Number(goods.page || urlState.page || 1),
        pages: Number(goods.pages || 1),
        sectionId: Number(section.id || 0),
        items: goods,
        sectionName: goods.sectionName || '',
        subsections: Array.isArray(goods.subsections) ? goods.subsections : [],
        filterMeta: normalizeFilterMeta(filterMeta),
        filter: clampFilterPrices(urlState.filter, filterMeta),
    };
}

export default {
    name: 'SectionList',
    components: { ProductCard, CatalogPagination, CatalogFilter, CatalogSortSelect },
    data() {
        const urlState = readInitialStateFromUrl();
        const initial = readInitialSectionFromPage(urlState);

        return {
            store: {
                total: initial ? initial.total : 0,
                limit: initial ? initial.limit : urlState.limit,
                page: initial ? initial.page : urlState.page,
                pages: initial ? initial.pages : 1,
                sectionId: initial
                    ? initial.sectionId
                    : Number(window.__PAGE_DATA__?.section?.id || 0),
                items: initial ? initial.items : { items: [] },
                sectionName: initial ? initial.sectionName : '',
                subsections: initial ? initial.subsections : [],
                loading: !initial,
                filtersLoaded: Boolean(initial),
                filterEnabled: initial
                    ? initial.filterMeta.enabled !== false
                    : window.__PAGE_DATA__?.section?.filter?.enabled !== false,
                loadError: '',
                filterMeta: initial
                    ? initial.filterMeta
                    : normalizeFilterMeta(null),
                filter: initial ? initial.filter : urlState.filter,
                sort: urlState.sort,
            },
            skeletonProductCount: SKELETON_PRODUCT_COUNT,
            isInitialLoad: !initial,
            hydratedFromPage: Boolean(initial),
        };
    },
    async mounted() {
        if (!this.store.filterEnabled) {
            this.clearHiddenFilterQuery();
        }
        if (this.hydratedFromPage) {
            this.isInitialLoad = false;
            return;
        }
        await Promise.all([
            this.loadFilters(),
            this.loadGoods({ page: this.store.page, resetPage: false }),
        ]);
        this.isInitialLoad = false;
    },
    methods: {
        clearHiddenFilterQuery() {
            const nextUrl = new URL(window.location.href);
            let changed = false;
            ['price_from', 'price_to'].forEach((key) => {
                if (nextUrl.searchParams.has(key)) {
                    nextUrl.searchParams.delete(key);
                    changed = true;
                }
            });
            [...nextUrl.searchParams.keys()].forEach((key) => {
                if (key.startsWith('props[')) {
                    nextUrl.searchParams.delete(key);
                    changed = true;
                }
            });
            if (changed) {
                window.history.replaceState({}, '', nextUrl.toString());
            }
        },
        buildFilterParams() {
            if (!this.store.filterEnabled) {
                return {};
            }

            const params = {};
            const properties = this.store.filter.properties || {};
            const props = {};
            Object.entries(properties).forEach(([code, values]) => {
                if (Array.isArray(values) && values.length) {
                    props[code] = values;
                }
            });
            if (Object.keys(props).length) {
                params.props = props;
            }

            const { min: minPrice, max: maxPrice } = normalizePriceBounds(this.store.filterMeta);
            if (Number(this.store.filter.price_from) > minPrice) {
                params.price_from = this.store.filter.price_from;
            }
            if (Number(this.store.filter.price_to) < maxPrice) {
                params.price_to = this.store.filter.price_to;
            }

            return params;
        },
        buildGoodsRequestParams() {
            const params = this.buildFilterParams();
            if (this.store.sort && this.store.sort !== 'default') {
                params.sort = this.store.sort;
            }
            return params;
        },
        async loadFilters() {
            try {
                const params = new URLSearchParams({
                    section: String(this.store.sectionId),
                });
                const res = await fetch(catalogApiUrl('catalog/filters') + '?' + params.toString(), {
                    headers: getReadHeaders(),
                });
                const data = await res.json();
                if (!res.ok) {
                    throw new Error(data?.error || 'Ошибка загрузки фильтров');
                }

                this.store.filterMeta = normalizeFilterMeta(data);
                this.store.filterEnabled = this.store.filterMeta.enabled !== false;
                this.store.filter = clampFilterPrices(this.store.filter, this.store.filterMeta);
            } catch (error) {
                console.error('Ошибка загрузки фильтров: ', error);
            } finally {
                this.store.filtersLoaded = true;
            }
        },
        async loadGoods({ page = 1, resetPage = false } = {}) {
            this.store.loading = true;
            this.store.loadError = '';

            try {
                const params = new URLSearchParams();
                const requestParams = {
                    ...this.buildGoodsRequestParams(),
                    section: this.store.sectionId,
                    page: resetPage ? 1 : page,
                    limit: this.store.limit,
                };
                appendCatalogQuery(params, requestParams);

                const res = await fetch(catalogApiUrl('catalog/goods') + '?' + params.toString(), {
                    headers: getReadHeaders(),
                });
                const data = await res.json();
                if (!res.ok) {
                    throw new Error(data?.error || 'Ошибка загрузки списка');
                }

                this.onPageData(data);
            } catch (error) {
                console.error('Ошибка загрузки списка: ', error);
                this.store.loadError = 'Не удалось загрузить товары';
                this.store.items = { items: [] };
                this.store.subsections = [];
            } finally {
                this.store.loading = false;
            }
        },
        async applyFilter() {
            await this.loadGoods({ page: 1, resetPage: true });
            const drawer = this.$el?.querySelector('.catalog-filter-drawer__state');
            if (drawer) {
                drawer.checked = false;
            }
        },
        async onSortChange(sort) {
            if (this.store.sort === sort) {
                return;
            }
            this.store.sort = sort;
            await this.loadGoods({ page: 1, resetPage: true });
        },
        onPageData(data) {
            this.store.items = data;
            this.store.total = Number(data.total || 0);
            this.store.page = Number(data.page || 1);
            this.store.pages = Number(data.pages || 1);
            this.store.limit = Number(data.limit || this.store.limit);
            this.store.sectionName = data.sectionName || this.store.sectionName;
            this.store.subsections = Array.isArray(data.subsections) ? data.subsections : this.store.subsections;

            const nextUrl = new URL(window.location.href);
            if (this.store.page <= 1) {
                nextUrl.searchParams.delete('page');
            } else {
                nextUrl.searchParams.set('page', String(this.store.page));
            }
            if (!this.store.sort || this.store.sort === 'default') {
                nextUrl.searchParams.delete('sort');
            } else {
                nextUrl.searchParams.set('sort', this.store.sort);
            }
            nextUrl.searchParams.delete('price_from');
            nextUrl.searchParams.delete('price_to');
            [...nextUrl.searchParams.keys()].forEach((key) => {
                if (key.startsWith('props[')) {
                    nextUrl.searchParams.delete(key);
                }
            });
            if (this.store.filterEnabled) {
                const properties = this.store.filter.properties || {};
                Object.entries(properties).forEach(([code, values]) => {
                    if (!Array.isArray(values)) {
                        return;
                    }
                    values.forEach((value) => {
                        if (value !== null && value !== undefined && value !== '') {
                            nextUrl.searchParams.append(`props[${code}][]`, String(value));
                        }
                    });
                });

                const { min: minPrice, max: maxPrice } = normalizePriceBounds(this.store.filterMeta);
                if (Number(this.store.filter.price_from) > minPrice) {
                    nextUrl.searchParams.set('price_from', String(this.store.filter.price_from));
                }
                if (Number(this.store.filter.price_to) < maxPrice) {
                    nextUrl.searchParams.set('price_to', String(this.store.filter.price_to));
                }
            }
            window.history.replaceState({}, '', nextUrl.toString());
            if (!this.isInitialLoad) {
                this.$nextTick(() => {
                    const content = this.$el?.querySelector('.catalog-layout__content');
                    smoothScrollToElement(content);
                });
            }
        },
    },
    template: `
        <div class="catalog-page">
            <h1 class="catalog-page__title">
                <span v-if="store.loading && !store.sectionName" class="catalog-skeleton-line catalog-skeleton-line--title" aria-hidden="true"></span>
                <template v-else>{{ store.sectionName }}</template>
            </h1>
            <template v-if="store.filterEnabled">
                <input
                    id="catalog-filter-drawer"
                    type="checkbox"
                    class="catalog-filter-drawer__state"
                >
                <div class="catalog-filter-drawer__backdrop" aria-hidden="true">
                    <label class="catalog-filter-drawer__backdrop-hit" for="catalog-filter-drawer"></label>
                </div>
            </template>
            <div class="catalog-layout">
                <aside v-if="store.filterEnabled" class="catalog-layout__sidebar">
                    <label
                        for="catalog-filter-drawer"
                        class="catalog-filter-drawer__close"
                        aria-label="Закрыть фильтр"
                    >×</label>
                    <div v-if="!store.filtersLoaded" class="catalog-filter-skeleton" aria-hidden="true">
                        <div class="catalog-skeleton-line catalog-skeleton-line--block"></div>
                        <div class="catalog-skeleton-line catalog-skeleton-line--block"></div>
                        <div class="catalog-skeleton-line catalog-skeleton-line--block catalog-skeleton-line--short"></div>
                    </div>
                    <CatalogFilter
                        v-else
                        v-model="store.filter"
                        :meta="store.filterMeta"
                        :applying="store.loading"
                        @apply="applyFilter"
                    />
                </aside>

                <section class="catalog-layout__content">
                    <div v-if="store.loading && !store.subsections.length" class="section-subcategories section-subcategories--skeleton" aria-hidden="true">
                        <span class="section-subcategories__skeleton-item"></span>
                        <span class="section-subcategories__skeleton-item"></span>
                        <span class="section-subcategories__skeleton-item"></span>
                        <span class="section-subcategories__skeleton-item"></span>
                    </div>
                    <div v-else-if="store.subsections.length" class="section-subcategories">
                        <a
                            v-for="sub in store.subsections"
                            :key="sub.ID"
                            :href="sub.URL || ('/catalog/section/' + sub.ID)"
                            class="section-subcategories__item"
                        >
                            {{ sub.NAME }}
                        </a>
                    </div>

                    <div class="catalog-page__toolbar">
                        <label
                            v-if="store.filterEnabled"
                            for="catalog-filter-drawer"
                            class="catalog-filter-drawer__open"
                        >Фильтр</label>
                        <span v-if="store.loading" class="catalog-skeleton-line catalog-skeleton-line--toolbar" aria-hidden="true"></span>
                        <span v-else class="catalog-page__toolbar-count">Найдено: {{ store.total }}</span>
                        <CatalogSortSelect
                            :model-value="store.sort"
                            :disabled="store.loading"
                            @update:model-value="onSortChange"
                        />
                    </div>

                    <div v-if="store.loading" class="catalog_list" aria-busy="true" aria-label="Загрузка товаров">
                        <div
                            v-for="n in skeletonProductCount"
                            :key="'psk-' + n"
                            class="catalog_item catalog-skeleton-card"
                            aria-hidden="true"
                        >
                            <div class="catalog-skeleton-card__image"></div>
                            <div class="catalog-skeleton-card__line catalog-skeleton-card__line--title"></div>
                            <div class="catalog-skeleton-card__line catalog-skeleton-card__line--text"></div>
                            <div class="catalog-skeleton-card__line catalog-skeleton-card__line--price"></div>
                            <div class="catalog-skeleton-card__btn"></div>
                        </div>
                    </div>
                    <template v-else>
                        <div v-if="store.loadError" class="catalog-empty">{{ store.loadError }}</div>
                        <div v-else-if="store.items.items && store.items.items.length" class="catalog_list">
                            <ProductCard v-for="product in store.items.items" :key="product.ID" :product="product" />
                        </div>
                        <div v-else class="catalog-empty">Товары не найдены</div>
                    </template>

                    <div v-if="store.loading" class="catalog-pagination-placeholder" aria-hidden="true">
                        <span class="catalog-pagination-placeholder__btn"></span>
                        <span class="catalog-pagination-placeholder__btn"></span>
                        <span class="catalog-pagination-placeholder__btn"></span>
                        <span class="catalog-pagination-placeholder__dots">...</span>
                        <span class="catalog-pagination-placeholder__btn"></span>
                    </div>
                    <CatalogPagination
                        v-else
                        :current-page="store.page"
                        :total-pages="store.pages"
                        :limit="store.limit"
                        :section-id="store.sectionId"
                        :request-params="buildGoodsRequestParams()"
                        @page-data="onPageData"
                    />
                </section>
            </div>
        </div>
    `,
};
