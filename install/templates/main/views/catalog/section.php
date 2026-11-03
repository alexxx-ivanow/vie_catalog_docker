<?php

use MyApp\Catalog\Config\Assets;

$showCatalogFilter = Assets::showCatalogFilter();
?>
<div id="app-catalog">
    <div class="catalog-page">
        <h1 style="text-align:center;">
            <span class="catalog-skeleton-line catalog-skeleton-line--title" aria-hidden="true"></span>
        </h1>
        <div class="catalog-layout">
            <?php if ($showCatalogFilter): ?>
            <aside class="catalog-layout__sidebar">
                <div class="catalog-filter-skeleton" aria-hidden="true">
                    <div class="catalog-skeleton-line catalog-skeleton-line--block"></div>
                    <div class="catalog-skeleton-line catalog-skeleton-line--block"></div>
                    <div class="catalog-skeleton-line catalog-skeleton-line--block catalog-skeleton-line--short"></div>
                </div>
            </aside>
            <?php endif; ?>
            <section class="catalog-layout__content">
                <div class="section-subcategories section-subcategories--skeleton" aria-hidden="true">
                    <span class="section-subcategories__skeleton-item"></span>
                    <span class="section-subcategories__skeleton-item"></span>
                    <span class="section-subcategories__skeleton-item"></span>
                    <span class="section-subcategories__skeleton-item"></span>
                </div>
                <div class="catalog-page__toolbar">
                    <span class="catalog-skeleton-line catalog-skeleton-line--toolbar" aria-hidden="true"></span>
                </div>
                <div class="catalog_list" aria-busy="true" aria-label="Загрузка товаров">
                    <?php for ($i = 0; $i < 8; $i++): ?>
                        <div class="catalog_item catalog-skeleton-card" aria-hidden="true">
                            <div class="catalog-skeleton-card__image"></div>
                            <div class="catalog-skeleton-card__line catalog-skeleton-card__line--title"></div>
                            <div class="catalog-skeleton-card__line catalog-skeleton-card__line--text"></div>
                            <div class="catalog-skeleton-card__line catalog-skeleton-card__line--price"></div>
                            <div class="catalog-skeleton-card__btn"></div>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="catalog-pagination-placeholder" aria-hidden="true">
                    <span class="catalog-pagination-placeholder__btn"></span>
                    <span class="catalog-pagination-placeholder__btn"></span>
                    <span class="catalog-pagination-placeholder__btn"></span>
                    <span class="catalog-pagination-placeholder__dots">...</span>
                    <span class="catalog-pagination-placeholder__btn"></span>
                </div>
            </section>
        </div>
    </div>
</div>
