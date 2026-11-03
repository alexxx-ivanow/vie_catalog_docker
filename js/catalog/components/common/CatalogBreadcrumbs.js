import { catalogApiUrl, getReadHeaders } from '../../api-client.js';

export default {
    name: 'CatalogBreadcrumbs',
    data() {
        return {
            items: [],
            loading: true,
        };
    },
    mounted() {
        this.loadBreadcrumbs();
    },
    methods: {
        async loadBreadcrumbs() {
            this.loading = true;
            try {
                const page = window.__PAGE_DATA__?.page || {};
                const sectionId = Number(
                    page.section_id ||
                    window.__PAGE_DATA__?.section?.id ||
                    window.__PAGE_DATA__?.product?.section_id ||
                    0
                );
                const productId = Number(window.__PAGE_DATA__?.product?.data?.ID || 0);
                const pageType = String(page.type || 'catalog');

                const params = new URLSearchParams({
                    pageType,
                    section: String(sectionId),
                    product: String(productId),
                });

                const res = await fetch(catalogApiUrl('catalog/breadcrumbs') + '?' + params.toString(), {
                    headers: getReadHeaders(),
                });
                const data = await res.json();
                this.items = Array.isArray(data.items) ? data.items : [];
            } catch (error) {
                console.error('Ошибка загрузки хлебных крошек:', error);
                this.items = [];
            } finally {
                this.loading = false;
            }
        },
    },
    template: `
        <div class="breadcrumbs-wrap">
            <div v-if="loading" class="breadcrumbs-placeholder" aria-hidden="true">
                <span class="breadcrumbs-placeholder__item"></span>
                <span class="breadcrumbs-placeholder__sep">/</span>
                <span class="breadcrumbs-placeholder__item"></span>
                <span class="breadcrumbs-placeholder__sep">/</span>
                <span class="breadcrumbs-placeholder__item breadcrumbs-placeholder__item--short"></span>
            </div>
            <nav v-if="!loading && items.length" class="breadcrumbs" aria-label="Хлебные крошки">
                <a
                    v-for="(item, index) in items"
                    :key="item.title + '-' + index"
                    :href="item.url || null"
                    class="breadcrumbs__item"
                    :class="{ 'breadcrumbs__item--current': !item.url }"
                    :aria-current="!item.url ? 'page' : null"
                >
                    {{ item.title }}
                </a>
            </nav>
        </div>
    `,
};
