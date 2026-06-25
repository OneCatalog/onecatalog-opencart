<?php
/**
 * OneCatalog — синхронизация цен/остатков из B2B-фида (§13, OpenCart 3.0.3.x).
 *
 * scan-and-diff (§13.4): префетч public_id→product_id (onecatalog_map) и сигнатур
 * (onecatalog_meta) одним запросом; резолв ЧИСТЫМИ резолверами в памяти; пишем ТОЛЬКО
 * изменившиеся. Цена → product.price; скидка → product_special; остаток →
 * product.quantity (+ subtract). Цена/остаток — живой фид (перетирает синкаемые товары).
 */
class ModelExtensionOnecatalogB2b extends Model
{
    /** Обработать одну страницу фида → прогресс. */
    public function processPage($start = 0, $limit = 200)
    {
        require_once DIR_SYSTEM . 'library/onecatalog/b2bapi.php';
        require_once DIR_SYSTEM . 'library/onecatalog/pricestock.php';

        $api = new OneCatalogB2bApi(
            (string) ($this->config->get('module_onecatalog_b2b_base') ?: 'https://api.onecatalog.net/b2b/v1'),
            (string) $this->config->get('module_onecatalog_b2b_url_key'),
            (string) $this->config->get('module_onecatalog_b2b_private_key')
        );
        if (!$api->configured()) {
            return array('error' => 'b2b not configured');
        }

        $data = $api->fetchPage($start, $limit);
        if (!is_array($data)) {
            return array('error' => 'feed fetch failed', 'next' => $start, 'more' => false);
        }

        $total = (int) ($data['meta']['counts'] ?? $data['meta']['total'] ?? 0);
        $known = (array) ($data['products']['known'] ?? array());

        $cfg = $this->cfg();
        $ids = array_keys($known);
        $map = $this->mapPublicIds($ids);                 // public_id → product_id
        $sigs = $this->sigPrefetch(array_values($map));   // product_id → сигнатура

        $scanned = 0;
        $changed = 0;
        $unchanged = 0;
        $missing = 0;

        foreach ($known as $publicId => $offers) {
            $publicId = (string) $publicId;
            $offers = (array) $offers;
            $scanned++;

            if (!isset($map[$publicId])) {
                $missing++; // нет в каталоге — импорт отдельным механизмом
                continue;
            }
            $productId = (int) $map[$publicId];
            $rec = OneCatalogPriceStock::resolveRecord($offers, $cfg);

            if (isset($sigs[$productId]) && $sigs[$productId] === $rec['sig']) {
                $unchanged++;
                continue;
            }
            $this->applyResolved($productId, $rec, $offers);
            $changed++;
        }

        $next = $start + $limit;
        $more = ($scanned > 0) && ($total > 0 ? $next < $total : $scanned >= $limit);

        return array(
            'scanned' => $scanned, 'changed' => $changed, 'unchanged' => $unchanged,
            'missing' => $missing, 'total' => $total, 'next' => $next, 'more' => $more,
        );
    }

    /** Запись резолва в товар (цена/скидка/остаток) + сигнатура/коды в meta. */
    private function applyResolved($productId, array $rec, array $offers)
    {
        require_once DIR_SYSTEM . 'library/onecatalog/pricestock.php';

        $sets = array('date_modified = NOW()');
        if ($rec['regular'] !== null) {
            $sets[] = "price = " . (float) $rec['regular'];
        }
        if (!empty($rec['manage'])) {
            $sets[] = "quantity = " . (int) round((float) $rec['qty']);
            $sets[] = "subtract = 1";
        }
        $this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . implode(', ', $sets) . " WHERE product_id = " . (int) $productId);

        // Скидка → product_special (для группы покупателей по умолчанию). Перезаписываем.
        $this->db->query("DELETE FROM `" . DB_PREFIX . "product_special` WHERE product_id = " . (int) $productId);
        if ($rec['sale'] !== null) {
            $groupId = (int) $this->config->get('config_customer_group_id');
            $this->db->query("INSERT INTO `" . DB_PREFIX . "product_special` SET "
                . "product_id = " . (int) $productId . ", customer_group_id = " . $groupId . ", "
                . "priority = 1, price = " . (float) $rec['sale'] . ", date_start = '0000-00-00', date_end = '0000-00-00'");
        }

        // Сигнатура + коды поставщиков — служебные, в meta (не поля товара, §5.1).
        $this->metaSet($productId, 'pricestock_sig', (string) $rec['sig']);
        $codes = OneCatalogPriceStock::extractCodes($offers);
        $flat = array();
        foreach ($codes as $c) {
            $flat[] = $c['supplier_id'] . ':' . $c['code'];
        }
        $this->metaSet($productId, 'supplier_code', implode(',', $flat));
    }

    private function cfg()
    {
        return array(
            'region_prio' => $this->csvInts($this->config->get('module_onecatalog_b2b_region_priority')),
            'supplier_prio' => $this->csvInts($this->config->get('module_onecatalog_b2b_supplier_priority')),
            'strategy' => (string) ($this->config->get('module_onecatalog_b2b_strategy') ?: 'min'),
            'supplier_fix' => (int) $this->config->get('module_onecatalog_b2b_supplier_fixed'),
            'promo_as_sale' => (string) $this->config->get('module_onecatalog_b2b_promo_as_sale') !== '0',
            'manage_stock' => (string) $this->config->get('module_onecatalog_b2b_manage_stock') === '1',
            'decimal_stock' => false, // OpenCart quantity целочисленный
        );
    }

    private function csvInts($s)
    {
        $out = array();
        foreach (explode(',', (string) $s) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $out[] = (int) $part;
            }
        }
        return $out;
    }

    /** public_id → product_id одним запросом. */
    private function mapPublicIds(array $publicIds)
    {
        $publicIds = array_values(array_filter(array_map('strval', $publicIds)));
        if (!$publicIds) {
            return array();
        }
        $in = array();
        foreach ($publicIds as $pid) {
            $in[] = "'" . $this->db->escape($pid) . "'";
        }
        $rows = $this->db->query("SELECT product_id, public_id FROM `" . DB_PREFIX . "onecatalog_map` WHERE public_id IN (" . implode(',', $in) . ")");
        $map = array();
        foreach ($rows->rows as $r) {
            $map[(string) $r['public_id']] = (int) $r['product_id'];
        }
        return $map;
    }

    /** product_id → сигнатура цены/остатка одним запросом. */
    private function sigPrefetch(array $productIds)
    {
        $ids = array_values(array_filter(array_map('intval', $productIds)));
        if (!$ids) {
            return array();
        }
        $rows = $this->db->query("SELECT product_id, value FROM `" . DB_PREFIX . "onecatalog_meta` "
            . "WHERE meta_key = 'pricestock_sig' AND product_id IN (" . implode(',', $ids) . ")");
        $out = array();
        foreach ($rows->rows as $r) {
            $out[(int) $r['product_id']] = (string) $r['value'];
        }
        return $out;
    }

    private function metaSet($productId, $key, $value)
    {
        $this->db->query("REPLACE INTO `" . DB_PREFIX . "onecatalog_meta` SET "
            . "product_id = " . (int) $productId . ", meta_key = '" . $this->db->escape($key) . "', value = '" . $this->db->escape($value) . "'");
    }
}
