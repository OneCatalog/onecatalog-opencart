# Интеграционный план: OpenCart 3.0.3.x

Порт OneCatalog Import под **OpenCart 3.0.3.x** по стандарту интеграции **v1.2**
(см. [onecatalog-standard](https://github.com/OneCatalog/onecatalog-standard)).
Эталон логики — [onecatalog-woocommerce](https://github.com/OneCatalog/onecatalog-woocommerce).

Два сценария стандарта внедряем по отдельности:
1. **Импорт каталога** из Wiki API (§1–§12). Цену импорт НЕ задаёт (§5.6).
2. **Синхронизация цен/остатков** из B2B-фида (§13) — живой фид, change-detection.

---

## Платформа и упаковка расширения

- **Тип расширения:** admin-side модуль OpenCart (MVC-L) + **OCMOD** (`install.xml`)
  для пункта меню в админке и регистрации событий. Распространение — `.ocmod.zip`
  (загрузка через «Расширения → Установка расширений») и/или OpenCart Marketplace.
- **Структура (MVC-L, namespace путей OpenCart 3):**
  ```
  admin/controller/extension/module/onecatalog.php      — контроллеры (страницы, AJAX)
  admin/model/extension/onecatalog/*.php                — importer, queue, media, b2b…
  admin/view/template/extension/module/onecatalog*.twig — шаблоны страниц (Twig)
  admin/view/javascript/onecatalog/*.js                 — пикер-лоадер, степпер импорта
  admin/language/{en-gb,ru-ru}/extension/module/onecatalog.php — строки (§9)
  system/library/onecatalog/*.php                        — Api, Units (платформо-независимое ядро)
  install.xml                                            — OCMOD: меню + события
  ```
- **БД-префикс** берём из `DB_PREFIX` (по умолчанию `oc_`).
- **Ядро PHP** (Api-клиент, Units, контент-ключ/дедуп, нормализация payload) пишем так,
  чтобы оно совпадало с эталоном и было кандидатом в общий Composer-core (бэклог стандарта).

---

## Маппинг сущностей → OpenCart

| OneCatalog | OpenCart 3.0.3.x | Примечание |
|---|---|---|
| product | `product` + `product_description` | цена по умолчанию `0` (не синтезируем, §5.6) |
| **public_id** | **своя таблица `*_oc_map`** (product_id ↔ public_id) | нативных custom-полей у товара нет; идемпотентность по этой таблице, не по `model`/`sku` |
| article | нативный **`product.model`** (и/или `sku`) | §5.2; пусто — не заполняем |
| options[] | **`attribute` + `attribute_group`** → `product_attribute.text` | значение — текст на язык (нет справочника термов, как в WC) |
| categories[] | `category` + `category_path` (+ `category_description`) | дерево по `parent_category` |
| **brand** | **нативный `manufacturer`** (find-by-name) | §3 «нативное прежде своего» |
| country | атрибут (или своё поле) — выбор цели | по умолчанию выкл (§7) |
| collections[] | категория / атрибут (выбор цели) | по умолчанию выкл; поля коллекции → событие (§8) |
| tags[] | нативный **`product_description.tag`** | родное поле тегов OpenCart |
| images_urls/files | `product.image` (обложка) + `product_image` (галерея) | скачивание вручную, MIME по содержимому (§2.3) |
| sizes/weight | `weight`+`weight_class_id`, `length/width/height`+`length_class_id` | конверсия в классы единиц магазина (§5.6) |
| measurement_unit, areas_per_package, … | событие `product_imported` (сайтовый слой) | §8 |

---

## Обязательные инварианты (как ложатся на OpenCart)

### §5.1 Идемпотентность
- Поиск по `public_id` через таблицу `*_oc_map` → найден: update, иначе: insert.
- Справочники (категории, атрибуты, manufacturer) — **find-or-create по имени**
  (label, регистронезависимо), не по slug/seo-keyword.
- **Служебные значения — вне формы товара (§5.1 v1.2):** сигнатуры идемпотентности
  (медиа, цены/остатки), контент-ключи дедупа, коды поставщиков — в служебных таблицах
  (`*_oc_meta` key-value по product_id), а НЕ как видимые атрибуты товара. `public_id`
  — в `*_oc_map`, доступен/редактируем через колонку в списке товаров (§AdminUI).

### §5.2 Артикул
- `product.model`/`sku` ← только `article` (если пришёл). `public_id` — отдельно (карта).
- Коллизия model/sku с другим товаром — не перезаписывать.

### §5.3 Медиа: качество + дедуп
- Размер (`min|middle|max`) фиксируем в служебной таблице рядом с file-id.
- Лучше доступный размер → перекачать/заменить; не лучше — не трогать. Галерея
  идемпотентна **по имени файла** (URL-подписи волатильны).
- **Дедуп по контент-ключу** `hash(path#size)` (path из base64-префикса `media_files`):
  таблица `*_oc_media` (content_key → путь файла); общие файлы при апгрейде НЕ удалять.

### §5.6 Не выдумывать; нормализация
- Цена в Wiki API отсутствует → товару не задаём (OpenCart требует поле `price` —
  оставляем `0`, не синтезируем). Подстановка — через фильтр/событие.
- Статус (`status`) и SEO-keyword — только при создании; при апдейте не затираем.
- boolean → «Нет» по умолчанию. Единицы: вес г → класс веса магазина, размеры мм →
  класс длины (универсальный конвертер Units + классы OpenCart `weight_class`/`length_class`).

### §6 Фоновая очередь + UX
- В OpenCart нет штатной job-очереди → **браузерный AJAX-степпер** (как в наших Bitrix/
  B2B-портах): режем выбор на порции по «шагу импорта», шлём последовательно на admin-
  контроллер с токеном сессии. Фолбэк — cron-контроллер.
- UX (§6 v1.2): индикатор прогресса (спиннер+бар), блокировка повторного запуска (пикер
  и поле ввода не дублируют импорт), сводка (создано/обновлено/ошибок), отмена между
  порциями, сохранение итога (`localStorage`).

### §2.4 Picker (по v1.2)
- iframe на `tools.onecatalog.net/picker.html`; **`parentOrigin = window.location.origin`
  (на клиенте)**; origin виджета — настройкой. Приём — по `event.source === iframe`,
  разбор JSON-строки `event.data`; своя кнопка × + Esc. Импорт по `productPublicIds`.

### §8 Точки расширения
- OpenCart **event system** (`oc_event`) + собственные события расширения:
  `before_import_product`, `product_imported`, `brand_imported`, `country_imported`,
  `collection_imported`, `queue_enqueued`. Сайт дозаполняет поля, не форкая расширение.

### §7 Настройки (через `oc_setting`)
- Токен/база Wiki API, язык, шаг импорта (≥10), статус новых, origin пикера.
- Справочные сущности (коллекции/бренд/страна/теги) — **по умолчанию выкл**, включаются
  после выбора цели (нативное/существующее прежде своего, §3). Бренд → manufacturer по
  умолчанию; теги → нативный tag.
- B2B (§13): url_key, private_key, стратегия цены, приоритеты регионов/поставщиков,
  склады → остаток, промо.

### §9 i18n
- Языковые файлы OpenCart `admin/language/en-gb` (исходные, английские) + `ru-ru`.

### §10 Жизненный цикл
- `install.xml` (OCMOD) — меню + события; при установке/первом заходе — создание
  служебных таблиц (`*_oc_map`, `*_oc_meta`, `*_oc_media`, очередь/лог). Удаление OCMOD
  — обратимо; служебные таблицы по желанию.

---

## §13 Синхронизация цен/остатков (предварительно)
- `B2bApi` — клиент B2B-фида (url_key в пути + private_key в query), пагинация,
  разведка справочников (regions/suppliers/warehouses).
- `PriceStockSync` по **scan-and-diff**: префетч `public_id → product_id + сигнатура`
  (из `*_oc_map` + `*_oc_meta`) одним запросом; пишем только изменившиеся.
  Цена → `product.price` (стратегия × приоритет регионов); остаток → `product.quantity`
  (сумма по складам; OpenCart — один склад по умолчанию, мультисклад — opt-in/событие).
  Промо → `product_special` (по датам) или поле — решить в ответах.
- Сигнатуры/коды — в `*_oc_meta` (не атрибуты товара). Событие `pricestock_updated`
  (сырые офферы) — для раскладки по регионам/складам сайтовым слоем.

---

## ❓ Вопросы (ответить ДО старта) — см. integration-answers.md
1. Хранение `public_id`: своя таблица (рекомендуется) vs `mpn`/доп-поле? 🟢
2. Характеристики: OpenCart-атрибуты — значение свободный текст на язык (нет термов);
   подтвердить, что find-or-create по имени группы/атрибута достаточно. 🟢
3. Категории: дерево через `category_path`; SEO-keyword только при создании. 🟢
4. Бренд → manufacturer (нет языковых вариантов имени) — ок? 🟢
5. Единицы: маппить на существующие weight/length-классы магазина или создавать? 🟡
6. Очередь: cron есть на целевом магазине или только AJAX-степпер? 🟡
7. Промо B2B → `product_special` (даты) или своё поле? 🟡
8. Мультисклад: OpenCart core — один склад; суммируем в `quantity` или ждём событие? 🟡
9. Версия/совместимость: строго 3.0.3.x; OCMOD vs vQmod (берём OCMOD). 🟢
10. Языки: en-gb + ru-ru; маппинг `lang` OneCatalog → language_id магазина. 🟡

## 🚫 Сложно / специфично для OpenCart
- Нет нативных product-custom-fields → служебные данные только своими таблицами.
- Атрибуты — текст на язык, без управляемого справочника термов (проще, но нет строгой
  типизации списков, как в WC global attributes / Bitrix enum).
- Мультисклад/мультицена по регионам — не нативны (core), требуют событий/доп-модулей.
