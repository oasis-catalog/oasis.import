<?php

namespace Oasis\Import;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\Model\Section;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\FileTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserFieldTable;

class Main
{

    public static function upProduct($dbProduct, $oasisProduct, $oasisCategories, $simple)
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

            $el = new \CIBlockElement;
            $el->Update($dbProduct['ID'], $data);

            self::executeProduct($dbProduct['ID'], $oasisProduct);
            self::executeStoreProduct($dbProduct['ID'], $oasisProduct);
            self::executePriceProduct($dbProduct['ID'], $oasisProduct);

        } catch (\Exception $e) {
        }
    }

    /**
     * @param $product
     * @return array|false
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function checkProduct($product)
    {
        Loader::includeModule('catalog');

        return ProductTable::getList([
            'filter' => ['UF_OASIS_ID_PRODUCT' => $product->id]
        ])->fetch();
    }

    /**
     * Add simple product
     *
     * @param $product
     * @param $oasisCategories
     * @param $simple
     * @return false|mixed
     */
    public static function addProduct($product, $oasisCategories, $simple)
    {
        try {
            Loader::includeModule('iblock');
            Loader::includeModule('catalog');

            $properties = self::getPropertiesArray($product);

            $i = 0;
            foreach ($product->images as $image) {
                $existImg = FileTable::getList([
                    'filter' => [
                        'ORIGINAL_NAME' => pathinfo($image->superbig)['basename'],
                    ],
                ])->fetch();

                if (!$existImg) {
                    $properties['MORE_PHOTO']['n' . $i++] = [
                        'VALUE' => \CFile::MakeFileArray($image->superbig),
                    ];
                }
            }

            $data = [
                'NAME'             => $product->name,
                'CODE'             => self::getUniqueCode($product->name),
                'IBLOCK_ID'        => self::getIblockId(),
                'DETAIL_TEXT'      => '<p>' . $product->description . '</p>' . self::getProductDetailText($product),
                'DETAIL_TEXT_TYPE' => 'html',
                'PROPERTY_VALUES'  => $properties,
                'ACTIVE'           => self::getStatusProduct($product),
            ];

            $data += self::getIblockSectionProduct($product, $oasisCategories);

            $el = new \CIBlockElement;
            $productId = $el->Add($data);

            self::executeProduct($productId, $product);
            self::executeStoreProduct($productId, $product);
            self::executePriceProduct($productId, $product);
        } catch (\Exception $e) {
        }

        return $productId;
    }

    /**
     * Execute ProductTable
     *
     * @param $productId
     * @param $product
     */
    public static function executeProduct($productId, $product)
    {
        try {
            $dbProduct = ProductTable::getList([
                'filter' => ['ID' => $productId]
            ])->fetch();

            if ($dbProduct) {
                ProductTable::update($dbProduct['ID'], [
                    'QUANTITY' => is_null($product->total_stock) ? 0 : $product->total_stock,
                ]);
            } else {
                ProductTable::add([
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
                ]);
            }
        } catch (\Exception $e) {
        }
    }

    /**
     * Execute StoreProductTable
     *
     * @param $productId
     * @param $product
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
        } catch (\Exception $e) {
        }
    }

    /**
     * Execute PriceTable
     *
     * @param $productId
     * @param $product
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
        } catch (\Exception $e) {
        }
    }

    /**
     * Get base catalog group id
     *
     * @return int
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
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
     * Get status product
     *
     * @param $product
     * @return string
     */
    public static function getStatusProduct($product): string
    {
        if (!is_null($product->total_stock) || $product->rating === 5) {
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
     */
    public static function getIblockSectionProduct($product, $oasisCategories): array
    {
        $categories = self::getCategories($oasisCategories, $product->full_categories);

        if (count($categories) > 1) {
            $result['IBLOCK_SECTION'] = $categories;
        } else {
            $result['IBLOCK_SECTION_ID'] = reset($categories);
        }

        return $result;
    }

    /**
     * Checking properties product and create if absent
     */
    public static function checkProperties()
    {
        $properties = [
            'ARTNUMBER'    => [
                'name' => 'Артикул',
                'type' => 'S',
            ],
            'MANUFACTURER' => [
                'name' => 'Производитель',
                'type' => 'S',
            ],
            'MATERIAL'     => [
                'name' => 'Материал',
                'type' => 'S',
            ],
            'COLOR'        => [
                'name' => 'Цвет',
                'type' => 'S',
            ],
        ];

        try {
            Loader::includeModule('iblock');

            foreach ($properties as $key => $value) {
                $dbProperty = PropertyTable::getList([
                    'filter' => ['CODE' => $key],
                ])->fetch();

                if (!$dbProperty) {
                    self::addProperty($key, $value);
                }
            }

        } catch (\Exception $e) {
        }
    }

    /**
     * Add property product
     *
     * @param $code
     * @param $data
     */
    private static function addProperty($code, $data)
    {
        try {
            PropertyTable::add([
                'IBLOCK_ID'        => self::getIblockId(),
                'NAME'             => $data['name'],
                'CODE'             => $code,
                'PROPERTY_TYPE'    => $data['type'],
                'XML_ID'           => $code,
                'WITH_DESCRIPTION' => 'N',
                'IS_REQUIRED'      => 'N',
            ]);
        } catch (\Exception $e) {
        }
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
            if (isset($attribute->id) && $attribute->id === 1000000001) {
                $result['COLOR'] = $attribute->value;
            } elseif (isset($attribute->id) && $attribute->id === 1000000002) {
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
     * Get detail text from properties
     *
     * @param $product
     * @return string
     */
    public static function getProductDetailText($product): string
    {
        $properties = [];

        foreach ($product->attributes as $attribute) {
            if ($attribute->name !== 'Размер') {
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
     * Get unique (not DB) code (alias)
     *
     * @param $name
     * @param int $i
     * @return string
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function getUniqueCode($name, int $i = 0): string
    {
        $code = \Cutil::translit($name, 'ru', ['replace_space' => '-', 'replace_other' => '-']);
        $code = $i === 0 ? $code : $code . '-' . $i;

        $dbCode = ElementTable::getList([
            'filter' => ['CODE' => $code],
        ])->fetch();

        if ($dbCode) {
            $code = self::getUniqueCode($name, ++$i);
        }

        return $code;
    }


    /**
     * Get array categories | add categories
     *
     * @param $oasisCategories
     * @param $productCategories
     * @return array
     */
    public static function getCategories($oasisCategories, $productCategories): array
    {
        $result = [];

        try {
            Loader::includeModule('iblock');

            $iblockId = self::getIblockId();

            foreach ($productCategories as $productCategory) {
                $result[] = self::getCategoryId($oasisCategories, $productCategory, $iblockId);
            }
        } catch (\Exception $e) {
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
        $sectionCategory = self::getSectionCategoryId($iblockId, $categoryId);

        if ($sectionCategory) {
            $result = $sectionCategory['ID'];
        } else {
            $iblockSectionId = null;
            $oasisCategory = self::searchObject($oasisCategories, $categoryId);

            if (!is_null($oasisCategory->parent_id)) {
                $parentSectionCategory = self::getSectionCategoryId($iblockId, $oasisCategory->parent_id);

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
    public static function getSectionCategoryId($iblockId, $categoryId)
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
        $objDateTime = new DateTime();
        $iblockSection = new \CIBlockSection;

        $arFields = [
            'DATE_CREATE'          => $objDateTime->format('Y-m-d H:i:s'),
            'IBLOCK_ID'            => $iblockId,
            'IBLOCK_SECTION_ID'    => $iblockSectionId,
            'ACTIVE'               => 'Y',
            'NAME'                 => $category->name,
            'DEPTH_LEVEL'          => $category->level,
            'DESCRIPTION_TYPE'     => 'text',
            'CODE'                 => $category->slug,
            'UF_OASIS_ID_CATEGORY' => $category->id,
        ];

        return $iblockSection->Add($arFields);
    }

    /**
     * Check user fields UF_OASIS_ID_CATEGORY and UF_OASIS_ID_PRODUCT
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
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
            'ENTITY_ID'  => 'IBLOCK_' . self::getIblockId() . '_SECTION',
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
     * @return int
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private static function addUserField($data): int
    {
        $dbUserFields = UserFieldTable::getList([
            'select' => ['ID'],
            'filter' => ['FIELD_NAME' => 'UF_' . $data['FIELD_NAME']],
        ])->fetch();

        if (!$dbUserFields) {
            $oUserTypeEntity = new \CUserTypeEntity();

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
            $userFieldId = $dbUserFields['ID'];
        }

        return (int)$userFieldId;
    }

    /**
     * Get Iblock Id
     *
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    public static function getIblockId()
    {
        $arIblock = IblockTable::getList([
            'select' => ['ID'],
            'filter' => ['CODE' => 'clothes'],
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
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
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