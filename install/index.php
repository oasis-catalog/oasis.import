<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Application;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

class stronglink_oasiscatalog extends CModule
{
	public $MODULE_ID = 'stronglink.oasiscatalog';

	public function __construct()
	{
		$arModuleVersion = [];
		include(__DIR__.'/version.php');

		$this->MODULE_VERSION      = $arModuleVersion['VERSION'];
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
		$this->MODULE_NAME         = Loc::getMessage('OASIS_CATALOG_NAME');
		$this->MODULE_DESCRIPTION  = Loc::getMessage('OASIS_CATALOG_DESCRIPTION');
		$this->PARTNER_NAME        = Loc::getMessage('OASIS_CATALOG_PARTNER_NAME');
		$this->PARTNER_URI         = Loc::getMessage('OASIS_CATALOG_PARTNER_URI');
		$this->MODULE_GROUP_RIGHTS = 'Y';
	}

	public function DoInstall()
	{
		global $APPLICATION;

		if (CheckVersion(ModuleManager::getVersion('main'), '14.00.00')) {
			$this->InstallFiles();
			$this->InstallDB();

			ModuleManager::registerModule($this->MODULE_ID);

			$this->InstallEvents();

			$objDateTime = new \Bitrix\Main\Type\DateTime();
			$dateUpStock = $objDateTime->add('-30 min')->toString();
			$dateImport = $objDateTime->add('-1 days')->toString();

			Option::set('main', 'agents_use_crontab', 'N');
			Option::set('main', 'check_agents', 'N');
			Option::set($this->MODULE_ID, 'step', 0);
			\CAgent::AddAgent('\\OasisCatalog\\Import\\Cli::ImportAgent();', $this->MODULE_ID, 'N', 24 * 60 * 60, '', 'Y', $dateImport);
			\CAgent::AddAgent('\\OasisCatalog\\Import\\Cli::UpStockAgent();', $this->MODULE_ID, 'N', 30 * 60, '', 'Y', $dateUpStock);
		} else {
			$APPLICATION->ThrowException(
				Loc::getMessage('OASIS_CATALOG_INSTALL_ERROR_VERSION')
			);
		}

		$APPLICATION->IncludeAdminFile(
			Loc::getMessage('OASIS_CATALOG_INSTALL_TITLE') . ' "' . Loc::getMessage('OASIS_CATALOG_NAME') . '"',
			__DIR__ . '/step.php'
		);

		return false;
	}

	public function InstallFiles()
	{
		CopyDirFiles(
			__DIR__ . '/assets/css',
			Application::getDocumentRoot() . '/bitrix/css/' . $this->MODULE_ID . '/',
			true,
			true
		);

		CopyDirFiles(
			__DIR__ . '/assets/js',
			Application::getDocumentRoot() . '/bitrix/js/' . $this->MODULE_ID . '/',
			true,
			true
		);

		return false;
	}

	public function InstallDB()
	{
		global $DB;
		$this->errors = false;
		$this->errors = $DB->RunSQLBatch($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/' . $this->MODULE_ID . '/install/db/mysql/install.sql');
		if (!$this->errors) {
			return true;
		} else {
			return $this->errors;
		}
	}

	public function InstallEvents()
	{
		return true;
	}

	public function DoUninstall()
	{
		global $APPLICATION;

		$this->UnInstallFiles();
		$this->UnInstallDB();
		$this->UnInstallEvents();
		Option::delete($this->MODULE_ID);

		\CAgent::RemoveModuleAgents($this->MODULE_ID);

		ModuleManager::unRegisterModule($this->MODULE_ID);

		$APPLICATION->IncludeAdminFile(
			Loc::getMessage('OASIS_CATALOG_UNINSTALL_TITLE') . ' "' . Loc::getMessage('OASIS_CATALOG_NAME') . '"',
			__DIR__ . '/unstep.php'
		);

		return false;
	}

	public function UnInstallFiles()
	{
		Directory::deleteDirectory(Application::getDocumentRoot() . '/bitrix/css/' . $this->MODULE_ID);
		Directory::deleteDirectory(Application::getDocumentRoot() . '/bitrix/js/' . $this->MODULE_ID);
		return false;
	}

	public function UnInstallDB()
	{
		global $DB;
		$this->errors = false;
		$this->errors = $DB->RunSQLBatch($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/' . $this->MODULE_ID . '/install/db/mysql/uninstall.sql');
		if (!$this->errors) {
			return true;
		} else {
			return $this->errors;
		}
	}

	public function UnInstallEvents()
	{
		return true;
	}
}