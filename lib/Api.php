<?php

namespace Oasis\Import;

use Bitrix\Main\Config\Option;

class Api
{

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