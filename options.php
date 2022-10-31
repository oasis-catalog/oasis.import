<?php

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\HttpApplication;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Page\Asset;
use Oasis\Import\Main;
use Oasis\Import\CustomFields;
use Oasis\Import\Oorder;

Loc::loadMessages(__FILE__);

$request = HttpApplication::getInstance()->getContext()->getRequest();
$module_id = htmlspecialcharsbx($request['mid'] != '' ? $request['mid'] : $request['id']);

Loader::includeModule($module_id);

try {
    $aTabs = [
        [
            'DIV'     => 'edit',
            'TAB'     => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_NAME'),
            'TITLE'   => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_NAME'),
            'OPTIONS' => [
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_AUTH'),
                [
                    'api_key',
                    Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_API_KEY'),
                    '',
                    ['text', 40]
                ],
                [
                    'api_user_id',
                    Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_API_USER_ID'),
                    '',
                    ['text', 20]
                ],
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_IBLOCK'),
                [
                    'iblock_catalog',
                    Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_IBLOCK_CATALOG'),
                    '',
                    ['selectbox', ['' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_SELECT')] + Main::getActiveIblocksForOptions()]
                ],
                [
                    'iblock_offers',
                    Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_IBLOCK_OFFERS'),
                    '',
                    ['selectbox', ['' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_SELECT')] + Main::getActiveIblocksForOptions()]
                ],
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_OPTIONS_STOCK'),
                [
                    'stocks',
                    Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_STOCKS'),
                    'N',
                    ['checkbox']
                ],
                [
                    'main_stock',
                    Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_MAIN_STOCK'),
                    '',
                    ['selectbox', ['' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_SELECT')] + Main::getActiveStoresForOptions()]
                ],
                [
                    'remote_stock',
                    Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_REMOTE_STOCK'),
                    '',
                    ['selectbox', ['' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_SELECT')] + Main::getActiveStoresForOptions()]
                ],
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CRON_TITLE'),
                [
                    'cron_type',
                    Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CRON_TYPE'),
                    '',
                    [
                        'selectbox',
                        [
                            'bitrix' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CRON_BITRIX'),
                            'custom' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CRON_CUSTOM'),
                        ]
                    ]
                ],
            ]
        ]
    ];

    $currencies = Main::getCurrenciesOasisArray();

    if (!empty($currencies)) {
        $cronType = Option::get($module_id, 'cron_type');

        if ($cronType === 'custom') {
            $cronPath = str_replace('\\', '/', realpath(dirname(__FILE__))) . '/cron.php';
        } else {
            $cronPath = Application::getDocumentRoot() . '/bitrix/php_interface/cron_events.php';
        }

        $aTabs[0]['OPTIONS'] = array_merge($aTabs[0]['OPTIONS'], [
            [
                'note' => sprintf(Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CRON_DESC'), $cronPath),
            ],
            Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_OPTIONS_IMPORT'),
            [
                'categories',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CATEGORIES'),
                'Y',
                ['checkboxes', Main::getOasisCategoriesToTree()]
            ],
            [
                'currency',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CURRENCY'),
                'rub',
                ['selectbox', $currencies]
            ],
            [
                'no_vat',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_NO_VAT'),
                'N',
                ['checkbox']
            ],
            [
                'not_on_order',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_NOT_ON_ORDER'),
                'N',
                ['checkbox']
            ],
            [
                'price_from',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_PRICE_FROM'),
                '',
                ['text', 10]
            ],
            [
                'price_to',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_PRICE_TO'),
                '',
                ['text', 10]
            ],
            [
                'rating',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_RATING'),
                '',
                ['selectbox', [
                    ''  => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_SELECT'),
                    '1' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_RATING_NEW'),
                    '2' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_RATING_HITS'),
                    '3' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_RATING_DISCOUNT'),
                ]]
            ],
            [
                'warehouse_moscow',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_WAREHOUSE_MOSCOW'),
                'N',
                ['checkbox']
            ],
            [
                'warehouse_europe',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_WAREHOUSE_EUROPE'),
                'N',
                ['checkbox']
            ],
            [
                'remote_warehouse',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_REMOTE_WAREHOUSE'),
                'N',
                ['checkbox']
            ],
            Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_LIMIT'),
            [
                'note' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_LIMIT_NOTE'),
            ],
            [
                'limit',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_LIMIT_PRODUCT'),
                '',
                ['text', 10]
            ],
            Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CALC'),
            [
                'factor',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_FACTOR'),
                '',
                ['text', 10]
            ],
            [
                'increase',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_INCREASE'),
                '',
                ['text', 10]
            ],
            [
                'dealer',
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_DEALER'),
                'N',
                ['checkbox']
            ],
        ]);

        $aTabs[] = [
            'DIV'   => 'orders',
            'TAB'   => Loc::getMessage('OASIS_IMPORT_ORDERS_TAB_NAME'),
            'TITLE' => Loc::getMessage('OASIS_IMPORT_ORDERS_TAB_AUTH'),
        ];
    } else {
        array_unshift($aTabs[0]['OPTIONS'], [
            'note' => Loc::getMessage('OASIS_IMPORT_OPTIONS_ERROR_API_KEY'),
        ]);
    }
} catch (Exception $e) {
}

if ($request->isPost() && check_bitrix_sessid()) {
    foreach ($aTabs as $aTab) {
        foreach ($aTab['OPTIONS'] as $arOption) {
            if ($request['apply'] && !empty($arOption[0])) {
                if ($arOption[0] === 'iblock_catalog' || $arOption[0] === 'iblock_offers') {
                    $optionValue = $request->getPost($arOption[0]);

                    if (empty($optionValue)) {
                        LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . $module_id . '&lang=' . LANG . '&errorIblock=1');
                        break;
                    }
                } elseif ($arOption[0] === 'main_stock' || $arOption[0] === 'remote_stock') {
                    $optionValueStock = $request->getPost('stocks');
                    $optionValue = $request->getPost($arOption[0]);

                    if ($arOption[0] === 'main_stock') {
                        if (empty($optionValue)) {
                            LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . $module_id . '&lang=' . LANG . '&errorStock=1');
                            break;
                        }
                    } elseif (!empty($optionValueStock)) {
                        $optionValue = $request->getPost($arOption[0]);
                        if (empty($optionValue)) {
                            LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . $module_id . '&lang=' . LANG . '&errorStock=1');
                            break;
                        }
                    }
                } elseif ($arOption[0] === 'cron_type') {
                    $optionValue = $request->getPost($arOption[0]);
                    $arFields = $optionValue === 'custom' ? ['ACTIVE' => 'N'] : ['ACTIVE' => 'Y'];
                    $agents = \CAgent::GetList([], ['MODULE_ID' => 'oasis.import']);

                    while ($agent = $agents->Fetch()) {
                        \CAgent::Update($agent['ID'], $arFields);
                    }
                }
            }
        }

        foreach ($aTab['OPTIONS'] as $arOption) {
            if (!is_array($arOption)) {
                continue;
            }

            if ($arOption['note']) {
                continue;
            }

            if ($request['apply']) {
                $optionValue = $request->getPost($arOption[0]);

                if (!empty($arOption[3][0]) && $arOption[3][0] === 'checkboxes') {
                    $optionValue = array_keys($optionValue);
                }

                if (!empty($arOption[0]) && $arOption[0] === 'limit') {
                    if ($optionValue && intval($optionValue) >= 0) {
                        $optionValue = abs($optionValue);
                    } else {
                        $optionValue = '';
                    }
                }

                Option::set($module_id, $arOption[0], is_array($optionValue) ? implode(',', $optionValue) : $optionValue);
            } elseif ($request['default']) {
                Option::set($module_id, $arOption[0], $arOption[2]);
            }
        }
    }

    LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . $module_id . '&lang=' . LANG);
}

$tabControl = new CAdminTabControl(
    'tabControl',
    $aTabs
);

$tabControl->Begin();

$values = $request->getValues();

if ((!empty($values['errorIblock']) && $values['errorIblock'] == 1) || (!empty($values['errorStock']) && $values['errorStock'] == 1)) {
    \Bitrix\Main\UI\Extension::load('ui.alerts');
    \Bitrix\Main\UI\Extension::load('ui.dialogs.messagebox');

    $title = Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_IBLOCK_ERROR_TITLE');

    if (!empty($values['errorIblock'])) {
        $desc = Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_IBLOCK_ERROR_DESC');
    } else {
        $desc = Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_STOCKS_ERROR_DESC');
    }
    ?>
    <script type="application/javascript">
        BX.UI.Dialogs.MessageBox.alert("<?php echo $desc; ?>", "<?php echo $title; ?>", (messageBox, button, event) => {
                messageBox.close();
            }
        );
    </script>
    <?php
}
?>
    <script type="application/javascript">
        BX.ready(function () {
            BX.bind(BX('stocks'), 'click', function () {
                const checkbox = document.querySelector("#stocks");

                checkbox.addEventListener("change", function () {
                    if (this.checked) {
                        BX.adjust(BX('remote_stock'), {style: {display: "table-row"}});
                    } else {
                        BX.adjust(BX('remote_stock'), {style: {display: "none"}});
                    }
                })
            });
        });
    </script>

    <form action="<? echo($APPLICATION->GetCurPage()); ?>?mid=<? echo($module_id); ?>&lang=<? echo(LANG); ?>"
          method="post">

        <?php
        foreach ($aTabs as $aTab) {
            if ($aTab['DIV'] !== 'orders') {
                if ($aTab['OPTIONS']) {
                    $tabControl->BeginNextTab();

                    if ($currencies) {
                        \Bitrix\Main\UI\Extension::load("ui.progressbar");
                        $APPLICATION->SetAdditionalCSS('/bitrix/css/' . $module_id . '/stylesheet.css');
                        CJSCore::Init(['jquery3']);
                        Asset::getInstance()->addJs('/bitrix/js/' . $module_id . '/jquery.tree.js');
                        Asset::getInstance()->addString('<script type="text/javascript">
    jQuery(document).ready(function () {
        jQuery("#tree").Tree();
    });
</script>');

                        $progressTotal = (int)Option::get($module_id, 'progressTotal');
                        $progressItem = (int)Option::get($module_id, 'progressItem');
                        $progressStepTotal = (int)Option::get($module_id, 'progressStepTotal');
                        $progressStepItem = (int)Option::get($module_id, 'progressStepItem');
                        $progressDate = Option::get($module_id, 'progressDate');
                        $limit = (int)Option::get($module_id, 'limit');

                        if (!empty($limit)) {
                            $step = (int)Option::get($module_id, 'step');
                            $stepTotal = !empty($progressTotal) ? ceil($progressTotal / $limit) : 0;
                        }

                        if (!empty($progressTotal) || !empty($progressItem)) {
                            $percentTotal = round(($progressItem / $progressTotal) * 100);
                        } else {
                            $percentTotal = 0;
                        }

                        if (!empty($progressStepTotal) || !empty($progressStepItem)) {
                            $percentStep = round(($progressStepItem / $progressStepTotal) * 100);
                        } else {
                            $percentStep = 0;
                        }

                        ?>
                        <div class="progress-notice">
                            <div class="progress-row">
                                <div class="progress-label">
                                    <h3><?php echo Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_PROGRESS_TOTAL'); ?></h3>
                                </div>
                                <div class="progress-container">
                                    <div class="ui-progressbar">
                                        <div class="ui-progressbar-track">
                                            <div class="ui-progressbar-bar"
                                                 style="width:<?php echo $percentTotal; ?>%;"></div>
                                        </div>
                                        <div class="ui-progressbar-text-after"><?php echo $percentTotal; ?>%</div>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($limit)) { ?>
                                <div class="progress-row">
                                    <div class="progress-label">
                                        <h3><?php echo sprintf(Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_PROGRESS_STEP'), ++$step, $stepTotal); ?><?php echo $progressBar['date'] ?? ''; ?></h3>
                                    </div>
                                    <div class="progress-container">
                                        <div class="ui-progressbar">
                                            <div class="ui-progressbar-track">
                                                <div class="ui-progressbar-bar"
                                                     style="width:<?php echo $percentStep; ?>%;"></div>
                                            </div>
                                            <div class="ui-progressbar-text-after"><?php echo $percentStep; ?>%</div>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>
                            <p><?php echo Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_PROGRESS_DATE'); ?><?php echo $progressDate ?? ''; ?></p>
                        </div>
                        <?php
                    }

                    foreach ($aTab['OPTIONS'] as $option) {
                        if (!empty($option[3][0]) && $option[3][0] === 'checkboxes') {
                            $customFields = new CustomFields();
                            echo $customFields->treeCategories($module_id, $option);
                        } elseif (!empty($option[0]) && $option[0] === 'remote_stock') {
                            $customFields = new CustomFields();
                            $customFields->hiddenSelect($module_id, $option);
                        } else {
                            __AdmSettingsDrawRow($module_id, $option);
                        }
                    }
                }
            } else {
                $tabControl->BeginNextTab();
                echo Oorder::getOrdersHtml();
            }
        }

        $tabControl->Buttons();
        ?>

        <input type="submit" name="apply" value="<? echo(Loc::GetMessage('OASIS_IMPORT_OPTIONS_INPUT_APPLY')); ?>"
               class="adm-btn-save"/>
        <input type="submit" name="default" value="<? echo(Loc::GetMessage('OASIS_IMPORT_OPTIONS_INPUT_DEFAULT')); ?>"/>

        <?php
        echo(bitrix_sessid_post());
        ?>

    </form>
<?php

$tabControl->End();

