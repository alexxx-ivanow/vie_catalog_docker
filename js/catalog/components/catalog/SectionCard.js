export default {
    name: 'SectionCard',
    props: {
        section: { type: Object, required: true },
    },
    template: `
        <div class="catalog_item">
            <a :href="'/catalog/section/' + section.ID" class="catalog_item__link">
                <p class="img_wrap">
                    <img :src="section.PICTURE" :alt="section.NAME" width="200" height="200"/>
                </p>
                <p class="catalog_item__name">{{ section.NAME }}</p>
                <p class="catalog_item__desc" v-html=section.DESCRIPTION></p>
            </a>            
        </div>
    `,
};