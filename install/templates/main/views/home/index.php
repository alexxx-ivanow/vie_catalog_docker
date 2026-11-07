<?php

/** @var array<string, mixed> $data_home */
$data_home = is_array($data_home ?? null) ? $data_home : [];
$show = is_array($data_home['show'] ?? null) ? $data_home['show'] : [];
$slider = is_array($data_home['slider'] ?? null) ? $data_home['slider'] : [];
$advantages = is_array($data_home['advantages'] ?? null) ? $data_home['advantages'] : [];
$about = is_array($data_home['about'] ?? null) ? $data_home['about'] : null;
$seo = is_array($data_home['seo'] ?? null) ? $data_home['seo'] : null;
/** @var list<array<string, mixed>> $data_sections */
$data_sections = is_array($data_sections ?? null) ? $data_sections : [];
?>
<div class="home-page">
    <?php if (!empty($show['slider']) && $slider !== []): ?>
        <?php $sliderNav = count($slider) > 2; ?>
        <section
            class="home-hero home-hero--slider<?= $sliderNav ? ' home-hero--slider-nav' : '' ?>"
            aria-label="Слайдер"
            <?php if ($sliderNav): ?>data-home-slider<?php endif; ?>
        >
            <div class="home-hero__track">
                <?php foreach ($slider as $index => $slide): ?>
                    <?php
                    $img = (string)($slide['image'] ?? '');
                    $link = (string)($slide['link'] ?? '');
                    $alt = (string)($slide['alt'] ?? 'Слайд');
                    $caption = (string)($slide['caption'] ?? '');
                    ?>
                    <div
                        class="home-hero__slide<?= $index === 0 ? ' home-hero__slide--active' : '' ?>"
                        data-home-slide
                        aria-hidden="<?= $index === 0 ? 'false' : 'true' ?>"
                    >
                        <?php if ($link !== ''): ?>
                            <a href="<?= htmlspecialcharsbx($link) ?>" class="home-hero__link">
                                <img class="home-hero__image" src="<?= htmlspecialcharsbx($img) ?>" alt="<?= htmlspecialcharsbx($alt) ?>" width="1920" height="700"<?= $index === 0 ? ' fetchpriority="high"' : ' loading="lazy"' ?>>
                            </a>
                        <?php else: ?>
                            <img class="home-hero__image" src="<?= htmlspecialcharsbx($img) ?>" alt="<?= htmlspecialcharsbx($alt) ?>" width="1920" height="700"<?= $index === 0 ? ' fetchpriority="high"' : ' loading="lazy"' ?>>
                        <?php endif; ?>
                        <?php if ($caption !== ''): ?>
                            <p class="home-hero__caption"><?= htmlspecialcharsbx($caption) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($sliderNav): ?>
                <button type="button" class="home-hero__nav home-hero__nav--prev" data-home-slider-prev aria-label="Предыдущий слайд">
                    <span aria-hidden="true">‹</span>
                </button>
                <button type="button" class="home-hero__nav home-hero__nav--next" data-home-slider-next aria-label="Следующий слайд">
                    <span aria-hidden="true">›</span>
                </button>
                <div class="home-hero__dots" role="tablist" aria-label="Слайды">
                    <?php foreach ($slider as $index => $slide): ?>
                        <button
                            type="button"
                            class="home-hero__dot<?= $index === 0 ? ' home-hero__dot--active' : '' ?>"
                            data-home-slider-dot="<?= (int)$index ?>"
                            role="tab"
                            aria-label="Слайд <?= (int)$index + 1 ?>"
                            aria-selected="<?= $index === 0 ? 'true' : 'false' ?>"
                        ></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php if ($sliderNav): ?>
            <script>
                (function () {
                    const root = document.querySelector('[data-home-slider]');
                    if (!root) {
                        return;
                    }
                    const slides = Array.from(root.querySelectorAll('[data-home-slide]'));
                    const dots = Array.from(root.querySelectorAll('[data-home-slider-dot]'));
                    if (slides.length < 2) {
                        return;
                    }
                    let index = slides.findIndex((el) => el.classList.contains('home-hero__slide--active'));
                    if (index < 0) {
                        index = 0;
                    }
                    const setSlide = (next) => {
                        index = (next + slides.length) % slides.length;
                        slides.forEach((slide, i) => {
                            const active = i === index;
                            slide.classList.toggle('home-hero__slide--active', active);
                            slide.setAttribute('aria-hidden', active ? 'false' : 'true');
                        });
                        dots.forEach((dot, i) => {
                            const active = i === index;
                            dot.classList.toggle('home-hero__dot--active', active);
                            dot.setAttribute('aria-selected', active ? 'true' : 'false');
                        });
                    };
                    root.querySelector('[data-home-slider-prev]')?.addEventListener('click', () => setSlide(index - 1));
                    root.querySelector('[data-home-slider-next]')?.addEventListener('click', () => setSlide(index + 1));
                    dots.forEach((dot) => {
                        dot.addEventListener('click', () => {
                            const i = parseInt(dot.getAttribute('data-home-slider-dot') || '0', 10);
                            if (!Number.isNaN(i)) {
                                setSlide(i);
                            }
                        });
                    });
                })();
            </script>
        <?php endif; ?>
    <?php elseif (!empty($show['slider'])): ?>
        <section class="home-hero" aria-label="Интернет-магазин">
            <img class="home-hero__image" src="/local/templates/assets/img/home/hero.jpg" alt="Интернет-магазин" width="1920" height="700" fetchpriority="high">
        </section>
    <?php endif; ?>

    <?php if (!empty($show['catalog']) && $data_sections !== []): ?>
        <section class="home-catalog home-section">
            <h2 class="home-section__title">Каталог</h2>
            <div class="catalog_list home-catalog__grid">
                <?php foreach ($data_sections as $section): ?>
                    <?php
                    $sectionId = (int)($section['ID'] ?? 0);
                    $url = (string)($section['URL'] ?? ('/catalog/section/' . $sectionId));
                    $picture = (string)($section['PICTURE'] ?? '');
                    ?>
                    <article class="catalog_item">
                        <a href="<?= htmlspecialcharsbx($url) ?>" class="catalog_item__link">
                            <img class="catalog_item__image" src="<?= htmlspecialcharsbx($picture) ?>" alt="<?= htmlspecialcharsbx((string)($section['NAME'] ?? '')) ?>" loading="lazy" decoding="async">
                            <h3 class="catalog_item__title"><?= htmlspecialcharsbx((string)($section['NAME'] ?? '')) ?></h3>
                            <?php if (!empty($section['DESCRIPTION'])): ?>
                                <p class="catalog_item__text"><?= htmlspecialcharsbx((string)$section['DESCRIPTION']) ?></p>
                            <?php endif; ?>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($show['about']) && is_array($about)): ?>
        <section class="home-about home-section">
            <div class="home-about__content">
                <?php if (($about['title'] ?? '') !== ''): ?>
                    <h2 class="home-section__title home-about__title"><?= htmlspecialcharsbx((string)$about['title']) ?></h2>
                <?php endif; ?>
                <?php if (($about['body'] ?? '') !== ''): ?>
                    <div class="home-about__text"><?= (string)$about['body'] ?></div>
                <?php endif; ?>
            </div>
            <?php if (($about['image'] ?? '') !== ''): ?>
                <div class="home-about__media">
                    <img class="home-about__image" src="<?= htmlspecialcharsbx((string)$about['image']) ?>" alt="" width="1120" height="800" loading="lazy" decoding="async">
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if (!empty($show['advantages']) && $advantages !== []): ?>
        <section class="home-advantages home-section" aria-label="Преимущества">
            <h2 class="home-section__title home-advantages__title">Почему мы</h2>
            <ul class="home-advantages__grid">
                <?php foreach ($advantages as $item): ?>
                    <li class="home-advantage-card">
                        <?php if (($item['icon'] ?? '') !== ''): ?>
                            <span class="home-advantage-card__icon" aria-hidden="true">
                                <img src="<?= htmlspecialcharsbx((string)$item['icon']) ?>" alt="" width="48" height="48" loading="lazy">
                            </span>
                        <?php endif; ?>
                        <p class="home-advantage-card__text"><?= htmlspecialcharsbx((string)($item['text'] ?? $item['title'] ?? '')) ?></p>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if (!empty($show['seo']) && is_array($seo) && ($seo['body'] ?? '') !== ''): ?>
        <section class="home-seo home-section" aria-label="Дополнительная информация">
            <div class="home-seo__body"><?= (string)$seo['body'] ?></div>
        </section>
    <?php endif; ?>
</div>
