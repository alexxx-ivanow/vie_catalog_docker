<?php

/**
 * @var string $moduleId
 */

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Install\CatalogContentIblockInstaller;

CatalogContentIblockInstaller::ensureInfrastructure();
$installErrors = CatalogContentIblockInstaller::getLastInstallErrors();
$homeFieldMapJson = (string)Option::get($moduleId, Assets::OPT_HOME_VIEW_FIELD_MAP, '');
if ($homeFieldMapJson === '') {
    $homeFieldMapJson = (string)json_encode(Assets::homeViewFieldMap(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>
<?php if (
    Assets::mainIblockSliderId() <= 0
    || Assets::mainIblockAdvantagesId() <= 0
    || Assets::mainIblockAboutId() <= 0
    || Assets::mainIblockSeoId() <= 0
): ?>
<tr>
    <td colspan="2">
        <div class="adm-info-message-wrap">
            <div class="adm-info-message adm-info-message-red">
                <?= Loc::getMessage('MYAPP_CATALOG_OPT_HOME_IBLOCK_MISSING') ?>
            </div>
        </div>
        <?php if ($installErrors !== []): ?>
            <pre style="margin-top:8px;font-size:12px;color:#a00;"><?= htmlspecialcharsbx(implode("\n", $installErrors)) ?></pre>
        <?php endif; ?>
    </td>
</tr>
<?php endif; ?>
<tr class="heading"><td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_OPT_HOME_SECTION') ?></td></tr>
<tr>
    <td class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_OPT_MAIN_SLIDER_IBLOCK') ?></td>
    <td class="adm-detail-content-cell-r">
        <input type="text" name="<?= htmlspecialcharsbx(Assets::OPT_MAIN_IBLOCK_SLIDER_ID) ?>" value="<?= Assets::mainIblockSliderId() ?>" maxlength="10">
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_OPT_MAIN_ADVANTAGES_IBLOCK') ?></td>
    <td class="adm-detail-content-cell-r">
        <input type="text" name="<?= htmlspecialcharsbx(Assets::OPT_MAIN_IBLOCK_ADVANTAGES_ID) ?>" value="<?= Assets::mainIblockAdvantagesId() ?>" maxlength="10">
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_OPT_MAIN_ABOUT_IBLOCK') ?></td>
    <td class="adm-detail-content-cell-r">
        <input type="text" name="<?= htmlspecialcharsbx(Assets::OPT_MAIN_IBLOCK_ABOUT_ID) ?>" value="<?= Assets::mainIblockAboutId() ?>" maxlength="10">
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_OPT_MAIN_SEO_IBLOCK') ?></td>
    <td class="adm-detail-content-cell-r">
        <input type="text" name="<?= htmlspecialcharsbx(Assets::OPT_MAIN_IBLOCK_SEO_ID) ?>" value="<?= Assets::mainIblockSeoId() ?>" maxlength="10">
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_OPT_HOME_SECTIONS_LIMIT') ?></td>
    <td class="adm-detail-content-cell-r">
        <input type="text" name="<?= htmlspecialcharsbx(Assets::OPT_HOME_SECTIONS_LIMIT) ?>" value="<?= Assets::homeSectionsLimit() ?>" maxlength="3">
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_OPT_HOME_UF_SHOW') ?></td>
    <td class="adm-detail-content-cell-r">
        <input type="text" name="<?= htmlspecialcharsbx(Assets::OPT_HOME_SECTIONS_UF_SHOW) ?>" value="<?= htmlspecialcharsbx(Assets::homeSectionsUfShowCode()) ?>" maxlength="50">
    </td>
</tr>
<tr>
    <td class="adm-detail-content-cell-l"><?= Loc::getMessage('MYAPP_CATALOG_OPT_HOME_UF_SORT') ?></td>
    <td class="adm-detail-content-cell-r">
        <input type="text" name="<?= htmlspecialcharsbx(Assets::OPT_HOME_SECTIONS_UF_SORT) ?>" value="<?= htmlspecialcharsbx(Assets::homeSectionsUfSortCode()) ?>" maxlength="50">
    </td>
</tr>
<tr class="heading"><td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_OPT_HOME_BLOCKS') ?></td></tr>
<?php
$homeFlags = [
    Assets::OPT_HOME_SHOW_SLIDER => Assets::homeShowSlider(),
    Assets::OPT_HOME_SHOW_ADVANTAGES => Assets::homeShowAdvantages(),
    Assets::OPT_HOME_SHOW_ABOUT => Assets::homeShowAbout(),
    Assets::OPT_HOME_SHOW_SEO => Assets::homeShowSeo(),
    Assets::OPT_HOME_SHOW_CATALOG => Assets::homeShowCatalog(),
];
$labels = [
    Assets::OPT_HOME_SHOW_SLIDER => Loc::getMessage('MYAPP_CATALOG_OPT_HOME_SHOW_SLIDER'),
    Assets::OPT_HOME_SHOW_ADVANTAGES => Loc::getMessage('MYAPP_CATALOG_OPT_HOME_SHOW_ADVANTAGES'),
    Assets::OPT_HOME_SHOW_ABOUT => Loc::getMessage('MYAPP_CATALOG_OPT_HOME_SHOW_ABOUT'),
    Assets::OPT_HOME_SHOW_SEO => Loc::getMessage('MYAPP_CATALOG_OPT_HOME_SHOW_SEO'),
    Assets::OPT_HOME_SHOW_CATALOG => Loc::getMessage('MYAPP_CATALOG_OPT_HOME_SHOW_CATALOG'),
];
foreach ($homeFlags as $optName => $checked): ?>
<tr>
    <td class="adm-detail-content-cell-l"><?= $labels[$optName] ?></td>
    <td class="adm-detail-content-cell-r">
        <input type="checkbox" name="<?= htmlspecialcharsbx($optName) ?>" value="Y"<?= $checked ? ' checked' : '' ?>>
    </td>
</tr>
<?php endforeach; ?>
<tr>
    <td class="adm-detail-content-cell-l" style="vertical-align: top;">
        <?= Loc::getMessage('MYAPP_CATALOG_OPT_HOME_FIELD_MAP') ?>
    </td>
    <td class="adm-detail-content-cell-r">
        <textarea name="<?= htmlspecialcharsbx(Assets::OPT_HOME_VIEW_FIELD_MAP) ?>" rows="14" cols="50" style="width:100%;max-width:480px;font-family:monospace;"><?= htmlspecialcharsbx($homeFieldMapJson) ?></textarea>
    </td>
</tr>
