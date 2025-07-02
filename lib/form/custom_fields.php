<?php

namespace Oasis\Import;

use Bitrix\Main\Config\Option;

class CustomFields
{
	public static function SettingsDrawRowList($cf, $options)
	{
		foreach ($options as $option) {
			if ($option[3][0] == 'tree') {
				$cf->initRelation();
				self::TreeCategories($option, null,  Main::getOasisCategoriesToTree(), $cf->categories, $cf->categories_rel);
			}
			elseif ($option[3][0] == 'category_rel') {
				$cf->initRelation();
				self::CategoryRel($option, $cf->category_rel, $cf->category_rel_label);
			}
			elseif ($option[0] === 'remote_stock' || $option[0] === 'europe_stock') {
				self::HiddenSelect($cf::MODULE_ID, $option);
			}
			else {
				self::SettingsDrawRow($cf::MODULE_ID, $option);
			}
		}
	}

	public static function SettingsDrawRow($module_id, $option)
	{
		$arControllerOption = \CControllerClient::GetInstalledOptions($module_id);

		if(!is_array($option)):
		?>
			<tr class="heading">
				<td colspan="2"><?=$option?></td>
			</tr>
		<?
		elseif(isset($option["note"])):
		?>
			<tr>
				<td colspan="2" align="center">
					<?= BeginNote('align="center"') . $option["note"] . EndNote() ?>
				</td>
			</tr>
		<?
		else:
			if ($option[0] != ""){
				$val = \COption::GetOptionString($module_id, $option[0], $option[2]);
			}
			else {
				$val = $option[2];
			}
			?>
			<tr>
			<?
				self::renderLable($option);
				renderInput($option, $arControllerOption, $option[0], $val);
			?>
			</tr>
		<?
		endif;
	}

	public static function renderLable($option){
		$type = $option[3];
		$opt_label = is_array($option[1]) ? $option[1] : [$option[1]];
		$label = $opt_label[0] ?? '';
		$help = $opt_label[1] ?? '';

		?>
		<td<?if ($type[0]=="multiselectbox" || $type[0]=="textarea" || $type[0]=="statictext" || $type[0]=="statichtml") echo ' class="adm-detail-valign-top"'?> width="50%">
		<?
			if($help){
				ShowJSHint($help);
			}
			if ($type[0]=="checkbox")
				echo "<label for='".htmlspecialcharsbx($option[0])."'>".htmlspecialcharsbx($label)."</label>";
			else
				echo $label;        
			?>
			<a name="opt_<?=htmlspecialcharsbx($option[0])?>"></a>
		</td>
		<?
	}


	public static function TreeCategories($option, $help, $data, $checkedArr, $relCategories)
	{
		?>
		<tr>
			<?= self::renderLable($option) ?>
			<td width="50%">
				<div id="oa-tree" class="oa-tree">
					<div class="oa-tree-ctrl">
						<button type="button" class="ui-btn ui-btn-xs oa-tree-ctrl-m">Свернуть все</button>
						<button type="button" class="ui-btn ui-btn-xs oa-tree-ctrl-p">Развернуть все</button>
					</div>
					<?= self::buildTreeCats($data, $checkedArr, $relCategories) ?>
				</div>
			</td>
		</tr>
		<?
	}

