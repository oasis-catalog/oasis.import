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
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\SectionPropertyTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentNullException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserFieldTable;
use CFile;
use CIBlockElement;
use CIBlockSection;
use CUserTypeEntity;
use Cutil;
use Exception;

class Main
{

    /**
     * Update simple product
     *
     * @param $dbProduct
     * @param $oasisProduct
     * @param $oasisCategories
     * @param $variable
     * @throws LoaderException
     * @throws Exception
     */
    public static function upProduct($dbProduct, $oasisProduct, $oasisCategories)
    {
        try {
            Loader::includeModule('iblock');
            Loader::includeModule('catalog');

            $data = [
                'NAME'            => $oasisProduct->name,
                'DETAIL_TEXT'     => '<p>' . $oasisProduct->description . '</p>' . self::getProductDetailText($oasisProduct),
                'PROPERTY_VALUES' => self::getPropertiesArray($oasisProduct),
                'ACTIVE'          => self::getStatusProduct($oasisProduct),
            ];
            $data += self::getIblockSectionProduct($oasisProduct, $oasisCategories);

            $el = new CIBlockElement;
            $el->Update($dbProduct['ID'], $data);

            self::executeProduct($dbProduct['ID'], $oasisProduct);
            self::executeStoreProduct($dbProduct['ID'], $oasisProduct);
            self::executePriceProduct($dbProduct['ID'], $oasisProduct);

        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * @param $product
     * @param int $type
     * @return array|false
     * @throws ArgumentException
     * @throws LoaderException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function checkProduct($product, int $type = 0)
    {
        Loader::includeModule('catalog');

        $arFields = [
            'filter' => [
                'UF_OASIS_ID_PRODUCT' => $product->id,
            ],
        ];

        if ($type) {
            $arFields['filter']['TYPE'] = $type;
        }

        return ProductTable::getList($arFields)->fetch();
    }

    /**
     * Add Iblock Element Product
     *
     * @param $product
     * @param $oasisCategories
     * @param $properties
     * @param $iblockCode
     * @param bool $offer
     * @return false|mixed|void
     * @throws LoaderException
     */
    public static function addIblockElementProduct($product, $oasisCategories, $properties, $iblockCode, $offer = false)
    {
        $productId = null;

        try {
            $data = [
                'NAME'             => $product->name,
                'CODE'             => self::getUniqueCodeElement($product->name),
                'IBLOCK_ID'        => self::getIblockId($iblockCode),
                'DETAIL_TEXT'      => '<p>' . $product->description . '</p>' . self::getProductDetailText($product),
                'DETAIL_TEXT_TYPE' => 'html',
                'PROPERTY_VALUES'  => $properties,
                'ACTIVE'           => self::getStatusProduct($product),
            ];

            if (isset($product->images[0]->superbig)) {
                $data['DETAIL_PICTURE'] = CFile::MakeFileArray($product->images[0]->superbig);
            }

            if ($offer === false) {
                $data += self::getIblockSectionProduct($product, $oasisCategories);
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
     * @param array $oasisCategories
     * @return false|mixed|void
     * @throws LoaderException
     */
    public static function upIblockElementProduct($iblockElementId, $product, array $oasisCategories = [])
    {
        $result = false;

        try {
            $data = [
                'NAME'             => $product->name,
                'DETAIL_TEXT'      => '<p>' . $product->description . '</p>' . self::getProductDetailText($product),
                'DETAIL_TEXT_TYPE' => 'html',
                'ACTIVE'           => self::getStatusProduct($product),
            ];

            if ($oasisCategories) {
                $data += self::getIblockSectionProduct($product, $oasisCategories);
            }

            $el = new CIBlockElement;
            $result = $el->Update($iblockElementId, $data);

            if (!empty($el->LAST_ERROR)) {
                $str = $oasisCategories ? 'Ошибка обновления товара: ' : 'Ошибка обновления торгового предложения: ';
                echo $str . $el->LAST_ERROR . PHP_EOL;
                die();
            }
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        return $result;
    }

    /**
     * Update status first product
     *
     * @param $productId
     */
    public static function upStatusFirstProduct($productId)
    {
        try {
            $arSKU = \CCatalogSKU::getOffersList($productId, self::getIblockId('clothes'), ['ACTIVE' => 'Y'], [], []);

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
     * @param bool $offer
     * @param bool $parent
     * @throws Exception
     */
    public static function executeProduct($productId, $product, $offer = false, $parent = false)
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
                    'UF_OASIS_ID_PRODUCT' => $product->id,
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
     * Execute StoreProductTable
     *
     * @param $productId
     * @param $product
     * @throws Exception
     */
    public static function executeStoreProduct($productId, $product)
    {
        try {
            $arStore = StoreTable::getList([
                'filter' => ['ACTIVE' => 'Y'],
            ])->fetch();

            $rsStoreProduct = StoreProductTable::getList([
                'filter' => ['=PRODUCT_ID' => $productId, 'STORE.ACTIVE' => 'Y'],
            ]);

            $arField = [
                'PRODUCT_ID' => intval($productId),
                'STORE_ID'   => $arStore['ID'],
                'AMOUNT'     => is_null($product->total_stock) ? 0 : $product->total_stock,
            ];

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
     * @throws Exception
     */
    public static function executePriceProduct($productId, $product)
    {
        try {
            $dbPrice = PriceTable::getList([
                'filter' => ['PRODUCT_ID' => $productId]
            ])->fetch();

            $arField = [
                'CATALOG_GROUP_ID' => self::getBaseCatalogGroupId(),
                'PRODUCT_ID'       => $productId,
                'PRICE'            => $product->price,
                'PRICE_SCALE'      => $product->price,
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
     * @return array
     * @throws LoaderException
     */
    public static function getIblockSectionProduct($product, $oasisCategories): array
    {
        $categories = self::getCategories($oasisCategories, $product->categories);

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
    public static function checkProperties()
    {
        $properties = [
            'ARTNUMBER'     => [
                'name'       => 'Артикул',
                'type'       => 'S',
                'iblockCode' => 'clothes',
            ],
            'MANUFACTURER'  => [
                'name'       => 'Производитель',
                'type'       => 'S',
                'iblockCode' => 'clothes',
            ],
            'MATERIAL'      => [
                'name'       => 'Материал',
                'type'       => 'S',
                'iblockCode' => 'clothes',
            ],
            'COLOR'         => [
                'name'       => 'Цвет',
                'type'       => 'S',
                'iblockCode' => 'clothes',
            ],
            'SIZES_CLOTHES' => [
                'name'       => 'Размеры одежды',
                'type'       => 'L',
                'iblockCode' => 'clothes_offers',
            ],
            'SIZES_FLASH'   => [
                'name'       => 'Объем памяти',
                'type'       => 'L',
                'iblockCode' => 'clothes_offers',
            ],
            'COLOR_CLOTHES' => [
                'name'       => 'Цвет',
                'type'       => 'L',
                'iblockCode' => 'clothes_offers',
            ],
        ];

        try {
            Loader::includeModule('iblock');

            foreach ($properties as $key => $value) {
                $dbProperty = PropertyTable::getList([
                    'filter' => ['CODE' => $key],
                ])->fetch();

                if (!$dbProperty) {
                    $propertyId = self::addProperty($key, $value);

                    if ($key === 'COLOR_CLOTHES' || $key === 'SIZES_CLOTHES' || $key === 'SIZES_FLASH') {
                        PropertyFeature::setFeatures($propertyId,
                            [
                                [
                                    'MODULE_ID'  => 'catalog',
                                    'IS_ENABLED' => 'Y',
                                    'FEATURE_ID' => 'IN_BASKET'
                                ],
                                [
                                    'MODULE_ID'  => 'catalog',
                                    'IS_ENABLED' => 'Y',
                                    'FEATURE_ID' => 'OFFER_TREE'
                                ],
                                [
                                    'MODULE_ID'  => 'iblock',
                                    'IS_ENABLED' => 'N',
                                    'FEATURE_ID' => 'LIST_PAGE_SHOW'
                                ],
                                [
                                    'MODULE_ID'  => 'iblock',
                                    'IS_ENABLED' => 'N',
                                    'FEATURE_ID' => 'DETAIL_PAGE_SHOW'
                                ],
                            ],
                        );
                    }

                    if ($key === 'SIZES_FLASH') {
                        SectionPropertyTable::add([
                            'IBLOCK_ID'        => self::getIblockId($value['iblockCode']),
                            'SECTION_ID'       => 0,
                            'PROPERTY_ID'      => $propertyId,
                            'SMART_FILTER'     => 'Y',
                            'DISPLAY_TYPE'     => 'F',
                            'DISPLAY_EXPANDED' => 'Y',
                            'FILTER_HINT'      => '',
                        ]);
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
     * @param $code
     * @param $data
     * @return array|int|void
     * @throws Exception
     */
    private static function addProperty($code, $data)
    {
        try {
            return PropertyTable::add([
                'IBLOCK_ID'        => self::getIblockId($data['iblockCode']),
                'NAME'             => $data['name'],
                'CODE'             => $code,
                'SORT'             => 1000,
                'PROPERTY_TYPE'    => $data['type'],
                'XML_ID'           => $code,
                'WITH_DESCRIPTION' => 'N',
                'IS_REQUIRED'      => 'N',
            ])->getId();
        } catch (SystemException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * Check property product
     *
     * @param $propertyCode
     * @param $value
     * @return array|int|void
     * @throws LoaderException
     * @throws Exception
     */
    public static function checkPropertyEnum($propertyCode, $value)
    {
        try {
            Loader::includeModule('iblock');

            $dbProperty = PropertyTable::getList([
                'filter' => ['CODE' => $propertyCode],
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
     * @return array
     * @throws LoaderException
     */
    public static function getPropertiesArrayOffer($productId, $product): array
    {
        $result = [
            'CML2_LINK' => $productId,
            'ARTNUMBER' => $product->article,
        ];

        if (!is_null($product->size) && in_array(3070, $product->full_categories)) {
            $result['SIZES_CLOTHES'] = self::checkPropertyEnum('SIZES_CLOTHES', $product->size);
        }

        $sizeFlash = self::searchObject($product->attributes, 219);

        if ($sizeFlash) {
            $result['SIZES_FLASH'] = self::checkPropertyEnum('SIZES_FLASH', $product->size);
        }

        foreach ($product->attributes as $attribute) {
            if (isset($attribute->id) && $attribute->id === 1000000001) {
                $result['COLOR_CLOTHES'] = self::checkPropertyEnum('COLOR_CLOTHES', $attribute->value);
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
     * @return array
     * @throws LoaderException
     */
    public static function getCategories($oasisCategories, $productCategories): array
    {
        $result = [];

        try {
            Loader::includeModule('iblock');

            $iblockId = self::getIblockId('clothes');

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
        $code = Cutil::translit($name, 'ru', ['replace_space' => '-', 'replace_other' => '-']);
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
    public static function checkUserFields()
    {
        Loader::includeModule('iblock');

        $dataField = [
            'ENTITY_ID'  => 'PRODUCT',
            'FIELD_NAME' => 'UF_OASIS_ID_PRODUCT',
            'LABEL'      => [
                'ru' => 'Oasis ID товара',
                'en' => 'Oasis ID product',
            ],
        ];
        self::addUserField($dataField);
        unset($dataField);

        $dataField = [
            'ENTITY_ID'  => 'IBLOCK_' . self::getIblockId('clothes') . '_SECTION',
            'FIELD_NAME' => 'UF_OASIS_ID_CATEGORY',
            'LABEL'      => [
                'ru' => 'Oasis ID категории',
                'en' => 'Oasis ID category',
            ],
        ];
        self::addUserField($dataField);
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
            'filter' => ['FIELD_NAME' => 'UF_' . $data['FIELD_NAME']],
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
     * @return array|mixed|void
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     */
    public static function getIblockId(string $code)
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

        return $arIblock['ID'] ?? $arIblock;
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
}