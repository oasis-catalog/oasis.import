# Oasis.import - импорт товаров в Битрикс

Модуль импорта товаров из oasiscatalog.com в 1С Битрикс: Интернет-магазин

**Внимание!** Установка модуля отключает выполнение агентов на хите.

**Требования:**

+ 1С-Битрикс: Управление сайтом 17.0.9 < 20.0

+ Редакция (необходим "Интернет-магазин"): Малый бизнес, Бизнес, Энтерпрайз

+ PHP version: 7.1

## Usage

Модуль из [ветки 17.0.9](https://github.com/oasis-catalog/oasis.import/tree/bitrix-17.0.9) разместить в /local/modules.
Активировать модуль **«Импорт товаров Oasis»** на вашем сайте в разделе: https://site.com/bitrix/admin/partner_modules.php

Перейдите на страницу настроек модуля _«Настройки»_ -> _«Настройки продукта»_ -> _«Настройки модулей»_ -> _«Импорт товаров Oasis»_ и укажите действующий API ключ и User ID из [личного кабинета oasiscatalog](https://www.oasiscatalog.com/cabinet/integrations) и сохраните настройки модуля.

В панели управления хостингом добавить crontab задачи со страницы настроек модуля

Для группировки товаров и отображения новых свойств торговых предложений необходимо произвести настройку:
1. Перейти на страницу добавленного торгового предложения и включить «Режим правки»
   ![Image alt](https://github.com/oasis-catalog/oasis.import/blob/bitrix-17.0.9/assets/img/img_1.jpg)
2. Навести указатель мыши на область карточки товара и на появившейся панели перейти в настройки.
   ![Image alt](https://github.com/oasis-catalog/oasis.import/blob/bitrix-17.0.9/assets/img/img_2.jpg)
3. В разделе «Внешний вид» в пункте «Свойства для отбора предложений:» через зажатый CTRL выделить пункты «[SIZES_FLASH] Объем памяти» и «[COLOR_CLOTHES] Цвет»
   ![Image alt](https://github.com/oasis-catalog/oasis.import/blob/bitrix-17.0.9/assets/img/img_3.jpg)
4. Тоже самое проделать в разделе «Добавление в корзину» в пункте «Свойства предложений, добавляемые в корзину:»
   ![Image alt](https://github.com/oasis-catalog/oasis.import/blob/bitrix-17.0.9/assets/img/img_4.jpg)
      и «Настройки поиска» в пункте «Свойства предложений:»
   ![Image alt](https://github.com/oasis-catalog/oasis.import/blob/bitrix-17.0.9/assets/img/img_5.jpg)

