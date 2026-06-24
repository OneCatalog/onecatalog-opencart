<?php
/**
 * OneCatalog Import — медиа (OpenCart 3.0.3.x, §2.3, §5.3).
 *
 * Обложка → product.image, галерея → product_image. URL без расширения → скачиваем
 * вручную, MIME по содержимому. Дедуп по контент-ключу (таблица onecatalog_media):
 * один файл скачивается один раз и переиспользуется. Трекинг качества: лучший размер
 * перекачиваем; общие файлы НЕ удаляем (§5.3). Рекорды качества — в onecatalog_meta.
 */
class ModelExtensionOnecatalogMedia extends Model
{
    const SUBDIR = 'catalog/onecatalog/';

    public function applyMedia($productId, array $p)
    {
        require_once DIR_SYSTEM . 'library/onecatalog/media.php';
        require_once DIR_SYSTEM . 'library/onecatalog/api.php';

        $hasToken = (string) $this->config->get('module_onecatalog_api_token') !== '';

        // --- обложка ---
        $coverUrls = is_array($p['images_urls'] ?? null) ? $p['images_urls'] : array();
        if ($coverUrls) {
            $pick = OneCatalogMedia::pickSizeInfo($coverUrls, $hasToken);
            if ($pick['url'] !== '') {
                $recorded = (string) $this->metaGet($productId, 'cover_quality');
                $current = $this->productImage($productId);
                $upgrade = ($current === '' || OneCatalogMedia::sizeRank($recorded) < OneCatalogMedia::sizeRank($pick['size']));
                if ($upgrade) {
                    $file = $this->sideload($pick['url'], $pick['size']);
                    if ($file !== '') {
                        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET image = '" . $this->db->escape($file) . "' WHERE product_id = " . (int) $productId);
                        $this->metaSet($productId, 'cover_quality', $pick['size']);
                    }
                }
            }
        }

        // --- галерея ---
        $files = array();
        foreach (($p['files'] ?? array()) as $f) {
            if (!is_array($f)) {
                continue;
            }
            $cat = (string) ($f['category'] ?? '');
            if ($cat !== '' && $cat !== 'images') {
                continue;
            }
            $urls = is_array($f['urls'] ?? null) ? $f['urls'] : array();
            $pick = OneCatalogMedia::pickSizeInfo($urls, $hasToken);
            if ($pick['url'] === '') {
                continue;
            }
            $name = (string) ($f['name'] ?? OneCatalogMedia::fileKey($pick['url']) ?? $pick['url']);
            $files[] = array('name' => $name, 'url' => $pick['url'], 'size' => $pick['size']);
        }

        // Карта прошлого импорта: name → {file,size} (идемпотентность по имени + качество).
        $prev = json_decode((string) $this->metaGet($productId, 'gallery'), true);
        if (!is_array($prev)) {
            $prev = array();
        }

        $newMap = array();
        $entries = array();
        foreach ($files as $f) {
            $name = $f['name'];
            if (isset($prev[$name]) && is_array($prev[$name])
                && OneCatalogMedia::sizeRank((string) ($prev[$name]['size'] ?? '')) >= OneCatalogMedia::sizeRank($f['size'])) {
                // качество не лучше — переиспользуем уже загруженный файл (без скачивания).
                $newMap[$name] = $prev[$name];
                $entries[] = (string) $prev[$name]['file'];
                continue;
            }
            $file = $this->sideload($f['url'], $f['size']);
            if ($file !== '') {
                $newMap[$name] = array('file' => $file, 'size' => $f['size']);
                $entries[] = $file;
            }
        }

        // Перезаписываем product_image нашим набором (импорт владеет галереей).
        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_image` WHERE product_id = " . (int) $productId);
        $sort = 0;
        foreach ($entries as $file) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_image` SET "
                . "product_id = " . (int) $productId . ", image = '" . $this->db->escape($file) . "', sort_order = " . $sort);
            $sort++;
        }
        $this->metaSet($productId, 'gallery', json_encode($newMap));
    }

    /**
     * Скачать файл с дедупом по контент-ключу. Возвращает путь относительно image/
     * (как хранит OpenCart), '' при неудаче.
     */
    private function sideload($url, $size)
    {
        $key = OneCatalogMedia::fileKey($url);
        if ($key === '') {
            $key = sha1($url); // фолбэк, если из URL ключ не извлечь
        }

        // Уже скачан (тот же контент-ключ) → переиспользуем без скачивания (§5.3 дедуп).
        $row = $this->db->query("SELECT file FROM `" . DB_PREFIX . "onecatalog_media` WHERE content_key = '" . $this->db->escape($key) . "' LIMIT 1");
        if ($row->num_rows) {
            $existing = (string) $row->row['file'];
            if (is_file(DIR_IMAGE . $existing)) {
                return $existing;
            }
            // файл пропал — перекачаем и обновим запись.
            $this->db->query("DELETE FROM `" . DB_PREFIX . "onecatalog_media` WHERE content_key = '" . $this->db->escape($key) . "'");
        }

        require_once DIR_SYSTEM . 'library/onecatalog/api.php';
        require_once DIR_SYSTEM . 'library/onecatalog/media.php';
        $api = new OneCatalogApi(
            (string) $this->config->get('module_onecatalog_api_base'),
            (string) $this->config->get('module_onecatalog_api_token'),
            (string) ($this->config->get('module_onecatalog_lang') ?: 'en')
        );
        $bin = $api->getBinary($url);
        if ($bin === null) {
            return '';
        }

        $ext = OneCatalogMedia::mimeToExt($bin['content_type']);
        if ($ext === null && function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $ext = OneCatalogMedia::mimeToExt(finfo_buffer($fi, $bin['body']));
            finfo_close($fi);
        }
        if ($ext === null) {
            return ''; // неизвестный тип — не сохраняем
        }

        $dir = DIR_IMAGE . self::SUBDIR;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return '';
        }
        $rel = self::SUBDIR . $key . '.' . $ext;
        if (false === @file_put_contents(DIR_IMAGE . $rel, $bin['body'])) {
            return '';
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "onecatalog_media` SET "
            . "content_key = '" . $this->db->escape($key) . "', size = '" . $this->db->escape($size) . "', "
            . "file = '" . $this->db->escape($rel) . "', shared = 0, date_added = NOW()");

        return $rel;
    }

    private function productImage($productId)
    {
        $row = $this->db->query("SELECT image FROM `" . DB_PREFIX . "product` WHERE product_id = " . (int) $productId . " LIMIT 1");
        return ($row->num_rows && $row->row['image'] !== null) ? (string) $row->row['image'] : '';
    }

    private function metaGet($productId, $key)
    {
        $row = $this->db->query("SELECT value FROM `" . DB_PREFIX . "onecatalog_meta` WHERE product_id = " . (int) $productId . " AND meta_key = '" . $this->db->escape($key) . "' LIMIT 1");
        return $row->num_rows ? (string) $row->row['value'] : '';
    }

    private function metaSet($productId, $key, $value)
    {
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "onecatalog_meta` SET "
            . "product_id = " . (int) $productId . ", meta_key = '" . $this->db->escape($key) . "', value = '" . $this->db->escape($value) . "'");
    }
}
