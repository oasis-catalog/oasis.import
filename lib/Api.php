<?php

namespace Oasis\Import;

use Bitrix\Main\Config\Option;

class Api
{
    /**
     * Get oasis products by module settings
     *
     * @param array $args
     * @return array
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     */
    public static function getProductsOasis(array $args = []): array
    {
        $module_id = pathinfo(dirname(__DIR__))['basename'];

        try {
            $args['fieldset'] = 'full';

            $data = [
                'currency'         => Option::get($module_id, 'currency') ?? 'rub',
                'no_vat'           => (bool)Option::get($module_id, 'no_vat') ?? 0,
                'not_on_order'     => (bool)Option::get($module_id, 'not_on_order'),
                'price_from'       => (float)Option::get($module_id, 'price_from'),
                'price_to'         => (float)Option::get($module_id, 'price_to'),
                'rating'           => (bool)Option::get($module_id, 'rating'),
                'warehouse_moscow' => (bool)Option::get($module_id, 'warehouse_moscow'),
                'warehouse_europe' => (bool)Option::get($module_id, 'warehouse_europe'),
                'remote_warehouse' => (bool)Option::get($module_id, 'remote_warehouse'),
            ];

            $categories = Option::get($module_id, 'categories');

            if (!$categories) {
                $categories = implode(',', array_keys(Main::getOasisMainCategories()));
            }

            $args += [
                'category' => $categories,
            ];

            foreach ($data as $key => $value) {
                if ($value) {
                    $args[$key] = $value;
                }
            }
            unset($category, $data, $key, $value);
        } catch (\Exception $e) {
        }

        return self::curlQuery('products', $args);
    }

    /**
     * Get categories oasis
     *
     * @return array
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     */
    public static function getCategoriesOasis(): array
    {
        return self::curlQuery('categories', ['fields' => 'id,parent_id,root,level,slug,name,path']);
    }

    /**
     * Get currencies oasis
     *
     * @return array
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     */
    public static function getCurrenciesOasis(): array
    {
        return self::curlQuery('currencies');
    }

    /**
     * Get api data
     *
     * @param $type
     * @param array $args
     *
     * @return array
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     */
    public static function curlQuery($type, array $args = []): array
    {
        $apiKey = Option::get(pathinfo(dirname(__DIR__))['basename'], 'api_key');

        if (empty($apiKey)) {
            return [];
        }

        $args_pref = [
            'key'    => $apiKey,
            'format' => 'json',
        ];
        $args = array_merge($args_pref, $args);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.oasiscatalog.com/v4/' . $type . '?' . http_build_query($args));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = json_decode(curl_exec($ch));
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $http_code === 200 ? $result : [];
    }
}