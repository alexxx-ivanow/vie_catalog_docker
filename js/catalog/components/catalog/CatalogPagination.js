import { catalogApiUrl, getReadHeaders } from '../../api-client.js';
import { appendCatalogQuery } from '../../filter-query.js';
import { buildPaginationPageList } from '../../pagination-pages.js';

export default {
    name: 'CatalogPagination',

    props: {
        currentPage: { type: Number, required: true },
        totalPages: { type: Number, required: true },
        limit: { type: Number, default: 20 },
        sectionId: { type: Number, required: true },
        requestParams: { type: Object, default: () => ({}) },
    },

    emits: ['page-data'],
    computed: {
        paginationItems() {
            return buildPaginationPageList(this.currentPage, this.totalPages);
        },
    },
    data() {
        return {
            loading: false,
        };
    },
    methods: {
        async go(page) {
            if (this.loading) return;
            if (page < 1 || page > this.totalPages) return;
            if (page === this.currentPage) return;

            this.loading = true;
            try {
                const params = new URLSearchParams({
                    section: String(this.sectionId),
                    page: String(page),
                    limit: String(this.limit),
                });
                appendCatalogQuery(params, this.requestParams);

                const res = await fetch(catalogApiUrl('catalog/goods') + '?' + params.toString(), {
                    headers: getReadHeaders(),
                });
                const data = await res.json();
                if (!res.ok) {
                    throw new Error(data?.error || 'Ошибка загрузки страницы');
                }

                this.$emit('page-data', data);
            } catch (error) {
                console.error('Ошибка ajax-пагинации: ', error);
            } finally {
                this.loading = false;
            }
        },
    },
    template: `
        <div v-if="totalPages > 1">
            <div v-if="loading" class="catalog-pagination-placeholder" aria-hidden="true">
                <span class="catalog-pagination-placeholder__btn"></span>
                <span class="catalog-pagination-placeholder__btn"></span>
                <span class="catalog-pagination-placeholder__btn"></span>
                <span class="catalog-pagination-placeholder__dots">...</span>
                <span class="catalog-pagination-placeholder__btn"></span>
            </div>
            <div v-else class="catalog-pagination">
                <button
                    class="catalog-pagination__btn"
                    :disabled="currentPage === 1"
                    @click="go(currentPage - 1)"
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
                        :class="{ 'catalog-pagination__btn--active': item.page === currentPage }"
                        @click="go(item.page)"
                    >{{ item.page }}</button>
                </template>

                <button
                    class="catalog-pagination__btn"
                    :disabled="currentPage === totalPages"
                    @click="go(currentPage + 1)"
                >→</button>
            </div>
        </div>
    `,
};
