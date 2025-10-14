<?php

use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\HttpApplication;
use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Page\Asset;
use Oasis\Import\Main;
use Oasis\Import\CustomFields;
use Oasis\Import\Oorder;
use Oasis\Import\Config as OasisConfig;


$module_id = 'oasis.import';

Loc::loadMessages(__FILE__);
Loader::includeModule($module_id);

$cf = OasisConfig::instance([
	'init' => true
]);

$request = HttpApplication::getInstance()->getContext()->getRequest();


if ($request->getRequestMethod() == 'GET' && $request['action'] == 'getTreeRelation') {
	echo CustomFields::AjaxTreeRadioCats(Main::getCategoriesToTree(), $cf);
	die();
}

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
			[
				'europe_stock',
				Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_EUROPE_STOCK'),
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
						'custom' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CRON_CUSTOM'),
						'bitrix' => Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CRON_BITRIX'),
					]
				]
			],
		]
	]
];

$currencies = Main::getCurrenciesOasisArray();

if (!empty($currencies)) {
	$cronType = OasisConfig::get('cron_type');
	$apiKey = OasisConfig::get('api_key');

	if ($cronType === 'custom') {
		$cronPath = str_replace('\\', '/', realpath(dirname(__FILE__))) . '/cron.php --key=' . md5($apiKey);
	} else {
		$cronPath = Application::getDocumentRoot() . '/bitrix/php_interface/cron_events.php';
	}

	$aTabs[0]['OPTIONS'] = array_merge($aTabs[0]['OPTIONS'], [
		[
			'note' => $cronType === 'custom' ? sprintf(Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CRON_DESC_CUSTOM'), $cronPath, $cronPath) : sprintf(Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CRON_DESC'), $cronPath),
		],
		Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_OPTIONS_IMPORT'),
		[
			'categories',
			[Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CATEGORIES'), Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CATEGORIES_DESC')],
			'',
			['tree']
		],
		[
			'category_rel',
			[Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CATEGORY_REL'), Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CATEGORY_REL_DESC')],
			'',
			['category_rel']
		],
		[
			'delete_exclude',
			Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_DELETE_EXCLUDE'),
			'N',
			['checkbox']
		],
		[
			'not_up_product_cat',
			Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_NOT_UP_PRODUCT_CAT'),
			'N',
			['checkbox']
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
			'not_defect',
			Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_NOT_DEFECT'),
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
		[
			'import_anytime',
			[Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_IMPORT_ANYTIME'), Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_IMPORT_ANYTIME_NOTE')],
			'N',
			['checkbox']
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
		Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_PHOTO'),
		[
			'move_first_img_to_detail',
			Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_MOVE_FIRST_IMG_TO_DETAIL'),
			'N',
			['checkbox']
		],
		[
			'up_photo',
			Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_UP_PHOTO'),
			'N',
			['checkbox']
		],
		[
			'is_cdn_photo',
			[Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CDN_PHOTO'), Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_CDN_PHOTO_NOTE')],
			'N',
			['checkbox']
		],
		Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_EXTRA'),
		[
			'is_brands',
			Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_BRANDS'),
			'N',
			['checkbox']
		],
		[
			'is_branding',
			[Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_BRANDING'), Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_BRANDING_NOTE')],
			'N',
			['checkbox']
		],
		[
			'branding_box',
			Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_BRANDING_BOX'),
			'',
			['text', 50]
		],
		[
			'is_fast_import',
			[Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_FAST_IMPORT'), Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_FAST_IMPORT_NOTE')],
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
				} elseif ($arOption[0] === 'main_stock' || $arOption[0] === 'remote_stock' || $arOption[0] === 'europe_stock') {
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
			if (!is_array($arOption) || $arOption['note']) {
				continue;
			}
			$key = $arOption[0];

			if ($request['apply']) {
				$optionValue = $request->getPost($key);

				if ($key == 'limit') {
					if ($optionValue && intval($optionValue) >= 0) {
						$optionValue = abs($optionValue);
					} else {
						$optionValue = '';
					}
				}
				elseif ($key == 'categories') {
					$categories_rel = $request->getPost('categories_rel') ?? [];
					OasisConfig::set('categories_rel', implode(',', $categories_rel));

					$optionValue = implode(',', Main::simplifyOptionCategories($optionValue ?? []));
				}
				else {
					$optionValue = is_array($optionValue) ? implode(',', $optionValue) : $optionValue;
				}

				OasisConfig::set($key, $optionValue);
			} elseif ($request['default']) {
				OasisConfig::set($key, $arOption[2]);
				OasisConfig::set('categories_rel', '');
			}
		}

		$eventManager = EventManager::getInstance();
		if (OasisConfig::get('is_branding') === 'Y') {
			$eventManager->registerEventHandler('sale', 'OnSaleBasketItemSaved', $module_id, 'Oasis\Import\Cli', 'OnSaleBasketItemSaved');
			$eventManager->registerEventHandler('sale', 'OnSaleBasketBeforeSaved', $module_id, 'Oasis\Import\Cli', 'OnSaleBasketBeforeSaved');
		}
		else {
			$eventManager->unRegisterEventHandler('sale', 'OnSaleBasketItemSaved', $module_id, 'Oasis\Import\Cli', 'OnSaleBasketItemSaved');
			$eventManager->unRegisterEventHandler('sale', 'OnSaleBasketBeforeSaved', $module_id, 'Oasis\Import\Cli', 'OnSaleBasketBeforeSaved');
		}

		if (OasisConfig::get('is_branding') === 'Y' || OasisConfig::get('is_cdn_photo') === 'Y') {
			$eventManager->registerEventHandler('main', 'OnEpilog', $module_id, 'Oasis\Import\Cli', 'OnEpilog');
		}
		else {
			$eventManager->unRegisterEventHandler('main', 'OnEpilog', $module_id, 'Oasis\Import\Cli', 'OnEpilog');
		}

		if (OasisConfig::get('is_cdn_photo') === 'Y') {
			$eventManager->registerEventHandler('main', 'OnGetFileSRC', $module_id, 'Oasis\Import\Cli', 'OnGetFileSRC');
		}
		else {
			$eventManager->unRegisterEventHandler('main', 'OnGetFileSRC', $module_id, 'Oasis\Import\Cli', 'OnGetFileSRC');
		}

		$cf->progressClear();
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
	<form action="<? echo($APPLICATION->GetCurPage()); ?>?mid=<? echo($module_id); ?>&lang=<? echo(LANG); ?>"
		  method="post">

		<?php
		foreach ($aTabs as $aTab) {
			if ($aTab['DIV'] !== 'orders') {
				if ($aTab['OPTIONS']) {
					$tabControl->BeginNextTab();

					if ($currencies) {
						\Bitrix\Main\UI\Extension::load(['ui.progressbar', 'ui.buttons']);
						$APPLICATION->SetAdditionalCSS('/bitrix/css/' . $module_id . '/stylesheet.css');

						CJSCore::Init(['jquery3']);
						Asset::getInstance()->addJs('/bitrix/js/' . $module_id . '/tree.js');
						Asset::getInstance()->addJs('/bitrix/js/' . $module_id . '/options.js');

						$optBar = $cf->getOptBar();
						?>
						<div class="progress-notice">
							<div class="progress-row">
								<div class="progress-label">
									<h3><?php echo Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_PROGRESS_TOTAL'); ?></h3>
								</div>
								<div class="progress-container">
									<div class="ui-progressbar">
										<div class="ui-progressbar-track">
											<div class="ui-progressbar-bar" style="width:<?php echo $optBar['p_total']; ?>%;"></div>
										</div>
										<div class="ui-progressbar-text-after"><?php echo $optBar['p_total']; ?>%</div>
									</div>
								</div>
							</div>
							<?php if (!empty($cf->limit)) { ?>
								<div class="progress-row">
									<div class="progress-label">
										<h3><?php echo sprintf(Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_PROGRESS_STEP'), ($optBar['step'] + 1), $optBar['steps']); ?></h3>
									</div>
									<div class="progress-container">
										<div class="ui-progressbar">
											<div class="ui-progressbar-track">
												<div class="ui-progressbar-bar" style="width:<?php echo $optBar['p_step']; ?>%;"></div>
											</div>
											<div class="ui-progressbar-text-after"><?php echo $optBar['p_step']; ?>%</div>
										</div>
									</div>
								</div>
							<?php } ?>
							<p><?php echo Loc::getMessage('OASIS_IMPORT_OPTIONS_TAB_PROGRESS_DATE'); ?><?php echo $optBar['date'] ?? ''; ?></p>
						</div>
						<?php
					}
					CustomFields::SettingsDrawRowList($cf, $aTab['OPTIONS']);
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