<?php

use Bitrix\Main\Config\Option;
use Oasis\Import\Cli;


$_SERVER['DOCUMENT_ROOT'] = realpath(dirname(__FILE__) . '/../../..');
$DOCUMENT_ROOT = $_SERVER['DOCUMENT_ROOT'];

const NO_KEEP_STATISTIC = true;
const NOT_CHECK_PERMISSIONS = true;
const CHK_EVENT = true;

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

if(!defined('OASIS_MODULE_ID')){
	define('OASIS_MODULE_ID', 'oasis.import');
}

try {
	$cronType = Option::get(OASIS_MODULE_ID, 'cron_type');

	if ($cronType !== 'custom') {
		die('Error: In "' . OASIS_MODULE_ID . '" module settings, Cron parameter is selected as "System (agents)"');
	}

	$params = [
		'short' => 'k:u',
		'long'  => ['key:', 'up', 'debug', 'debug_log'],
	];

	$errors = '';
	$cliOptions = getopt($params['short'], $params['long']);

	if (isset($cliOptions['key']) || isset($cliOptions['k'])) {
		$cron_key = $cliOptions['key'] ?? $cliOptions['k'];
	} else {
		$errors = 'key required';
	}

	$cron_up = false;
	if (isset($cliOptions['up']) || isset($cliOptions['u'])) {
		$cron_up = true;
	}

	if ($errors) {
		die('
usage:  php ' . __DIR__ . '/cron.php [-k|--key=secret] [-u|--up]
Options:
-k  --key      substitute your secret key from the Oasis module
-u  --up       specify this key to use the update
Example import products:
php ' . __DIR__ . '/cron.php --key=secret
Example update stock (quantity) products:
php ' . __DIR__ . '/cron.php --key=secret --up
Errors: ' . $errors . PHP_EOL);
	}

	$version_php = intval(PHP_MAJOR_VERSION . PHP_MINOR_VERSION);
	if ($version_php < 74) {
		die('Error! Minimum PHP version 7.4, your PHP version ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
	}

	if (CModule::IncludeModule(OASIS_MODULE_ID)) {
		Cli::RunCron($cron_key, $cron_up, [
			'debug' => isset($cliOptions['debug']),
			'debug_log' => isset($cliOptions['debug_log'])
		]);
	}
} catch (Exception $e) {
	echo $e->getMessage() . PHP_EOL;
	exit();
}