	private static function buildTreeCats($data, array $checkedArr = [], array $relCategories = [], int $parent_id = 0, bool $parent_checked = false): string {
		$treeItem = '';
		if ( ! empty( $data[ $parent_id ] ) ) {
			foreach($data[ $parent_id ] as $item){
				$checked = $parent_checked || in_array($item['id'], $checkedArr);

				$rel_cat = $relCategories[$item['id']] ?? null;
				$rel_label = '';
				$rel_value = '';
				if($rel_cat){
					$rel_value = $item['id'].'_'.$rel_cat['id'];
					$rel_label = $rel_cat['rel_label'];
				}

				$treeItemChilds = self::buildTreeCats($data, $checkedArr, $relCategories, $item['id'], $checked);

				if(empty($treeItemChilds)){
					$treeItem .= '<div class="oa-tree-leaf">
						<div class="oa-tree-label ' . ($rel_value ? 'relation-active' : '') . '">
							<input type="hidden" class="oa-tree-inp-rel ui-ctl-element" name="categories_rel[]" value="' . $rel_value . '" />
							<label>
								<input type="checkbox" class="oa-tree-cb-cat ui-ctl-element" name="categories[]" value="' . $item['id'] . '"' . ($checked ? ' checked' : '' ) . '/>
								<div class="oa-tree-btn-relation"></div>' . $item['name'] . '
							</label>
							<div class="oa-tree-dashed"></div>
							<div class="oa-tree-relation">' . $rel_label . '</div>
						</div>
					</div>';
				}
				else{
					$treeItem .= '<div class="oa-tree-node oa-tree-collapsed">
						<div class="oa-tree-label ' . ($rel_value ? 'relation-active' : '') . '">
							<input type="hidden" class="oa-tree-inp-rel ui-ctl-element" name="categories_rel[]" value="' . $rel_value . '" />
							<span class="oa-tree-handle-p">+</span>
							<span class="oa-tree-handle-m">-</span>
							<label>
								<input type="checkbox" class="oa-tree-cb-cat ui-ctl-element" name="categories[]" value="' . $item['id'] . '"' . ($checked ? ' checked' : '' ) . '/>
								<div class="oa-tree-btn-relation"></div>' . $item['name'] . '
							</label>
							<div class="oa-tree-dashed"></div>
							<div class="oa-tree-relation">' . $rel_label . '</div>
						</div>
						<div class="oa-tree-childs">' . $treeItemChilds . '</div>
					</div>';
				}
			}
		}

		return $treeItem ?? '';
	}

	

	public static function AjaxTreeRadioCats(array $data, $cf): string {
		return '<div class="oa-tree oa-tree-popup">
				<div class="oa-tree-ctrl oa-tree-ctrl-center">
					<button type="button" class="ui-btn ui-btn-xs oa-tree-ctrl-m">Свернуть все</button>
					<button type="button" class="ui-btn ui-btn-xs oa-tree-ctrl-p">Развернуть все</button>
				</div>
				<div class="oa-tree-leaf">
					<div class="oa-tree-label">
						<label><input type="radio" class="oa-tree-radio" name="oa_radio_tree" value="0" />' . $cf::CATALOG_ROOT_NAME . '</label>
					</div>
				</div>' . self::buildTreeRadioCats($data) . '</div>';
	}


	private static function buildTreeRadioCats( $data, array $checked_id = null, int $parent_id = 0 ): string {
		$treeItem = '';
		if ( ! empty( $data[ $parent_id ] ) ) {
			foreach($data[ $parent_id ] as $item){
				$checked = $checked_id === $item['id'];

				$treeItemChilds = self::buildTreeRadioCats( $data, $checkedArr, $item['id'] );

				if(empty($treeItemChilds)){
					$treeItem .= '<div class="oa-tree-leaf">
						<div class="oa-tree-label">
							<label><input type="radio" class="oa-tree-radio" name="oa_radio_tree" value="' . $item['id'] . '"' . $checked . '/>' . $item['name'] . '</label>
						</div>
					</div>';
				}
				else{
					$treeItem .= '<div class="oa-tree-node oa-tree-collapsed">
						<div class="oa-tree-label">
							<span class="oa-tree-handle-p">+</span>
							<span class="oa-tree-handle-m">-</span>
							<label><input type="radio" class="oa-tree-radio" name="oa_radio_tree" value="' . $item['id'] . '"' . $checked . '/>' . $item['name'] . '</label>
						</div>
						<div class="oa-tree-childs">' . $treeItemChilds . '</div>
					</div>';
				}
			}
		}

		return $treeItem ?? '';
	}

	private static function CategoryRel($option, $value, $value_label)
	{
		?>
		<tr>
			<? self::renderLable($option); ?>
			<td id="cf_opt_<?=  $option[0] ?>">
				<input type="hidden" value="<?= $value ?>" name="<?= $option[0] ?>">
				<div class="oa-category-rel"><?= htmlspecialcharsbx($value_label) ?></div>
			<td>
		</tr>
		<?
	}

	public static function HiddenSelect($module_id, $option)
	{
		$multiStocks = Option::get($module_id, 'stocks');
		$siteValue = Option::get($module_id, $option[0]);

		if ($multiStocks == 'Y') {
			$style = '';
		} else {
			$style = 'display: none;';
			$siteValue = '';
		}

		?>
		<tr id="<?php echo $option[0]; ?>" style="<?php echo $style; ?>">
			<?
			self::renderLable($option);
			renderInput($option, [], $option[0], $siteValue ?? $option[2]);
			?>
		</tr>
		<?
	}
}