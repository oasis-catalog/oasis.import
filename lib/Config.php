<?php
namespace Oasis\Import;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Application;


class Config {
	public const MODULE_ID = 'oasis.import';
	public const CATALOG_ROOT_NAME = 'Корень инфоблока';

	public bool $debug = false;
	public bool $debug_log = false;
	public string $root_path;

	public string $cron_type;
	public string $api_key;
	public string $api_user_id;

	public ?int $iblock_catalog;
	public ?int $iblock_offers;

	public array $categories;
	public array $categories_rel;
	private array $categories_easy;
	public ?int $category_rel;
	public string $category_rel_label;

	public bool $delete_exclude;
	public bool $not_up_product_cat;
	public bool $import_anytime;

	public int $limit;

	public ?\DateTime $import_date;
	public string $progressDate;
	public int $progressTotal;
	public int $progressItem;
	public int $progressStepTotal;
	public int $progressStepItem;
	public int $step;

	public float $factor;
	public float $increase;
	public bool $dealer;

	public bool $move_first_img_to_detail;
	public bool $up_photo;

	private bool $init_rel = false;


	public function __construct($opt = []) {
		$this->root_path = Application::getDocumentRoot();

		if(isset($opt['debug']) || isset($opt['debug_log'])){
			$this->debug = true;
		}
		if(isset($opt['debug_log'])){
			$this->debug_log = true;
		}
	}

	public function init() {
		$opt = Option::getForModule($this::MODULE_ID);

		$this->cron_type =			$opt['cron_type'] ?? '';
		$this->api_key =			$opt['api_key'] ?? '';
		$this->api_user_id =		$opt['api_user_id'] ?? '';

		$this->iblock_catalog = 	$opt['iblock_catalog'] ? intval($opt['iblock_catalog']) : null;
		$this->iblock_offers =		$opt['iblock_offers'] ? intval($opt['iblock_offers']) : null;

		$cat = [];
		if (!empty($opt['categories']) || !$opt['categories'] === 'Y') {
			$cat = explode(',', $opt['categories']);
		}
		$this->categories =			array_map(fn($x) => intval($x), $cat);

		$cat_rel = [];
		if (!empty($opt['categories_rel']) || !$opt['categories_rel'] === 'Y') {
			$cat_rel = explode(',', $opt['categories_rel']);
		}
		$this->categories_rel = [];
		foreach($cat_rel as $rel){
			$rel = 	explode('_', $rel);
			$cat_id = (int)$rel[0];
			$rel_id = (int)$rel[1];

			$this->categories_rel[$cat_id] = [
				'id' =>  $rel_id,
				'rel_label' => null
			];
		}

		$this->category_rel = 		(isset($opt['category_rel']) && $opt['category_rel'] !== '') ? intval($opt['category_rel']) : null;
		$this->category_rel_label = '';

		$this->delete_exclude = 	$opt['delete_exclude'] === 'Y';
		$this->not_up_product_cat = $opt['not_up_product_cat'] === 'Y';
		$this->import_anytime = 	$opt['import_anytime'] === 'Y';
		$this->limit =				$opt['limit'] ? intval($opt['limit']) : 0;

		$dt = null;
		if(!empty($opt['import_date'])){
			$dt = \DateTime::createFromFormat('d.m.Y H:i:s', $opt['import_date']);
		} 
		$this->import_date =		$dt;

		$this->progressDate =		$opt['progressDate'] ?? '';
		$this->progressTotal =		$opt['progressTotal'] ? intval($opt['progressTotal']) : 0;
		$this->progressItem =		$opt['progressItem'] ? intval($opt['progressItem']) : 0;
		$this->progressStepTotal =	$opt['progressStepTotal'] ? intval($opt['progressStepTotal']) : 0;
		$this->progressStepItem =	$opt['progressStepItem'] ? intval($opt['progressStepItem']) : 0;
		$this->step =				$opt['step'] ? intval($opt['step']) : 0;

		$this->factor =				$opt['factor'] ? floatval(str_replace(',', '.', $opt['factor'])) : 0;
		$this->increase =			$opt['increase'] ? floatval(str_replace(',', '.', $opt['increase'])) : 0;
		$this->dealer =				$opt['dealer'] === 'Y';

		$this->move_first_img_to_detail =	$opt['move_first_img_to_detail'] === 'Y';
		$this->up_photo	=					$opt['up_photo'] === 'Y';
	}

	public function initRelation() {
		if($this->init_rel){
			return;
		}

		foreach($this->categories_rel as $cat_id => $rel){
			$this->categories_rel[$cat_id]['rel_label'] = $this->getRelLabel($rel['id']);
		}
		if(isset($this->category_rel)){
			$this->category_rel_label = $this->getRelLabel($this->category_rel);
		}

		$this->init_rel = true;
	}

	private function getRelLabel(int $rel_id) {
		if($rel_id === 0){
			return $this::CATALOG_ROOT_NAME;
		}

		$nav = \CIBlockSection::GetNavChain($this->iblock_catalog, $rel_id);
		$result = [];
		foreach($nav->arResult as $item){
			$result []= $item['NAME'];
		}
		return implode(' / ', $result);
 	}

	public function checkCronKey(string $cron_key): bool {
		return $cron_key === md5($this->api_key);
	}

	public function lock($fn, $fn_error) {
		$path = $this->root_path . '/upload/module-oasis/';

		if (!is_dir($path)) {
			if(!mkdir($path, 0777, true)){
				die('Failed to create directories: ' . $path);
			}
		}

		$lock = fopen($path . 'start.lock', 'w');
		if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
			$fn();
		}
		else{
			$fn_error();
		}
	}

	public function checkPermissionImport(): bool {
		if(!$this->import_anytime && 
			$this->import_date &&
			$this->import_date->format("Y-m-d") == (new \DateTime())->format("Y-m-d")){
				return false;
		}
		return true;
	}

	public function log($str) {
		if ($this->debug) {
			$str = '['.date('Y-m-d H:i:s').'] '.$str;

			if ($this->debug_log) {
				file_put_contents($this->root_path . '/upload/module-oasis/oasis_'.date('Y-m-d').'.log', $str . "\n", FILE_APPEND);
			} else {
				echo $str . PHP_EOL;
			}
		}
	}

	public function deleteLogFile() {
		$filePath = $this->root_path . '/upload/module-oasis/oasis.log';
		if (file_exists($filePath)) {
			unlink($filePath);
		}
	}

	public function getRelCategoryId($oasis_cat_id) {
		if(isset($this->categories_rel[$oasis_cat_id])){
			return $this->categories_rel[$oasis_cat_id]['id'];
		}
		if(isset($this->category_rel)){
			return $this->category_rel;
		}
		return null;
	}

	public function getEasyCategories() {
		// if($this->categories_easy){
		// 	return $this->categories_easy;
		// }

		// $categories = Api::getCategoriesOasis();

		// //categories_easy
	}
}