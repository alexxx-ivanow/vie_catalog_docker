<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();

$pageTitle = $pageTitle ?? 'Каталог';
$pageType = $pageType ?? 'catalog';
$sectionId = (int)($sectionId ?? 0);
$contentView = $contentView ?? '';
$data_sections = is_array($data_sections ?? null) ? $data_sections : [];
$data_section = is_array($data_section ?? null) ? $data_section : [];
$data_product = is_array($data_product ?? null) ? $data_product : [];
$data_order = is_array($data_order ?? null) ? $data_order : [];
$data_auth_confirm = is_array($data_auth_confirm ?? null) ? $data_auth_confirm : [];
$data_auth_change_password = is_array($data_auth_change_password ?? null) ? $data_auth_change_password : [];
$data_search = is_array($data_search ?? null) ? $data_search : [];
$data_page = is_array($data_page ?? null) ? $data_page : [];
$data_home = is_array($data_home ?? null) ? $data_home : [];
$contentPageTitle = (string)($contentPageTitle ?? '');
$metaDescription = (string)($metaDescription ?? '');
$sectionPagination = is_array($sectionPagination ?? null) ? $sectionPagination : [];
$sectionFilter = is_array($sectionFilter ?? null) ? $sectionFilter : [];
$apiBase = (string)($apiBase ?? '/vue_catalog/api/v1/');
$jsBase = rtrim((string)($jsBase ?? '/local/modules/myapp.catalog/js/catalog/'), '/') . '/';
$vueModuleUrl = (string)($vueModuleUrl ?? '/local/modules/myapp.catalog/js/vendor/vue.esm-browser.min.js');
$useBitrixVue = !empty($useBitrixVue);
$bitrixAssetJs = (string)($bitrixAssetJs ?? '');
$showBreadcrumbs = ($showBreadcrumbs ?? true) !== false;
// JSON_HEX_TAG экранирует < и > — пользовательские строки (например, ?q=)
// не могут закрыть тег <script> и выполнить XSS.
$jsonPageFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="/local/templates/assets/css/style.css">

    <title><?= htmlspecialcharsbx($pageTitle) ?></title>
    <?php if ($metaDescription !== ''): ?>
        <meta name="description" content="<?= htmlspecialcharsbx($metaDescription) ?>">
    <?php endif; ?>

    <?php if ($useBitrixVue && $bitrixAssetJs !== ''): ?>
        <?= $bitrixAssetJs ?>
    <?php endif; ?>
</head>
<body class="site-body">

<header class="site-header">
    <div class="site-header__inner container">
        <a href="/" class="site-header__logo">
            <img src="/local/templates/assets/img/svg/logo.svg" alt="<?= htmlspecialcharsbx($pageTitle) ?>" width="180" height="48">
        </a>
        <nav class="site-nav" aria-label="Основное меню">
            <div id="app-site-search"></div>
            <a href="/" class="site-nav__link">Главная</a>
            <a href="/catalog/" class="site-nav__link">Каталог</a>
            <div id="app-site-header"></div>
        </nav>
    </div>
</header>

<main class="site-main">
    <div class="container">
        <?php if ($showBreadcrumbs): ?>
            <div id="app-breadcrumbs">
                <div class="breadcrumbs-wrap" aria-busy="true">
                    <div class="breadcrumbs-placeholder" aria-hidden="true">
                        <span class="breadcrumbs-placeholder__item"></span>
                        <span class="breadcrumbs-placeholder__sep">/</span>
                        <span class="breadcrumbs-placeholder__item"></span>
                        <span class="breadcrumbs-placeholder__sep">/</span>
                        <span class="breadcrumbs-placeholder__item breadcrumbs-placeholder__item--short"></span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($contentView)): ?>
            <?php
            $contentFile = \Bitrix\Main\Application::getDocumentRoot() . $contentView;
            if (is_file($contentFile)) {
                include $contentFile;
            }
            ?>
        <?php endif; ?>
    </div>
</main>

<footer class="site-footer">
    <div class="site-footer__inner container">
        <p class="site-footer__copy">© <?= date('Y') ?> Интернет-магазин. Все права защищены.</p>
    </div>
</footer>

<!-- Import Map: позволяет писать import 'vue' без npm -->
<script type="importmap">
    {
        "imports": {
            "vue": <?= json_encode($vueModuleUrl, $jsonPageFlags) ?>
    }
}
</script>

<!-- Передаём данные из PHP в JS -->
<script>
    <?php
    $bxUser = $GLOBALS['USER'];
    $bxUserId = is_object($bxUser) ? (int)$bxUser->GetID() : 0;
    $bxUserName = '';
    if ($bxUserId > 0 && is_object($bxUser)) {
        $bxUserName = trim((string)$bxUser->GetFirstName() . ' ' . (string)$bxUser->GetLastName());
    }
    ?>
    window.__BX__ = {
        sessid:  '<?= bitrix_sessid() ?>',
        apiBase: <?= json_encode(rtrim($apiBase, '/') . '/', $jsonPageFlags) ?>,
        userId:  <?= $bxUserId ?>,
        userName: <?= json_encode($bxUserName, $jsonPageFlags) ?>,
        isAuthorized: <?= $bxUserId > 0 ? 'true' : 'false' ?>,
    };

    window.__PAGE_DATA__ = {
        page: {
            type: <?= json_encode($pageType, $jsonPageFlags) ?>,
            section_id: <?= $sectionId ?>,
            content_title: <?= json_encode($contentPageTitle, $jsonPageFlags) ?>,
        },
        catalog: <?php if ($pageType === 'home'): ?>
            { items: [], total: 0 },
        <?php else: ?>
            {
                items: <?= json_encode($data_sections, $jsonPageFlags) ?>,
                total: <?= count($data_sections) ?>,
            },
        <?php endif; ?>
        section: {
            id: <?= $sectionId ?>,
            data: <?= json_encode($data_section, $jsonPageFlags) ?>,
            total: <?= count($data_section['items'] ?? []) ?>,
            filter: <?= json_encode($sectionFilter, $jsonPageFlags) ?>,
            pagination: {
                page: <?= (int)($sectionPagination['page'] ?? 1) ?>,
                pages: <?= (int)($sectionPagination['pages'] ?? 1) ?>,
                limit: <?= (int)($sectionPagination['limit'] ?? 20) ?>,
                total: <?= (int)($sectionPagination['total'] ?? 0) ?>,
            },
        },
        product: {
            section_id: <?= $sectionId ?>,
            data: <?= json_encode($data_product, $jsonPageFlags) ?>,
        },
        order: {
            data: <?= json_encode($data_order, $jsonPageFlags) ?>,
        },
        authConfirm: <?= json_encode($data_auth_confirm, $jsonPageFlags) ?>,
        authChangePassword: <?= json_encode($data_auth_change_password, $jsonPageFlags) ?>,
        search: <?= json_encode($data_search, $jsonPageFlags) ?>,
    };
</script>

<?php if ($showBreadcrumbs): ?>
    <script type="module" src="<?= htmlspecialcharsbx($jsBase) ?>app-breadcrumbs.js"></script>
<?php endif; ?>
<script type="module" src="<?= htmlspecialcharsbx($jsBase) ?>app-site-header.js"></script>

<!-- JS подключается в конце body, только если нужен на этой странице -->
<?php if (!empty($jsEntry)): ?>
    <script type="module" src="<?= htmlspecialcharsbx($jsBase . $jsEntry) ?>"></script>
<?php endif; ?>

</body>
</html>