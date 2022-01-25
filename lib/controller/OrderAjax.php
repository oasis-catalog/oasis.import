<?php
namespace Oasis\Import\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter;
Use Oasis\Import\Oorder;

class OrderAjax extends Controller
{

    /**
     * @return array
     */
    public function configureActions()
    {
        return [
            'send' => [
                'prefilters' => [
                    new ActionFilter\Authentication(),
                    new ActionFilter\HttpMethod([
                        ActionFilter\HttpMethod::METHOD_POST
                    ]),
                    new ActionFilter\Csrf(),
                ]
            ]
        ];
    }

    /**
     * Action send order
     *
     * @param null $orderId
     * @return bool
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     */
    public static function sendAction($orderId = null)
    {
        $result = false;

        if ($orderId)  {
            Oorder::setOrder(Oorder::getOasisProductIds(intval($orderId)), $orderId);
            $result = true;
        }

        return $result;
    }
}