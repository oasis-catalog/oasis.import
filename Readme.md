# Oasis.import - импорт товаров в Битрикс

Модуль импорта товаров из oasiscatalog.com в cms интернет-магазин Битрикс

PHP version: 7.3+

**Внимание!** Установка модуля отключает выполнение агентов на хите.

## Usage

Скачать архив oasis.import.zip из [свежего релиза](https://github.com/oasis-catalog/oasis.import/releases) и разархивировать в корень сайта.
Активировать модуль «Импорт товаров Oasis» на вашем сайте в разделе: https://site.com/bitrix/admin/partner_modules.php

Перейдите на страницу настроек модуля «Настройки» -> «Настройки продукта» -> «Настройки модулей» -> «Импорт товаров Oasis» и укажите действующий API ключ и User ID из [личного кабинета oasiscatalog](https://www.oasiscatalog.com/cabinet/integrations) и сохраните настройки модуля.

В панели управления хостингом добавить crontab задачи со страницы настроек модуля

