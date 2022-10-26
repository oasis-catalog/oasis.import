<?php

use Bitrix\Main\Config\Option;
use Oasis\Import\Cli;
use Bitrix\Main\IO;
use Bitrix\Main\Application;

$_SERVER['DOCUMENT_ROOT'] = realpath(dirname(__FILE__) . '/../../..');
$DOCUMENT_ROOT = $_SERVER['DOCUMENT_ROOT'];

const NO_KEEP_STATISTIC = true;
const NOT_CHECK_PERMISSIONS = true;
const CHK_EVENT = true;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

class StartCli
{
    private bool $cronUp = false;

    public function __construct()
    {
        define('MODULE_ID', pathinfo(dirname(__FILE__))['basename']);
        $cronType = Option::get(MODULE_ID, 'cron_type');

        if ($cronType !== 'custom') {
            die('Error: In "' . MODULE_ID . '" module settings, Cron parameter is selected as "System (agents)"');
        }

        $params = [
            'short' => 'k:u',
            'long'  => ['key:', 'up'],
        ];

        $errors = '';
        $cliOptions = getopt($params['short'], $params['long']);

        if (isset($cliOptions['key']) || isset($cliOptions['k'])) {
            define('CRON_KEY', $cliOptions['key'] ?? $cliOptions['k']);
        } else {
            $errors = 'key required';
        }

        if (isset($cliOptions['up']) || isset($cliOptions['u'])) {
            $this->cronUp = true;
        }

        if ($errors) {
            $help = '
usage:  php ' . __DIR__ . '/cron.php [-k|--key=secret] [-u|--up]
Options:
        -k  --key      substitute your secret key from the Oasis module
        -u  --up       specify this key to use the update
Example import products:
        php ' . __DIR__ . '/cron.php --key=secret
Example update stock (quantity) products:
        php ' . __DIR__ . '/cron.php --key=secret --up
Errors: ' . $errors . PHP_EOL;
            die($help);
        }

        define('API_KEY', Option::get(MODULE_ID, 'api_key') ?? '');

        if (CRON_KEY !== md5(API_KEY)) {
            die('Error! Invalid --key');
        }

        $version_php = intval(PHP_MAJOR_VERSION . PHP_MINOR_VERSION);
        if ($version_php < 74) {
            die('Error! Minimum PHP version 7.4, your PHP version ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
        }

        $this->doExecute();
    }

    public function doExecute()
    {
        try {
            $path = Application::getDocumentRoot() . '/upload/cron_lock/';

            if (!IO\Directory::isDirectoryExists($path)) {
                IO\Directory::createDirectory($path);
            }

            $lock = fopen($path . 'start.lock', 'w');
            if (!($lock && flock($lock, LOCK_EX | LOCK_NB))) {
                throw new Exception('Already running');
            }

            if (CModule::IncludeModule(MODULE_ID)) {
                if ($this->cronUp) {
                    Cli::upStock();
                } else {
                    Cli::import();
                }
            }

        } catch (Exception $e) {
            echo $e->getMessage() . PHP_EOL;
            exit();
        }
    }
}

try {
    new StartCli();
} catch (Exception $e) {
}
