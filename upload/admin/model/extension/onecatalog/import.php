<?php
/**
 * OneCatalog Import — ядро импорта одного товара (OpenCart 3.0.3.x, §5).
 *
 * Идемпотентность по public_id через onecatalog_map (не по model/sku, §5.1).
 * Прямой SQL по нативным таблицам (product/_description/_to_category/_attribute):
 * полный контроль, без деструктивного editProduct (который стирает секции).
 *
 * §5.6: цена и статус — только при создании, при переимпорте не затираются.
 * Габариты — Units → классы веса/длины магазина. Цена НЕ синтезируется (price=0).
 */
class ModelExtensionOnecatalogImport extends Model
{
    private $languages = null; // [ [language_id, code], ... ]

    /** Полный цикл: public_id → API → импорт. */
    public function importByPublicId($publicId)
    {
        $api = $this->api();
        $payload = $api->getProduct($publicId);
        if ($payload === null) {
            return array('status' => 'error', 'public_id' => $publicId, 'message' => 'product not found in API');
        }
        return $this->importPayload($payload, $publicId);
    }

    /** Импорт из готового payload → отчёт. */
    public function importPayload(array $p, $publicId = null)
    {
        $publicId = (string) ($publicId !== null ? $publicId : ($p['public_id'] ?? ''));
        if ($publicId === '') {
            return array('status' => 'error', 'public_id' => '', 'message' => 'empty public_id');
        }

        $name = trim((string) ($p['name'] ?? $p['title'] ?? $p['menutitle'] ?? ''));
        $description = (string) ($p['description_text'] ?? $p['description'] ?? '');
        $article = trim((string) ($p['article'] ?? ''));
        $dim = $this->resolveDimensions($p);

        $existing = $this->mapGet($publicId);

        try {
            if ($existing) {
                $productId = (int) $existing;
                $this->updateProduct($productId, $name, $description, $dim);
                $status = 'updated';
            } else {
                if ($name === '') {
                    return array('status' => 'error', 'public_id' => $publicId, 'message' => 'empty product name');
                }
                $productId = $this->createProduct($name, $description, $article, $dim);
                $this->mapSet($productId, $publicId);
                $status = 'created';
            }

            // Категории и характеристики — импорт владеет ими (replace).
            $attrs = $this->resolveAttributes($p);
            $cats = $this->resolveCategories($p);
            // Справочные сущности (бренд/страна/теги/коллекции) — §3/§7, по умолчанию выкл.
            $this->applyReferences($productId, $p, $attrs, $cats);
            $this->assignCategories($productId, $cats);
            $this->assignAttributes($productId, $attrs);

            // Медиа: обложка + галерея (дедуп + трекинг качества, §5.3).
            $this->load->model('extension/onecatalog/media');
            $this->model_extension_onecatalog_media->applyMedia($productId, $p);

            // Событие для сайтового слоя (§8): дозаполнение полей, не входящих в ядро.
            $this->trigger('onecatalog.product.imported', array(
                'product_id' => $productId, 'public_id' => $publicId, 'status' => $status, 'payload' => $p,
            ));

            return array('status' => $status, 'public_id' => $publicId, 'product_id' => $productId);
        } catch (\Exception $e) {
            return array('status' => 'error', 'public_id' => $publicId, 'message' => $e->getMessage());
        }
    }

    // --- создание / обновление товара ---------------------------------------

