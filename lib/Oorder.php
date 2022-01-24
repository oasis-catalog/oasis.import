<?php

namespace Oasis\Import;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Loader;
use Bitrix\Sale\Order;
use Bitrix\Sale\Basket;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class Oorder
{
    /**
     * @throws \Bitrix\Main\LoaderException
     * @throws \Bitrix\Main\ArgumentException
     */
    public static function getOrdersHtml(): string
    {
        Loader::includeModule('sale');
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
        <div class="table_order_col ">ID</div>
        <div class="table_order_col">Дата создания</div>
        <div class="table_order_col">Сумма</div>
        <div class="table_order_col">Статус</div>
        <div class="table_order_col">Выгрузить</div>
    </div>';

        if ($orders) {
            foreach ($orders as $order) {
                $idsStr = '-';
                $basket = Basket::getList([
                    'filter' => [
                        'ORDER_ID' => $order['ID']
                    ],
                ])->fetchAll();

                if ($basket) {
                    Loader::includeModule('catalog');

                    foreach ($basket as $item) {
                        $result = ProductTable::getList([
                            'select' => ['UF_OASIS_ID_PRODUCT'],
                            'filter' => [
                                'ID' => $item['PRODUCT_ID'],
                            ],
                        ])->fetch();

                        if ($result['UF_OASIS_ID_PRODUCT']) {
                            $ids[] = $result['UF_OASIS_ID_PRODUCT'];
                        } else {
                            $ids = [];
                            break;
                        }

//                        echo '<pre>' . print_r($result, true) . '</pre>';
                    }

                    if (!empty($ids)) {
                        $idsStr = implode(',', $ids);
                    } else {
                        $idsStr = Loc::getMessage('OASIS_IMPORT_ORDERS_NOT_PRODUCT');
                    }
                }

                $html .= '
	<div class="table_order_row table_order_body">
        <div class="table_order_col">' . $order['ID'] . '</div>
        <div class="table_order_col">' . $order['DATE_INSERT']->toString() . '</div>
        <div class="table_order_col">' . $order['STATUS_ID'] . '</div>
        <div class="table_order_col">' . $order['PRICE'] . '</div>
        <div class="table_order_col">' . $idsStr . '</div>
	</div>';
            }
        }
        $html .= '
</div>';
        return $html;
    }

    /**
     * @return array
     * @throws \Bitrix\Main\ArgumentException
     */
    public static function getOrders(): array
    {
        return Order::getList([
            'order' => ['ID' => 'DESC']
        ])->fetchAll();
    }

}