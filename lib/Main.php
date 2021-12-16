<?php

namespace Oasis\Import;

use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\Model\Section;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\Debug;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserFieldTable;

class Main
{

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

            self::userFieldCategory($iblockId);

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
     * Check user field UF_OASIS_ID_CATEGORY or add user field
     *
     * @param $iblockId
     * @return int
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ObjectPropertyException
     * @throws \Bitrix\Main\SystemException
     */
    private function userFieldCategory($iblockId): int
    {
        $dbUserFields = UserFieldTable::getList([
            'select' => ['ID'],
            'filter' => ['FIELD_NAME' => 'UF_OASIS_ID_CATEGORY'],
        ])->fetch();

        if (!$dbUserFields) {
            $oUserTypeEntity = new \CUserTypeEntity();

            $aUserFields = [
                'ENTITY_ID'         => 'IBLOCK_' . $iblockId . '_SECTION',
                'FIELD_NAME'        => 'UF_OASIS_ID_CATEGORY',
                'USER_TYPE_ID'      => 'string',
                'XML_ID'            => 'XML_ID_OASIS_ID_CATEGORY',
                'MULTIPLE'          => 'N',
                'MANDATORY'         => 'N',
                'SHOW_FILTER'       => 'N',
                'SHOW_IN_LIST'      => '',
                'EDIT_IN_LIST'      => '',
                'IS_SEARCHABLE'     => 'N',
                'SETTINGS'          => [
                    'DEFAULT_VALUE' => '',
                    'SIZE'          => '11',
                    'ROWS'          => '1',
                    'MIN_LENGTH'    => '0',
                    'MAX_LENGTH'    => '0',
                    'REGEXP'        => '',
                ],
                'EDIT_FORM_LABEL'   => [
                    'ru' => 'Oasis ID категории',
                    'en' => 'Oasis ID category',
                ],
                'LIST_COLUMN_LABEL' => [
                    'ru' => 'Oasis ID категории',
                    'en' => 'Oasis ID category',
                ],
                'LIST_FILTER_LABEL' => [
                    'ru' => 'Oasis ID категории',
                    'en' => 'Oasis ID category',
                ],
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
                // Добавить инфоблок
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