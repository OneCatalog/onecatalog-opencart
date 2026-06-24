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
        );
        foreach ($data['fields'] as $key => $default) {
            if (isset($this->request->post[$key])) {
                $data['fields'][$key] = $this->request->post[$key];
            } elseif (null !== $this->config->get($key)) {
                $data['fields'][$key] = $this->config->get($key);
            }
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/onecatalog', $data));
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
