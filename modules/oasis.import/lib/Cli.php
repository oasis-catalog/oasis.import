<?php

namespace Oasis\Import;
use Bitrix\Main\Diag\Debug;

class Cli {

    public static function import() {
        Debug::dumpToFile('Import ' . date('Y-m-d H:i:s'));

        return "\\Oasis\\Import\\Cli::import();";
    }

    public static function upStock() {
        Debug::dumpToFile('Up Stock ' . date('Y-m-d H:i:s'));

        return "\\Oasis\\Import\\Cli::upStock();";
    }
}