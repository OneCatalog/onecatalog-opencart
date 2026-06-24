<?php
/**
 * OneCatalog Import — модель установки (OpenCart 3.0.3.x).
 *
 * Служебные таблицы (стандарт §5.1 v1.2 — служебные значения вне формы товара):
 *   onecatalog_map   — идемпотентность: public_id ↔ product_id (не model/sku/mpn);
 *   onecatalog_meta  — служебные key-value по товару (сигнатуры идемпотентности,
 *                      коды поставщиков) — НЕ атрибуты товара;
 *   onecatalog_media — дедуп медиа по контент-ключу (§5.3);
 *   onecatalog_queue — порции AJAX-степпера импорта (§6);
 *   onecatalog_log   — последние результаты импорта (прогресс/диагностика, §5.5).
 */
class ModelExtensionModuleOnecatalog extends Model
{
    public function install()
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "onecatalog_map` (
            `product_id` INT(11) NOT NULL,
            `public_id` VARCHAR(64) NOT NULL,
            PRIMARY KEY (`product_id`),
            UNIQUE KEY `uq_oc_public_id` (`public_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "onecatalog_meta` (
            `meta_id` INT(11) NOT NULL AUTO_INCREMENT,
            `product_id` INT(11) NOT NULL,
            `meta_key` VARCHAR(50) NOT NULL,
            `value` LONGTEXT NULL,
            PRIMARY KEY (`meta_id`),
            UNIQUE KEY `uq_oc_meta` (`product_id`, `meta_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "onecatalog_media` (
            `media_id` INT(11) NOT NULL AUTO_INCREMENT,
            `content_key` VARCHAR(64) NOT NULL,
            `size` VARCHAR(8) NOT NULL DEFAULT 'min',
            `file` VARCHAR(255) NOT NULL,
            `shared` TINYINT(1) NOT NULL DEFAULT '0',
            `date_added` DATETIME NOT NULL,
            PRIMARY KEY (`media_id`),
            UNIQUE KEY `uq_oc_media_key` (`content_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "onecatalog_queue` (
            `queue_id` INT(11) NOT NULL AUTO_INCREMENT,
            `batch` INT(11) NOT NULL DEFAULT '0',
            `public_id` VARCHAR(64) NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
            `message` TEXT NULL,
            `date_added` DATETIME NOT NULL,
            `date_modified` DATETIME NULL,
            PRIMARY KEY (`queue_id`),
            KEY `ix_oc_queue_status` (`status`),
            KEY `ix_oc_queue_public` (`public_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");

        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "onecatalog_log` (
            `log_id` INT(11) NOT NULL AUTO_INCREMENT,
            `public_id` VARCHAR(64) NOT NULL,
            `status` VARCHAR(16) NOT NULL,
            `message` TEXT NULL,
            `date_added` DATETIME NOT NULL,
            PRIMARY KEY (`log_id`),
            KEY `ix_oc_log_date` (`date_added`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;");
    }

    /**
     * Удаление модуля. Служебные таблицы (особенно onecatalog_map) по умолчанию
     * НЕ удаляем — иначе при переустановке потеряется идемпотентность и товары
     * задвоятся. Полная очистка — отдельным действием (в настройках, позже).
     */
    public function uninstall()
    {
        // Намеренно пусто: сохраняем карту public_id ↔ product_id.
    }
}
