<?php

/**
 * @var CAdminTabControl $tabControl
 * @var string $moduleId
 */

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use MyApp\Catalog\Config\Assets;
use MyApp\Catalog\Install\CatalogContentIblockInstaller;

CatalogContentIblockInstaller::ensureInfrastructure();
$pagesIblockId = Assets::pagesIblockId();
$installErrors = CatalogContentIblockInstaller::getLastInstallErrors();
$pagesFieldMapJson = (string)Option::get(
    $moduleId,
    Assets::OPT_PAGES_VIEW_FIELD_MAP,
    ''
);
if ($pagesFieldMapJson === '') {
    $pagesFieldMapJson = (string)json_encode(Assets::pagesViewFieldMap(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>
<?php if ($pagesIblockId <= 0): ?>
<tr>
    <td colspan="2">
        <div class="adm-info-message-wrap">
            <div class="adm-info-message adm-info-message-red">
                <?= Loc::getMessage('MYAPP_CATALOG_OPT_PAGES_IBLOCK_MISSING') ?>
            </div>
        </div>
        <?php if ($installErrors !== []): ?>
            <pre style="margin-top:8px;font-size:12px;color:#a00;"><?= htmlspecialcharsbx(implode("\n", $installErrors)) ?></pre>
        <?php endif; ?>
    </td>
</tr>
<?php endif; ?>
<tr class="heading"><td colspan="2"><?= Loc::getMessage('MYAPP_CATALOG_OPT_CONTENT_PAGES_SECTION') ?></td></tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l">
        <label><?= Loc::getMessage('MYAPP_CATALOG_OPT_PAGES_IBLOCK_ID') ?></label>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <input
            type="text"
            inputmode="numeric"
            name="<?= htmlspecialcharsbx(Assets::OPT_PAGES_IBLOCK_ID) ?>"
            value="<?= (int)$pagesIblockId ?>"
            maxlength="10"
        >
    </td>
</tr>
<tr>
    <td width="50%" class="adm-detail-content-cell-l" style="vertical-align: top;">
        <?= Loc::getMessage('MYAPP_CATALOG_OPT_PAGES_FIELD_MAP') ?>
        <br>
        <small><?= Loc::getMessage('MYAPP_CATALOG_OPT_PAGES_FIELD_MAP_HINT') ?></small>
    </td>
    <td width="50%" class="adm-detail-content-cell-r">
        <textarea
            name="<?= htmlspecialcharsbx(Assets::OPT_PAGES_VIEW_FIELD_MAP) ?>"
            rows="10"
            cols="50"
            style="width: 100%; max-width: 480px; font-family: monospace;"
        ><?= htmlspecialcharsbx($pagesFieldMapJson) ?></textarea>
    </td>
</tr>
