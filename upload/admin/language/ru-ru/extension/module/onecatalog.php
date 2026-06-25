<?php
// Heading
$_['heading_title']      = 'OneCatalog Импорт';
$_['heading_import']     = 'OneCatalog — импорт товаров';

// Text
$_['text_home']          = 'Главная';
$_['text_extension']     = 'Расширения';
$_['text_success']       = 'Готово: настройки OneCatalog сохранены!';
$_['text_edit']          = 'OneCatalog — настройки';
$_['text_enabled']       = 'Включено';
$_['text_disabled']      = 'Выключено';
$_['text_no_token']      = 'API-токен не задан. Откройте «Настройки» и внесите токен перед импортом.';

// Buttons
$_['button_save']        = 'Сохранить';
$_['button_cancel']      = 'Назад';
$_['button_settings']    = 'Настройки';
$_['button_import']      = 'Импорт';
$_['button_pick']        = 'Выбрать товары (OneCatalog)';
$_['button_cancel_run']  = 'Отмена';

// Import page
$_['entry_or_paste']     = 'или вставьте список идентификаторов (public_id) через запятую/пробел/перенос строки:';

// JS (степпер)
$_['js_empty']           = 'Список идентификаторов пуст';
$_['js_importing']       = 'Импорт…';
$_['js_done']            = 'Готово:';
$_['js_error']           = 'Ошибка';
$_['js_cancelled']       = 'Отменено:';
$_['js_created']         = 'Создано';
$_['js_updated']         = 'Обновлено';
$_['js_errors']          = 'Ошибок';
$_['js_last']            = 'Последний результат';

// Entry
$_['entry_status']       = 'Статус';
$_['entry_api_base']     = 'Базовый URL Wiki API';
$_['entry_api_token']    = 'API-токен';
$_['entry_lang']         = 'Язык товаров';
$_['entry_step']         = 'Шаг импорта (размер порции)';
$_['entry_new_status']   = 'Статус новых товаров';
$_['entry_picker_base']  = 'Базовый URL пикера';

// Help
$_['help_api_token']     = 'X-API-Key для запросов Wiki API и виджета выбора.';
$_['help_lang']          = 'Код языка из API (например en, ru). Сопоставляется с языком магазина.';
$_['help_step']          = 'Сколько товаров в одной порции импорта (минимум 10).';
$_['help_new_status']    = 'Статус задаётся только новым товарам; при переимпорте не затирается.';
$_['help_picker_base']   = 'Origin виджета выбора товаров (по умолчанию https://tools.onecatalog.net).';

// Справочные сущности (§3/§7)
$_['help_references']       = 'Справочные сущности по умолчанию выключены — включайте осознанно. Приоритет — нативные поля платформы, а не создание своих.';
$_['entry_import_brand']    = 'Импортировать бренд';
$_['entry_import_tags']     = 'Импортировать теги';
$_['entry_import_country']  = 'Импортировать страну';
$_['entry_import_collections'] = 'Импортировать коллекции';
$_['entry_collection_target']  = '↳ Цель для коллекций';
$_['help_brand_native']     = '→ нативный «Производитель» (Manufacturer)';
$_['help_tags_native']      = '→ нативное поле тегов товара';
$_['help_country_attr']     = '→ атрибут «Country»';
$_['text_target_attribute'] = 'Атрибут';
$_['text_target_category']  = 'Категория';

// Журнал импорта
$_['heading_log']        = 'OneCatalog — журнал импорта';
$_['button_refresh']     = 'Обновить';
$_['column_time']        = 'Время';
$_['column_key']         = 'public_id';
$_['column_status']      = 'Статус';
$_['column_message']     = 'Сообщение';
$_['text_no_entries']    = 'Записей пока нет.';
$_['text_log_hint']      = 'Последние 200 результатов импорта (создан / обновлён / ошибка).';

// B2B — цены и остатки (§13)
$_['heading_b2b']             = 'OneCatalog — цены и остатки (B2B)';
$_['heading_b2b_run']         = 'Запуск синхронизации';
$_['entry_b2b_base']          = 'Базовый URL B2B API';
$_['entry_b2b_url_key']       = 'Ключ ритейлера (url_key)';
$_['entry_b2b_private_key']   = 'Приватный ключ';
$_['entry_b2b_strategy']      = 'Стратегия цены';
$_['entry_b2b_region_priority']   = 'Приоритет регионов';
$_['entry_b2b_supplier_priority'] = 'Приоритет поставщиков';
$_['entry_b2b_supplier_fixed']    = 'Фиксированный поставщик (id)';
$_['entry_b2b_promo']         = 'Промо как скидку';
$_['entry_b2b_manage_stock']  = 'Управлять остатком';
$_['text_strategy_min']       = 'Минимальная цена';
$_['text_strategy_priority']  = 'По приоритету поставщиков';
$_['text_strategy_supplier']  = 'Фиксированный поставщик';
$_['help_b2b_csv']            = 'Id через запятую, сначала наивысший приоритет.';
$_['help_b2b_supplier_fixed'] = 'Используется только со стратегией «Фиксированный поставщик».';
$_['help_b2b_promo']          = 'Писать promo_price (0 < promo < base) как спец-цену товара.';
$_['help_b2b_manage_stock']   = 'Писать суммарный остаток по складам в количество товара (subtract вкл).';
$_['help_b2b_run']            = 'Идёт постранично в браузере (cron не нужен). Пишутся только изменившиеся товары (scan-and-diff).';
$_['button_b2b_run']          = 'Синхронизировать сейчас';
$_['text_b2b_not_configured'] = 'B2B-ключи не заданы — заполните url_key и private_key и сохраните.';
$_['js_b2b_running']          = 'Синхронизация…';
$_['js_b2b_changed']          = 'Изменено';
$_['js_b2b_unchanged']        = 'Без изменений';
$_['js_b2b_missing']          = 'Нет в каталоге';

// Error
$_['error_permission']   = 'Внимание: у вас нет прав на изменение OneCatalog Импорт!';
$_['error_no_token']     = 'API-токен не задан (Настройки).';
