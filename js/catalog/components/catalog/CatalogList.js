import SectionGrid from './SectionGrid.js';

export default {
    name: 'CatalogList',
    components: { SectionGrid },
    template: `
        <div class="catalog-page">
            <h1 class="catalog-page__title">Каталог</h1>
            <section class="catalog-page__content">
                <SectionGrid />
            </section>
        </div>
    `,
};
