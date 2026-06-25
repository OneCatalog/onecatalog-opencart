<?php
/**
 * OneCatalog Import — admin-контроллер (OpenCart 3.0.3.x).
 *
 * 0.1.0: страница настроек (хранение через model/setting), установка/удаление
 * служебных таблиц. Импорт/пикер/B2B — следующие инкременты.
 *
 * Стандарт интеграции v1.2 (см. onecatalog-standard). Маршрут: extension/module/onecatalog.
 */
class ControllerExtensionModuleOnecatalog extends Controller
{
    private $error = array();

    public function index()
    {
        $this->load->language('extension/module/onecatalog');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('module_onecatalog', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('extension/module/onecatalog', 'user_token=' . $this->session->data['user_token'], true));
        }

        // Подписи ошибок.
        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';

        // Сообщение об успехе.
        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        // Хлебные крошки.
        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true),
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true),
        );
        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/module/onecatalog', 'user_token=' . $this->session->data['user_token'], true),
        );

        $data['action'] = $this->url->link('extension/module/onecatalog', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

        // Значения полей (POST → сохранённая настройка → дефолт).
        $data['fields'] = array(
            'module_onecatalog_status'      => '0',
            'module_onecatalog_api_base'    => 'https://api.onecatalog.net/wiki/v1',
            'module_onecatalog_api_token'   => '',
            'module_onecatalog_lang'        => 'en',
            'module_onecatalog_step'        => '10',
            'module_onecatalog_new_status'  => '1',
            'module_onecatalog_picker_base' => 'https://tools.onecatalog.net',
            // Справочные сущности (§3/§7): по умолчанию ВЫКЛючены.
            'module_onecatalog_import_brand'        => '0',
            'module_onecatalog_import_tags'         => '0',
            'module_onecatalog_import_country'      => '0',
            'module_onecatalog_import_collections'  => '0',
            'module_onecatalog_collection_target'   => 'attribute',
        );
        foreach ($data['fields'] as $key => $default) {
            if (isset($this->request->post[$key])) {
                $data['fields'][$key] = $this->request->post[$key];
            } elseif (null !== $this->config->get($key)) {
                $data['fields'][$key] = $this->config->get($key);
            }
        }

        $data['import_url'] = $this->url->link('extension/module/onecatalog/importPage', 'user_token=' . $this->session->data['user_token'], true);

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/onecatalog', $data));
    }

    /** Операционная страница импорта (пикер + поле ввода + прогресс). */
    public function importPage()
    {
        $this->load->language('extension/module/onecatalog');
        $this->document->setTitle($this->language->get('heading_import'));

        $this->document->addScript('view/javascript/onecatalog/picker-loader.js');
        $this->document->addScript('view/javascript/onecatalog/admin-import.js');

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true));
        $data['breadcrumbs'][] = array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/module/onecatalog/importPage', 'user_token=' . $this->session->data['user_token'], true));

        $data['settings_url'] = $this->url->link('extension/module/onecatalog', 'user_token=' . $this->session->data['user_token'], true);
        $data['configured'] = (string) $this->config->get('module_onecatalog_api_token') !== '';

        // Конфиг для фронта (степпер + пикер). user_token — в ajaxUrl (требуется админкой).
        $cfg = array(
            'ajaxUrl'    => $this->url->link('extension/module/onecatalog/importBatch', 'user_token=' . $this->session->data['user_token'], true),
            'pickerBase' => (string) ($this->config->get('module_onecatalog_picker_base') ?: 'https://tools.onecatalog.net'),
            'token'      => (string) $this->config->get('module_onecatalog_api_token'),
            'step'       => max(10, (int) $this->config->get('module_onecatalog_step')),
            'messages'   => array(
                'empty'     => $this->language->get('js_empty'),
                'importing' => $this->language->get('js_importing'),
                'done'      => $this->language->get('js_done'),
                'error'     => $this->language->get('js_error'),
                'cancelled' => $this->language->get('js_cancelled'),
                'created'   => $this->language->get('js_created'),
                'updated'   => $this->language->get('js_updated'),
                'errors'    => $this->language->get('js_errors'),
                'last'      => $this->language->get('js_last'),
            ),
        );
        $data['oc_cfg_json'] = json_encode($cfg);

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/onecatalog_import', $data));
    }

    /** AJAX: импорт одной порции public_id → JSON {results, log}. */
    public function importBatch()
    {
        $this->load->language('extension/module/onecatalog');
        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/module/onecatalog')) {
            $json['error'] = $this->language->get('error_permission');
        } elseif ((string) $this->config->get('module_onecatalog_api_token') === '') {
            $json['error'] = $this->language->get('error_no_token');
        } else {
            $ids = isset($this->request->post['ids']) ? (array) $this->request->post['ids'] : array();
            $ids = array_values(array_filter(array_map('trim', $ids)));

            $this->load->model('extension/onecatalog/import');
            $results = array();
            foreach ($ids as $publicId) {
                try {
                    $r = $this->model_extension_onecatalog_import->importByPublicId($publicId);
                } catch (\Exception $e) {
                    $r = array('status' => 'error', 'public_id' => $publicId, 'message' => $e->getMessage());
                }
                $results[] = $r;
                $this->logResult($r);
            }
            $json['results'] = $results;
            $json['log'] = $this->recentLog();
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    private function logResult(array $r)
    {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "onecatalog_log` SET "
            . "public_id = '" . $this->db->escape((string) ($r['public_id'] ?? '')) . "', "
            . "status = '" . $this->db->escape((string) ($r['status'] ?? '')) . "', "
            . "message = '" . $this->db->escape((string) ($r['message'] ?? '')) . "', date_added = NOW()");
    }

    private function recentLog()
    {
        $rows = $this->db->query("SELECT public_id, status, message FROM `" . DB_PREFIX . "onecatalog_log` ORDER BY log_id DESC LIMIT 50");
        return array_reverse($rows->rows);
    }

    /** Страница «Цены и остатки» (B2B-синк): настройки + браузерный степпер запуска. */
    public function b2bPage()
    {
        $this->load->language('extension/module/onecatalog');
        $this->document->setTitle($this->language->get('heading_b2b'));
        $this->load->model('setting/setting');

        // B2B-настройки — ОТДЕЛЬНАЯ группа, чтобы не затирать основные настройки.
        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('module_onecatalog_b2b', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('extension/module/onecatalog/b2bPage', 'user_token=' . $this->session->data['user_token'], true));
        }

        $data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true));
        $data['breadcrumbs'][] = array('text' => $this->language->get('heading_b2b'), 'href' => $this->url->link('extension/module/onecatalog/b2bPage', 'user_token=' . $this->session->data['user_token'], true));

        $data['action'] = $this->url->link('extension/module/onecatalog/b2bPage', 'user_token=' . $this->session->data['user_token'], true);

        $data['fields'] = array(
            'module_onecatalog_b2b_base'              => 'https://api.onecatalog.net/b2b/v1',
            'module_onecatalog_b2b_url_key'           => '',
            'module_onecatalog_b2b_private_key'       => '',
            'module_onecatalog_b2b_strategy'          => 'min',
            'module_onecatalog_b2b_region_priority'   => '',
            'module_onecatalog_b2b_supplier_priority' => '',
            'module_onecatalog_b2b_supplier_fixed'    => '',
            'module_onecatalog_b2b_promo_as_sale'     => '1',
            'module_onecatalog_b2b_manage_stock'      => '1',
        );
        foreach ($data['fields'] as $key => $default) {
            if (isset($this->request->post[$key])) {
                $data['fields'][$key] = $this->request->post[$key];
            } elseif (null !== $this->config->get($key)) {
                $data['fields'][$key] = $this->config->get($key);
            }
        }

        $cfg = array(
            'syncUrl'  => $this->url->link('extension/module/onecatalog/b2bSync', 'user_token=' . $this->session->data['user_token'], true),
            'limit'    => 200,
            'messages' => array(
                'running'   => $this->language->get('js_b2b_running'),
                'done'      => $this->language->get('js_done'),
                'error'     => $this->language->get('js_error'),
                'changed'   => $this->language->get('js_b2b_changed'),
                'unchanged' => $this->language->get('js_b2b_unchanged'),
                'missing'   => $this->language->get('js_b2b_missing'),
            ),
        );
        $data['oc_b2b_cfg_json'] = json_encode($cfg);
        $data['configured'] = ((string) $this->config->get('module_onecatalog_b2b_url_key') !== '' && (string) $this->config->get('module_onecatalog_b2b_private_key') !== '');

        $this->document->addScript('view/javascript/onecatalog/b2b-sync.js');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/onecatalog_b2b', $data));
    }

    /** AJAX: обработать одну страницу B2B-фида (scan-and-diff) → JSON прогресс. */
    public function b2bSync()
    {
        $this->load->language('extension/module/onecatalog');
        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/module/onecatalog')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $start = (int) ($this->request->post['start'] ?? 0);
            $limit = max(1, (int) ($this->request->post['limit'] ?? 200));
            $this->load->model('extension/onecatalog/b2b');
            $json = $this->model_extension_onecatalog_b2b->processPage($start, $limit);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /** Журнал импорта — последние результаты (из onecatalog_log). */
    public function logPage()
    {
        $this->load->language('extension/module/onecatalog');
        $this->document->setTitle($this->language->get('heading_log'));

        $data['breadcrumbs'] = array();
        $data['breadcrumbs'][] = array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true));
        $data['breadcrumbs'][] = array('text' => $this->language->get('heading_log'), 'href' => $this->url->link('extension/module/onecatalog/logPage', 'user_token=' . $this->session->data['user_token'], true));

        $data['refresh'] = $this->url->link('extension/module/onecatalog/logPage', 'user_token=' . $this->session->data['user_token'], true);

        $rows = $this->db->query("SELECT public_id, status, message, date_added FROM `" . DB_PREFIX . "onecatalog_log` ORDER BY log_id DESC LIMIT 200");
        $data['entries'] = $rows->rows;

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/onecatalog_log', $data));
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/module/onecatalog')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        return !$this->error;
    }

    /** Вызывается при установке модуля (Расширения → Модули → установить). */
    public function install()
    {
        $this->load->model('extension/module/onecatalog');
        $this->model_extension_module_onecatalog->install();

        // Дать права доступа текущей группе пользователей (admin) к маршруту.
        $this->load->model('user/user_group');
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'extension/module/onecatalog');
        $this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', 'extension/module/onecatalog');
    }

    /** Вызывается при удалении модуля. Служебные таблицы по умолчанию НЕ удаляем. */
    public function uninstall()
    {
        $this->load->model('extension/module/onecatalog');
        $this->model_extension_module_onecatalog->uninstall();
    }
}
