<?php
namespace Oasis\Import;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Oasis\Import\Cli;
use Oasis\Import\Main;
use Oasis\Import\Api;


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

	public string $currency;
	public bool $is_no_vat;
	public bool $is_not_on_order;
	public bool $is_not_defect;
	public ?float $price_from;
	public ?float $price_to;
	public ?int $rating;
	public bool $is_wh_moscow;
	public bool $is_wh_europe;
	public bool $is_wh_remote;

	public array $progress;
	public float $factor;
	public float $increase;
	public bool $dealer;

	public bool $move_first_img_to_detail;
	public bool $up_photo;
	public bool $is_cdn_photo;
	public bool $is_brands;
	public bool $is_branding;
	public string $branding_box;
	
	public bool $is_fast_import;

	private bool $is_init = false;
	private bool $is_init_rel = false;

	private static $instance;

	public static function instance($opt = []) {
		if (!isset(self::$instance)) {
			self::$instance = new self($opt);
		} else {
			if(!empty($opt['init'])){
				self::$instance->init();
			}
			if(!empty($opt['init_rel'])){
				self::$instance->initRelation();
			}
		}

		return self::$instance;
	}

	public function __construct($opt = []) {
		$this->root_path = Application::getDocumentRoot();

		$this->debug = !empty($opt['debug']);
		$this->debug_log = !empty($opt['debug_log']);

		Cli::$cf = $this;
		Main::$cf = $this;
		Api::$cf = $this;

		if(!empty($opt['init'])){
			$this->init();
		}
		if(!empty($opt['init_rel'])){
			$this->initRelation();
		}
	}

	public function init() {
		if($this->is_init) {
			return;
		}

		$opt = Option::getForModule($this::MODULE_ID);

		$this->cron_type      = $opt['cron_type'] ?? '';
		$this->api_key        = $opt['api_key'] ?? '';
		$this->api_user_id    = $opt['api_user_id'] ?? '';

		$this->iblock_catalog = $opt['iblock_catalog'] ? intval($opt['iblock_catalog']) : null;
		$this->iblock_offers  = $opt['iblock_offers'] ? intval($opt['iblock_offers']) : null;

		$cat = [];
		if (!empty($opt['categories']) || !$opt['categories'] === 'Y') {
			$cat = explode(',', $opt['categories']);
		}
		$this->categories = array_map(fn($x) => intval($x), $cat);

		$cat_rel = [];
		if (!empty($opt['categories_rel']) || !$opt['categories_rel'] === 'Y') {
			$cat_rel = explode(',', $opt['categories_rel']);
		}
		$this->categories_rel = [];
		foreach ($cat_rel as $rel) {
			$rel = explode('_', $rel);
			$cat_id = (int)$rel[0];
			$rel_id = (int)$rel[1];

			$this->categories_rel[$cat_id] = [
				'id' =>  $rel_id,
				'rel_label' => null
			];
		}

		$this->category_rel       = (isset($opt['category_rel']) && $opt['category_rel'] !== '') ? intval($opt['category_rel']) : null;
		$this->category_rel_label = '';

		$this->delete_exclude     = $opt['delete_exclude'] === 'Y';
		$this->not_up_product_cat = $opt['not_up_product_cat'] === 'Y';
		$this->import_anytime     = $opt['import_anytime'] === 'Y';
		$this->limit              = $opt['limit'] ? intval($opt['limit']) : 0;
		$this->currency           = $opt['currency'] ?? 'rub';
		$this->is_no_vat          = $opt['no_vat'] === 'Y';
		$this->is_not_on_order    = $opt['not_on_order'] === 'Y';
		$this->is_not_defect      = $opt['not_defect'] === 'Y';
		$this->price_from         = $opt['price_from'] ? floatval(str_replace(',', '.', $opt['price_from'])) : null;
		$this->price_to           = $opt['price_to'] ? floatval(str_replace(',', '.', $opt['price_to'])) : null;
		$this->rating             = $opt['rating'] ? intval($opt['rating']) : null;
		$this->is_wh_moscow       = $opt['warehouse_moscow'] === 'Y';
		$this->is_wh_europe       = $opt['warehouse_europe'] === 'Y';
		$this->is_wh_remote       = $opt['remote_warehouse'] === 'Y';

		$this->progress = [
			'item'       => $opt['progress_item'] ?? 0,			// count updated products
			'total'      => $opt['progress_total'] ?? 0,		// count all products
			'step'       => $opt['progress_step'] ?? 0,			// step (for limit)
			'step_item'  => $opt['progress_step_item'] ?? 0,	// count updated products for step
			'step_total' => $opt['progress_step_total'] ?? 0,	// count step total products
			'date'       => $opt['progress_date'] ?? '',		// date end import
			'date_step'  => $opt['progress_date_step'] ?? ''	// date end import for step
		];

		$this->factor                   = $opt['factor'] ? floatval(str_replace(',', '.', $opt['factor'])) : 0;
		$this->increase                 = $opt['increase'] ? floatval(str_replace(',', '.', $opt['increase'])) : 0;
		$this->dealer                   = $opt['dealer'] === 'Y';
		$this->move_first_img_to_detail = $opt['move_first_img_to_detail'] === 'Y';
		$this->up_photo	                = $opt['up_photo'] === 'Y';
		$this->is_cdn_photo             = $opt['is_cdn_photo'] === 'Y';
		$this->is_brands                = $opt['is_brands'] === 'Y';
		$this->is_branding              = $opt['is_branding'] === 'Y';
		$this->branding_box             = $opt['branding_box'] ?? '';

		$this->is_fast_import           = $opt['is_fast_import'] === 'Y';

		$this->is_init = true;
	}

	public function initRelation() {
		if($this->is_init_rel){
			return;
		}

		Loader::includeModule('iblock');

		foreach($this->categories_rel as $cat_id => $rel){
			$this->categories_rel[$cat_id]['rel_label'] = $this->getRelLabel($rel['id']);
		}
		if(isset($this->category_rel)){
			$this->category_rel_label = $this->getRelLabel($this->category_rel);
		}

		$this->is_init_rel = true;
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

 	public function progressStart(int $total, int $step_total) {
		$this->progress['total'] = $total;
		$this->progress['step_total'] = $step_total;
		$this->progress['step_item'] = 0;
		$this->updateSettingProgress();
	}

	public function progressUp() {
		$this->progress['step_item']++;
		$this->updateSettingProgress();
	}

	public function progressEnd() {
		$dt = (new \DateTime())->format('d.m.Y H:i:s');
		$this->progress['date_step'] = $dt;

		$is_full_import = false;
		if ($this->limit > 0) {
			$this->progress['item'] += $this->progress['step_item'];

			if(($this->limit * ($this->progress['step'] + 1)) > $this->progress['total']){
				$this->progress['step'] = 0;
				$is_full_import = true;
			}
			else{
				$this->progress['step']++;
			}
		}
		else {
			$is_full_import = true;
		}

		$this->progress['step_item'] = 0;
		$this->progress['step_total'] = 0;

		if($is_full_import) {
			$this->progress['item'] = 0;
			$this->progress['date'] = $dt;

			if($this->is_fast_import) {
				$this->is_fast_import = false;
				Option::set(self::MODULE_ID, 'is_fast_import', 'N');
			}
		}

		$this->updateSettingProgress();
	}

	public function progressClear() {
		$this->progress['total']      = 0;
		$this->progress['step']       = 0;
		$this->progress['item']       = 0;
		$this->progress['step_item']  = 0;
		$this->progress['step_total'] = 0;
		$this->progress['date']       = '';
		$this->progress['date_step']  = '';

		$this->updateSettingProgress();
	}

	private function updateSettingProgress() {
		$p = $this->progress;
		Option::set(self::MODULE_ID, 'progress_item',		$p['item']);
		Option::set(self::MODULE_ID, 'progress_total',		$p['total']);
		Option::set(self::MODULE_ID, 'progress_step',		$p['step']);
		Option::set(self::MODULE_ID, 'progress_step_item',	$p['step_item']);
		Option::set(self::MODULE_ID, 'progress_step_total',	$p['step_total']);
		Option::set(self::MODULE_ID, 'progress_date', 		$p['date']);
		Option::set(self::MODULE_ID, 'progress_date_step',	$p['date_step']);
	}

	public function getOptBar() {
		$opt = $this->progress;
		$p_total = 0;
		$p_step = 0;

		if (!empty($opt['step_item']) && !empty($opt['step_total'])) {
			$p_step = round(($opt['step_item'] / $opt['step_total']) * 100, 2, PHP_ROUND_HALF_DOWN );
			$p_step = min($p_step, 100);
		}

		if (!(empty($opt['item']) && empty($opt['step_item'])) && !empty($opt['total'])) {
			$p_total = round((($opt['item'] + $opt['step_item']) / $opt['total']) * 100, 2, PHP_ROUND_HALF_DOWN );
			$p_total = min($p_total, 100);
		}

		return [
			'p_total' => $p_total,
			'p_step'  => $p_step,
			'step'    => $opt['step'] ?? 0,
			'steps'   => ($this->limit > 0 && !empty($opt['total'])) ? (ceil($opt['total'] / $this->limit)) : 0,
			'date'    => $opt['date_step'] ?? ''
		];
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
		if(!$this->import_anytime 
			&& $this->progress['date']
			&& \DateTime::createFromFormat('d.m.Y H:i:s', $this->progress['date'])->format("Y-m-d") == (new \DateTime())->format("Y-m-d")
		) {
				return false;
		}
		return true;
	}

	public function log($str) {
		if ($this->debug || $this->debug_log) {
			$str = date('H:i:s').' '.$str;

			if ($this->debug_log) {
				file_put_contents($this->root_path . '/upload/module-oasis/oasis_'.date('Y-m-d').'.log', $str . "\n", FILE_APPEND);
			} else {
				echo $str . PHP_EOL;
			}
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

	public static function get($key) {
		return Option::get(self::MODULE_ID, $key);
	}

	public static function set($key, $val) {
		return Option::set(self::MODULE_ID, $key, $val);
	}
}