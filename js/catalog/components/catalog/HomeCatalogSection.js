import SectionGrid from './SectionGrid.js';

export default {
    name: 'HomeCatalogSection',
    components: { SectionGrid },
    template: `
        <SectionGrid :show-toolbar="false" loading-label="Загрузка категорий" />
    `,
};
