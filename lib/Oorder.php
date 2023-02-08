<?php

namespace Oasis\Import;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Sale\Order;
use Bitrix\Sale\Basket;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class Oorder extends Main
{
    /**
     * Preparing data and submitting an order
     *
     * @param $products
     * @param $orderId
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     */
    public static function setOrder($products, $orderId)
    {
        $data['userId'] = Option::get(pathinfo(dirname(__DIR__))['basename'], 'api_user_id');

        if (!empty($data['userId']) && $products) {
            foreach ($products as $productId => $quantity) {
                $data['items'][] = [
                    'productId' => $productId,
                    'quantity'  => $quantity,
                ];
            }
            unset($productId, $quantity);

            $request = Api::sendOrder($data);

            if ($request) {
                $main = new Main();
                $main->addOasisOrder(intval($orderId), intval($request->queueId));
            }
        }
    }

    /**
     * Get html order list
     *
     * @return string
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\ArgumentNullException
     * @throws \Bitrix\Main\ArgumentOutOfRangeException
     * @throws \Bitrix\Main\LoaderException
     */
    public static function getOrdersHtml(): string
    {
        $orders = self::getOrders();

        $html = '
<style type="text/css">
    .adm-list-table-cell {
        width: 19%;
        display: inline-block;
    }
    
    .table_order_row {
        min-width: 800px;
    }
    .table_order_col {
        height: 21px;
        vertical-align: middle;
        padding: 6px 0 4px 16px;
        display: inline-block;
        width: 17%;
        margin: 0;
    }
    .table_order_header .table_order_col {
        background-color: #aebbc0;
        background-image: -webkit-linear-gradient(top, #b9c7cd, #aab6b8);
        background-image: linear-gradient(to bottom, #b9c7cd, #aab6b8);
        border-color: #d9e0e4 #89979d #919b9d #ebeef0;
        font-weight: bold;
    }
    .table_order_header .table_order_body {
    }
    .table_order_row .table_order_col {
        background-color: #ffffff;
    }
    .table_order_row:nth-child(odd) .table_order_col {
        background-color: #f5f9f9;
    }
</style>
<div class="table_order">
    <div class="table_order_row table_order_header">
        <div class="table_order_col">' . Loc::getMessage('OASIS_IMPORT_ORDERS_ID') . '</div>
        <div class="table_order_col">' . Loc::getMessage('OASIS_IMPORT_ORDERS_DATE_INSERT') . '</div>
        <div class="table_order_col">' . Loc::getMessage('OASIS_IMPORT_ORDERS_PRICE') . '</div>
        <div class="table_order_col">' . Loc::getMessage('OASIS_IMPORT_ORDERS_UPLOAD') . '</div>
    </div>';

        if ($orders) {
            foreach ($orders as $order) {
                $ids = self::getOasisProductIds(intval($order['ID']));

                if ($ids) {
                    $existOrder = parent::getOasisOrder(intval($order['ID']));
                    if ($existOrder) {
                        $dataOrderOasis = Api::getOrder($existOrder['ID_QUEUE']);

                        if (isset($dataOrderOasis['state'])) {
                            $sendStr = Loc::getMessage('OASIS_IMPORT_ORDERS_SENT');

                            if ($dataOrderOasis['state'] == 'created') {
                                $sendStr .= $dataOrderOasis['order']->statusText . Loc::getMessage('OASIS_IMPORT_ORDERS_ORDER_NUMBER') . $dataOrderOasis['order']->number;
                            } elseif ($dataOrderOasis['state'] == 'pending') {
                                $sendStr .= Loc::getMessage('OASIS_IMPORT_ORDERS_ORDER_PENDING');
                            } elseif ($dataOrderOasis['state'] == 'error') {
                                $sendStr .= Loc::getMessage('OASIS_IMPORT_ORDERS_ORDER_ERROR');
                            }
                        } else {
                            $sendStr = Loc::getMessage('OASIS_IMPORT_ORDERS_CONNECTION_ERROR');
                        }
                    } else {
                        $sendStr = '<input type="submit" class="adm-btn" name="send_order" onclick="sendHere(' . $order['ID'] . ');" value="' . Loc::getMessage('OASIS_IMPORT_ORDERS_SEND') . '" style="height: 20px;">';
                    }
                } else {
                    $sendStr = Loc::getMessage('OASIS_IMPORT_ORDERS_NOT_PRODUCT');
                }

                $html .= '
	<div class="table_order_row table_order_body">
        <div class="table_order_col">' . $order['ID'] . '</div>
        <div class="table_order_col">' . $order['DATE_INSERT']->toString() . '</div>
        <div class="table_order_col">' . $order['PRICE'] . '</div>
        <div class="table_order_col">' . $sendStr . '</div>
	</div>';
            }
        }
        $html .= '
</div>';

        $html .= '
<script type="text/javascript">
    function sendHere(order) {
        BX.ajax.runAction("oasis:import.api.orderajax.send", {
            data: {
                orderId: order,
            },
            method: "POST",
            sessid: BX.message("bitrix_sessid")
        });
    }
</script>
';

        return $html;
    }

    /**
     * Get oasis product ids by order id
     *
     * @param int $orderId
     * @return array|null
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     */
    public static function getOasisProductIds(int $orderId): ?array
    {
        Loader::includeModule('catalog');
        Loader::includeModule('sale');

        $result = null;
        $basket = Basket::getList([
            'filter' => [
                'ORDER_ID' => $orderId
            ],
        ])->fetchAll();

        if ($basket) {
            foreach ($basket as $item) {
                try {
                    $product = ProductTable::getList([
                        'filter' => [
                            'ID' => intval($item['PRODUCT_ID']),
                            '!UF_OASIS_ID_PRODUCT' => '',
                        ],
                        'select' => ['UF_OASIS_ID_PRODUCT'],
                    ])->fetch();
                } catch (SystemException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }

                if (!empty($product['UF_OASIS_ID_PRODUCT'])) {
                    $result[$product['UF_OASIS_ID_PRODUCT']] = intval($item['QUANTITY']);
                } else {
                    return null;
                }
            }
        }
        return $result;
    }

    /**
     * Get orders
     *
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     * @throws \Bitrix\Main\LoaderException
     */
    public static function getOrders(): array
    {
        Loader::includeModule('sale');

        return Order::getList([
            'order' => ['ID' => 'DESC']
        ])->fetchAll();
    }

}