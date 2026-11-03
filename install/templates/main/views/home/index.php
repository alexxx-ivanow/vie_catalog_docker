<div class="home-page">
    <section class="home-hero" aria-label="Интернет-магазин">
        <img
            class="home-hero__image"
            src="/local/templates/assets/img/home/hero.jpg"
            alt="Интернет-магазин"
            width="1920"
            height="700"
            fetchpriority="high"
        >
    </section>

    <section class="home-catalog home-section">
        <h2 class="home-section__title">Каталог</h2>
        <div id="app-home-catalog">
            <div class="catalog_list" aria-busy="true" aria-label="Загрузка категорий">
                <?php for ($i = 0; $i < 8; $i++): ?>
                    <div class="catalog_item catalog-skeleton-card" aria-hidden="true">
                        <div class="catalog-skeleton-card__image"></div>
                        <div class="catalog-skeleton-card__line catalog-skeleton-card__line--title"></div>
                        <div class="catalog-skeleton-card__line catalog-skeleton-card__line--text"></div>
                    </div>
                <?php endfor; ?>
            </div>
        </div>
    </section>

    <section class="home-about home-section">
        <div class="home-about__content">
            <h2 class="home-section__title home-about__title">О компании</h2>
            <p class="home-about__text">
                Мы помогаем подобрать инструмент, электронику и расходные материалы для дома и бизнеса.
                Работаем с проверенными поставщиками, держим актуальные остатки и быстро отгружаем заказы.
            </p>
            <p class="home-about__text">
                В каталоге — тысячи позиций с понятными описаниями и фотографиями. Если нужна консультация,
                напишите нам — подскажем совместимость и оптимальный вариант под вашу задачу.
            </p>
        </div>
        <div class="home-about__media">
            <img
                class="home-about__image"
                src="/local/templates/assets/img/home/about.jpg"
                alt="Техническое оборудование и инструменты"
                width="1120"
                height="800"
                loading="lazy"
                decoding="async"
            >
        </div>
    </section>

    <section class="home-advantages home-section" aria-label="Преимущества">
        <h2 class="home-section__title home-advantages__title">Почему мы</h2>
        <ul class="home-advantages__grid">
            <li class="home-advantage-card">
                <span class="home-advantage-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 48 48" width="48" height="48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="4" y="10" width="40" height="28" rx="4" stroke="currentColor" stroke-width="2"/>
                        <path d="M4 18h40" stroke="currentColor" stroke-width="2"/>
                        <path d="M12 30h8M12 34h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </span>
                <p class="home-advantage-card__text">Удобная оплата картой и по счёту для юридических лиц</p>
            </li>
            <li class="home-advantage-card">
                <span class="home-advantage-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 48 48" width="48" height="48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M8 36h32l-4-16H12L8 36z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        <circle cx="18" cy="38" r="2" fill="currentColor"/>
                        <circle cx="32" cy="38" r="2" fill="currentColor"/>
                        <path d="M16 20V12h16v8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </span>
                <p class="home-advantage-card__text">Отправка в день заказа при наличии на складе</p>
            </li>
            <li class="home-advantage-card">
                <span class="home-advantage-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 48 48" width="48" height="48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M24 6l14 8v12c0 10-6 16-14 18C16 42 10 36 10 26V14l14-8z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                        <path d="M18 24l4 4 8-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <p class="home-advantage-card__text">Гарантия на товар и помощь с возвратом</p>
            </li>
            <li class="home-advantage-card">
                <span class="home-advantage-card__icon" aria-hidden="true">
                    <svg viewBox="0 0 48 48" width="48" height="48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="24" cy="20" r="8" stroke="currentColor" stroke-width="2"/>
                        <path d="M10 40c2-8 8-12 14-12s12 4 14 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M32 14l4-4 4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </span>
                <p class="home-advantage-card__text">Поддержка менеджера на всех этапах покупки</p>
            </li>
        </ul>
    </section>
</div>
