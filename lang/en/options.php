<?php

$MESS["OASIS_IMPORT_OPTIONS_TAB_NAME"] = "Options";
$MESS["OASIS_IMPORT_OPTIONS_TAB_AUTH"] = "Data API";
$MESS["OASIS_IMPORT_OPTIONS_TAB_API_KEY"] = "API Key (<strong style='color: #ff0000;'>required</strong>)";
$MESS["OASIS_IMPORT_OPTIONS_TAB_API_USER_ID"] = "API user ID";
$MESS["OASIS_IMPORT_OPTIONS_TAB_IBLOCK"] = "Options Iblocks";
$MESS["OASIS_IMPORT_OPTIONS_TAB_IBLOCK_CATALOG"] = "Iblock catalog (<strong style='color: #ff0000;'>required</strong>)";
$MESS["OASIS_IMPORT_OPTIONS_TAB_IBLOCK_OFFERS"] = "Iblock offers (<strong style='color: #ff0000;'>required</strong>)";
$MESS["OASIS_IMPORT_OPTIONS_TAB_IBLOCK_ERROR_TITLE"] = "Error!";
$MESS["OASIS_IMPORT_OPTIONS_TAB_IBLOCK_ERROR_DESC"] = "<div class='ui-alert ui-alert-icon-warning ui-alert-danger'><span class='ui-alert-message'><strong>Attention!</strong> Data not saved! <br> No infoblocks selected, select infoblocks and save the module.</span></div>";
$MESS["OASIS_IMPORT_OPTIONS_TAB_CRON_TITLE"] = "Options cron";
$MESS["OASIS_IMPORT_OPTIONS_TAB_CRON_TYPE"] = "Cron run mode";
$MESS["OASIS_IMPORT_OPTIONS_TAB_CRON_BITRIX"] = "System (agents)";
$MESS["OASIS_IMPORT_OPTIONS_TAB_CRON_CUSTOM"] = "Cron CLI (works independently of agents)";
$MESS["OASIS_IMPORT_OPTIONS_TAB_CRON_DESC"] = "<strong>Add this job to CRON</strong><br/><code>* * * * * /usr/bin/php %s/bitrix/php_interface/cron_events.php</code>";
$MESS["OASIS_IMPORT_OPTIONS_TAB_CRON_DESC_CUSTOM"] = "<strong>Add this job to CRON</strong><br/>Import/update products (once a day): <br/><code>0 0 * * * /usr/bin/php %s</code><br/>Update quantity (every half hour): <br/><code>*/30 * * * * /usr/bin/php %s --up</code><br/>When working through Cron CLI, it is necessary to disable the agents of the module <code>oasis.import</code>";
$MESS["OASIS_IMPORT_OPTIONS_TAB_OPTIONS_IMPORT"] = "Advanced options import";
$MESS["OASIS_IMPORT_OPTIONS_TAB_CATEGORIES"] = "Categories";
$MESS["OASIS_IMPORT_OPTIONS_TAB_CATEGORIES_DESC"] = "Allows you to select product categories to download";
$MESS["OASIS_IMPORT_OPTIONS_TAB_CATEGORY_REL"] = "Default section";
$MESS["OASIS_IMPORT_OPTIONS_TAB_CATEGORY_REL_DESC"] = "If no section is specified for a product category, the product will be placed in the default section";
$MESS["OASIS_IMPORT_OPTIONS_TAB_CURRENCY"] = "Currency";
$MESS["OASIS_IMPORT_OPTIONS_TAB_NO_VAT"] = "No VAT";
$MESS["OASIS_IMPORT_OPTIONS_TAB_NOT_ON_ORDER"] = "Without goods \"to order\"";
$MESS["OASIS_IMPORT_OPTIONS_TAB_PRICE_FROM"] = "Price from";
$MESS["OASIS_IMPORT_OPTIONS_TAB_PRICE_TO"] = "Price to";
$MESS["OASIS_IMPORT_OPTIONS_TAB_RATING"] = "Type";
$MESS["OASIS_IMPORT_OPTIONS_TAB_SELECT"] = "---Select---";
$MESS["OASIS_IMPORT_OPTIONS_TAB_RATING_NEW"] = "Only new items";
$MESS["OASIS_IMPORT_OPTIONS_TAB_RATING_HITS"] = "Hits only";
$MESS["OASIS_IMPORT_OPTIONS_TAB_RATING_DISCOUNT"] = "Only with a discount";
$MESS["OASIS_IMPORT_OPTIONS_TAB_WAREHOUSE_MOSCOW"] = "In a warehouse in Moscow";
$MESS["OASIS_IMPORT_OPTIONS_TAB_WAREHOUSE_EUROPE"] = "In stock in Europe";
$MESS["OASIS_IMPORT_OPTIONS_TAB_REMOTE_WAREHOUSE"] = "At a remote warehouse";
$MESS["OASIS_IMPORT_OPTIONS_INPUT_APPLY"] = "Apply";
$MESS["OASIS_IMPORT_OPTIONS_INPUT_DEFAULT"] = "Default";
$MESS["OASIS_IMPORT_OPTIONS_ERROR_API_KEY"] = "<strong>Attention!</strong> API key is missing or not valid.";
$MESS["OASIS_IMPORT_ORDERS_TAB_NAME"] = "Orders";
$MESS["OASIS_IMPORT_ORDERS_TAB_AUTH"] = "Uploading orders to Oasiscatalog";
$MESS["OASIS_IMPORT_OPTIONS_TAB_LIMIT"] = "Limit";
$MESS["OASIS_IMPORT_OPTIONS_TAB_LIMIT_NOTE"] = "Setting for weak servers. Specify the limit of goods for one-time receipt and processing in CRON.<br/><strong>Attention!</strong> This option increases the time for adding products<br/>Values >1, 0 or empty - disables the limit";
$MESS["OASIS_IMPORT_OPTIONS_TAB_LIMIT_PRODUCT"] = "Products limit";
$MESS["OASIS_IMPORT_OPTIONS_TAB_IMPORT_ANYTIME"] = "Do not limit update";
$MESS["OASIS_IMPORT_OPTIONS_TAB_IMPORT_ANYTIME_NOTE"] = "Full product update is limited, no more than once a day";
$MESS["OASIS_IMPORT_OPTIONS_TAB_CALC"] = "Price settings";
$MESS["OASIS_IMPORT_OPTIONS_TAB_FACTOR"] = "Price factor";
$MESS["OASIS_IMPORT_OPTIONS_TAB_INCREASE"] = "Price surcharge";
$MESS["OASIS_IMPORT_OPTIONS_TAB_DELETE_EXCLUDE"] = "Remove products from excluded categories";
$MESS["OASIS_IMPORT_OPTIONS_TAB_NOT_UP_PRODUCT_CAT"] = "Not update product categories";
$MESS["OASIS_IMPORT_OPTIONS_TAB_DEALER"] = "Use dealer prices";
$MESS["OASIS_IMPORT_OPTIONS_TAB_OPTIONS_STOCK"] = "Stocks";
$MESS["OASIS_IMPORT_OPTIONS_TAB_STOCKS"] = "Use different stocks";
$MESS["OASIS_IMPORT_OPTIONS_TAB_STOCKS_ERROR_DESC"] = "<div class='ui-alert ui-alert-icon-warning ui-alert-danger'><span class='ui-alert-message'><strong>Attention!</strong> Data not saved! <br> No stocks selected, select stocks and save the module.</span></div>";
$MESS["OASIS_IMPORT_OPTIONS_TAB_MAIN_STOCK"] = "Moscow (<strong style='color: #ff0000;'>required</strong>)";
$MESS["OASIS_IMPORT_OPTIONS_TAB_REMOTE_STOCK"] = "Remote stock (<strong style='color: #ff0000;'>required</strong>)";
$MESS["OASIS_IMPORT_OPTIONS_TAB_EUROPE_STOCK"] = "Europe (<strong style='color: #ff0000;'>required</strong>)";
$MESS["OASIS_IMPORT_OPTIONS_TAB_PROGRESS_TOTAL"] = "Total processing status";
$MESS["OASIS_IMPORT_OPTIONS_TAB_PROGRESS_STEP"] = "Step %d out of %d in progress. Current step status";
$MESS["OASIS_IMPORT_OPTIONS_TAB_PROGRESS_DATE"] = "Last import completed: ";
$MESS["OASIS_IMPORT_OPTIONS_TAB_PHOTO"] = "Photo";
$MESS["OASIS_IMPORT_OPTIONS_TAB_MOVE_FIRST_IMG_TO_DETAIL"] = "Move the first gallery image to a detail image";
$MESS["OASIS_IMPORT_OPTIONS_TAB_UP_PHOTO"] = "Update product photos";

// Orders
$MESS["OASIS_IMPORT_ORDERS_ID"] = "ID";
$MESS["OASIS_IMPORT_ORDERS_DATE_INSERT"] = "Date insert";
$MESS["OASIS_IMPORT_ORDERS_PRICE"] = "Price";
$MESS["OASIS_IMPORT_ORDERS_UPLOAD"] = "Upload";
$MESS["OASIS_IMPORT_ORDERS_SEND"] = "Send";
$MESS["OASIS_IMPORT_ORDERS_NOT_PRODUCT"] = "Invalid items in the order";
$MESS["OASIS_IMPORT_ORDERS_SENT"] = "Status: ";
$MESS["OASIS_IMPORT_ORDERS_ORDER_NUMBER"] = ", order №";
$MESS["OASIS_IMPORT_ORDERS_ORDER_PENDING"] = "order is created";
$MESS["OASIS_IMPORT_ORDERS_ORDER_ERROR"] = "order creation error";
$MESS["OASIS_IMPORT_ORDERS_CONNECTION_ERROR"] = "Error getting data";
