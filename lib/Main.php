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
use Bitrix\Iblock\Model\Section;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\SectionPropertyTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use CFile;
use CIBlockElement;
use CIBlockSection;
use CUserTypeEntity;
use Cutil;
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
                        self::executeStoreProduct($products[$item->id], $item->stock, true);
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
     * @throws \Bitrix\Main\DB\SqlQueryException
     * @throws \Bitrix\Main\LoaderException
     */
    public static function checkProduct($productId, int $type = 0, $fetchAll = false)
    {
        Loader::includeModule('catalog');

        $query = "
        SELECT
            P.ID, U.UF_OASIS_ID_PRODUCT
        FROM
             b_uts_product U
                 JOIN b_catalog_product P ON U.VALUE_ID=P.ID
        WHERE
            U.UF_OASIS_ID_PRODUCT = '" . $productId . "'
       ";

        if ($type) {
            $query .= " AND P.TYPE = '" . $type . "'";
        }

        $result = Application::getConnection()->query($query);

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
                'DETAIL_TEXT'      => '<p>' . $product->description . '</p>' . self::getProductDetailText($product),
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
                $str = $offer === false ? 'Ошибка добавления товара: ' : 'Ошибка добавления торгового предложения: ';
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
                'DETAIL_TEXT'      => '<p>' . $product->description . '</p>' . self::getProductDetailText($product),
                'DETAIL_TEXT_TYPE' => 'html',
                'ACTIVE'           => self::getStatusProduct($product),
            ];

            if ($oasisCategories) {
                $data += self::getIblockSectionProduct($product, $oasisCategories, $iblockId);
            }

            $el = new CIBlockElement;
            $el->Update($iblockElementId, $data);

            if (!empty($el->LAST_ERROR)) {
                $str = $oasisCategories ? 'Ошибка обновления товара: ' : 'Ошибка обновления торгового предложения: ';
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
                    'TYPE'                => $offer ? ProductTable::TYPE_OFFER : ProductTable::TYPE_PRODUCT,
                ];

                $arFields = array_merge($arFields, self::getAdditionalFields($parent, $product->rating));

                ProductTable::add($arFields);
                Application::getConnection()->queryExecute("INSERT INTO b_uts_product (VALUE_ID, UF_OASIS_ID_PRODUCT) VALUES (" . $productId . ", '" . $utsProductId . "')");
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
     * Execute StoreProductTable
     *
     * @param $productId
     * @param $quantity
     * @param bool $upStock
     * @throws Exception
     */
    public static function executeStoreProduct($productId, $quantity, bool $upStock = false)
    {
        try {
            $arStore = StoreTable::getList([
                'filter' => ['ACTIVE' => 'Y'],
            ])->fetch();

            $rsStoreProduct = StoreProductTable::getList([
                'filter' => ['=PRODUCT_ID' => $productId, 'STORE.ACTIVE' => 'Y'],
            ]);

            $arField = [
                'PRODUCT_ID' => (int)$productId,
                'STORE_ID'   => $arStore['ID'],
            ];

            if ($upStock) {
                $arField['AMOUNT'] = $quantity;
            } else {
                $arField['AMOUNT'] = is_null($quantity) ? 0 : $quantity;
            }

            if ($arStoreProduct = $rsStoreProduct->fetch()) {
                StoreProductTable::update($arStoreProduct['ID'], $arField);
            } else {
                StoreProductTable::add($arField);
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
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
     * @throws LoaderException
     * @throws Exception
     */
    public static function checkProperties($iblockIdCatalog, $iblockIdOffers)
    {
        $arProperties = [
            $iblockIdCatalog => [
                [
                    'CODE'             => 'MORE_PHOTO',
                    'NAME'             => 'Картинки',
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
                    'NAME'          => 'Артикул',
                    'PROPERTY_TYPE' => 'S',
                    'COL_COUNT'     => 30,
                    'SEARCHABLE'    => 'N',
                    'FILTRABLE'     => 'N',
                    'SORT'          => 310,
                    'extend'        => [
                        'section' => [
                            'smartFilter'     => 'N',
                            'displayType'     => 'F',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'MANUFACTURER',
                    'NAME'          => 'Производитель',
                    'PROPERTY_TYPE' => 'S',
                    'SORT'          => 320,
                    'extend'        => [
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'F',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'MATERIAL',
                    'NAME'          => 'Материал',
                    'PROPERTY_TYPE' => 'S',
                    'MULTIPLE'      => 'Y',
                    'MULTIPLE_CNT'  => 1,
                    'SORT'          => 330,
                    'extend'        => [
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'F',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'COLOR',
                    'NAME'          => 'Цвет',
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
            ],
            $iblockIdOffers  => [
                [
                    'CODE'             => 'MORE_PHOTO',
                    'NAME'             => 'Картинки',
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
                    'NAME'          => 'Артикул',
                    'PROPERTY_TYPE' => 'S',
                    'COL_COUNT'     => 60,
                    'SEARCHABLE'    => 'Y',
                    'FILTRABLE'     => 'Y',
                    'SORT'          => 1010,
                    'extend'        => [
                        'section' => [
                            'smartFilter'     => 'N',
                            'displayType'     => 'P',
                            'displayExpanded' => 'N',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'SIZES_CLOTHES',
                    'NAME'          => 'Размеры одежды',
                    'PROPERTY_TYPE' => 'L',
                    'SORT'          => 1020,
                ],
                [
                    'CODE'          => 'SIZES_FLASH',
                    'NAME'          => 'Объем памяти',
                    'PROPERTY_TYPE' => 'L',
                    'SORT'          => 1030,
                    'extend'        => [
                        'section' => [
                            'smartFilter'     => 'Y',
                            'displayType'     => 'F',
                            'displayExpanded' => 'Y',
                        ],
                    ],
                ],
                [
                    'CODE'          => 'COLOR_CLOTHES',
                    'NAME'          => 'Цвет',
                    'PROPERTY_TYPE' => 'L',
                    'SORT'          => 1040,
                ],
            ],
        ];

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
     * @param bool $parentOffer
     * @return array
     */
    public static function getPropertiesArray($product, $parentOffer = false): array
    {
        $result = [
            'ARTNUMBER' => $product->article
        ];

        foreach ($product->attributes as $attribute) {
            if (isset($attribute->id) && $attribute->id === 1000000001 && $parentOffer === false) {
                $result['COLOR'] = $attribute->value;
            }
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
            if ($attribute->name !== 'Размер' && $attribute->name !== 'Объем памяти') {
                if (!(isset($attribute->id) && $attribute->id === 1000000001 || $attribute->id === 1000000002)) {
                    $dim = isset($attribute->dim) ? ' ' . $attribute->dim : '';
                    $needed = array_search($attribute->name, array_column($properties, 'name'));

                    if ($needed === false) {
                        $properties[] = [
                            'name'  => $attribute->name,
                            'value' => $attribute->value . $dim,
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
<p><b>Дополнительное описание:</b></p>
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
        $code = Cutil::translit($slug, 'ru', ['replace_space' => '-', 'replace_other' => '-']);
        $code = $i === 0 ? $code : $code . '-' . $i;

        $dbCode = CIBlockSection::GetList([], ['CODE' => $code], false, ['ID'])->Fetch();

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
     * @param $iblockId
     * @throws \Bitrix\Main\LoaderException
     */
    public static function checkUserFields($iblockId)
    {
        Loader::includeModule('iblock');

        $dataFields = [
            [
                'ENTITY_ID'  => 'PRODUCT',
                'FIELD_NAME' => 'UF_OASIS_ID_PRODUCT',
                'LABEL'      => [
                    'ru' => 'Oasis ID товара',
                    'en' => 'Oasis ID product',
                ],
            ],
            [
                'ENTITY_ID'  => 'IBLOCK_' . $iblockId . '_SECTION',
                'FIELD_NAME' => 'UF_OASIS_ID_CATEGORY',
                'LABEL'      => [
                    'ru' => 'Oasis ID категории',
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
     */
    private static function addUserField($data)
    {
        $dbUserFields = CUserTypeEntity::GetList([], [
            'FIELD_NAME' => $data['FIELD_NAME'],
            'ENTITY_ID' => $data['ENTITY_ID']
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
                //TODO Добавить инфоблок
                print_r('Добавить инфоблок');
                Debug::dumpToFile('Добавить инфоблок');
                exit();
            }
        }

        return (int)$arIblock['ID'] ?? 0;
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
     *
     * @return string
     */
    public static function transliteration( $string ): string {
        $arr_trans = [
            'А'  => 'A',
            'Б'  => 'B',
            'В'  => 'V',
            'Г'  => 'G',
            'Д'  => 'D',
            'Е'  => 'E',
            'Ё'  => 'E',
            'Ж'  => 'J',
            'З'  => 'Z',
            'И'  => 'I',
            'Й'  => 'Y',
            'К'  => 'K',
            'Л'  => 'L',
            'М'  => 'M',
            'Н'  => 'N',
            'О'  => 'O',
            'П'  => 'P',
            'Р'  => 'R',
            'С'  => 'S',
            'Т'  => 'T',
            'У'  => 'U',
            'Ф'  => 'F',
            'Х'  => 'H',
            'Ц'  => 'TS',
            'Ч'  => 'CH',
            'Ш'  => 'SH',
            'Щ'  => 'SCH',
            'Ъ'  => '',
            'Ы'  => 'YI',
            'Ь'  => '',
            'Э'  => 'E',
            'Ю'  => 'YU',
            'Я'  => 'YA',
            'а'  => 'a',
            'б'  => 'b',
            'в'  => 'v',
            'г'  => 'g',
            'д'  => 'd',
            'е'  => 'e',
            'ё'  => 'e',
            'ж'  => 'j',
            'з'  => 'z',
            'и'  => 'i',
            'й'  => 'y',
            'к'  => 'k',
            'л'  => 'l',
            'м'  => 'm',
            'н'  => 'n',
            'о'  => 'o',
            'п'  => 'p',
            'р'  => 'r',
            'с'  => 's',
            'т'  => 't',
            'у'  => 'u',
            'ф'  => 'f',
            'х'  => 'h',
            'ц'  => 'ts',
            'ч'  => 'ch',
            'ш'  => 'sh',
            'щ'  => 'sch',
            'ъ'  => 'y',
            'ы'  => 'yi',
            'ь'  => '',
            'э'  => 'e',
            'ю'  => 'yu',
            'я'  => 'ya',
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
        $string    = str_replace( [ '-', '+', '.', '?', '/', '\\', '*', ':', '*', '|' ], ' ', $string );
        $string    = htmlspecialchars_decode( $string );
        $string    = strip_tags( $string );
        $pattern   = '/[\w\s\d]+/u';
        preg_match_all( $pattern, $string, $result );
        $string = implode( '', $result[0] );
        $string = preg_replace( '/[\s]+/us', ' ', $string );

        return strtolower( strtr( $string, $arr_trans ) );
    }

}