<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Application;
use Bitrix\Main\IO\Directory;
use Bitrix\Main\Type\DateTime;

Loc::loadMessages(__FILE__);

class oasis_import extends CModule
{
    public function __construct()
    {
        if (file_exists(__DIR__ . '/version.php')) {

            $arModuleVersion = [];
            include_once(__DIR__ . '/version.php');

            $this->MODULE_ID = str_replace('_', '.', get_class($this));
            $this->MODULE_VERSION = $arModuleVersion['VERSION'];
            $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
            $this->MODULE_NAME = Loc::getMessage('OASIS_IMPORT_NAME');
            $this->MODULE_DESCRIPTION = Loc::getMessage('OASIS_IMPORT_DESCRIPTION');
            $this->PARTNER_NAME = Loc::getMessage('OASIS_IMPORT_PARTNER_NAME');
            $this->PARTNER_URI = Loc::getMessage('OASIS_IMPORT_PARTNER_URI');
            $this->MODULE_GROUP_RIGHTS = 'Y';
        }

        return false;
    }

    public function DoInstall()
    {
        global $APPLICATION;

        if (CheckVersion(ModuleManager::getVersion('main'), '14.00.00')) {
            $this->InstallFiles();
            $this->InstallDB();

            ModuleManager::registerModule($this->MODULE_ID);

            $this->InstallEvents();

            $objDateTime = new DateTime();
            $dateImport = new DateTime($objDateTime, 'd.m.Y');

            Option::set('main', 'agents_use_crontab', 'N');
            Option::set('main', 'check_agents', 'N');
            \CAgent::AddAgent('\\Oasis\\Import\\Cli::import();', 'oasis.import', 'N', 24 * 60 * 60, '', 'Y', $dateImport->add('1 days 1 hours 30 min')->toString());
            \CAgent::AddAgent('\\Oasis\\Import\\Cli::upStock();', 'oasis.import', 'N', 30 * 60, '', 'Y', $objDateTime->add('30 min')->format('d.m.Y H:i:s'));
        } else {
            $APPLICATION->ThrowException(
                Loc::getMessage('OASIS_IMPORT_INSTALL_ERROR_VERSION')
            );
        }

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('OASIS_IMPORT_INSTALL_TITLE') . ' "' . Loc::getMessage("OASIS_IMPORT_NAME") . '"',
            __DIR__ . '/step.php'
        );

        return false;
    }

    public function InstallFiles()
    {
        CopyDirFiles(
            __DIR__ . '/assets/php',
            Application::getDocumentRoot() . '/bitrix/php_interface/',
            false,
            true
        );

        return false;
    }

    public function InstallDB()
    {
        global $DB;
        $this->errors = false;
        $this->errors = $DB->RunSQLBatch(Application::getDocumentRoot() . '/local/modules/oasis.import/install/db/mysql/install.sql');
        if (!$this->errors) {
            return true;
        } else {
            return $this->errors;
        }
    }

    public function InstallEvents()
    {
        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnBeforeEndBufferContent',
            $this->MODULE_ID,
            'Oasis\Import\Main',
        );

        EventManager::getInstance()->registerEventHandler(
            'api',
            'OnBeforeEndBufferContent',
            $this->MODULE_ID,
            'Oasis\Import\Api',
        );

        EventManager::getInstance()->registerEventHandler(
            'oorder',
            'OnBeforeEndBufferContent',
            $this->MODULE_ID,
            'Oasis\Import\Oorder',
        );

        return false;
    }

    public function DoUninstall()
    {
        global $APPLICATION;

        $this->UnInstallFiles();
        $this->UnInstallDB();
        $this->UnInstallEvents();

        \CAgent::RemoveModuleAgents('oasis.import');

        ModuleManager::unRegisterModule($this->MODULE_ID);

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('OASIS_IMPORT_UNINSTALL_TITLE') . ' "' . Loc::getMessage('OASIS_IMPORT_NAME') . '"',
            __DIR__ . '/unstep.php'
        );

        return false;
    }

    public function UnInstallFiles()
    {
        return false;
    }

    public function UnInstallDB()
    {
        global $DB;
        $this->errors = false;
        $this->errors = $DB->RunSQLBatch(Application::getDocumentRoot() . '/local/modules/oasis.import/install/db/mysql/uninstall.sql');
        Option::delete($this->MODULE_ID);

        if (!$this->errors) {
            return true;
        } else {
            return $this->errors;
        }
    }

    public function UnInstallEvents()
    {
        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnBeforeEndBufferContent',
            $this->MODULE_ID,
            'Oasis\Import\Main',
        );

        EventManager::getInstance()->unRegisterEventHandler(
            'api',
            'OnBeforeEndBufferContent',
            $this->MODULE_ID,
            'Oasis\Import\Api',
        );

        EventManager::getInstance()->unRegisterEventHandler(
            'oorder',
            'OnBeforeEndBufferContent',
            $this->MODULE_ID,
            'Oasis\Import\Oorder',
        );

        return false;
    }

}