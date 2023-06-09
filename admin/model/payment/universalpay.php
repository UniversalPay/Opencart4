<?php
namespace Opencart\Admin\Model\Extension\Universalpay\Payment;
require_once DIR_EXTENSION.'universalpay/system/library/universalpay/payments.php';
class Universalpay extends \Opencart\System\Engine\Model {
	
    /**
     * parameters to initiate the SDK payment.
     *
     */
    protected $environment_params;
    
    public function install() {
        $this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "universalpay_order` (
			  `universalpay_order_id` INT(11) NOT NULL AUTO_INCREMENT,
			  `order_id` INT(11) NOT NULL,
			  `merchant_tx_id` VARCHAR(50) NOT NULL,
              `created` DATETIME NOT NULL,
			  `modified` DATETIME NOT NULL,
              `capture_status` INT(1) DEFAULT NULL,
			  `void_status` INT(1) DEFAULT NULL,
			  `refund_status` INT(1) DEFAULT NULL,
              `currency_code` CHAR(3) NOT NULL,
			  `total` DECIMAL( 10, 2 ) NOT NULL,
			  PRIMARY KEY (`universalpay_order_id`)
			) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;");
        
        $this->db->query("
			CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "universalpay_transaction` (
			  `id` INT(11) NOT NULL AUTO_INCREMENT,
			  `universalpay_order_id` INT(11) NOT NULL,
			  `created` DATETIME NOT NULL,
			  `type` ENUM('auth', 'payment', 'refund', 'void') DEFAULT NULL,
			  `amount` DECIMAL( 10, 2 ) NOT NULL,
			  PRIMARY KEY (`id`)
			) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;");
    }
    
    public function uninstall() {
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "universalpay_order`;");
        $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "universalpay_transaction`;");
    }

	public function getOrder($order_id) {
	    $qry = $this->db->query("SELECT * FROM `" . DB_PREFIX . "universalpay_order` WHERE `order_id` = '" . (int)$order_id . "' and `capture_status` is not NULL");
	    
	    if ($qry->num_rows) {
	        $order = $qry->row;
	        $order['transactions'] = $this->getTransactions($order['universalpay_order_id']);
	        return $order;
	    } else {
	        return false;
	    }
	}
	private function getTransactions($universalpay_order_id) {
	    $qry = $this->db->query("SELECT * FROM `" . DB_PREFIX . "universalpay_transaction` WHERE `universalpay_order_id` = '" . (int)$universalpay_order_id  . "'");
	    
	    if ($qry->num_rows) {
	        return $qry->rows;
	    } else {
	        return false;
	    }
	}
	public function getMysqlNowTime(){
	    $qry = $this->db->query("SELECT NOW()");
	    return $qry->row['NOW()'];
	}
	public function addTransaction($universalpay_order_id, $type, $amount) {
	    
	    $this->db->query("INSERT INTO `" . DB_PREFIX . "universalpay_transaction` SET `universalpay_order_id` = '" . (int)$universalpay_order_id . "', `created` = NOW(), "  . " `type` = '" . $this->db->escape($type) . "', `amount` = '" . $amount . "'");
	    
	    return $this->db->getLastId();
	}
	
	public function updateVoidStatus($universalpay_order_id, $status) {
	    $this->db->query("UPDATE `" . DB_PREFIX . "universalpay_order` SET `void_status` = '" . (int)$status . "' WHERE `universalpay_order_id` = '" . (int)$universalpay_order_id . "'");
	}
	public function updateCaptureStatus($universalpay_order_id, $status) {
	    $this->db->query("UPDATE `" . DB_PREFIX . "universalpay_order` SET `capture_status` = '" . (int)$status . "' WHERE `universalpay_order_id` = '" . (int)$universalpay_order_id . "'");
	}
	public function updateRefundStatus($universalpay_order_id, $status) {
	    $this->db->query("UPDATE `" . DB_PREFIX . "universalpay_order` SET `refund_status` = '" . (int)$status . "' WHERE `universalpay_order_id` = '" . (int)$universalpay_order_id . "'");
	}
	public function getTotalRefunded($universalpay_order_id) {
	    $query = $this->db->query("SELECT SUM(`amount`) AS `total` FROM `" . DB_PREFIX . "universalpay_transaction` WHERE `universalpay_order_id` = '" . (int)$universalpay_order_id . "' AND `type` = 'refund'");
	    
	    return (double)$query->row['total'];
	}
	public function getTotalCaptured($universalpay_order_id) {
	    $query = $this->db->query("SELECT SUM(`amount`) AS `total` FROM `" . DB_PREFIX . "universalpay_transaction` WHERE `universalpay_order_id` = '" . (int)$universalpay_order_id . "' AND `type` = 'payment' ");
	    
	    return (double)$query->row['total'];
	}
	public function capture($order_id, $capture_amount) {
	    $order = $this->getOrder($order_id);
	    
	    if ($order && $capture_amount > 0 ) {
	        
	        $this->initConfig();
	        $payments = (new \Payments\Payments())->environmentUrls($this->environment_params);
	        $capture = $payments->capture();
	        $capture->originalMerchantTxId($order['merchant_tx_id'])->
	        amount($capture_amount)->
	        allowOriginUrl($this->getAllowOriginUrl());
	        $result = $capture->execute();
	        if(!isset($result->result) || $result->result != 'success'){
	            return false;
	        }else{
	            return true;
	        }
	        
	    } else {
	        return false;
	    }
	}
	public function void($order_id) {
	    $order = $this->getOrder($order_id);
	    if ($order) {
	        
	        $this->initConfig();
	        $payments = (new \Payments\Payments())->environmentUrls($this->environment_params);
	        $capture = $payments->void();
	        $capture->originalMerchantTxId($order['merchant_tx_id'])->
	        allowOriginUrl($this->getAllowOriginUrl());
	        $result = $capture->execute();
	        if(!isset($result->result) || $result->result != 'success'){
	            return false;
	        }else{
	            return true;
	        }
	        
	    } else {
	        return false;
	    }
	}
	public function refund($order_id, $refund_amount) {
	    $order = $this->getOrder($order_id);
	    
	    if ($order && $refund_amount > 0 ) {
	        
	        $this->initConfig();
	        $payments = (new \Payments\Payments())->environmentUrls($this->environment_params);
	        $refund = $payments->refund();
	        $refund->originalMerchantTxId($order['merchant_tx_id'])->
	        amount($refund_amount)->
	        allowOriginUrl($this->getAllowOriginUrl());
	        $result = $refund->execute();
	        if(!isset($result->result) || $result->result != 'success'){
	           return false;
	        }else{
	            return true;
	        }
	        
	    } else {
	        return false;
	    }
	}
	// init the SDK configuration settings
	private function initConfig(){
	    $this->environment_params['merchantId'] =  trim($this->config->get('payment_universalpay_clientid'));
	    $this->environment_params['password'] = trim($this->config->get('payment_universalpay_password'));
	    $testmode = $this->config->get('payment_universalpay_testmode');
	    if ($testmode){
	        $this->environment_params['tokenURL'] = $this->config->get('payment_universalpay_test_token_url');
	        $this->environment_params['paymentsURL'] = $this->config->get('payment_universalpay_test_payments_url');
	        $this->environment_params['baseUrl'] = $this->config->get('payment_universalpay_test_cashier_url');
	        $this->environment_params['jsApiUrl'] = $this->config->get('payment_universalpay_test_javascript_url');
	    }else{
	        $this->environment_params['tokenURL'] = $this->config->get('payment_universalpay_token_url');
	        $this->environment_params['paymentsURL'] = $this->config->get('payment_universalpay_payments_url');
	        $this->environment_params['baseUrl'] = $this->config->get('payment_universalpay_cashier_url');
	        $this->environment_params['jsApiUrl'] = $this->config->get('payment_universalpay_javascript_url');
	    }
	}
	private function getAllowOriginUrl(){
	    $parse_result = parse_url(HTTP_SERVER);
	    if(isset($parse_result['port'])){
	        $allowOriginUrl = $parse_result['scheme']."://".$parse_result['host'].":".$parse_result['port'];
	    }else{
	        $allowOriginUrl = $parse_result['scheme']."://".$parse_result['host'];
	    }
	    return $allowOriginUrl;
	}
	public function updateOrderHistory($order_id,$order_status_id,$comment,$notify=0){
	    $this->db->query("INSERT INTO " . DB_PREFIX . "order_history SET order_id = '" . (int)$order_id . "', order_status_id = '" . (int)$order_status_id . "', notify = '" . (int)$notify . "', comment = '" . $this->db->escape($comment) . "', date_added = NOW()");
	    $this->db->query("UPDATE `" . DB_PREFIX . "order` SET order_status_id = '" . (int)$order_status_id . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'");
	}
}