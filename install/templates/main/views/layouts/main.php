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

    <!-- Import Map: позволяет писать import 'vue' без npm -->
    <script type="importmap">
    {
        "imports": {
            "vue": <?= json_encode($vueModuleUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
        }
    }
    </script>
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
            <div id="app-breadcrumbs"></div>
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
        apiBase: <?= json_encode(rtrim($apiBase, '/') . '/', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        userId:  <?= $bxUserId ?>,
        userName: <?= json_encode($bxUserName, JSON_UNESCAPED_UNICODE) ?>,
        isAuthorized: <?= $bxUserId > 0 ? 'true' : 'false' ?>,
    };

    window.__PAGE_DATA__ = {
        page: {
            type: <?= json_encode($pageType, JSON_UNESCAPED_UNICODE) ?>,
            section_id: <?= $sectionId ?>,
            content_title: <?= json_encode($contentPageTitle, JSON_UNESCAPED_UNICODE) ?>,
        },
        pageContent: <?= json_encode($data_page, JSON_UNESCAPED_UNICODE) ?>,
        home: <?= json_encode($data_home, JSON_UNESCAPED_UNICODE) ?>,
        catalog: {
            items: <?= json_encode($data_sections, JSON_UNESCAPED_UNICODE) ?>,
            total: <?= count($data_sections) ?>,
        },
        section: {
            id: <?= $sectionId ?>,
            data: <?= json_encode($data_section, JSON_UNESCAPED_UNICODE) ?>,
            total: <?= count($data_section['items'] ?? []) ?>,
            filter: <?= json_encode($sectionFilter, JSON_UNESCAPED_UNICODE) ?>,
            pagination: {
                page: <?= (int)($sectionPagination['page'] ?? 1) ?>,
                pages: <?= (int)($sectionPagination['pages'] ?? 1) ?>,
                limit: <?= (int)($sectionPagination['limit'] ?? 20) ?>,
                total: <?= (int)($sectionPagination['total'] ?? 0) ?>,
            },
        },
        product: {
            section_id: <?= $sectionId ?>,
            data: <?= json_encode($data_product, JSON_UNESCAPED_UNICODE) ?>,
        },
        order: {
            data: <?= json_encode($data_order, JSON_UNESCAPED_UNICODE) ?>,
        },
        authConfirm: <?= json_encode($data_auth_confirm, JSON_UNESCAPED_UNICODE) ?>,
        authChangePassword: <?= json_encode($data_auth_change_password, JSON_UNESCAPED_UNICODE) ?>,
        search: <?= json_encode($data_search, JSON_UNESCAPED_UNICODE) ?>,
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