<?php

require_once(DIR_SYSTEM . "/engine/neoseo_model.php");

class ModelToolNeoSeoBitrix24 extends NeoSeoModel
{

	public function __construct($registry)
	{
		parent::__construct($registry);
		$this->_moduleSysName = 'neoseo_bitrix24';
		$this->_module_code = "neoseo_bitrix24";
		$this->_modulePostfix = ""; // Постфикс для разных типов модуля, поэтому переходим на испольлзование $this->_moduleSysName()()
		$this->_logFile = $this->_moduleSysName() . '.log';
		$this->debug = $this->config->get($this->_moduleSysName() . '_debug') == 1;
		$this->addAccessLevels();
	}

	public function getUrl()
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
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

		$domain = $this->config->get($this->_moduleSysName . '_domain');
		$domain = $domain ? $domain : 'bitrix24.ua';
		$url = 'https://' . $portal_name . '.' . $domain . '/rest/' . $id_user . '/' . $secret_code . '/';

		return $url;
	}

	public function sendRequest($params)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		$url = $this->getUrl();
		if (!$url) {
			$this->log('Необходимые параметры для подключения к bitrix24 отсутствуют в настройках модуля!');
			return false;
		}

		require_once(DIR_SYSTEM . 'library/bitrix24/bitrix24_crest.php');

		CRest::$web_hook_url = $url;
		CRest::$debug = $this->debug;
		CRest::$logFile = $this->_logFile;

		if (isset($params['params']) && $params['params']) {
			$result = CRest::call($params['method'], $params['params']);
		} else {
			$result = CRest::call($params['method']);
		}

		return $result;
	}

	public function createEntity($customer_id, $source_id, $params = array())
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		$custom_field_phone_name = $this->config->get($this->_moduleSysName() . '_custom_field_phone');
		$not_add_new_contact = $this->config->get($this->_moduleSysName() . '_not_add_new_contact');

		if ($this->config->get($this->_moduleSysName() . '_status') != 1) {
			$this->log('Модуль NeoSeo Обмен с Bitrix24 отключен');
			return false;
		}

		if ((!isset($customer_id) || !$customer_id) && !$params) {
			$this->log('Данные покупателя не получены.');
			return false;
		}

		if (!isset($source_id) || !$source_id) {
			$this->log('ИД источника не получен.');
			return false;
		}

		$data['title'] = '';
		$data['name'] = '';
		$data['last_name'] = '';
		$data['telephone'] = '';
		$data['email'] = '';
		$data['comments'] = '';
		$data['customer_group_id'] = 0;

		if ($params) {
			$data['title'] = isset($params['name']) ? $params['name'] : '';
			$data['name'] = $data['title'];
			if (isset($order_info['telephone']) || $params['telephone'] != "") {
				$data['telephone'] = $params['telephone'];
			} elseif (isset($order_info['payment_custom_field']) || isset($order_info['shipping_custom_field'])) {
				$data['telephone'] = '';
				$custom_field_telephone = $this->getCustomFieldValue($custom_field_phone_name, $params['payment_custom_field']);
				if ($custom_field_telephone) {
					$data['telephone'] = $custom_field_telephone;
				} else {
					$custom_field_telephone = $this->getCustomFieldValue($custom_field_phone_name, $params['shipping_custom_field']);
					if ($custom_field_telephone) {
						$data['telephone'] = $custom_field_telephone;
					}
				}
			} else {
				$data['telephone'] = '';
			}
			$data['email'] = isset($params['email']) ? $params['email'] : '';
			$data['comments'] = $params['comments'];
		} elseif ($customer_id) {
			$this->load->model('account/customer');
			$customer = $this->model_account_customer->getCustomer($customer_id);
			$data['title'] = $customer['firstname'] . ' ' . $customer['lastname'];
			$data['name'] = $customer['firstname'];
			$data['last_name'] = $customer['lastname'];
			$data['telephone'] = $customer['telephone'];
			$data['email'] = $customer['email'];
			$data['customer_group_id'] = isset($customer['customer_group_id']) ? $customer['customer_group_id'] : 0;
		}

		if ($not_add_new_contact) {
			$contact_id = $this->searchContactBitrix($data['telephone'], $data['email']);
		} else {
			$contact_id = $this->addContact($customer_id, $data); // Создание нового контакта
		}

		$lead_id = $this->addLead($customer_id, $source_id, $contact_id, $data); // Создание нового лида

		$result = array(
			'contact_id' => $contact_id,
			'lead_id' => $lead_id,
		);

		return $result;
	}

	public function getCustomFieldValue($field_name, $custom_fields)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return false;
		}
		$fields = $this->getCustomFields($custom_fields);
		if (count($fields) > 0) {
			if (isset($fields[$field_name])) {
				return $fields[$field_name];
			}
		}
		return false;
	}

	public function createDeal($order_id, $order_status_id)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return false;
		}
		$unload_order_status = $this->config->get($this->_moduleSysName() . '_unload_order_status');
		$custom_field_phone_name = $this->config->get($this->_moduleSysName() . '_custom_field_phone');

		$one_deal = $this->config->get($this->_moduleSysName() . '_one_deal');

		if (!in_array($order_status_id, $unload_order_status)) {
			$this->log("Статус заказа с #{$order_status_id} не выбран в настройках для передачи в bitrix24");
			return false;
		}

		$params['order_id'] = $order_id;
		$order_parts = $this->getOrderParts($order_id);
		if (!$order_parts) {
			$this->log("В заказе №$order_id не найдены товары или сделки уже были созданы.");
			return false;
		}

		$this->load->model('checkout/order');
		$order_info = $this->model_checkout_order->getOrder($order_id);
		$params['order_total'] = $order_info['total'];
		$params['order_comment'] = $order_info['comment'];
		$params['customer_id'] = $order_info['customer_id'];


		if (isset($order_info['telephone']) && $order_info['telephone'] != "") {
			$params['contact_id'] = $this->getContactId($params['customer_id'], $order_info['email'], $order_info['telephone']);
		} elseif (isset($order_info['payment_custom_field']) || isset($order_info['shipping_custom_field'])) {
			$additinal_tel = '';
			$custom_field_telephone = $this->getCustomFieldValue($custom_field_phone_name, $order_info['payment_custom_field']);
			if ($custom_field_telephone) {
				$additinal_tel = $custom_field_telephone;
			} else {
				$custom_field_telephone = $this->getCustomFieldValue($custom_field_phone_name, $order_info['shipping_custom_field']);
				if ($custom_field_telephone) {
					$additinal_tel = $custom_field_telephone;
				}
			}
			if ($additinal_tel) {
				$params['contact_id'] = $this->getContactId($params['customer_id'], $order_info['email'], $additinal_tel);
			}
		} else {
			$params['contact_id'] = $this->getContactId($params['customer_id'], $order_info['email'], $order_info['telephone']);
		}

		if (!isset($params['contact_id']) || $params['contact_id'] == 0) {
			$contact_data['name'] = isset($order_info['firstname']) ? $order_info['firstname'] : 'No firstname';
			$contact_data['last_name'] = isset($order_info['lastname']) ? $order_info['lastname'] : 'No lastname';

			if (isset($order_info['telephone']) && $order_info['telephone'] != "") {
				$contact_data['telephone'] = $order_info['telephone'];
			} elseif (isset($order_info['payment_custom_field']) || isset($order_info['shipping_custom_field'])) {
				$contact_data['telephone'] = '';
				$custom_field_telephone = $this->getCustomFieldValue($custom_field_phone_name, $order_info['payment_custom_field']);
				if ($custom_field_telephone) {
					$contact_data['telephone'] = $custom_field_telephone;
				} else {
					$custom_field_telephone = $this->getCustomFieldValue($custom_field_phone_name, $order_info['shipping_custom_field']);
					if ($custom_field_telephone) {
						$contact_data['telephone'] = $custom_field_telephone;
					}
				}
			} else {
				$contact_data['telephone'] = '';
			}


			$contact_data['email'] = isset($order_info['email']) ? $order_info['email'] : '';
			$contact_data['comments'] = "";
			if (isset($order_info['comments'])) {
				$contact_data['comments'] = $order_info['comments'];
			}
			$params['contact_id'] = $this->addContact($params['customer_id'], $contact_data); // Создание нового контакта
		}

		$params['order_status_id'] = $order_info['order_status_id'];
		$params['currency_code'] = $order_info['currency_code'];

		$params['deal_stage'] = str_replace("_STATUS_ID", "", $this->config->get($this->_moduleSysName() . '_deal_stage_default'));
		$params['deal_type'] = $this->config->get($this->_moduleSysName() . '_deal_type_default');
		$result_deal_stage = $this->getDealStage($order_info['order_status_id']);
		if ($result_deal_stage) {
			$params['deal_stage'] = $result_deal_stage;
		}

		//Добавление дополнительных даных
		$params['extra_property'] = array();
		$deal_extra_property = trim($this->config->get($this->_moduleSysName() . "_deal_extra_property"));
		if ($deal_extra_property) {
			$custom_field = isset($order_info['payment_custom_field']) ? $this->getCustomFields($order_info['payment_custom_field']) : array();
			$deal_extra_property = $this->getAdditionalProperty($deal_extra_property, $custom_field);
			foreach ($deal_extra_property as $extra_params) {

				$property = trim($extra_params[0]);
				$table = trim($extra_params[1]);

				if (count($extra_params) == 2) {
					if (utf8_strtolower($table) == '{order_link}') {
						$store_url = $this->config->get('config_secure') ? HTTPS_SERVER : HTTP_SERVER;
						$store_url = rtrim($store_url, '/');
						$params['extra_property'][$property] = $store_url . '/admin/index.php?route=sale/order/info&order_id=' . $order_id;
						continue;
					}
					$params['extra_property'][$property] = $custom_field[$table];
					continue;
				}

				$field = trim($extra_params[2]);

				$where_field_name = str_replace(DB_PREFIX, "", $table) . "_id";

				$filter_by_value_raw = explode("=", $field);
				$additional_where = "";
				if (count($filter_by_value_raw) != 1) {
					$additional_where = " AND " . $filter_by_value_raw[0] . " = '" . $filter_by_value_raw[1] . "' ";
					$field = $filter_by_value_raw[2];
				}

				if ($where_field_name === "order_total_id") {
					$where_field_name = "order_id";
				}

				//Получаем значение поля
				$query = $this->db->query("SELECT `" . $field . "` FROM `" . $table . "` WHERE 	" . $where_field_name . " = '" . $params[$where_field_name] . "'" . $additional_where);
				if (!$query->num_rows) {
					$this->log("Не найдено значение поля " . $field . " в таблице '" . $table . "' для заказа #" . $params['order_id'] . ". Формирование дополнительного поля " . $property . " пропущено.");
					continue;
				}
				if (is_null($query->row[$field]))
					$query->row[$field] = 0; //continue;
				$params['extra_property'][$property] = $query->row[$field];
			}
		}
		//deal_extra_property end

		if ($one_deal) {
			$this->addOneDeal($order_parts, $params);
		} else {
			foreach ($order_parts as $parts) {
				$this->addDeal($parts, $params);
			}
		}
		return true;
	}

	private function searchContactBitrix($telephone = "", $email = "")
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return 0;
		}
		$search_contact_by = $this->config->get($this->_moduleSysName() . '_search_contact_by');

		$filter = array();

		if (!$search_contact_by) {
			$filter = array("PHONE" => $telephone);
		} elseif ($search_contact_by == 1) {
			$filter = array("EMAIL" => $email);
		} elseif ($search_contact_by == 2) {
			if ($telephone != "") {
				$filter = array("PHONE" => $telephone);
			} elseif ($email != "") {
				$filter = array("EMAIL" => $email);
			}
		}

		$params = array(
			'method' => 'crm.contact.list',
			'params' => array(
				'order' => array("NAME" => "ASC"),
				'filter' => $filter,
				'select' => array("ID"),
			)
		);

		$request = $this->sendRequest($params);

		if (isset($request['result'][0]['ID'])) {
			$this->log("Нашли контакт в Bitrix24 " . $request['result'][0]['ID']);

			return $request['result'][0]['ID'];
		}

		return 0;
	}

	private function addContact($customer_id, $params = false)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return false;
		}
		if ($this->config->get($this->_moduleSysName() . '_add_contact') != 1) {
			$this->log('Создание контакта отключено в настройках модуля.');
			return false;
		}

		$source_id = $this->config->get($this->_moduleSysName() . '_source_contact');

		$contact_id = $this->getContactId($customer_id, $params['email'], $params['telephone']);
		if ($contact_id) {
			return $contact_id;
		}

		$contact_id = $this->searchContactBitrix($params['telephone'], $params['email']);
		if ($contact_id) {
			return $contact_id;
		}

		if (!$params) {
			$this->load->model('account/customer');
			$customer = $this->model_account_customer->getCustomer($params['customer_id']);
			$data['title'] = $customer['firstname'] . ' ' . $customer['lastname'];
			$data['name'] = $customer['firstname'];
			$data['last_name'] = $customer['lastname'];
			$data['telephone'] = $customer['telephone'];
			$data['email'] = $customer['email'];
			$data['customer_group_id'] = isset($customer['customer_group_id']) ? $customer['customer_group_id'] : 0;
		}

		$type_id = $this->config->get($this->_moduleSysName() . '_type_contact_default');
		if (isset($params['customer_group_id']) && $params['customer_group_id']) {
			$result_type_id = $this->getTypeContact($params['customer_group_id']);
			if ($result_type_id) {
				$type_id = $result_type_id;
			}
		}

		$data = array(
			'method' => 'crm.contact.add',
			'params' => array(
				'fields' => array(
					"ORIGIN_ID" => $customer_id,
					"NAME" => $params['name'],
					"LAST_NAME" => $params['last_name'],
					"SECOND_NAME" => "Отчество",
					"STATUS_ID" => "NEW",
					"TYPE_ID" => $type_id,
					"OPENED" => "Y",
					"ASSIGNED_BY_ID" => $this->config->get($this->_moduleSysName() . '_contact_user_id'),
					"PHONE" => array(array("VALUE" => $params['telephone'], "VALUE_TYPE" => "WORK")),
					"EMAIL" => array(array("VALUE" => $params['email'], "VALUE_TYPE" => "WORK")),
					"SOURCE_ID" => $source_id,
					"COMMENTS" => $params['comments'],
				),
				'params' => array("REGISTER_SONET_EVENT" => "Y")
			),
		);

		$request = $this->sendRequest($data);
		if (isset($request['result'])) {
			$this->log('Новый контакт успешно создан в bitrix24 c ИД ' . $request['result']);
			if ($customer_id || trim($params['email']) || trim($params['telephone'])) {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "customer_to_contact_bitrix24` (`customer_id`, `telephone`, `email`, `contact_id`) VALUES ('" . (int) $customer_id . "', '" . $this->db->escape($params['telephone']) . "', '" . $this->db->escape($params['email']) . "', '" . $this->db->escape($request['result']) . "')");
			} else {
				$this->log('Добавить в базу ИД контакта не возможно, т.к. нет ИД покупателя и email адреса');
			}
			return $request['result'];
		}

		return false;
	}

	private function addLead($customer_id, $source_id, $contact_id, $params)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return false;
		}
		if (!$contact_id) {
			$this->log('ИД контакта не передан при создании лида.');
			$contact_id = 0;
		}

		$lead_id = $this->getleadId($customer_id, $params['email']);
		if ($lead_id) {
			return $lead_id;
		}

		$data = array(
			'method' => 'crm.lead.add',
			'params' => array(
				'fields' => array(
					"ORIGIN_ID" => $customer_id,
					"TITLE" => $params['title'],
					"NAME" => $params['name'],
					"LAST_NAME" => $params['last_name'],
					"STATUS_ID" => "NEW",
					"OPENED" => "Y",
					"CONTACT_ID" => $contact_id,
					"ASSIGNED_BY_ID" => $this->config->get($this->_moduleSysName() . '_lead_user_id'),
					"PHONE" => array(array("VALUE" => $params['telephone'], "VALUE_TYPE" => "WORK")),
					"EMAIL" => array(array("VALUE" => $params['email'], "VALUE_TYPE" => "WORK")),
					"SOURCE_ID" => $source_id,
					"COMMENTS" => $params['comments'],
				),
				'params' => array("REGISTER_SONET_EVENT" => "Y")
			),
		);

		$request = $this->sendRequest($data);
		if (isset($request['result'])) {
			$this->log('Новый лид успешно создан в bitrix24 c ИД ' . $request['result']);
			if ($customer_id || trim($params['email'])) {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "customer_to_lead_bitrix24` (`customer_id`, `email`, `lead_id`) VALUES ('" . (int) $customer_id . "', '" . $this->db->escape($params['email']) . "', '" . $this->db->escape($request['result']) . "')");
			} else {
				$this->log('Добавить в базу ИД лида не возможно, т.к. нет ИД покупателя и email адреса');
			}
			return $request['result'];
		}

		return true;
	}

	private function addDeal($item, $params)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return false;
		}
		if (isset($params['deal_stage'])) {
			if (strpos($params['deal_stage'], ':') !== false) {
				$deals = explode(':', $params['deal_stage']);
				if (count($deals) >= 2) {
					$deal_category = preg_replace("/[^0-9]/", '', $deals[0]);
					$deal_stage = $params['deal_stage'];
				} else {
					$deal_stage = $params['deal_stage'];
					$deal_category = "";
				}
			} else {
				$deal_stage = $params['deal_stage'];
				$deal_category = "";
			}
		}

		$data = array(
			'method' => 'crm.deal.add',
			'params' => array(
				'fields' => array(
					"TITLE" => $item['title'],
					"STAGE_ID" => $deal_stage,
					"CATEGORY_ID" => $deal_category,
					"TYPE_ID" => $item['deal_type'],
					"CONTACT_ID" => $params['contact_id'],
					"OPENED" => "Y",
					"ASSIGNED_BY_ID" => $this->config->get($this->_moduleSysName() . '_deal_user_id'),
					"CURRENCY_ID" => $params['currency_code'],
					"OPPORTUNITY" => $item['total'],
					"COMMENTS" => $item['comments'] . "<br>" . "Номер заказа на сайте: " . $params['order_id'],
				),
				'params' => array("REGISTER_SONET_EVENT" => "Y")
			),
		);

		if (count($params['extra_property'])) {
			$this->log('Даные по дополнительным полям ' . $params['order_id'] . " >>");
			foreach ($params['extra_property'] as $property => $field) {
				$data['params']['fields'][$property] = $field;
				$this->log(" >> " . $property . " : " . $field);
			}
		}

		$request = $this->sendRequest($data); //array('result' => true);//
		if (isset($request['result'])) {
			$this->log('Новая сделка успешно создана в bitrix24 c ИД ' . $request['result'] . '. На основе заказа #' . $params['order_id']);
			$this->db->query("INSERT INTO `" . DB_PREFIX . "order_to_deal_bitrix24` (`order_id`, `order_product_id`, `deal_id`) VALUES ('" . (int) $params['order_id'] . "', '" . (int) $item['order_product_id'] . "', '" . $this->db->escape($request['result']) . "')");
		}

		//$this->log(" Полные даные что летят на Битрикс: ");
		//$this->log(print_r($data,true));

		return true;
	}

	private function addOneDeal($item, $params)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return false;
		}
		if (isset($params['deal_stage'])) {
			if (strpos($params['deal_stage'], ':') !== false) {
				$deals = explode(':', $params['deal_stage']);
				if (count($deals) >= 2) {
					$deal_category = preg_replace("/[^0-9]/", '', $deals[0]);
					$deal_stage = $params['deal_stage'];
				} else {
					$deal_stage = $params['deal_stage'];
					$deal_category = "";
				}
			} else {
				$deal_stage = $params['deal_stage'];
				$deal_category = "";
			}
		}
		$data = array(
			'method' => 'crm.deal.add',
			'params' => array(
				'fields' => array(
					"TITLE" => "Заказ от сайта № " . $params['order_id'],
					"STAGE_ID" => $deal_stage,
					"CATEGORY_ID" => $deal_category,
					"TYPE_ID" => $params['deal_type'],
					"CONTACT_ID" => $params['contact_id'],
					"OPENED" => "Y",
					"ASSIGNED_BY_ID" => $this->config->get($this->_moduleSysName() . '_deal_user_id'),
					"CURRENCY_ID" => $params['currency_code'],
					"OPPORTUNITY" => $params['order_total'],
					"COMMENTS" => $params['order_comment'] . "<br>" . "Номер заказа на сайте: " . $params['order_id'],
				),
				'params' => array("REGISTER_SONET_EVENT" => "Y")
			),
		);

		if (count($params['extra_property'])) {
			$this->log('Даные по дополнительным полям ' . $params['order_id'] . " >>");
			foreach ($params['extra_property'] as $property => $field) {
				$data['params']['fields'][$property] = $field;
				$this->log(" >> " . $property . " : " . $field);
			}
		}

		$request = $this->sendRequest($data); //array('result' => true);//
		if (isset($request['result'])) {

			$data_products = array(
				'method' => 'crm.deal.productrows.set',
				'params' => array(
					'id' => $request['result'],
					'rows' => $item,
				)
			);
			//$this->log("data_products " .print_r($data_products,true));
			$this->sendRequest($data_products); //array('result' => true);//

			$this->log('Новая сделка успешно создана в bitrix24 c ИД ' . $request['result'] . '. На основе заказа #' . $params['order_id']);
			foreach ($item as $prod_item) {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "order_to_deal_bitrix24` (`order_id`, `order_product_id`, `deal_id`) VALUES ('" . (int) $params['order_id'] . "', '" . (int) $prod_item['order_product_id'] . "', '" . $this->db->escape($request['result']) . "')");
			}
		}
		return true;
	}

	private function searchProductBitrix($product_name)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return false;
		}
		$params = array(
			'method' => 'crm.product.list',
			'params' => array(
				'order' => array("NAME" => "ASC"),
				'filter' => array("NAME" => $product_name),
				'select' => array("ID"),
			)
		);

		$request = $this->sendRequest($params);

		if (isset($request['result'][0]['ID'])) {
			$this->log("Нашли товар в Bitrix24 " . $request['result'][0]['ID']);

			return $request['result'][0]['ID'];
		}

		return false;
	}

	private function addProductBitrix($product_name, $product_price)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		$params = array(
			'method' => 'crm.product.add',
			'params' => array(
				'fields' => array(
					"NAME" => $product_name,
					"PRICE" => $product_price
				),
			)
		);
		$request = $this->sendRequest($params);

		if (isset($request['result'])) {
			$this->log("Создали товар в Bitrix24 " . $request['result']);
			return $request['result'];
		}

		return "";
	}

	private function getOrderParts($order_id)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		$one_deal = $this->config->get($this->_moduleSysName() . '_one_deal');
		$product_model_name = $this->config->get($this->_moduleSysName() . '_product_model_name');
		$order_parts = array();
		$order_product_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = '" . (int) $order_id . "'");

		if (!$order_product_query->num_rows) {
			return $order_parts;
		}

		$unload_options = $this->config->get($this->_moduleSysName() . '_unload_options');
		$deal_type_default = $this->config->get($this->_moduleSysName() . '_deal_type_default');

		foreach ($order_product_query->rows as $order_product_row) {

			$isset_order_deal = $this->issetOrderDeal($order_id, $order_product_row['order_product_id']);
			if ($isset_order_deal) {
				$this->log("На основе заказа №$order_id и товара #{$order_product_row['product_id']} уже создана сделка в bitrix24 №$isset_order_deal");
				continue;
			}

			$comments = '';
			$deal_type = $deal_type_default;
			$order_option_query = $this->db->query("SELECT oo.*, po.option_id, pov.option_value_id FROM " . DB_PREFIX . "order_option oo"
					. " LEFT JOIN " . DB_PREFIX . "product_option po ON (oo.product_option_id=po.product_option_id)"
					. " LEFT JOIN " . DB_PREFIX . "product_option_value pov ON (oo.product_option_value_id=pov.product_option_value_id)"
					. " WHERE oo.order_product_id = '" . (int) $order_product_row['order_product_id'] . "'");

			foreach ($order_option_query->rows as $order_option_row) {

				if (in_array($order_option_row['option_id'], $unload_options)) {
					$comments .= $order_option_row['name'] . ': ' . $order_option_row['value'] . '<br>';
				}
			}

			$query = $this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "product_to_category` LIKE 'main_category'");
			$where = '';
			if ($query->num_rows) {
				$where = ' AND p2c.main_category=1';
			}

			$deal_type_query = $this->db->query("SELECT c2dt.deal_type FROM " . DB_PREFIX . "product_to_category p2c"
					. " LEFT JOIN " . DB_PREFIX . "category_to_deal_type_bitrix24 c2dt ON (c2dt.category_id=p2c.category_id)"
					. " WHERE p2c.product_id = '" . (int) $order_product_row['product_id'] . "'" . $where . ' LIMIT 1');

			if ($deal_type_query->num_rows && $deal_type_query->row['deal_type']) {
				$deal_type = $deal_type_query->row['deal_type'];
			}

			if ($one_deal) {
				if ($product_model_name) {
					$product_name = $order_product_row['name'] . "" . trim($comments, '<br>') . " " . $order_product_row['model'];
				} else {
					$product_name = $order_product_row['name'] . "" . trim($comments, '<br>');
				}
                $this->log('Name: ');
                $this->log(print_r($product_name, true));
                $bit_product_id = $this->searchProductBitrix($product_name);
				if (!$bit_product_id) {
					$bit_product_id = $this->addProductBitrix($product_name, $order_product_row['price']);
				}
				$order_part_product = array(
					'order_product_id' => $order_product_row['order_product_id'],
					'PRODUCT_ID' => $bit_product_id,
					'QUANTITY' => $order_product_row['quantity'],
					'PRICE' => $order_product_row['price'],
				);

				if ($bit_product_id) {
					$order_part_product['PRODUCT_NAME'] = $product_name;
				}
				$order_parts[] = $order_part_product;
			} else {
				$order_parts[] = array(
					'order_product_id' => $order_product_row['order_product_id'],
					'product_id' => $order_product_row['product_id'],
					'comments' => $comments,
					'deal_type' => $deal_type,
					'title' => 'Заказ "' . $order_product_row['name'] . '"',
					'total' => $order_product_row['total'],
				);
			}
		}
		return $order_parts;
	}

	private function getTypeContact($customer_group_id)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		$query = $this->db->query("SELECT contact_type FROM " . DB_PREFIX . "group_to_contact_bitrix24 WHERE customer_group_id='" . (int) $customer_group_id . "' LIMIT 1");

		if (!$query->num_rows) {
			return false;
		}

		return $query->row['contact_type'];
	}

	private function getDealStage($order_status_id)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		$query = $this->db->query("SELECT deal_stage FROM " . DB_PREFIX . "order_status_to_deal_stage_bitrix24 WHERE order_status_id='" . (int) $order_status_id . "' LIMIT 1");

		if (!$query->num_rows) {
			return false;
		}

		return $query->row['deal_stage'];
	}

	private function getContactId($customer_id, $email, $telephone)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		$search_contact_by = $this->config->get($this->_moduleSysName() . '_search_contact_by');
		if (!$customer_id && !trim($email) && !trim($telephone)) {
			$this->log('ИД покупателя равен 0 и почта и телефон не заполнена! Выполнить поиск ИД контакта не возможно!');
			return 0;
		}

		if (!$customer_id && !$customer_id && !trim($telephone)) {
			return 0;
		}

		$this->log("Поиск контакта $customer_id $email $telephone");
		if (!$customer_id || $customer_id == 0) {

			if (!$search_contact_by || $search_contact_by == 0) {
				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer_to_contact_bitrix24 WHERE telephone='" . $this->db->escape($telephone) . "'");
			} elseif ($search_contact_by == 1) {
				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer_to_contact_bitrix24 WHERE email='" . $this->db->escape($email) . "'");
			} elseif ($search_contact_by == 2) {
				$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer_to_contact_bitrix24 WHERE email='" . $this->db->escape($email) . "'");
				if (!$query->num_rows) {
					$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer_to_contact_bitrix24 WHERE telephone='" . $this->db->escape($telephone) . "'");
				}
			}
		} else {
			$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer_to_contact_bitrix24 WHERE customer_id='" . (int) $customer_id . "' LIMIT 1");
		}

		if (!$query->num_rows) {
			return 0;
		}

		if ($query->row['customer_id'] == 0) {
			$this->db->query("UPDATE " . DB_PREFIX . "customer_to_contact_bitrix24 SET customer_id='" . (int) $customer_id . "' WHERE email='" . $this->db->escape($email) . "' OR telephone='" . $this->db->escape($telephone) . "'");
		}

		return $query->row['contact_id'];
	}

	private function getleadId($customer_id, $email)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		if (!$customer_id && !trim($email)) {
			$this->log('ИД покупателя равен 0 и почта не заполнена! Выполнить поиск ИД лида не возможно!');
			return 0;
		}

		if (!$customer_id) {
			$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer_to_lead_bitrix24 WHERE email='" . $this->db->escape($email) . "'");
		} else {
			$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "customer_to_lead_bitrix24 WHERE customer_id='" . (int) $customer_id . "' OR email='" . $this->db->escape($email) . "' LIMIT 1");
		}

		if (!$query->num_rows) {
			return 0;
		}

		if ($query->row['customer_id'] == 0) {
			$this->db->query("UPDATE " . DB_PREFIX . "customer_to_lead_bitrix24 SET customer_id='" . (int) $customer_id . "' WHERE email='" . $this->db->escape($email) . "'");
		}

		return $query->row['lead_id'];
	}

	private function issetOrderDeal($order_id, $order_product_id)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		$query = $this->db->query("SELECT deal_id FROM " . DB_PREFIX . "order_to_deal_bitrix24 WHERE order_id='" . (int) $order_id . "' AND order_product_id ='" . (int) $order_product_id . "'");

		if (!$query->num_rows) {
			return false;
		}

		return $query->row['deal_id'];
	}

	private function getAdditionalProperty($params_raw, $custom_field = array())
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		$return = array();
		foreach (explode("\n", $params_raw) as $line) {
			$line = trim($line);
			if (!$line)
				continue;

			$params = explode(";", $line);

			$property = trim($params[0]);
			$table = trim($params[1]);

			if (count($params) == 2 && (strpos($table, "[") !== false || strpos($table, "{") !== false)) {
				$field_name = str_replace(array("[", "]"), "", $table);
				if (!isset($custom_field[$field_name]) && strpos($table, "{") === false) {
					$this->log("Не найдено дополнительного поля '" . $field_name . " в настраиваемых полях для заказа.");
					continue;
				}
				$return[] = array($property, $field_name);
				continue;
			}

			$field = trim($params[2]);
			$info = isset($params[3]) ? trim($params[3]) . " " : "";

			//Проверяем наличе таблицы
			$query = $this->db->query("SHOW TABLES LIKE '" . $table . "'");
			if (!$query->num_rows) {
				$this->log("Таблица '" . $table . "' не найдена в БД. Формирование дополнительных полей " . $info . $property . " пропущено");
				continue;
			}

			$filter_by_value_raw = explode("=", $field);
			if (count($filter_by_value_raw) != 1) {
				$field = $filter_by_value_raw[0];
			}
			//Проверяем наличе поля
			$query = $this->db->query("SHOW COLUMNS FROM `" . $table . "` LIKE '" . $field . "'");
			if (!$query->num_rows) {
				$this->log("Поле " . $field . " не найдено в таблице '" . $table . "'. Формироание дополнительного поля " . $info . $property . " пропущено");
				continue;
			}

			$return[] = $params;
		}

		return $return;
	}

	public function getCustomFields($fields)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		$data = array();
		foreach ($fields as $field_id => $field_value) {
			$field_data = $this->getCustomField($field_id);
			$data[$field_data["name"]] = $field_value;
		}
		return $data;
	}

	public function getCustomField($custom_field_id)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "custom_field` cf LEFT JOIN " . DB_PREFIX . "custom_field_description cfd ON (cf.custom_field_id = cfd.custom_field_id) WHERE cf.custom_field_id = '" . (int) $custom_field_id . "' AND cfd.language_id = '" . (int) $this->config->get('config_language_id') . "'");

		return $query->row;
	}

	private function addAccessLevels()
	{
		$this->setAccessLevels(
				array(
					'getUrl' => 2,
					'sendRequest' => 2,
					'createEntity' => 2,
					'getCustomFieldValue' => 2,
					'createDeal' => 2,
					'searchContactBitrix' => 2,
					'addContact' => 2,
					'addLead' => 2,
					'addDeal' => 2,
					'addOneDeal' => 2,
					'searchProductBitrix' => 2,
					'addProductBitrix' => 2,
					'getOrderParts' => 2,
					'getTypeContact' => 2,
					'getDealStage' => 2,
					'getContactId' => 2,
					'getleadId' => 2,
					'issetOrderDeal' => 2,
					'getAdditionalProperty' => 2,
					'getCustomFields' => 2,
					'getCustomField' => 2,
				)
		);
	}

}
