export default {
    name: 'SearchModal',
    props: {
        open: { type: Boolean, default: false },
    },
    emits: ['close'],
    data() {
        return {
            query: '',
        };
    },
    watch: {
        open(value) {
            if (value) {
                this.query = '';
                this.$nextTick(() => {
                    this.$refs.input?.focus();
                });
            }
        },
    },
    methods: {
        onBackdropClick(event) {
            if (event.target === event.currentTarget) {
                this.$emit('close');
            }
        },
        onSubmit() {
            const q = this.query.trim();
            if (q === '') {
                return;
            }
            const params = new URLSearchParams();
            params.set('q', q);
            window.location.href = '/catalog/search/?' + params.toString();
        },
    },
    template: `
        <div
            v-if="open"
            class="search-modal"
            role="dialog"
            aria-modal="true"
            aria-label="Поиск по каталогу"
            @click="onBackdropClick"
        >
            <div class="search-modal__panel" @click.stop>
                <button type="button" class="search-modal__close" aria-label="Закрыть" @click="$emit('close')">×</button>
                <h2 class="search-modal__title">Поиск</h2>
                <form class="search-modal__form" @submit.prevent="onSubmit">
                    <input
                        ref="input"
                        v-model="query"
                        type="search"
                        class="search-modal__input"
                        name="q"
                        placeholder="Название, артикул, свойство…"
                        autocomplete="off"
                    >
                    <button type="submit" class="search-modal__submit">Найти</button>
                </form>
            </div>
        </div>
    `,
};