    private function createProduct($name, $description, $article, array $dim)
    {
        $newStatus = ((string) $this->config->get('module_onecatalog_new_status') === '0') ? 0 : 1;
        $stockStatus = (int) $this->config->get('config_stock_status_id');

        $this->db->query("INSERT INTO `" . DB_PREFIX . "product` SET "
            . "model = '" . $this->db->escape($article) . "', "
            . "sku = '', upc = '', ean = '', jan = '', isbn = '', mpn = '', location = '', "
            . "quantity = 0, minimum = 1, subtract = 1, stock_status_id = " . $stockStatus . ", "
            . "shipping = 1, price = 0, points = 0, tax_class_id = 0, "
            . "weight = " . (float) $dim['weight'] . ", weight_class_id = " . (int) $dim['weight_class_id'] . ", "
            . "length = " . (float) $dim['length'] . ", width = " . (float) $dim['width'] . ", height = " . (float) $dim['height'] . ", "
            . "length_class_id = " . (int) $dim['length_class_id'] . ", "
            . "manufacturer_id = 0, sort_order = 1, status = " . (int) $newStatus . ", "
            . "date_available = NOW(), date_added = NOW(), date_modified = NOW()");

        $productId = (int) $this->db->getLastId();

        // Описания (на все языки магазина — импорт одноязычный, §9 мультиязычность — бэклог).
        foreach ($this->langs() as $lang) {
            $this->writeDescription($productId, (int) $lang['language_id'], $name, $description);
        }

        // Привязка к складу по умолчанию (без неё товар не виден на витрине).
        $this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_store` SET product_id = " . $productId . ", store_id = 0");

        return $productId;
    }

    private function updateProduct($productId, $name, $description, array $dim)
    {
        // §5.6: status и price НЕ трогаем (только при создании). model (article) тоже
        // не клобберим — уважаем ручные правки. Обновляем габариты и контент.
        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET "
            . "weight = " . (float) $dim['weight'] . ", weight_class_id = " . (int) $dim['weight_class_id'] . ", "
            . "length = " . (float) $dim['length'] . ", width = " . (float) $dim['width'] . ", height = " . (float) $dim['height'] . ", "
            . "length_class_id = " . (int) $dim['length_class_id'] . ", date_modified = NOW() "
            . "WHERE product_id = " . (int) $productId);

        foreach ($this->langs() as $lang) {
            $this->writeDescription((int) $productId, (int) $lang['language_id'], $name, $description);
        }
    }

    private function writeDescription($productId, $languageId, $name, $description)
    {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_description` WHERE product_id = " . (int) $productId . " AND language_id = " . (int) $languageId);
        $this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET "
            . "product_id = " . (int) $productId . ", language_id = " . (int) $languageId . ", "
            . "name = '" . $this->db->escape($name) . "', "
            . "description = '" . $this->db->escape($description) . "', "
            . "tag = '', meta_title = '" . $this->db->escape($name) . "', "
            . "meta_description = '', meta_keyword = ''");
    }

    // --- категории (find-or-create + closure table) --------------------------

    /** @return int[] leaf category_ids */
    private function resolveCategories(array $p)
    {
        $cats = $p['categories'] ?? null;
        if (!is_array($cats) || !$cats) {
            return array();
        }
        $leaves = array();
        foreach ($cats as $cat) {
            if (!is_array($cat)) {
                continue;
            }
            $chain = $this->categoryChain($cat); // root..leaf
            $parentId = 0;
            $leafId = 0;
            foreach ($chain as $node) {
                $title = trim((string) ($node['menutitle'] ?? $node['name'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $leafId = $this->ensureCategory($title, $parentId);
                if (!$leafId) {
                    break;
                }
                $parentId = $leafId;
            }
            if ($leafId) {
                $leaves[$leafId] = $leafId;
            }
        }
        return array_values($leaves);
    }

    /** Цепочка root..leaf по встроенному parent_category (защита от циклов). */
    private function categoryChain(array $start)
    {
        $chain = array();
        $seen = array();
        $node = $start;
        while (is_array($node)) {
            $id = (int) ($node['id'] ?? 0);
            if ($id !== 0) {
                if (isset($seen[$id])) {
                    break;
                }
                $seen[$id] = true;
            }
            array_unshift($chain, $node);
            $parent = $node['parent_category'] ?? null;
            $node = is_array($parent) ? $parent : null;
        }
        return $chain;
    }

    private function ensureCategory($name, $parentId)
    {
        $row = $this->db->query("SELECT c.category_id FROM `" . DB_PREFIX . "category` c "
            . "JOIN `" . DB_PREFIX . "category_description` cd ON c.category_id = cd.category_id "
            . "WHERE c.parent_id = " . (int) $parentId . " AND LOWER(cd.name) = LOWER('" . $this->db->escape($name) . "') "
            . "LIMIT 1");
        if ($row->num_rows) {
            return (int) $row->row['category_id'];
        }

        $this->db->query("INSERT INTO `" . DB_PREFIX . "category` SET "
            . "parent_id = " . (int) $parentId . ", `top` = 0, `column` = 1, sort_order = 0, status = 1, "
            . "date_added = NOW(), date_modified = NOW()");
        $categoryId = (int) $this->db->getLastId();

        foreach ($this->langs() as $lang) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "category_description` SET "
                . "category_id = " . $categoryId . ", language_id = " . (int) $lang['language_id'] . ", "
                . "name = '" . $this->db->escape($name) . "', description = '', "
                . "meta_title = '" . $this->db->escape($name) . "', meta_description = '', meta_keyword = ''");
        }
        $this->db->query("INSERT INTO `" . DB_PREFIX . "category_to_store` SET category_id = " . $categoryId . ", store_id = 0");

        // Closure table (category_path): пути родителя + сам узел.
        $this->db->query("DELETE FROM `" . DB_PREFIX . "category_path` WHERE category_id = " . $categoryId);
        $level = 0;
        $paths = $this->db->query("SELECT path_id, level FROM `" . DB_PREFIX . "category_path` WHERE category_id = " . (int) $parentId . " ORDER BY level ASC");
        foreach ($paths->rows as $pp) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "category_path` SET category_id = " . $categoryId . ", path_id = " . (int) $pp['path_id'] . ", level = " . $level);
            $level++;
        }
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "category_path` SET category_id = " . $categoryId . ", path_id = " . $categoryId . ", level = " . $level);

        return $categoryId;
    }

    private function assignCategories($productId, array $categoryIds)
    {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_to_category` WHERE product_id = " . (int) $productId);
        $seen = array();
        foreach ($categoryIds as $cid) {
            $cid = (int) $cid;
            if ($cid <= 0 || isset($seen[$cid])) {
                continue;
            }
            $seen[$cid] = true;
            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_category` SET product_id = " . (int) $productId . ", category_id = " . $cid);
        }
    }

    // --- характеристики → атрибуты (find-or-create по имени, §5.1) -----------

    /** @return array[] [ ['attribute_id'=>int, 'text'=>string], ... ] */
    private function resolveAttributes(array $p)
    {
        $options = $p['options'] ?? null;
        if (!is_array($options) || !$options) {
            return array();
        }
        $groupId = $this->ensureAttributeGroup('OneCatalog');
        $out = array();
        foreach ($options as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            $label = trim((string) ($opt['specification_label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $type = (string) ($opt['specification_type'] ?? 'text');
            if ($type === 'numeric') {
                $num = $opt['numeric_option'] ?? null;
                $text = ($num === null || $num === '') ? '' : (string) $num;
            } elseif ($type === 'boolean') {
                $isTrue = (($opt['bool_option'] ?? null) === true); // null/false → false (§5.6)
                $text = $isTrue ? 'Yes' : 'No';
            } else {
                $text = trim((string) ($opt['specification_option_name'] ?? ''));
            }
            if ($text === '') {
                continue;
            }
            $attributeId = $this->ensureAttribute($label, $groupId);
            if ($attributeId) {
                $out[] = array('attribute_id' => $attributeId, 'text' => $text);
            }
        }
        return $out;
    }

    private function ensureAttributeGroup($name)
    {
        $row = $this->db->query("SELECT ag.attribute_group_id FROM `" . DB_PREFIX . "attribute_group` ag "
            . "JOIN `" . DB_PREFIX . "attribute_group_description` agd ON ag.attribute_group_id = agd.attribute_group_id "
            . "WHERE LOWER(agd.name) = LOWER('" . $this->db->escape($name) . "') LIMIT 1");
        if ($row->num_rows) {
            return (int) $row->row['attribute_group_id'];
        }
        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_group` SET sort_order = 0");
        $groupId = (int) $this->db->getLastId();
        foreach ($this->langs() as $lang) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_group_description` SET "
                . "attribute_group_id = " . $groupId . ", language_id = " . (int) $lang['language_id'] . ", "
                . "name = '" . $this->db->escape($name) . "'");
        }
        return $groupId;
    }

    private function ensureAttribute($name, $groupId)
    {
        $row = $this->db->query("SELECT a.attribute_id FROM `" . DB_PREFIX . "attribute` a "
            . "JOIN `" . DB_PREFIX . "attribute_description` ad ON a.attribute_id = ad.attribute_id "
            . "WHERE a.attribute_group_id = " . (int) $groupId . " AND LOWER(ad.name) = LOWER('" . $this->db->escape($name) . "') "
            . "LIMIT 1");
        if ($row->num_rows) {
            return (int) $row->row['attribute_id'];
        }
        $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute` SET attribute_group_id = " . (int) $groupId . ", sort_order = 0");
        $attributeId = (int) $this->db->getLastId();
        foreach ($this->langs() as $lang) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "attribute_description` SET "
                . "attribute_id = " . $attributeId . ", language_id = " . (int) $lang['language_id'] . ", "
                . "name = '" . $this->db->escape($name) . "'");
        }
        return $attributeId;
    }

    private function assignAttributes($productId, array $attrs)
    {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_attribute` WHERE product_id = " . (int) $productId);
        foreach ($attrs as $a) {
            foreach ($this->langs() as $lang) {
                $this->db->query("INSERT INTO `" . DB_PREFIX . "product_attribute` SET "
                    . "product_id = " . (int) $productId . ", attribute_id = " . (int) $a['attribute_id'] . ", "
                    . "language_id = " . (int) $lang['language_id'] . ", text = '" . $this->db->escape($a['text']) . "'");
            }
        }
    }

    // --- справочные сущности (§3/§7: нативное прежде своего, по умолчанию выкл) -

    /** Бренд → нативный manufacturer; теги → нативный tag; страна/коллекции → атрибут/категория. */
    private function applyReferences($productId, array $p, array &$attrs, array &$cats)
    {
        // Бренд → нативный manufacturer (find-or-create по имени).
        if ($this->enabled('import_brand')) {
            $brand = trim((string) ($p['brand']['menutitle'] ?? $p['brand']['name'] ?? ''));
            if ($brand !== '') {
                $mid = $this->ensureManufacturer($brand);
                if ($mid) {
                    $this->db->query("UPDATE `" . DB_PREFIX . "product` SET manufacturer_id = " . (int) $mid . " WHERE product_id = " . (int) $productId);
                }
            }
        }

        // Теги → нативное поле product_description.tag (на все языки).
        if ($this->enabled('import_tags') && is_array($p['tags'] ?? null)) {
            $names = array();
            foreach ($p['tags'] as $t) {
                $n = trim((string) (is_array($t) ? ($t['title'] ?? $t['name'] ?? '') : $t));
                if ($n !== '') {
                    $names[$n] = $n;
                }
            }
            if ($names) {
                $this->applyTags($productId, implode(',', array_values($names)));
            }
        }

        // Страна → атрибут «Country» (find-by-name переиспользует существующий).
        if ($this->enabled('import_country')) {
            $country = trim((string) ($p['country']['menutitle'] ?? $p['country']['name'] ?? ''));
            if ($country !== '') {
                $gid = $this->ensureAttributeGroup('OneCatalog');
                $aid = $this->ensureAttribute('Country', $gid);
                if ($aid) {
                    $attrs[] = array('attribute_id' => $aid, 'text' => $country);
                }
            }
        }

        // Коллекции → атрибут (по умолчанию) ИЛИ категории (выбор цели).
        if ($this->enabled('import_collections') && is_array($p['collections'] ?? null)) {
            $names = array();
            foreach ($p['collections'] as $c) {
                $n = trim((string) (is_array($c) ? ($c['menutitle'] ?? $c['name'] ?? '') : $c));
                if ($n !== '') {
                    $names[$n] = $n;
                }
            }
            if ($names) {
                $target = (string) ($this->config->get('module_onecatalog_collection_target') ?: 'attribute');
                if ($target === 'category') {
                    foreach ($names as $n) {
                        $cid = $this->ensureCategory($n, 0);
                        if ($cid) {
                            $cats[$cid] = $cid; // объединяем с категориями товара
                        }
                    }
                } else {
                    $gid = $this->ensureAttributeGroup('OneCatalog');
                    $aid = $this->ensureAttribute('Collection', $gid);
                    if ($aid) {
                        $attrs[] = array('attribute_id' => $aid, 'text' => implode(', ', array_values($names)));
                    }
                }
            }
        }
    }

    private function ensureManufacturer($name)
    {
        $row = $this->db->query("SELECT manufacturer_id FROM `" . DB_PREFIX . "manufacturer` WHERE LOWER(name) = LOWER('" . $this->db->escape($name) . "') LIMIT 1");
        if ($row->num_rows) {
            return (int) $row->row['manufacturer_id'];
        }
        $this->db->query("INSERT INTO `" . DB_PREFIX . "manufacturer` SET name = '" . $this->db->escape($name) . "', sort_order = 0");
        $mid = (int) $this->db->getLastId();
        $this->db->query("INSERT INTO `" . DB_PREFIX . "manufacturer_to_store` SET manufacturer_id = " . $mid . ", store_id = 0");
        return $mid;
    }

    private function applyTags($productId, $tagString)
    {
        foreach ($this->langs() as $lang) {
            $this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET tag = '" . $this->db->escape($tagString) . "' "
                . "WHERE product_id = " . (int) $productId . " AND language_id = " . (int) $lang['language_id']);
        }
    }

    private function enabled($key)
    {
        return (string) $this->config->get('module_onecatalog_' . $key) === '1';
    }

    /** Триггер события расширения (§8) — сайт подписывается через систему событий OpenCart. */
    private function trigger($event, array $args)
    {
        if ($this->registry->has('event')) {
            $this->registry->get('event')->trigger($event, array($args));
        }
    }

    // --- габариты (Units → классы магазина, §5.6) ----------------------------

    private function resolveDimensions(array $p)
    {
        $weightClassId = (int) $this->config->get('config_weight_class_id');
        $lengthClassId = (int) $this->config->get('config_length_class_id');
        $wUnit = $this->classUnit('weight', $weightClassId);
        $lUnit = $this->classUnit('length', $lengthClassId);

        $sizes = is_array($p['sizes'] ?? null) ? $p['sizes'] : array();

        // Вес: значение из payload; единица источника (sizes.weight_unit) иначе граммы.
        $weightGrams = $this->baseValue($sizes, array('weight'), 'weight_unit', 'weight');
        $weight = $weightGrams === null ? 0.0 : OneCatalogUnits::weight($weightGrams, $wUnit);

        // Размеры: мм → единица класса длины.
        $lMm = $this->baseValue($sizes, array('length'), 'length_unit', 'length');
        $wMm = $this->baseValue($sizes, array('width'), 'length_unit', 'length');
        $hMm = $this->baseValue($sizes, array('height', 'thickness'), 'length_unit', 'length');

        return array(
            'weight' => $weight,
            'weight_class_id' => $weightClassId,
            'length' => $lMm === null ? 0.0 : OneCatalogUnits::length($lMm, $lUnit),
            'width' => $wMm === null ? 0.0 : OneCatalogUnits::length($wMm, $lUnit),
            'height' => $hMm === null ? 0.0 : OneCatalogUnits::length($hMm, $lUnit),
            'length_class_id' => $lengthClassId,
        );
    }

    /** Значение из sizes по первому подходящему ключу, приведённое к базе (г/мм). */
    private function baseValue(array $sizes, array $keys, $unitKey, $kind)
    {
        foreach ($keys as $k) {
            if (isset($sizes[$k]) && $sizes[$k] !== '' && is_numeric($sizes[$k])) {
                $val = (float) $sizes[$k];
                $unit = (string) ($sizes[$unitKey] ?? '');
                if ($unit !== '') {
                    return $kind === 'weight'
                        ? OneCatalogUnits::toBaseWeight($val, $unit)
                        : OneCatalogUnits::toBaseLength($val, $unit);
                }
                return $val; // уже в базовой единице (г/мм)
            }
        }
        return null;
    }

    private function classUnit($kind, $classId)
    {
        $table = $kind === 'weight' ? 'weight_class_description' : 'length_class_description';
        $col = $kind === 'weight' ? 'weight_class_id' : 'length_class_id';
        $row = $this->db->query("SELECT unit FROM `" . DB_PREFIX . $table . "` WHERE " . $col . " = " . (int) $classId . " LIMIT 1");
        return $row->num_rows ? (string) $row->row['unit'] : '';
    }

    // --- инфраструктура ------------------------------------------------------

    private function mapGet($publicId)
    {
        $row = $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "onecatalog_map` WHERE public_id = '" . $this->db->escape($publicId) . "' LIMIT 1");
        return $row->num_rows ? (int) $row->row['product_id'] : null;
    }

    private function mapSet($productId, $publicId)
    {
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "onecatalog_map` SET product_id = " . (int) $productId . ", public_id = '" . $this->db->escape($publicId) . "'");
    }

    private function langs()
    {
        if ($this->languages === null) {
            $this->languages = array();
            $rows = $this->db->query("SELECT language_id, code FROM `" . DB_PREFIX . "language` WHERE status = 1");
            foreach ($rows->rows as $r) {
                $this->languages[] = array('language_id' => (int) $r['language_id'], 'code' => (string) $r['code']);
            }
            if (!$this->languages) { // фолбэк: язык по умолчанию
                $this->languages[] = array('language_id' => (int) $this->config->get('config_language_id'), 'code' => 'en-gb');
            }
        }
        return $this->languages;
    }

    private function api()
    {
        require_once DIR_SYSTEM . 'library/onecatalog/api.php';
        require_once DIR_SYSTEM . 'library/onecatalog/units.php';
        return new OneCatalogApi(
            (string) $this->config->get('module_onecatalog_api_base'),
            (string) $this->config->get('module_onecatalog_api_token'),
            (string) ($this->config->get('module_onecatalog_lang') ?: 'en')
        );
    }
}
