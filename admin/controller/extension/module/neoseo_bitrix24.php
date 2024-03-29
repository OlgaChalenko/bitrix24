<?php

require_once( DIR_SYSTEM . '/engine/neoseo_controller.php');
require_once( DIR_SYSTEM . '/engine/neoseo_view.php' );
require_once( DIR_SYSTEM . 'library/bitrix24/bitrix24_crest.php');

class ControllerExtensionModuleNeoSeoBitrix24 extends NeoSeoController
{

	private $error = array();

	public function __construct($registry)
	{
		parent::__construct($registry);
		$this->_moduleSysName = "neoseo_bitrix24";
		$this->_module_code = "neoseo_bitrix24";
		$this->_modulePostfix = ""; // Постфикс для разных типов модуля, поэтому переходим на использование $this->_moduleSysName()
		$this->_logFile = $this->_moduleSysName() . ".log";
		$this->debug = $this->config->get($this->_moduleSysName() . "_debug") == 1;
	}

	public function index()
	{
		$this->upgrade();

		$data = $this->load->language('extension/' . $this->_route . '/' . $this->_moduleSysName());

		$this->document->setTitle($this->language->get('heading_title_raw'));

		$this->load->model('setting/setting');
		$this->load->model('tool/' . $this->_moduleSysName());

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && ($this->validate())) {

			$this->{'model_tool_' . $this->_moduleSysName()}->addGroup2Contact($this->request->post);
			unset($this->request->post['group_to_contact']);

			$this->{'model_tool_' . $this->_moduleSysName()}->addOrderStatus2DealStage($this->request->post);
			unset($this->request->post['order_status_to_deal_stage']);

			$this->{'model_tool_' . $this->_moduleSysName()}->addCategory2DealType($this->request->post);
			unset($this->request->post['category_to_deal_type']);

			$this->model_setting_setting->editSetting($this->_moduleSysName(), $this->request->post);

			//Это нужно чтобы при нажатии кнопки "сохранить и закрыть" был правильный статус
			$this->{"model_extension_" . $this->_route . "_" . $this->_moduleSysName()}->setModuleStatus($this->request->post[$this->_moduleSysName() . "_status"]);

			$this->session->data['success'] = $this->language->get('text_success');

			if ($this->request->post['action'] == "save") {
				$this->response->redirect($this->url->link('extension/' . $this->_route . '/' . $this->_moduleSysName(), 'user_token=' . $this->session->data['user_token'], true));
			} else {
				$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'], true));
			}
		}

		$data = $this->initBreadcrumbs(array(
			array('marketplace/extension', 'text_module'),
			array('extension/' . $this->_route . '/' . $this->_moduleSysName(), "heading_title_raw")
				), $data);


		$data = $this->initButtons($data);

		$this->load->model("extension/" . $this->_route . "/" . $this->_moduleSysName());
		$data = $this->initParamsListEx($this->{"model_extension_" . $this->_route . "_" . $this->_moduleSysName()}->getParams(), $data);

		$this->checkParams();

		if (isset($this->error['warning'])) {
			$data['error_warning'] = $this->error['warning'];
		} else if (isset($this->session->data['error_warning'])) {
			$data['error_warning'] = $this->session->data['error_warning'];
			unset($this->session->data['error_warning']);
		} else {
			$data['error_warning'] = '';
		}
		if (isset($this->session->data['success'])) {
			$data['success'] = $this->session->data['success'];
			unset($this->session->data['success']);
		}

		$data['group_to_contact'] = array();
		$data['group_to_contact'] = $this->{'model_tool_' . $this->_moduleSysName()}->getGroup2Contact();

		$data['order_status_to_deal_stage'] = array();
		$data['order_status_to_deal_stage'] = $this->{'model_tool_' . $this->_moduleSysName()}->getOrderStatus2DealStage();

		$data['category_to_deal_type'] = array();
		$data['category_to_deal_type'] = $this->{'model_tool_' . $this->_moduleSysName()}->getCategory2DealType();

