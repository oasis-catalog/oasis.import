# Oasis.import - импорт товаров в Битрикс

Модуль импорта товаров из oasiscatalog.com в 1С Битрикс: Интернет-магазин

**Внимание!** Установка модуля отключает выполнение агентов на хите.

**Требования:**

+ 1С-Битрикс: Управление сайтом 21+

+ Редакция (необходим "Интернет-магазин"): Малый бизнес, Бизнес, Энтерпрайз

+ PHP version: 7.4+

## Usage

Скачать архив oasis.import.zip из [свежего релиза](https://github.com/oasis-catalog/oasis.import/releases) и разархивировать в корень сайта.
Активировать модуль **«Импорт товаров Oasis»** на вашем сайте в разделе: https://site.com/bitrix/admin/partner_modules.php

Перейдите на страницу настроек модуля _«Настройки»_ -> _«Настройки продукта»_ -> _«Настройки модулей»_ -> _«Импорт товаров Oasis»_ и укажите действующий API ключ и User ID из [личного кабинета oasiscatalog](https://www.oasiscatalog.com/cabinet/integrations) и сохраните настройки модуля.

В панели управления хостингом добавить crontab задачи со страницы настроек модуля

