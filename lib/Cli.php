<?php

namespace Oasis\Import;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Exception;

class Cli
{
    const MSG_STATUS = true;
    const MSG_TO_FILE = false;

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
            $iblockIdCatalog = (int)Option::get($module_id, 'iblock_catalog');
            $iblockIdOffers = (int)Option::get($module_id, 'iblock_offers');

            if (empty($iblockIdCatalog) || empty($iblockIdOffers)) {
                throw new Exception('Infoblocks not selected');
            }

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
            } else {
                Option::set($module_id, 'progressItem', 0);
            }

            Main::deleteLogFile();
            $oasisProducts = Api::getProductsOasis($args);
            $oasisCategories = Api::getCategoriesOasis();
            $stat = Api::getStatProducts();

            $group_ids = [];
            $countProducts = 0;
            foreach ($oasisProducts as $product) {
                if ($product->is_deleted === false) {
                    $group_ids[$product->group_id][$product->id] = $product;
                    $countProducts++;
                } else {
                    Main::checkDeleteProduct($product->id);
                }
            }
            unset($product);

            Main::checkStores();
            Main::checkUserFields($iblockIdCatalog);
            Main::checkProperties($iblockIdCatalog, $iblockIdOffers);

            if ($group_ids) {
                Option::set($module_id, 'progressTotal', $stat['products']);
                Option::set($module_id, 'progressStepItem', 0);
                Option::set($module_id, 'progressStepTotal', (!empty($limit)) ? $countProducts : 0);

                $nextStep = ++$step;
                $totalGroup = count($group_ids);
                $itemGroup = 0;

                foreach ($group_ids as $products) {
                    if (count($products) === 1) {
                        $product = reset($products);
                        $dbProduct = Main::checkProduct($product->group_id);

                        if ($dbProduct) {
                            $productId = (int)$dbProduct['ID'];
                            Main::upIblockElementProduct($productId, $product, $iblockIdCatalog, $oasisCategories);
                            Main::cliMsg('Up product id ' . $product->id, self::MSG_STATUS, self::MSG_TO_FILE);
                        } else {
                            $properties = Main::getPropertiesArray($product);
                            $properties += Main::getProductImages($product);
                            $productId = Main::addIblockElementProduct($product, $oasisCategories, $properties, $iblockIdCatalog);
                            Main::cliMsg('Add product id ' . $product->id, self::MSG_STATUS, self::MSG_TO_FILE);
                        }

                        Main::upPropertiesFilter($productId, $product, $iblockIdCatalog);
                        Main::executeProduct($productId, $product, $product->group_id);
                        Main::executeStoreProduct($productId, $product);
                        Main::executePriceProduct($productId, $product, $dataCalcPrice);
                        Main::upProgressBar($module_id, $limit);
                    } else {
                        $firstProduct = reset($products);
                        $dbProduct = Main::checkProduct($firstProduct->group_id);

                        if ($dbProduct) {
                            $productId = (int)$dbProduct['ID'];
                            Main::upIblockElementProduct($productId, $firstProduct, $iblockIdCatalog, $oasisCategories);
                            Main::cliMsg('Up product id ' . $firstProduct->id, self::MSG_STATUS, self::MSG_TO_FILE);
                        } else {
                            $properties = Main::getPropertiesArray($firstProduct);
                            $productId = Main::addIblockElementProduct($firstProduct, $oasisCategories, $properties, $iblockIdCatalog);
                            Main::cliMsg('Add product id ' . $firstProduct->id, self::MSG_STATUS, self::MSG_TO_FILE);
                        }

                        Main::executeProduct($productId, $firstProduct, $firstProduct->group_id, true, true);
                        Main::executePriceProduct($productId, $firstProduct, $dataCalcPrice);

                        foreach ($products as $product) {
                            $dbOffer = Main::checkProduct($product->id, 4);

                            if ($dbOffer) {
                                $productOfferId = (int)$dbOffer['ID'];
                                Main::upIblockElementProduct($productOfferId, $product, 0);
                                Main::cliMsg('Up offer id ' . $product->id, self::MSG_STATUS, self::MSG_TO_FILE);
                            } else {
                                $propertiesOffer = Main::getPropertiesArrayOffer($productId, $product, $iblockIdOffers);
                                $productOfferId = Main::addIblockElementProduct($product, $oasisCategories, $propertiesOffer, $iblockIdOffers, true);
                                Main::executeMeasureRatioTable($productOfferId);
                                Main::cliMsg('Add offer id ' . $product->id, self::MSG_STATUS, self::MSG_TO_FILE);
                            }

                            Main::upPropertiesFilter($productId, $product, $iblockIdCatalog);
                            Main::executeProduct($productOfferId, $product, $product->id, true);
                            Main::executeStoreProduct($productOfferId, $product);
                            Main::executePriceProduct($productOfferId, $product, $dataCalcPrice);
                            Main::upProgressBar($module_id, $limit);
                        }

                        Main::upStatusFirstProduct($productId, $iblockIdCatalog);
                    }
                    Main::cliMsg('Done ' . ++$itemGroup . ' from ' . $totalGroup, self::MSG_STATUS, self::MSG_TO_FILE);
                }
            } else {
                $nextStep = 0;
                Option::set($module_id, 'progressItem', 0);
            }

            if (!empty($limit)) {
                Option::set($module_id, 'step', $nextStep);
                Option::set($module_id, 'progressStepItem', 0);
            } else {
                Option::set($module_id, 'progressItem', $stat['products']);
            }

            $objDateTime = new DateTime();
            Option::set($module_id, 'progressDate', $objDateTime->toString());
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
            exit();
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
            Main::checkStores();
            $stock = Api::getOasisStock();
            Main::upQuantity($stock);
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        return "\\Oasis\\Import\\Cli::upStock();";
    }
}