		$data['sources'] = $this->getEntity('SOURCE'); //Получаем список источников
		$data['users'] = $this->getUsers(); // Получаем список пользователей
		$data['contact_types'] = $this->getEntity('CONTACT_TYPE'); // Получаем типы контактов
		$deal_stages_list = array('DEAL_STAGE');
		$deal_stages_list = array_merge($deal_stages_list, $this->getEntityTypesList()); // получаем все списки стадий
		$deal_stages = array();
		foreach ($deal_stages_list as $deal_stage_row) {
			$deal_stages = array_merge($deal_stages, $this->getEntityStage($deal_stage_row)); // Получаем стадии сделок
		}
		$data['deal_stage'] = $deal_stages;
		$data['deal_types'] = $this->getEntity('DEAL_TYPE'); // Получаем типы сделок

		$data['customer_group'] = array();
		$this->load->model('customer/customer_group');
		$customer_group = $this->model_customer_customer_group->getCustomerGroups();
		foreach ($customer_group as $item) {
			$data['customer_group'][$item['customer_group_id']] = $item['name'];
		}

		$data['order_statuses'] = array();
		$this->load->model('localisation/order_status');
		$order_statuses = $this->model_localisation_order_status->getOrderStatuses();
		foreach ($order_statuses as $item) {
			$data['order_statuses'][$item['order_status_id']] = $item['name'];
		}

		$data['categories'] = array();
		$this->load->model('catalog/category');
		$order_statuses = $this->model_catalog_category->getCategories();
		foreach ($order_statuses as $item) {
			$data['categories'][$item['category_id']] = $item['name'];
		}

		$data['options'] = array();
		$this->load->model('catalog/option');
		$options = $this->model_catalog_option->getOptions();
		foreach ($options as $item) {
			$data['options'][$item['option_id']] = $item['name'];
		}
		$data['option_values'] = array();
		if ($data['options']) {
			foreach ($data['options'] as $option_id => $name) {
				$option_values = $this->model_catalog_option->getOptionValues($option_id);
				foreach ($option_values as $item) {
					$data['option_values'][$item['option_value_id']] = $item['name'] . ' (' . $name . ')';
				}
			}
		}

		$data['domains'] = array(
			'bitrix24.ua' => 'bitrix24.ua',
			'bitrix24.ru' => 'bitrix24.ru',
			'bitrix24.com' => 'bitrix24.com',
			'bitrix24.kz' => 'bitrix24.kz',
			'bitrix24.by' => 'bitrix24.by',
			'ua' => 'ua',
			'com.ua' => 'com.ua',
			'ru' => 'ru',
			'kz' => 'kz',
			'by' => 'by',
			'org' => 'org',
			'com' => 'com',
			'store' => 'store',
		);

		$data["user_token"] = $this->session->data['user_token'];
		$data['config_language_id'] = $this->config->get('config_language_id');
		$data['params'] = $data;

		$data["logs"] = $this->getLogs();

