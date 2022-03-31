<?php

namespace Oasis\Import;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Exception;

class Cli
{
    /**
     * @throws LoaderException
     * @throws Exception
     */
    public static function import()
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');

        set_time_limit(0);
        ini_set('memory_limit', '2G');

        try {
            $args = [];
            $module_id = pathinfo(dirname(__DIR__))['basename'];
            $step = (int)Option::get($module_id, 'step');
            $limit = (int)Option::get($module_id, 'limit');
            $dataCalcPrice = [
                'factor'   => str_replace(',', '.', Option::get($module_id, 'factor')),
                'increase' => str_replace(',', '.', Option::get($module_id, 'increase')),
                'dealer'   => Option::get($module_id, 'dealer'),
            ];
            $dataCalcPrice = array_diff($dataCalcPrice, ['', 0]);

            if ($limit > 0) {
                $args['limit'] = $limit;
                $args['offset'] = $step * $limit;
            }

            $oasisProducts = Api::getProductsOasis($args);
            $oasisCategories = Api::getCategoriesOasis();

            $group_ids = [];
            foreach ($oasisProducts as $product) {
                $group_ids[$product->group_id][$product->id] = $product;
            }
            unset($product);

            Main::checkUserFields();
            Main::checkProperties();

            if ($group_ids) {
                $nextStep = ++$step;

                foreach ($group_ids as $products) {
                    if (count($products) === 1) {
                        $product = reset($products);
                        $dbProduct = Main::checkProduct($product->id);

                        if ($dbProduct) {
                            $productId = (int)$dbProduct['ID'];
                            Main::upIblockElementProduct($productId, $product, $oasisCategories);
                        } else {
                            $properties = Main::getPropertiesArray($product);
                            $properties += Main::getProductImages($product);
                            $productId = Main::addIblockElementProduct($product, $oasisCategories, $properties, 'clothes');
                        }

                        Main::executeProduct($productId, $product, $product->group_id);
                        Main::executeStoreProduct($productId, $product->total_stock);
                        Main::executePriceProduct($productId, $product, $dataCalcPrice);
                    } else {
                        $firstProduct = reset($products);
                        $dbProduct = Main::checkProduct($firstProduct->group_id);

                        if ($dbProduct) {
                            $productId = (int)$dbProduct['ID'];
                            Main::upIblockElementProduct($productId, $firstProduct, $oasisCategories);
                        } else {
                            $properties = Main::getPropertiesArray($firstProduct, true);
                            $productId = Main::addIblockElementProduct($firstProduct, $oasisCategories, $properties, 'clothes');
                        }

                        Main::executeProduct($productId, $firstProduct, $firstProduct->group_id, true, true);
                        Main::executePriceProduct($productId, $firstProduct, $dataCalcPrice);

                        foreach ($products as $product) {
                            $dbOffer = Main::checkProduct($product->id, 4);

                            if ($dbOffer) {
                                $productOfferId = (int)$dbOffer['ID'];
                                Main::upIblockElementProduct($productOfferId, $product);
                            } else {
                                $propertiesOffer = Main::getPropertiesArrayOffer($productId, $product);
                                $productOfferId = Main::addIblockElementProduct($product, $oasisCategories, $propertiesOffer, 'clothes_offers', true);
                                Main::executeMeasureRatioTable($productOfferId);
                            }

                            Main::executeProduct($productOfferId, $product, $product->id, true);
                            Main::executeStoreProduct($productOfferId, $product->total_stock);
                            Main::executePriceProduct($productOfferId, $product, $dataCalcPrice);
                        }

                        Main::upStatusFirstProduct($productId);
                    }
                }
            } else {
                $nextStep = 0;
            }

            if (!empty($limit)) {
                Option::set($module_id, 'step', $nextStep);
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        return "\\Oasis\\Import\\Cli::import();";
    }

    /**
     * Cron - Up stock
     *
     * @return string
     * @throws LoaderException
     * @throws Exception
     */
    public static function upStock()
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');

        set_time_limit(0);
        ini_set('memory_limit', '2G');

        try {
            $stock = Api::getOasisStock();
            Main::upQuantity($stock);
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        return "\\Oasis\\Import\\Cli::upStock();";
    }
}