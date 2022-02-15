<?php

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\HttpApplication;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
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
            ]
        ]
    ];

    $currencies = Main::getCurrenciesOasisArray();

    if (!empty($currencies)) {
        $aTabs[0]['OPTIONS'][] = Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CRON_TITLE');
        $aTabs[0]['OPTIONS'][] = [
            'note' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CRON_DESC_PREFIX') .
                Application::getDocumentRoot() .
                Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CRON_DESC_POSTFIX'),
        ];
        $aTabs[0]['OPTIONS'][] = Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_OPTIONS_IMPORT');
        $mainCategories = Main::getOasisMainCategories();

        $aTabs[0]['OPTIONS'][] = [
            'categories',
            Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CATEGORIES'),
            'Y',
            ['checkboxes', $mainCategories]
        ];

        $aTabs[0]['OPTIONS'] = array_merge($aTabs[0]['OPTIONS'], [
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
                    ''  => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_RATING_SELECT'),
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
        ]);

        $aTabs[] = [
            'DIV'     => 'orders',
            'TAB'     => Loc::getMessage('OASIS_IMPORT_ORDERS_TAB_NAME'),
            'TITLE'   => Loc::getMessage('OASIS_IMPORT_ORDERS_TAB_AUTH'),
        ];
    } else {
        $aTabs[0]['OPTIONS'] = array_merge($aTabs[0]['OPTIONS'], [
            [
                'note' => Loc::getMessage('OASIS_IMPORT_OPTIONS_ERROR_API_KEY'),
            ],
        ]);
    }
} catch (Exception $e) {
}

if ($request->isPost() && check_bitrix_sessid()) {
    foreach ($aTabs as $aTab) {
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
?>

    <form action="<? echo($APPLICATION->GetCurPage()); ?>?mid=<? echo($module_id); ?>&lang=<? echo(LANG); ?>" method="post">

        <?php
        foreach ($aTabs as $aTab) {
            if ($aTab['DIV'] !== 'orders') {
                if ($aTab['OPTIONS']) {
                    $tabControl->BeginNextTab();
                    foreach ($aTab['OPTIONS'] as $option) {
                            if (!empty($option[3][0]) && $option[3][0] === 'checkboxes') {
                                CustomFields::checkboxes($module_id, $option);
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

        <input type="submit" name="apply" value="<? echo(Loc::GetMessage('OASIS_IMPORT_OPTIONS_INPUT_APPLY')); ?>" class="adm-btn-save"/>
        <input type="submit" name="default" value="<? echo(Loc::GetMessage('OASIS_IMPORT_OPTIONS_INPUT_DEFAULT')); ?>"/>

        <?php
        echo(bitrix_sessid_post());
        ?>

    </form>
<?php

$tabControl->End();