		$widgets = new NeoSeoWidgets($this->_moduleSysName() . '_', $data);
		$widgets->text_select_all = $this->language->get('text_select_all');
		$widgets->text_unselect_all = $this->language->get('text_unselect_all');
		$data['widgets'] = $widgets;

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/' . $this->_route . '/' . $this->_moduleSysName(), $data));
	}

	private function getUrl()
	{
		$portal_name = $this->config->get($this->_moduleSysName() . '_portal_name');
		$id_user = $this->config->get($this->_moduleSysName() . '_id_user');
		$secret_code = $this->config->get($this->_moduleSysName() . '_secret_code');

		if (!trim($portal_name)) {
			$this->log('Название портала не введено в настройках модуля!');
			return false;
		}
		if (!trim($id_user)) {
			$this->log('ИД пользователя не введено в настройках модуля!');
			return false;
		}
		if (!trim($secret_code)) {
			$this->log('Секретный код не введен в настройках модуля!');
			return false;
		}

		$domain = $this->config->get($this->_moduleSysName() . '_domain');
		$domain = $domain ? $domain : 'bitrix24.ua';
		$url = 'https://' . $portal_name . '.' . $domain . '/rest/' . $id_user . '/' . $secret_code . '/';

		return $url;
	}

	private function getEntity($type)
	{
		$sources = array();

		$params = array(
			'method' => 'crm.status.entity.items',
			'params' => array(
				'entityId' => $type,
			)
		);
		$request = $this->sendRequest($params);

		if (isset($request['result'])) {
			foreach ($request['result'] as $item) {
				$sources[$item['STATUS_ID']] = $item['NAME'];
			}
		}

		return $sources;
	}

	private function getEntityStage($type)
	{
		$sources = array();

		$params = array(
			'method' => 'crm.status.entity.items',
			'params' => array(
				'entityId' => $type,
			)
		);
		$request = $this->sendRequest($params);

		$params_status_list = array(
			'method' => 'crm.status.list'
		);
		$status_lists = array();
		$request_status_list = $this->sendRequest($params_status_list);
		if (isset($request_status_list['result'])) {
			foreach ($request_status_list['result'] as $item_status_list) {
				$status_lists[$item_status_list['STATUS_ID']] = $item_status_list['ID'];
			}
		}

		if (isset($request['result'])) {
			foreach ($request['result'] as $item) {
				if (isset($status_lists[$item['STATUS_ID']])) {
					$sources[$item['STATUS_ID'] . "_STATUS_ID"] = $item['STATUS_ID'] . "|" . $item['NAME'];
				} else {
					$sources[$item['STATUS_ID'] . "_STATUS_ID"] = $item['STATUS_ID'] . "|" . $item['NAME'];
				}
			}
		}

		return $sources;
	}

	private function getEntityTypesList()
	{
		$sources = array();

		$params = array(
			'method' => 'crm.status.entity.types'
		);
		$request = $this->sendRequest($params);

		if (isset($request['result'])) {
			foreach ($request['result'] as $item) {
				if (isset($item['PARENT_ID']) && $item['PARENT_ID'] == "DEAL_STAGE") {
					$sources[] = $item['ID'];
				}
			}
		}

		return $sources;
	}

	public function getUsers($start = 0, $users = array())
	{
		$params = array(
			'method' => 'user.get',
			'params' => array(
				'start' => $start
			)
		);

		$request = $this->sendRequest($params);
		if (isset($request['result']) && $request['result']) {
			foreach ($request['result'] as $item) {
				$users[$item['ID']] = $item['LAST_NAME'] . ' ' . $item['NAME'];
			}
			$start += 50;
			return $this->getUsers($start, $users);
		} else {
			return $users;
		}
	}

	private function sendRequest($params)
	{
		$url = $this->getUrl();
		if (!$url) {
			$this->log('Необходимые параметры для подключения к bitrix24 отсутствуют в настройках модуля!');
			return false;
		}
		CRest::$web_hook_url = $url;
		CRest::$debug = false;
		CRest::$logFile = $this->_logFile;

		if (isset($params['params']) && $params['params']) {
			$result = CRest::call($params['method'], $params['params']);
		} else {
			$result = CRest::call($params['method']);
		}

		return $result;
	}

	private function validate()
	{
		if (!$this->user->hasPermission('modify', 'extension/' . $this->_route . '/' . $this->_moduleSysName())) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		if (!$this->error) {
			return TRUE;
		} else {
			return FALSE;
		}
	}

	private function checkParams()
	{
		$portal_name = $this->config->get($this->_moduleSysName() . '_portal_name');
		$id_user = $this->config->get($this->_moduleSysName() . '_id_user');
		$secret_code = $this->config->get($this->_moduleSysName() . '_secret_code');

		if (!trim($portal_name) || !trim($id_user) || !trim($secret_code)) {
			$this->error['warning'] = $this->language->get('error_empty_params');
		}

		if (!$this->error) {
			return TRUE;
		} else {
			return FALSE;
		}
	}

}
