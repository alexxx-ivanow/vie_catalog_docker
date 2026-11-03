<div id="app-catalog">
    <div class="catalog-page">
        <section class="catalog-page__content">
            <div class="catalog-page__toolbar">
                <span class="catalog-skeleton-line catalog-skeleton-line--toolbar" aria-hidden="true"></span>
            </div>
            <div class="catalog_list" aria-busy="true" aria-label="Загрузка каталога">
                <?php for ($i = 0; $i < 8; $i++): ?>
                    <div class="catalog_item catalog-skeleton-card" aria-hidden="true">
                        <div class="catalog-skeleton-card__image"></div>
                        <div class="catalog-skeleton-card__line catalog-skeleton-card__line--title"></div>
                        <div class="catalog-skeleton-card__line catalog-skeleton-card__line--text"></div>
                    </div>
                <?php endfor; ?>
            </div>
        </section>
    </div>
</div>
