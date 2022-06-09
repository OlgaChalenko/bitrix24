<?php

require_once( DIR_SYSTEM . "/engine/neoseo_model.php");

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

	public function getGroup2Contact()
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return array();
		}
		$result = array();

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "group_to_contact_bitrix24");

		if (!$query->num_rows)
			return $result;


		foreach ($query->rows as $row) {
			$result[$row['customer_group_id']] = $row['contact_type'];
		}

		return $result;
	}

	public function addGroup2Contact($data)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return false;
		}
		$this->db->query("DELETE FROM " . DB_PREFIX . "group_to_contact_bitrix24");

		if (isset($data['group_to_contact'])) {
			foreach ($data['group_to_contact'] as $value) {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "group_to_contact_bitrix24` (`customer_group_id`, `contact_type`) VALUES ('" . (int) $value['customer_group_id'] . "', '" . $this->db->escape($value['contact_type']) . "')");
			}
		}

		return true;
	}

	public function getOrderStatus2DealStage()
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return array();
		}
		$result = array();

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_status_to_deal_stage_bitrix24");

		if (!$query->num_rows)
			return $result;


		foreach ($query->rows as $row) {
			$result[$row['order_status_id']] = $row['deal_stage'];
		}

		return $result;
	}

	public function addOrderStatus2DealStage($data)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return false;
		}
		$this->db->query("DELETE FROM " . DB_PREFIX . "order_status_to_deal_stage_bitrix24");

		if (isset($data['order_status_to_deal_stage'])) {
			foreach ($data['order_status_to_deal_stage'] as $value) {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "order_status_to_deal_stage_bitrix24` (`order_status_id`, `deal_stage`) VALUES ('" . (int) $value['order_status_id'] . "', '" . $this->db->escape($value['deal_stage']) . "')");
			}
		}

		return true;
	}

	public function getCategory2DealType()
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return "";
		}
		$result = array();

		$query = $this->db->query("SELECT * FROM " . DB_PREFIX . "category_to_deal_type_bitrix24");

		if (!$query->num_rows)
			return $result;


		foreach ($query->rows as $row) {
			$result[$row['category_id']] = $row['deal_type'];
		}

		return $result;
	}

	public function addCategory2DealType($data)
	{
		if (!$this->isAccesible(__FUNCTION__, true)) {
			return false;
		}
		$this->db->query("DELETE FROM " . DB_PREFIX . "category_to_deal_type_bitrix24");

		if (isset($data['category_to_deal_type'])) {
			foreach ($data['category_to_deal_type'] as $value) {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "category_to_deal_type_bitrix24` (`category_id`, `deal_type`) VALUES ('" . (int) $value['category_id'] . "', '" . $this->db->escape($value['deal_type']) . "')");
			}
		}

		return true;
	}

	private function addAccessLevels()
	{
		$this->setAccessLevels(
				array(
					'getGroup2Contact' => 2,
					'addGroup2Contact' => 2,
					'getOrderStatus2DealStage' => 2,
					'addOrderStatus2DealStage' => 2,
					'getCategory2DealType' => 2,
					'addCategory2DealType' => 2,
				)
		);
	}

}
