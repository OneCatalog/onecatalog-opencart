<?php
/**
 * OneCatalog Wiki API — клиент (платформо-независимое ядро, §2.1).
 *
 * GET с заголовком X-API-Key, параметр lang ко всем запросам. Ответ — JSON
 * {success, data}. Сетевые/HTTP-ошибки → null (наверх не бросаем, §5.5).
 *
 * Плоский класс без зависимостей OpenCart — кандидат в общий Composer-core.
 */
class OneCatalogApi
{
    private $base;
    private $token;
    private $lang;
    public $lastStatus = 0;   // последний HTTP-код (0 = транспортная ошибка curl)
    public $lastError  = '';  // человекочитаемая причина последней неудачи

    public function __construct($base, $token = '', $lang = 'en')
    {
        $this->base = rtrim((string) $base, '/');
        $this->token = (string) $token;
        $this->lang = (string) ($lang ?: 'en');
    }

    public function base()
    {
        return $this->base;
    }

    /** GET → массив или null. lang добавляется автоматически. */
    public function get($url)
    {
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        $url .= $sep . 'lang=' . rawurlencode($this->lang);

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 40,
            CURLOPT_HTTPHEADER     => $this->token !== '' ? array('X-API-Key: ' . $this->token) : array(),
        ));
        $body = curl_exec($ch);
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            $this->lastError = 'network error: ' . $curlErr;
            return null;
        }
        if ($this->lastStatus < 200 || $this->lastStatus >= 300) {
            $this->lastError = 'HTTP ' . $this->lastStatus;
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            $this->lastError = 'invalid JSON response';
            return null;
        }
        $this->lastError = '';
        return $json;
    }

    /** Товар по public_id → payload (data) или null. */
    public function getProduct($publicId)
    {
        $publicId = trim((string) $publicId);
        if ($publicId === '') {
            $this->lastStatus = 0;
            $this->lastError = 'empty public_id';
            return null;
        }
        $r = $this->get($this->base . '/products/' . rawurlencode($publicId) . '/');
        if (is_array($r) && !empty($r['success']) && isset($r['data']) && is_array($r['data'])) {
            return $r['data'];
        }
        if (is_array($r)) {
            // транспорт ок (2xx, валидный JSON), но API не вернул товар
            $this->lastError = 'product not found in API';
        }
        return null;
    }

    /** Скачать бинарь (медиа) → ['body'=>..., 'content_type'=>...] или null (для §5.3). */
    public function getBinary($url)
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $this->token !== '' ? array('X-API-Key: ' . $this->token) : array(),
        ));
        $body = curl_exec($ch);
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $this->lastStatus < 200 || $this->lastStatus >= 300 || $body === '') {
            $this->lastError = ($body === false) ? ('network error: ' . $curlErr) : ('HTTP ' . $this->lastStatus);
            return null;
        }
        $this->lastError = '';
        return array('body' => $body, 'content_type' => $type);
    }
}
