<?php

namespace Oasis\Import;

use Bitrix\Main\Config\Option;
use Oasis\Import\Api;

class Main
{

    public function appendScriptsToPage()
    {
        if (!defined("ADMIN_SECTION") && ADMIN_SECTION !== true) {
            $module_id = pathinfo(dirname(__DIR__))["basename"];
        }

        return false;
    }

    /**
     * Get categories level 1
     *
     * @return array
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     */
    public static function getOasisMainCategories(): array {
        $result     = [];
        $categories = Api::getCategoriesOasis();

        foreach ( $categories as $category ) {
            if ( $category->level === 1 ) {
                $result[ $category->id ] = $category->name;
            }
        }

        return $result;
    }

    /**
     * @return array
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     */
    public static function getCurrenciesOasisArray(): array
    {
        $result = [];

        if ($currenciesOasis = Api::getCurrenciesOasis()) {
            foreach ($currenciesOasis as $currency) {
                $result[$currency->code] = $currency->full_name;
            }
        }

        return $result;
    }
}