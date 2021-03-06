<?php

namespace Oasis\Import;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\MeasureRatioTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\Model\PropertyFeature;
use Bitrix\Iblock\Model\Section;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\PropertyIndex\Manager;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\SectionPropertyTable;
use Bitrix\Highloadblock;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserFieldTable;
use CFile;
use CIBlockElement;
use CIBlockSection;
use CUserTypeEntity;
use Exception;

class Main
{

    /**
     * Get order id oasis
     *
     * @param int $orderId
     * @return array|false
     */
    public static function getOasisOrder(int $orderId)
    {
        $result = false;

        try {
            $result = Application::getConnection()->query("SELECT * FROM b_oasis_import_orders  WHERE `ID_ORDER` = " . $orderId)->fetch();
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        return $result;
    }

    /**
     * Insert order data in b_oasis_import_orders
     *
     * @param int $orderId
     * @param int $queueId
     */
    protected function addOasisOrder(int $orderId, int $queueId)
    {
        try {
            $queryRow = self::getOasisOrder($orderId);

            if (!$queryRow) {
                Application::getConnection()->queryExecute("INSERT INTO b_oasis_import_orders (ID_ORDER, ID_QUEUE) VALUES (" . $orderId . ", " . $queueId . ")");
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Up quantity products
     *
     * @param $stock
     * @throws Exception
     */
    public static function upQuantity($stock)
    {
        try {
            $queryRows = Application::getConnection()->query("
        SELECT
            P.ID, P.TYPE, U.UF_OASIS_ID_PRODUCT
        FROM
             b_uts_product U
                 JOIN b_catalog_product P ON U.VALUE_ID=P.ID
        ")->fetchAll();

            if ($queryRows) {
                $products = [];
                foreach ($queryRows as $row) {
                    if (!array_key_exists($row['UF_OASIS_ID_PRODUCT'], $products)) {
                        $products[$row['UF_OASIS_ID_PRODUCT']] = $row['ID'];
                    } elseif ($row['TYPE'] == 4) {
                        $products[$row['UF_OASIS_ID_PRODUCT']] = $row['ID'];
                    }
                }
                unset($row);

                foreach ($stock as $item) {
                    if (array_key_exists($item->id, $products)) {
                        ProductTable::update($products[$item->id], ['QUANTITY' => $item->stock]);
                        self::executeStoreProduct($products[$item->id], $item, true);
                    }
                }
                unset($item);
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * @param $productId
     * @param int $type
     * @param bool $fetchAll
     * @return array|false
     * @throws ArgumentException
     * @throws LoaderException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function checkProduct($productId, int $type = 0, $fetchAll = false)
    {
        Loader::includeModule('catalog');

        $arFields = [
            'filter' => [
                'UF_OASIS_ID_PRODUCT' => $productId,
            ],
        ];

        if ($type) {
            $arFields['filter']['TYPE'] = $type;
        }

        $result = ProductTable::getList($arFields);

        if ($fetchAll) {
            return $result->fetchAll();
        } else {
            return $result->fetch();
        }
    }

    /**
     * Add Iblock Element Product
     *
     * @param $product
     * @param $oasisCategories
     * @param $properties
     * @param $iblockId
     * @param bool $offer
     * @return false|mixed|void
     */
    public static function addIblockElementProduct($product, $oasisCategories, $properties, $iblockId, $offer = false)
    {
        $productId = null;

        try {
            $data = [
                'NAME'             => $product->name,
                'CODE'             => self::getUniqueCodeElement($product->name),
                'IBLOCK_ID'        => $iblockId,
                'DETAIL_TEXT'      => '<p>' . htmlentities($product->description, ENT_QUOTES, 'UTF-8') . '</p>' . self::getProductDetailText($product),
                'DETAIL_TEXT_TYPE' => 'html',
                'PROPERTY_VALUES'  => $properties,
                'ACTIVE'           => self::getStatusProduct($product),
            ];

            if (array_key_exists('MORE_PHOTO', $properties) && $properties['MORE_PHOTO']) {
                $data['DETAIL_PICTURE'] = reset($properties['MORE_PHOTO'])['VALUE'];
            }

            if ($offer === false) {
                $data += self::getIblockSectionProduct($product, $oasisCategories, $iblockId);
            }

            $el = new CIBlockElement;
            $productId = $el->Add($data);

            if (!empty($el->LAST_ERROR)) {
                $str = $offer === false ? '???????????? ???????????????????? ????????????: ' : '???????????? ???????????????????? ?????????????????? ??????????????????????: ';
                echo $str . $el->LAST_ERROR . PHP_EOL;
                die();
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        return $productId;
    }

    /**
     * Update Iblock Element Product
     *
     * @param $iblockElementId
     * @param $product
     * @param $iblockId
     * @param array $oasisCategories
     */
    public static function upIblockElementProduct($iblockElementId, $product, $iblockId, array $oasisCategories = [])
    {
        try {
            $data = [
                'NAME'             => $product->name,
                'DETAIL_TEXT'      => '<p>' . htmlentities($product->description, ENT_QUOTES, 'UTF-8') . '</p>' . self::getProductDetailText($product),
                'DETAIL_TEXT_TYPE' => 'html',
                'ACTIVE'           => self::getStatusProduct($product),
            ];

            if ($oasisCategories) {
                $data += self::getIblockSectionProduct($product, $oasisCategories, $iblockId);
            }

            $el = new CIBlockElement;
            $el->Update($iblockElementId, $data);

            if (!empty($el->LAST_ERROR)) {
                $str = $oasisCategories ? '???????????? ???????????????????? ????????????: ' : '???????????? ???????????????????? ?????????????????? ??????????????????????: ';
                echo $str . $el->LAST_ERROR . PHP_EOL;
                die();
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Update status first product
     *
     * @param $productId
     * @param $iblockId
     */
    public static function upStatusFirstProduct($productId, $iblockId)
    {
        try {
            $arSKU = \CCatalogSKU::getOffersList($productId, $iblockId, ['ACTIVE' => 'Y'], [], []);

            if ($arSKU) {
                $el = new CIBlockElement;
                $el->Update($productId, ['ACTIVE' => 'Y']);

                if (!empty($el->LAST_ERROR)) {
                    echo $el->LAST_ERROR . PHP_EOL;
                    die();
                }
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Execute ProductTable
     *
     * @param $productId
     * @param $product
     * @param $utsProductId
     * @param bool $offer
     * @param bool $parent
     * @throws \Exception
     */
    public static function executeProduct($productId, $product, $utsProductId, $offer = false, $parent = false)
    {
        try {
            $dbProduct = ProductTable::getList([
                'filter' => ['ID' => $productId]
            ])->fetch();

            if ($dbProduct) {
                $arFields['QUANTITY'] = is_null($product->total_stock) ? 0 : $product->total_stock;
                $arFields = array_merge($arFields, self::getAdditionalFields($parent, $product->rating));
                ProductTable::update($dbProduct['ID'], $arFields);
            } else {
                $arFields = [
                    'ID'                  => $productId,
                    'QUANTITY'            => is_null($product->total_stock) ? 0 : $product->total_stock,
                    'RECUR_SCHEME_LENGTH' => null,
                    'SELECT_BEST_PRICE'   => 'N',
                    'PURCHASING_CURRENCY' => 'RUB',
                    'LENGTH'              => null,
                    'WIDTH'               => null,
                    'HEIGHT'              => null,
                    'MEASURE'             => 5,
                    'AVAILABLE'           => 'Y',
                    'BUNDLE'              => 'N',
                    'UF_OASIS_ID_PRODUCT' => $utsProductId,
                    'TYPE'                => $offer ? ProductTable::TYPE_OFFER : ProductTable::TYPE_PRODUCT,
                ];

                $arFields = array_merge($arFields, self::getAdditionalFields($parent, $product->rating));

                ProductTable::add($arFields);
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Get Additional Fields
     *
     * @param $parent
     * @param $rating
     * @return array
     */
    public static function getAdditionalFields($parent, $rating): array
    {
        $result = [];

        if ($parent) {
            $result['QUANTITY'] = 0;
            $result['QUANTITY_TRACE'] = 'N';
            $result['TYPE'] = ProductTable::TYPE_SKU;
        }

        if ($parent || $rating === 5) {
            $result['CAN_BUY_ZERO'] = 'Y';
            $result['NEGATIVE_AMOUNT_TRACE'] = 'Y';
        }

        return $result;
    }

    /**
     * Checking stores and adding in the absence
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function checkStores()
    {
        $arStores = StoreTable::getList([
            'filter' => ['ACTIVE' => 'Y'],
        ])->fetchAll();

        $arFieldsStores = [
            [
                'TITLE'   => '??????????',
                'ACTIVE'  => 'Y',
                'ADDRESS' => '?????????? ????????????',
                'SORT'    => 100,
            ],
            [
                'TITLE'   => '???????????????? ??????????',
                'ACTIVE'  => 'Y',
                'ADDRESS' => '???????????????? ??????????',
                'XML_ID'  => 'OASIS_STOCK_REMOTE',
                'SORT'    => 500,
                'CODE'    => 'OASIS_STOCK_REMOTE'
            ]
        ];

        if (empty($arStores)) {
            foreach ($arFieldsStores as $arFieldsStore) {
                StoreTable::add($arFieldsStore);
            }
        } else {
            $neededStock = array_filter($arStores, function ($e) {
                return $e['CODE'] == 'OASIS_STOCK_REMOTE';
            });

            if (empty($neededStock)) {
                StoreTable::add(array_pop($arFieldsStores));
            }
        }
    }

    /**
     * Execute StoreProductTable
     *
     * @param $productId
     * @param $data
     * @param bool $upStock
     * @throws \Exception
     */
    public static function executeStoreProduct($productId, $data, bool $upStock = false)
    {
        if ($upStock) {
            $stockMain = (int)$data->stock;
            $stockRemote = (int)$data->{'stock-remote'};
        } else {
            $stockMain = (int)$data->outlets->{'000000029'};
            $stockRemote = !empty($data->outlets->{'1-0000052'}) ? (int)$data->outlets->{'1-0000052'} : 0;
        }

        $stores = [
            'main' => [
                'ID'     => (int)Option::get(pathinfo(dirname(__DIR__))['basename'], 'main_stock'),
                'AMOUNT' => $stockMain,
            ]
        ];

        try {
            $multiStocks = Option::get(pathinfo(dirname(__DIR__))['basename'], 'stocks');

            if (!empty($multiStocks)) {
                $stores['remote'] = [
                    'ID'     => (int)Option::get(pathinfo(dirname(__DIR__))['basename'], 'remote_stock'),
                    'AMOUNT' => $stockRemote,
                ];

                if (empty($stores['main']['ID']) || empty($stores['remote']['ID'])) {
                    throw new SystemException('Stock not updated. No main stock ID or no remote stock ID. Select stocks in module settings.');
                }

                foreach ($stores as $store) {
                    $arField = [
                        'PRODUCT_ID' => (int)$productId,
                        'STORE_ID'   => $store['ID'],
                        'AMOUNT'     => $store['AMOUNT'],
                    ];

                    $rsStoreProduct = StoreProductTable::getList([
                        'filter' => [
                            '=PRODUCT_ID' => (int)$productId,
                            'STORE.ID'    => $store['ID'],
                        ],
                    ])->fetch();

                    if (!empty($rsStoreProduct)) {
                        StoreProductTable::update($rsStoreProduct['ID'], $arField);
                    } else {
                        StoreProductTable::add($arField);
                    }
                }

            } else {
                $rsStoreProduct = StoreProductTable::getList([
                    'filter' => [
                        '=PRODUCT_ID' => $productId,
                        'STORE.ID'    => $stores['main']['ID']
                    ],
                ])->fetch();

                $arField = [
                    'PRODUCT_ID' => (int)$productId,
                    'STORE_ID'   => $stores['main']['ID'],
                    'AMOUNT'     => $stockMain + $stockRemote,
                ];

                if (!empty($rsStoreProduct)) {
                    StoreProductTable::update($rsStoreProduct['ID'], $arField);
                } else {
                    StoreProductTable::add($arField);
                }

                $arDeleteStoreProducts = StoreProductTable::getList([
                    'filter' => [
                        '=PRODUCT_ID' => $productId,
                        '!=STORE.ID'  => $stores['main']['ID']
                    ],
                ])->fetchAll();

                foreach ($arDeleteStoreProducts as $arDeleteStoreProduct) {
                    StoreProductTable::delete($arDeleteStoreProduct['ID']);
                }
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
            exit();
        }
    }

    /**
     * Execute PriceTable
     *
     * @param $productId
     * @param $product
     * @param array $dataCalcPrice
     * @throws \Exception
     */
    public static function executePriceProduct($productId, $product, array $dataCalcPrice)
    {
        try {
            $dbPrice = PriceTable::getList([
                'filter' => ['PRODUCT_ID' => $productId]
            ])->fetch();

            $price = !empty($dataCalcPrice['dealer']) ? $product->discount_price : $product->price;

            if (!empty($dataCalcPrice['factor'])) {
                $price = $price * (float)$dataCalcPrice['factor'];
            }

            if (!empty($dataCalcPrice['increase'])) {
                $price = $price + (float)$dataCalcPrice['increase'];
            }

            $arField = [
                'CATALOG_GROUP_ID' => self::getBaseCatalogGroupId(),
                'PRODUCT_ID'       => $productId,
                'PRICE'            => $price,
                'PRICE_SCALE'      => $price,
                'CURRENCY'         => 'RUB',
            ];

            if ($dbPrice) {
                PriceTable::update($dbPrice['ID'], $arField);
            } else {
                PriceTable::add($arField);
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Execute MeasureRatio
     *
     * @param $productId
     * @throws Exception
     */
    public static function executeMeasureRatioTable($productId)
    {
        try {
            $result = MeasureRatioTable::add([
                'PRODUCT_ID' => $productId,
                'IS_DEFAULT' => 'Y',
                'RATIO'      => 1,
            ]);

            if (!$result->isSuccess()) {
                throw new SystemException(sprintf('ErrorMessages: ' . print_r($result->getErrorMessages(), true) . 'Error add MeasureRatio PRODUCT_ID="%s".', $productId));
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Get base catalog group id
     *
     * @return int
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function getBaseCatalogGroupId(): int
    {
        $rsGroup = GroupTable::getList([
            'filter' => ['BASE' => 'Y']
        ]);

        if ($arGroup = $rsGroup->fetch()) {
            $result = (int)$arGroup['ID'];
        } else {
            $result = 1;
        }

        return $result;
    }

    /**
     * Get images product
     *
     * @param $product
     * @return array
     */
    public static function getProductImages($product): array
    {
        $result = [];
        $i = 0;

        foreach ($product->images as $image) {
            $value = CFile::MakeFileArray($image->superbig);

            if ($value['type'] !== 'text/html') {
                $result['MORE_PHOTO']['n' . $i++] = [
                    'VALUE' => $value,
                ];
            }
        }

        return $result;
    }

    /**
     * Get status product
     *
     * @param $product
     * @return string
     */
    public static function getStatusProduct($product): string
    {
        if ($product->total_stock > 0 || $product->rating === 5) {
            $status = 'Y';
        } else {
            $status = 'N';
        }

        return $status;
    }

    /**
     * Get iblock section product
     *
     * @param $product
     * @param $oasisCategories
     * @param $iblockId
     * @return array
     */
    public static function getIblockSectionProduct($product, $oasisCategories, $iblockId): array
    {
        $categories = self::getCategories($oasisCategories, $product->categories, $iblockId);

        if (count($categories) > 1) {
            $result['IBLOCK_SECTION'] = $categories;
        } else {
            $result['IBLOCK_SECTION_ID'] = reset($categories);
        }

        return $result;
    }

    /**
     * Checking properties product and create if absent
     *
     * @throws LoaderException
     * @throws Exception
     */
    public static function checkProperties($iblockIdCatalog, $iblockIdOffers)
    {
        $arProperties = [
            $iblockIdCatalog => [
                [
                    'CODE'             => 'MORE_PHOTO',
                    'NAME'             => '????????????????',
                    'PROPERTY_TYPE'    => 'F',
                    'COL_COUNT'        => 30,
                    'MULTIPLE'         => 'Y',
                    'FILE_TYPE'        => 'jpg, gif, bmp, png, jpeg',
                    'WITH_DESCRIPTION' => 'Y',
                    'SORT'             => 500,
                    'extend'           => [
                        'section' => [
                            'smartFilter'     => 'N',
                            'displayType'     => '',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'ARTNUMBER',
                    'NAME'          => '??????????????',
                    'PROPERTY_TYPE' => 'S',
                    'COL_COUNT'     => 30,
                    'SEARCHABLE'    => 'N',
                    'FILTRABLE'     => 'N',
                    'SORT'          => 310,
                    'extend'        => [
                        'feature' => [
                            'LIST_PAGE_SHOW'   => 'Y',
                            'DETAIL_PAGE_SHOW' => 'Y',
                        ],
                        'section' => [
                            'smartFilter'     => 'N',
                            'displayType'     => 'F',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'MANUFACTURER',
                    'NAME'          => '??????????????????????????',
                    'PROPERTY_TYPE' => 'S',
                    'SORT'          => 320,
                    'extend'        => [
                        'feature' => [
                            'LIST_PAGE_SHOW'   => 'Y',
                            'DETAIL_PAGE_SHOW' => 'Y',
                        ],
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'F',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'MATERIAL',
                    'NAME'          => '????????????????',
                    'PROPERTY_TYPE' => 'S',
                    'MULTIPLE'      => 'Y',
                    'MULTIPLE_CNT'  => 1,
                    'SORT'          => 330,
                    'extend'        => [
                        'feature' => [
                            'LIST_PAGE_SHOW'   => 'Y',
                            'DETAIL_PAGE_SHOW' => 'Y',
                        ],
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'F',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'COLOR',
                    'NAME'          => '????????',
                    'PROPERTY_TYPE' => 'S',
                    'MULTIPLE_CNT'  => 3,
                    'SORT'          => 340,
                    'extend'        => [
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'F',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'                    => 'COLOR_OA_REF',
                    'NAME'                    => '????????',
                    'PROPERTY_TYPE'           => 'S',
                    'COL_COUNT'               => 30,
                    'MULTIPLE'                => 'Y',
                    'FILTRABLE'               => 'Y',
                    'SORT'                    => 341,
                    'USER_TYPE'               => 'directory',
                    'USER_TYPE_SETTINGS_LIST' => [
                        'size'       => 1,
                        'width'      => 0,
                        'group'      => 'N',
                        'multiple'   => 'Y',
                        'TABLE_NAME' => 'oasis_color_reference'
                    ],
                    'extend'                  => [
                        'feature' => [
                            'IN_BASKET'        => 'N',
                            'OFFER_TREE'       => 'N',
                            'LIST_PAGE_SHOW'   => 'N',
                            'DETAIL_PAGE_SHOW' => 'N',
                        ],
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'G',
                            'displayExpanded' => 'Y',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'GENDER',
                    'NAME'          => '????????????',
                    'PROPERTY_TYPE' => 'S',
                    'MULTIPLE'      => 'Y',
                    'MULTIPLE_CNT'  => 1,
                    'SORT'          => 510,
                    'extend'        => [
                        'feature' => [
                            'LIST_PAGE_SHOW'   => 'Y',
                            'DETAIL_PAGE_SHOW' => 'Y',
                        ],
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'F',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'BRANDING',
                    'NAME'          => '?????????? ??????????????????',
                    'PROPERTY_TYPE' => 'S',
                    'MULTIPLE'      => 'Y',
                    'MULTIPLE_CNT'  => 1,
                    'SORT'          => 520,
                    'extend'        => [
                        'feature' => [
                            'LIST_PAGE_SHOW'   => 'Y',
                            'DETAIL_PAGE_SHOW' => 'Y',
                        ],
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'F',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'MECHANISM',
                    'NAME'          => '?????? ??????????????????',
                    'PROPERTY_TYPE' => 'S',
                    'MULTIPLE'      => 'Y',
                    'MULTIPLE_CNT'  => 1,
                    'SORT'          => 530,
                    'extend'        => [
                        'feature' => [
                            'LIST_PAGE_SHOW'   => 'Y',
                            'DETAIL_PAGE_SHOW' => 'Y',
                        ],
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'F',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'INK_COLOR',
                    'NAME'          => '???????? ????????????',
                    'PROPERTY_TYPE' => 'S',
                    'MULTIPLE'      => 'Y',
                    'MULTIPLE_CNT'  => 1,
                    'SORT'          => 540,
                    'extend'        => [
                        'feature' => [
                            'LIST_PAGE_SHOW'   => 'Y',
                            'DETAIL_PAGE_SHOW' => 'Y',
                        ],
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'F',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'ROD_TYPE',
                    'NAME'          => '?????? ??????????????',
                    'PROPERTY_TYPE' => 'S',
                    'MULTIPLE'      => 'Y',
                    'MULTIPLE_CNT'  => 1,
                    'SORT'          => 550,
                    'extend'        => [
                        'feature' => [
                            'LIST_PAGE_SHOW'   => 'Y',
                            'DETAIL_PAGE_SHOW' => 'Y',
                        ],
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'F',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
            ],
            $iblockIdOffers  => [
                [
                    'CODE'             => 'MORE_PHOTO',
                    'NAME'             => '????????????????',
                    'PROPERTY_TYPE'    => 'F',
                    'COL_COUNT'        => 60,
                    'MULTIPLE'         => 'Y',
                    'FILE_TYPE'        => 'jpg, gif, bmp, png, jpeg',
                    'WITH_DESCRIPTION' => 'N',
                    'SORT'             => 500,
                    'extend'           => [
                        'section' => [
                            'smartFilter'     => 'N',
                            'displayType'     => '',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'ARTNUMBER',
                    'NAME'          => '??????????????',
                    'PROPERTY_TYPE' => 'S',
                    'COL_COUNT'     => 60,
                    'SEARCHABLE'    => 'Y',
                    'FILTRABLE'     => 'Y',
                    'SORT'          => 1010,
                    'extend'        => [
                        'feature' => [
                            'IN_BASKET'        => 'Y',
                            'LIST_PAGE_SHOW'   => 'Y',
                            'DETAIL_PAGE_SHOW' => 'Y',
                        ],
                        'section' => [
                            'smartFilter'     => 'N',
                            'displayType'     => 'P',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'SIZES_CLOTHES',
                    'NAME'          => '?????????????? ????????????',
                    'PROPERTY_TYPE' => 'L',
                    'SORT'          => 1020,
                    'extend'        => [
                        'feature' => [
                            'IN_BASKET'        => 'Y',
                            'OFFER_TREE'       => 'Y',
                            'LIST_PAGE_SHOW'   => 'N',
                            'DETAIL_PAGE_SHOW' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'SIZES_FLASH',
                    'NAME'          => '?????????? ????????????',
                    'PROPERTY_TYPE' => 'L',
                    'SORT'          => 1030,
                    'extend'        => [
                        'feature' => [
                            'IN_BASKET'        => 'Y',
                            'OFFER_TREE'       => 'Y',
                            'LIST_PAGE_SHOW'   => 'N',
                            'DETAIL_PAGE_SHOW' => 'N',
                        ],
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'F',
                            'displayExpanded' => 'Y',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'COLOR_CLOTHES',
                    'NAME'          => '????????',
                    'PROPERTY_TYPE' => 'L',
                    'SORT'          => 1040,
                    'extend'        => [
                        'feature' => [
                            'IN_BASKET'        => 'Y',
                            'OFFER_TREE'       => 'Y',
                            'LIST_PAGE_SHOW'   => 'N',
                            'DETAIL_PAGE_SHOW' => 'N',
                        ],
                    ],
                ],
            ],
        ];

        self::checkHLblock();

        try {
            Loader::includeModule('iblock');

            foreach ($arProperties as $iblockId => $properties) {

                foreach ($properties as $property) {
                    $dbProperty = PropertyTable::getList([
                        'filter' => [
                            'IBLOCK_ID' => $iblockId,
                            'CODE'      => $property['CODE']
                        ],
                    ])->fetch();

                    if (!$dbProperty) {
                        if (!empty($property['extend'])) {
                            $extend = $property['extend'];
                            unset($property['extend']);
                        }

                        $arFields = array_merge([
                            'IBLOCK_ID' => $iblockId,
                            'XML_ID'    => $property['CODE'],
                        ], $property);

                        $propertyId = self::addProperty($arFields);

                        if (!empty($extend)) {
                            if (!empty($extend['feature'])) {
                                self::propertySetFeatures($propertyId, $extend['feature']);
                            }

                            if (!empty($extend['section'])) {
                                self::addSectionProperty($iblockId, $propertyId, $extend['section']);
                            }
                        }
                    }
                }
            }

        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Add property product
     *
     * @param $data
     * @return array|int
     * @throws \Exception
     */
    private static function addProperty($data)
    {
        $result = [];

        $arFields = array_merge([
            'SORT'             => 1000,
            'WITH_DESCRIPTION' => 'N',
            'IS_REQUIRED'      => 'N',
        ], $data);

        try {
            $result = PropertyTable::add($arFields)->getId();
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        return $result;
    }

    /**
     * Check highloadblock OasisColorReference
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function checkHLblock()
    {
        Loader::includeModule('highloadblock');
        $imgPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;

        $sort = 0;
        $colorValues = [];

        $colors = [
            'AZURE'      => [
                'XML_ID'    => 1480,
                'PATH'      => 'colors/azure.jpg',
                'FILE_NAME' => 'azure.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1480
            ],
            'BEIGE'      => [
                'XML_ID'    => 1483,
                'PATH'      => 'colors/beige.jpg',
                'FILE_NAME' => 'beige.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1483
            ],
            'BLACK'      => [
                'XML_ID'    => 1471,
                'PATH'      => 'colors/black.jpg',
                'FILE_NAME' => 'black.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1471
            ],
            'BLUE'       => [
                'XML_ID'    => 1472,
                'PATH'      => 'colors/blue.jpg',
                'FILE_NAME' => 'blue.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1472
            ],
            'BROWN'      => [
                'XML_ID'    => 1482,
                'PATH'      => 'colors/brown.jpg',
                'FILE_NAME' => 'brown.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1482
            ],
            'BURGUNDY'   => [
                'XML_ID'    => 1478,
                'PATH'      => 'colors/burgundy.jpg',
                'FILE_NAME' => 'burgundy.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1478
            ],
            'DARKBLUE'   => [
                'XML_ID'    => 1488,
                'PATH'      => 'colors/darkblue.jpg',
                'FILE_NAME' => 'darkblue.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1488
            ],
            'GOLDEN'     => [
                'XML_ID'    => 1484,
                'PATH'      => 'colors/golden.jpg',
                'FILE_NAME' => 'golden.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1484
            ],
            'GREEN'      => [
                'XML_ID'    => 1474,
                'PATH'      => 'colors/green.jpg',
                'FILE_NAME' => 'green.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1474
            ],
            'GREENAPPLE' => [
                'XML_ID'    => 1475,
                'PATH'      => 'colors/greenapple.jpg',
                'FILE_NAME' => 'greenapple.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1475
            ],
            'GREY'       => [
                'XML_ID'    => 1481,
                'PATH'      => 'colors/grey.jpg',
                'FILE_NAME' => 'grey.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1481
            ],
            'MULTICOLOR' => [
                'XML_ID'    => 1486,
                'PATH'      => 'colors/multicolor.jpg',
                'FILE_NAME' => 'multicolor.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1486
            ],
            'ORANGE'     => [
                'XML_ID'    => 1476,
                'PATH'      => 'colors/orange.jpg',
                'FILE_NAME' => 'orange.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1476
            ],
            'PINK'       => [
                'XML_ID'    => 1487,
                'PATH'      => 'colors/pink.jpg',
                'FILE_NAME' => 'pink.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1487
            ],
            'PURPLE'     => [
                'XML_ID'    => 1479,
                'PATH'      => 'colors/purple.jpg',
                'FILE_NAME' => 'purple.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1479
            ],
            'RED'        => [
                'XML_ID'    => 1473,
                'PATH'      => 'colors/red.jpg',
                'FILE_NAME' => 'orangered.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1473
            ],
            'SILVER'     => [
                'XML_ID'    => 1485,
                'PATH'      => 'colors/silver.jpg',
                'FILE_NAME' => 'silver.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1485
            ],
            'WHITE'      => [
                'XML_ID'    => 1470,
                'PATH'      => 'colors/white.jpg',
                'FILE_NAME' => 'white.jpg',
                'FILE_TYPE' => 'image/jpeg',
                'TITLE'     => '',
                'OASIS_ID'  => 1470
            ],
            'YELLOW'     => [
                'XML_ID'    => 1477,
                'PATH'      => 'colors/yellow.png',
                'FILE_NAME' => 'yellow.png',
                'FILE_TYPE' => 'image/png',
                'TITLE'     => '',
                'OASIS_ID'  => 1477
            ]
        ];

        foreach (array_keys($colors) as $index) {
            $colors[$index]['TITLE'] = Loc::getMessage('FILTER_COLOR_' . $index);
        }
        unset($index);

        foreach ($colors as $row) {
            $sort += 100;
            $colorValues[] = [
                'UF_NAME'   => $row['TITLE'],
                'UF_FILE'   => [
                    'name'     => $row['FILE_NAME'],
                    'type'     => $row['FILE_TYPE'],
                    'tmp_name' => $imgPath . $row['PATH']
                ],
                'UF_SORT'   => $sort,
                'UF_DEF'    => '0',
                'UF_XML_ID' => $row['XML_ID']
            ];
        }
        unset($row);

        $tables = [
            'oasis_color_reference' => [
                'name'   => 'OasisColorReference',
                'fields' => [
                    [
                        'FIELD_NAME'        => 'UF_NAME',
                        'USER_TYPE_ID'      => 'string',
                        'XML_ID'            => 'UF_COLOR_NAME',
                        'IS_SEARCHABLE'     => 'Y',
                        'EDIT_FORM_LABEL'   => [
                            'ru' => '????????????????',
                            'en' => 'Name',
                        ],
                        'LIST_COLUMN_LABEL' => [
                            'ru' => '????????????????',
                            'en' => 'Name',
                        ],
                        'LIST_FILTER_LABEL' => [
                            'ru' => '????????????????',
                            'en' => 'Name',
                        ]
                    ],
                    [
                        'FIELD_NAME'        => 'UF_FILE',
                        'USER_TYPE_ID'      => 'file',
                        'XML_ID'            => 'UF_COLOR_FILE',
                        'IS_SEARCHABLE'     => 'Y',
                        'EDIT_FORM_LABEL'   => [
                            'ru' => '????????',
                            'en' => 'File',
                        ],
                        'LIST_COLUMN_LABEL' => [
                            'ru' => '????????',
                            'en' => 'File',
                        ],
                        'LIST_FILTER_LABEL' => [
                            'ru' => '????????',
                            'en' => 'File',
                        ]
                    ],
                    [
                        'FIELD_NAME'        => 'UF_SORT',
                        'USER_TYPE_ID'      => 'double',
                        'XML_ID'            => 'UF_COLOR_SORT',
                        'EDIT_FORM_LABEL'   => [
                            'ru' => '????????????????????',
                            'en' => 'Sort',
                        ],
                        'LIST_COLUMN_LABEL' => [
                            'ru' => '????????????????????',
                            'en' => 'Sort',
                        ],
                        'LIST_FILTER_LABEL' => [
                            'ru' => '????????????????????',
                            'en' => 'Sort',
                        ]
                    ],
                    [
                        'FIELD_NAME'        => 'UF_DEF',
                        'USER_TYPE_ID'      => 'boolean',
                        'XML_ID'            => 'UF_COLOR_DEF',
                        'EDIT_FORM_LABEL'   => [
                            'ru' => '???? ??????????????????',
                            'en' => 'Default',
                        ],
                        'LIST_COLUMN_LABEL' => [
                            'ru' => '???? ??????????????????',
                            'en' => 'Default',
                        ],
                        'LIST_FILTER_LABEL' => [
                            'ru' => '???? ??????????????????',
                            'en' => 'Default',
                        ]
                    ],
                    [
                        'FIELD_NAME'        => 'UF_XML_ID',
                        'USER_TYPE_ID'      => 'string',
                        'XML_ID'            => 'UF_XML_ID',
                        'MANDATORY'         => 'Y',
                        'EDIT_FORM_LABEL'   => [
                            'ru' => 'XML ID',
                            'en' => 'XML ID',
                        ],
                        'LIST_COLUMN_LABEL' => [
                            'ru' => 'XML ID',
                            'en' => 'XML ID',
                        ],
                        'LIST_FILTER_LABEL' => [
                            'ru' => 'XML ID',
                            'en' => 'XML ID',
                        ]
                    ]
                ],
                'values' => $colorValues
            ]
        ];

        foreach ($tables as $tableName => &$table) {
            $tableId = null;

            $res = Highloadblock\HighloadBlockTable::getList([
                'select' => ['ID', 'NAME', 'TABLE_NAME'],
                'filter' => [
                    '=NAME'       => $table['name'],
                    '=TABLE_NAME' => $tableName
                ]
            ]);

            $row = $res->fetch();
            unset($res);

            if (empty($row)) {
                $result = Highloadblock\HighloadBlockTable::add([
                    'NAME'       => $table['name'],
                    'TABLE_NAME' => $tableName
                ]);

                if ($result->isSuccess()) {
                    $sort = 100;
                    $tableId = $result->getId();
                    $userField = new \CUserTypeEntity;

                    foreach ($table['fields'] as $field) {
                        $field['ENTITY_ID'] = 'HLBLOCK_' . $tableId;
                        $res = \CUserTypeEntity::getList(
                            [],
                            [
                                'ENTITY_ID'  => $field['ENTITY_ID'],
                                'FIELD_NAME' => $field['FIELD_NAME']
                            ]
                        );

                        if (!$res->Fetch()) {
                            $field['SORT'] = $sort;
                            $userField->Add($field);
                            $sort += 100;
                        }
                    }
                }
            } else {
                $tableId = (int)$row['ID'];
            }

            if (!empty($tableId)) {
                $hldata = Highloadblock\HighloadBlockTable::getById($tableId)->fetch();
                $hlentity = Highloadblock\HighloadBlockTable::compileEntity($hldata);
                $entityClass = $hlentity->getDataClass();

                foreach ($table['values'] as $item) {
                    $rowColor = $entityClass::getList([
                        'select' => ['ID'],
                        'filter' => [
                            '=UF_XML_ID' => $item['UF_XML_ID']
                        ],
                    ])->fetch();

                    if (empty($rowColor)) {
                        $entityClass::add($item);
                    }
                }
            }
        }
    }

    /**
     * Set features class PropertyFeature
     *
     * @param int $propertyId
     * @param array $data
     */
    private static function propertySetFeatures(int $propertyId, array $data)
    {
        $arFields = [];

        foreach ($data as $featureId => $isEnabled) {
            if ($featureId === 'IN_BASKET' || $featureId === 'OFFER_TREE') {
                $moduleId = 'catalog';
            } elseif ($featureId === 'LIST_PAGE_SHOW' || $featureId === 'DETAIL_PAGE_SHOW') {
                $moduleId = 'iblock';
            }

            $arFields[] = [
                'MODULE_ID'  => $moduleId ?? '',
                'FEATURE_ID' => $featureId,
                'IS_ENABLED' => $isEnabled,
            ];
        }

        PropertyFeature::setFeatures($propertyId, $arFields);
    }

    /**
     * Add section property table
     *
     * @param int $iblockId
     * @param int $propertyId
     * @param array $data
     * @throws \Exception
     */
    private static function addSectionProperty(int $iblockId, int $propertyId, array $data)
    {
        try {
            SectionPropertyTable::add([
                'IBLOCK_ID'        => $iblockId,
                'SECTION_ID'       => 0,
                'PROPERTY_ID'      => $propertyId,
                'SMART_FILTER'     => $data['smartFilter'],
                'DISPLAY_TYPE'     => $data['displayType'],
                'DISPLAY_EXPANDED' => $data['displayExpanded'],
                'FILTER_HINT'      => $data['filterHint'] ?? '',
            ]);
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Check property product
     *
     * @param $propertyCode
     * @param $value
     * @param $iblockId
     * @return array|int|void
     * @throws \Bitrix\Main\LoaderException
     */
    public static function checkPropertyEnum($propertyCode, $value, $iblockId)
    {
        try {
            Loader::includeModule('iblock');

            $dbProperty = PropertyTable::getList([
                'filter' => [
                    'CODE'      => $propertyCode,
                    'IBLOCK_ID' => $iblockId
                ],
            ])->fetch();

            $dbPropertyEnum = PropertyEnumerationTable::getList([
                'filter' => [
                    'PROPERTY_ID' => $dbProperty['ID'],
                    'VALUE'       => $value,
                ],
            ])->fetch();

            if (!$dbPropertyEnum) {
                return self::addPropertyEnum($dbProperty['ID'], $value);
            } else {
                return (int)$dbPropertyEnum['ID'];
            }

        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Add property product
     *
     * @param $propertyId
     * @param $value
     * @return array|int
     * @throws Exception
     */
    public static function addPropertyEnum($propertyId, $value)
    {
        $propertyEnumId = null;

        try {
            $res = PropertyEnumerationTable::add([
                'PROPERTY_ID' => $propertyId,
                'VALUE'       => $value,
                'XML_ID'      => md5($propertyId . $value),
                'SORT'        => 1000,
            ]);

            if (!$res->isSuccess()) {
                throw new SystemException(sprintf('Error add property enumeration value "%s" not list type.', $value));
            } else {
                $propertyEnumId = $res->getId();
            }

        } catch (SystemException $e) {
            echo $e->getMessage();
        }

        return $propertyEnumId;
    }

    /**
     * Get array properties products
     *
     * @param $product
     * @return array
     */
    public static function getPropertiesArray($product): array
    {
        $result = [
            'ARTNUMBER' => $product->article
        ];

        foreach ($product->attributes as $attribute) {
            if (isset($attribute->id) && $attribute->id === 1000000002) {
                $result['MATERIAL'] = $attribute->value;
            }
        }
        unset($attribute);

        if (!is_null($product->brand)) {
            $result['MANUFACTURER'] = $product->brand;
        }

        return $result;
    }

    /**
     * Update product properties for filter
     *
     * @param $productId
     * @param $product
     * @param $iblockId
     */
    public static function upPropertiesFilter($productId, $product, $iblockId)
    {
        $properties = [];

        if (!empty($product->colors)) {
            foreach ($product->colors as $color) {
                $properties['COLOR_OA_REF'][] = $color->parent_id;
            }
            unset($color);
        }

        foreach ($product->attributes as $attribute) {
            if (isset($attribute->id)) {
                switch ($attribute->id) {
                    case 65:
                        $properties['GENDER'][] = $attribute->value;
                        break;
                    case 1000000008:
                        $properties['BRANDING'][] = $attribute->value;
                        break;
                    case 8:
                        $properties['MECHANISM'][] = $attribute->value;
                        break;
                    case 6:
                        $properties['INK_COLOR'][] = $attribute->value;
                        break;
                    case 105:
                        $properties['ROD_TYPE'][] = $attribute->value;
                        break;
                }
            }
        }
        unset($attribute);

        foreach ($properties as $code => $dataProperty) {
            self::checkPropertyValues($productId, $product, $iblockId, $code, $dataProperty);
        }
        unset($code, $dataProperty);

        Manager::updateElementIndex($iblockId, $productId);
    }

    /**
     * Check and set property values for filter
     *
     * @param $productId
     * @param $product
     * @param $iblockId
     * @param $code
     * @param array $properties
     */

    public static function checkPropertyValues($productId, $product, $iblockId, $code, array $properties = [])
    {
        $statusProduct = self::getStatusProduct($product);
        $result = [];

        CIBlockElement::GetPropertyValuesArray(
            $result,
            $iblockId,
            [
                'ID' => [$productId]
            ],
            [
                'CODE' => $code
            ]
        );

        $arValues = !empty($result[$productId][$code]['VALUE']) ? $result[$productId][$code]['VALUE'] : [];
        unset($result);

        if ($statusProduct === 'Y') {
            if (empty($arValues)) {
                CIBlockElement::SetPropertyValuesEx($productId, $iblockId, [$code => $properties]);
            } else {
                CIBlockElement::SetPropertyValuesEx($productId, $iblockId, [$code => array_unique(array_merge($arValues, $properties))]);
            }
        } elseif (!empty($arValues)) {
            CIBlockElement::SetPropertyValuesEx($productId, $iblockId, [$code => array_values(array_diff($arValues, $properties))]);
        }
    }

    /**
     * Get array properties product offer
     *
     * @param $productId
     * @param $product
     * @param $iblockId
     * @return array
     * @throws \Bitrix\Main\LoaderException
     */
    public static function getPropertiesArrayOffer($productId, $product, $iblockId): array
    {
        $result = [
            'CML2_LINK' => $productId,
            'ARTNUMBER' => $product->article,
        ];

        if (!is_null($product->size) && in_array(3070, $product->full_categories)) {
            $result['SIZES_CLOTHES'] = self::checkPropertyEnum('SIZES_CLOTHES', $product->size, $iblockId);
        }

        $sizeFlash = self::searchObject($product->attributes, 219);

        if ($sizeFlash) {
            $result['SIZES_FLASH'] = self::checkPropertyEnum('SIZES_FLASH', $product->size, $iblockId);
        }

        foreach ($product->attributes as $attribute) {
            if (isset($attribute->id) && $attribute->id === 1000000001) {
                $result['COLOR_CLOTHES'] = self::checkPropertyEnum('COLOR_CLOTHES', $attribute->value, $iblockId);
            }
        }
        unset($attribute);

        $result += self::getProductImages($product);

        return $result;
    }

    /**
     * Get detail text from properties
     *
     * @param $product
     * @return string
     */
    public static function getProductDetailText($product): string
    {
        $properties = [];

        foreach ($product->attributes as $attribute) {
            if ($attribute->name !== '????????????' && $attribute->name !== '?????????? ????????????') {
                if (!(isset($attribute->id) && $attribute->id === 1000000001 || $attribute->id === 1000000002)) {
                    $dim = isset($attribute->dim) ? ' ' . $attribute->dim : '';
                    $needed = array_search($attribute->name, array_column($properties, 'name'));

                    if ($needed === false) {
                        $properties[] = [
                            'name'  => htmlentities($attribute->name, ENT_QUOTES, 'UTF-8'),
                            'value' => htmlentities($attribute->value . $dim, ENT_QUOTES, 'UTF-8'),
                        ];
                    } else {
                        $properties[$needed]['value'] .= ', ' . $attribute->value . $dim;
                    }
                    unset($needed);
                }
            }
        }
        unset($attribute);

        $html = '';

        if ($properties) {
            $html = '
<p><b>???????????????????????????? ????????????????:</b></p>
<ul>';
            foreach ($properties as $property) {
                $html .= '
    <li>
        <b>' . $property['name'] . '</b>: ' . $property['value'] . '
    </li>';
            }
            unset($property);
            $html .= '
</ul>';
        }

        return $html;
    }

    /**
     * Get array categories | add categories
     *
     * @param $oasisCategories
     * @param $productCategories
     * @param $iblockId
     * @return array
     */
    public static function getCategories($oasisCategories, $productCategories, $iblockId): array
    {
        $result = [];

        try {
            foreach ($productCategories as $productCategory) {
                $result[] = self::getCategoryId($oasisCategories, $productCategory, $iblockId);
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        return $result;
    }

    /**
     * Get iblock section id | add category and get iblock section id
     *
     * @param $oasisCategories
     * @param $categoryId
     * @param $iblockId
     * @return int
     */
    public static function getCategoryId($oasisCategories, $categoryId, $iblockId): int
    {
        $sectionCategory = self::getSectionByOasisCategoryId($iblockId, $categoryId);

        if ($sectionCategory) {
            $result = $sectionCategory['ID'];
        } else {
            $iblockSectionId = null;
            $oasisCategory = self::searchObject($oasisCategories, $categoryId);

            if (!is_null($oasisCategory->parent_id)) {
                $parentSectionCategory = self::getSectionByOasisCategoryId($iblockId, $oasisCategory->parent_id);

                if ($parentSectionCategory) {
                    $iblockSectionId = $parentSectionCategory['ID'];
                } else {
                    $iblockSectionId = self::getCategoryId($oasisCategories, $oasisCategory->parent_id, $iblockId);
                }
            }

            $result = self::addCategory($oasisCategory, $iblockSectionId, $iblockId);
        }

        return (int)$result;
    }

    /**
     * Get iblock section id by user field UF_OASIS_ID_CATEGORY
     *
     * @param $iblockId
     * @param $categoryId
     * @return mixed
     */
    public static function getSectionByOasisCategoryId($iblockId, $categoryId)
    {
        $entity = Section::compileEntityByIblock($iblockId);

        return $entity::getList([
            'select' => ['ID'],
            'filter' => ['UF_OASIS_ID_CATEGORY' => $categoryId]
        ])->fetch();
    }

    /**
     * Add category
     *
     * @param $category
     * @param $iblockSectionId
     * @param $iblockId
     * @return false|int|mixed
     */
    public static function addCategory($category, $iblockSectionId, $iblockId)
    {
        $result = false;

        try {
            $objDateTime = new DateTime();
            $iblockSection = new CIBlockSection;

            $arFields = [
                'DATE_CREATE'          => $objDateTime->format('Y-m-d H:i:s'),
                'IBLOCK_ID'            => $iblockId,
                'IBLOCK_SECTION_ID'    => $iblockSectionId,
                'ACTIVE'               => 'Y',
                'NAME'                 => $category->name,
                'DEPTH_LEVEL'          => $category->level,
                'DESCRIPTION_TYPE'     => 'text',
                'CODE'                 => self::getUniqueCodeSection($category->slug),
                'UF_OASIS_ID_CATEGORY' => $category->id,
            ];

            $result = $iblockSection->Add($arFields);

            if (!empty($iblockSection->LAST_ERROR)) {
                throw new SystemException('ErrorMessages: ' . $iblockSection->LAST_ERROR . print_r($arFields, true));
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        return $result;
    }

    /**
     * Get unique (not DB) section code (alias)
     *
     * @param $slug
     * @param int $i
     * @return string
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function getUniqueCodeSection($slug, int $i = 0): string
    {
        $code = self::transliteration($slug);
        $code = $i === 0 ? $code : $code . '-' . $i;

        $dbCode = SectionTable::getList([
            'filter' => [
                'CODE' => $code,
            ],
            'select' =>  ['ID'],
        ])->fetch();

        if ($dbCode) {
            $code = self::getUniqueCodeSection($slug, ++$i);
        }

        return $code;
    }

    /**
     * Get unique (not DB) element code (alias)
     *
     * @param $name
     * @param int $i
     * @return string
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function getUniqueCodeElement($name, int $i = 0): string
    {
        $code = self::transliteration($name);
        $code = $i === 0 ? $code : $code . '-' . $i;

        $dbCode = ElementTable::getList([
            'filter' => ['CODE' => $code],
        ])->fetch();

        if ($dbCode) {
            $code = self::getUniqueCodeElement($name, ++$i);
        }

        return $code;
    }

    /**
     * Check user fields UF_OASIS_ID_CATEGORY and UF_OASIS_ID_PRODUCT
     *
     * @throws ArgumentException
     * @throws LoaderException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function checkUserFields($iblockId)
    {
        Loader::includeModule('iblock');

        $dataFields = [
            [
                'ENTITY_ID'  => 'PRODUCT',
                'FIELD_NAME' => 'UF_OASIS_ID_PRODUCT',
                'LABEL'      => [
                    'ru' => 'Oasis ID ????????????',
                    'en' => 'Oasis ID product',
                ],
            ],
            [
                'ENTITY_ID'  => 'IBLOCK_' . $iblockId . '_SECTION',
                'FIELD_NAME' => 'UF_OASIS_ID_CATEGORY',
                'LABEL'      => [
                    'ru' => 'Oasis ID ??????????????????',
                    'en' => 'Oasis ID category',
                ],
            ],
        ];

        foreach ($dataFields as $dataField) {
            self::addUserField($dataField);
        }
    }

    /**
     * Check user field or add user field
     *
     * @param $data
     * @return false|int
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    private static function addUserField($data)
    {
        $dbUserFields = UserFieldTable::getList([
            'select' => ['ID'],
            'filter' => [
                'FIELD_NAME' => $data['FIELD_NAME'],
                'ENTITY_ID'  => $data['ENTITY_ID']
            ],
        ])->fetch();

        if (!$dbUserFields) {
            $oUserTypeEntity = new CUserTypeEntity();

            $aUserFields = [
                'ENTITY_ID'         => $data['ENTITY_ID'],
                'FIELD_NAME'        => $data['FIELD_NAME'],
                'USER_TYPE_ID'      => 'string',
                'XML_ID'            => $data['FIELD_NAME'],
                'MULTIPLE'          => 'N',
                'MANDATORY'         => 'N',
                'SHOW_FILTER'       => 'N',
                'SHOW_IN_LIST'      => 'N',
                'EDIT_IN_LIST'      => 'N',
                'IS_SEARCHABLE'     => 'N',
                'SETTINGS'          => [
                    'DEFAULT_VALUE' => '',
                    'SIZE'          => '11',
                    'ROWS'          => '1',
                    'MIN_LENGTH'    => '0',
                    'MAX_LENGTH'    => '0',
                    'REGEXP'        => '',
                ],
                'EDIT_FORM_LABEL'   => $data['LABEL'],
                'LIST_COLUMN_LABEL' => $data['LABEL'],
                'LIST_FILTER_LABEL' => $data['LABEL'],
                'HELP_MESSAGE'      => [
                    'ru' => '',
                    'en' => '',
                ],
            ];

            $userFieldId = $oUserTypeEntity->Add($aUserFields);
        } else {
            $userFieldId = (int)$dbUserFields['ID'];
        }

        return $userFieldId;
    }

    /**
     * Get Iblock Id
     *
     * @param string $code
     * @return int
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function getIblockId(string $code): int
    {
        $arIblock = IblockTable::getList([
            'select' => ['ID'],
            'filter' => ['CODE' => $code],
        ])->fetch();

        if (!$arIblock) {
            $arIblock = IblockTable::getList([
                'select' => ['ID'],
                'filter' => ['IBLOCK_TYPE_ID' => 'catalog'],
            ])->fetch();

            if (!$arIblock) {
                //TODO ???????????????? ????????????????
                print_r('???????????????? ????????????????');
                Debug::dumpToFile('???????????????? ????????????????');
                exit();
            }
        }

        return (int)$arIblock['ID'] ?? 0;
    }

    /**
     * Get array active stores for selectbox in page options
     *
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function getActiveStoresForOptions(): array
    {
        Loader::includeModule('catalog');

        $result = [];
        $arStores = StoreTable::getList([
            'filter' => ['ACTIVE' => 'Y'],
        ])->fetchAll();

        foreach ($arStores as $arStore) {
            $result[$arStore['ID']] = '(ID=' . $arStore['ID'] . ') ' . $arStore['TITLE'];
        }

        return $result;
    }

    /**
     * Get array active iblocks for selectbox in page options
     *
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function getActiveIblocksForOptions(): array
    {
        Loader::includeModule('iblock');

        $result = [];
        $arIblocks = IblockTable::getList([
            'select' => ['ID', 'NAME', 'IBLOCK_TYPE_ID'],
            'filter' => ['ACTIVE' => 'Y'],
        ])->fetchAll();

        foreach ($arIblocks as $arIblock) {
            $result[$arIblock['ID']] = '(ID=' . $arIblock['ID'] . ') ' . $arIblock['NAME'];
        }

        return $result;
    }

    /**
     * Get categories level 1
     *
     * @return array
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
     */
    public static function getOasisMainCategories(): array
    {
        $result = [];
        $categories = Api::getCategoriesOasis();

        foreach ($categories as $category) {
            if ($category->level === 1) {
                $result[$category->id] = $category->name;
            }
        }

        return $result;
    }

    /**
     * Get currencies array
     *
     * @return array
     * @throws ArgumentNullException
     * @throws ArgumentOutOfRangeException
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

    /**
     * Search object by id
     *
     * @param $data
     * @param $id
     * @return false|mixed|null
     */
    public static function searchObject($data, $id)
    {
        $neededObject = array_filter($data, function ($e) use ($id) {
            return $e->id == $id;
        });

        if (!$neededObject) {
            return false;
        }

        return array_shift($neededObject);
    }

    /**
     * String transliteration for url
     *
     * @param $string
     * @return string
     */
    public static function transliteration($string): string
    {
        $arr_trans = [
            '??'  => 'A',
            '??'  => 'B',
            '??'  => 'V',
            '??'  => 'G',
            '??'  => 'D',
            '??'  => 'E',
            '??'  => 'E',
            '??'  => 'J',
            '??'  => 'Z',
            '??'  => 'I',
            '??'  => 'Y',
            '??'  => 'K',
            '??'  => 'L',
            '??'  => 'M',
            '??'  => 'N',
            '??'  => 'O',
            '??'  => 'P',
            '??'  => 'R',
            '??'  => 'S',
            '??'  => 'T',
            '??'  => 'U',
            '??'  => 'F',
            '??'  => 'H',
            '??'  => 'TS',
            '??'  => 'CH',
            '??'  => 'SH',
            '??'  => 'SCH',
            '??'  => '',
            '??'  => 'YI',
            '??'  => '',
            '??'  => 'E',
            '??'  => 'YU',
            '??'  => 'YA',
            '??'  => 'a',
            '??'  => 'b',
            '??'  => 'v',
            '??'  => 'g',
            '??'  => 'd',
            '??'  => 'e',
            '??'  => 'e',
            '??'  => 'j',
            '??'  => 'z',
            '??'  => 'i',
            '??'  => 'y',
            '??'  => 'k',
            '??'  => 'l',
            '??'  => 'm',
            '??'  => 'n',
            '??'  => 'o',
            '??'  => 'p',
            '??'  => 'r',
            '??'  => 's',
            '??'  => 't',
            '??'  => 'u',
            '??'  => 'f',
            '??'  => 'h',
            '??'  => 'ts',
            '??'  => 'ch',
            '??'  => 'sh',
            '??'  => 'sch',
            '??'  => 'y',
            '??'  => 'yi',
            '??'  => '',
            '??'  => 'e',
            '??'  => 'yu',
            '??'  => 'ya',
            '.'  => '-',
            ' '  => '-',
            '?'  => '-',
            '/'  => '-',
            '\\' => '-',
            '*'  => '-',
            ':'  => '-',
            '>'  => '-',
            '|'  => '-',
            '\'' => '',
            '('  => '',
            ')'  => '',
            '!'  => '',
            '@'  => '',
            '%'  => '',
            '`'  => '',
        ];
        $string = str_replace(['-', '+', '.', '?', '/', '\\', '*', ':', '*', '|'], ' ', $string);
        $string = htmlspecialchars_decode($string);
        $string = strip_tags($string);
        $pattern = '/[\w\s\d]+/u';
        preg_match_all($pattern, $string, $result);
        $string = implode('', $result[0]);
        $string = preg_replace('/[\s]+/us', ' ', $string);

        return strtolower(strtr($string, $arr_trans));
    }
}