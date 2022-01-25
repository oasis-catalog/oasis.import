<?php

namespace Oasis\Import;

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
            $oasisProducts = Api::getProductsOasis();
            $oasisCategories = Api::getCategoriesOasis();

            $group_ids = [];
            foreach ($oasisProducts as $product) {
                $group_ids[$product->group_id][$product->id] = $product;
            }
            unset($product);

            Main::checkUserFields();
            Main::checkProperties();

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

                    Main::executeProduct($productId, $product);
                    Main::executeStoreProduct($productId, $product->total_stock);
                    Main::executePriceProduct($productId, $product);
                } else {
                    $firstProduct = reset($products);
                    $dbProduct = Main::checkProduct($firstProduct->id);

                    if ($dbProduct) {
                        $productId = (int)$dbProduct['ID'];
                        Main::upIblockElementProduct($productId, $firstProduct, $oasisCategories);
                    } else {
                        $properties = Main::getPropertiesArray($firstProduct, true);
                        $productId = Main::addIblockElementProduct($firstProduct, $oasisCategories, $properties, 'clothes');
                    }

                    Main::executeProduct($productId, $firstProduct, true, true);
                    Main::executePriceProduct($productId, $firstProduct);

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

                        Main::executeProduct($productOfferId, $product, true);
                        Main::executeStoreProduct($productOfferId, $product->total_stock);
                        Main::executePriceProduct($productOfferId, $product);
                    }

                    Main::upStatusFirstProduct($productId);
                }
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