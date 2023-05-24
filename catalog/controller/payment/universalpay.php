<?php
namespace Opencart\Catalog\Controller\Extension\Universalpay\Payment;
require_once DIR_EXTENSION.'universalpay/system/library/universalpay/payments.php';
class Universalpay extends \Opencart\System\Engine\Controller {

	/**
	 * constants
	 */
	const AUTHORIZED_STATUS       =  1;
	const PURCHASED_STATUS        =  2;
	const PAYMENT_METHOD = 'universalpay';
	const PAYMENT_IFRAME = 'iframe';
	const PAYMENT_STANDALONE = 'standalone';
	const PAYMENT_HOSTEDPAYPAGE = 'hostedPayPage';
	const CHECKSTATUS_CALLBACK       =  'callback';
	const CHECKSTATUS_REDIRECT        =  'redirect';

    /**
     * parameters to initiate the SDK payment.
     *
     */
    protected $environment_params;
	
	public function index() {

		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['language'] = $this->config->get('config_language');

		return $this->load->view('extension/universalpay/payment/universalpay', $data);
	}
	//the checkout page will call this function using an ajax way to proceed for the payment
	public function send() {
		$this->load->language('extension/universalpay/payment/universalpay');
		$json = [];
		
		if (!isset($this->session->data['order_id'])) {
			$json['error'] = $this->language->get('error_order');
		}
		if (!isset($this->session->data['payment_method']) || $this->session->data['payment_method'] != self::PAYMENT_METHOD) {
			$json['error'] = $this->language->get('error_payment_method');
		}
		if (!$json) {
			$this->load->model('checkout/order');
			$this->load->model('extension/universalpay/payment/universalpay');
			if ($order_info = $this->model_checkout_order->getOrder($this->session->data['order_id'])) {
				$post_data = $this->prepareData($order_info);
				$data = array();
				try {
					$this->initConfig();
					$payments = (new \Payments\Payments())->environmentUrls($this->environment_params);
					$payment_universalpay_pay_action = $this->config->get('payment_universalpay_pay_action'); //1 refer to auth, 0 refer to purchase
					if ($payment_universalpay_pay_action){
						$post_data['action'] = "AUTH";
						$payments_request = $payments->auth();
					}else {
						$post_data['action'] = "PURCHASE";
						$payments_request = $payments->purchase();
					}
					$payments_request->merchantTxId($post_data['merchantTxId'])->
					brandId($post_data['brandId'])->
					action($post_data['action'])->
					customerId($post_data['customerId'])->
					allowOriginUrl($post_data['allowOriginUrl'])->
					merchantLandingPageUrl($post_data['merchantLandingPageUrl'])->
					merchantNotificationUrl($post_data['merchantNotificationUrl'])->
					channel($post_data['channel'])->
					language($post_data['language'])->
					amount($post_data['amount'])->
					paymentSolutionId($post_data['paymentSolutionId'])->
					currency($post_data['currency'])->
					country($post_data['country'])->
					customerFirstName($post_data['customerFirstName'])->
					customerLastName($post_data['customerLastName'])->
					customerEmail($post_data['customerEmail'])->
					customerId($post_data['customerId'])->
					userDevice($post_data['userDevice'])->
					userAgent($post_data['userAgent'])->
					customerIPAddress($post_data['customerIPAddress'])->
					customerAddressStreet($post_data['customerAddressStreet'])->
					customerAddressCity($post_data['customerAddressCity'])->
					customerAddressCountry($post_data['customerAddressCountry'])->
					merchantChallengeInd($post_data['merchantChallengeInd'])->
					merchantDecReqInd($post_data['merchantDecReqInd'])->
					merchantLandingPageRedirectMethod($post_data['merchantLandingPageRedirectMethod']);
					//customerPhone may be empty if the admin does not turn on the Telephone Required setting in the back office
					if($post_data['customerPhone']){
						$payments_request->customerPhone($post_data['customerPhone']);
					}
					if($post_data['customerAddressPostalCode']){
						$payments_request->customerAddressPostalCode($post_data['customerAddressPostalCode']);
					}
					$res = $payments_request->token();
					if(isset($res->result) && $res->result == 'success'){

						$data['token'] = $res->token;
						$data['merchantId'] = trim($this->config->get('payment_universalpay_clientid'));
						$testmode = $this->config->get('payment_universalpay_testmode');
						if ($testmode){
							$data['action'] = $this->config->get('payment_universalpay_test_cashier_url');
							$data['java_script_url'] = $this->config->get('payment_universalpay_test_javascript_url');
						}else{
							$data['action'] = $this->config->get('payment_universalpay_cashier_url');
							$data['java_script_url'] = $this->config->get('payment_universalpay_javascript_url');
						}
						//insert the order detail into the gateway order table.
						$gateway_data = array(
							'order_id' => $this->session->data['order_id'],
							'merchant_tx_id' => $post_data['merchantTxId'],
							'total' => $post_data['amount'],
							'currency_code' => $post_data['currency']
						);
						$this->model_extension_universalpay_payment_universalpay->addOrder($gateway_data);
						if($this->config->get('payment_universalpay_pay_type') == 1){
							//iframe payment mode
							$json['action'] = str_replace('&amp;', '&', $this->url->link('extension/universalpay/payment/universalpay|iframe', 'language=' . $this->config->get('config_language')));
						}else if($this->config->get('payment_universalpay_pay_type') == 2){
							//redirect payment mode
							$json['integrationMode'] = self::PAYMENT_STANDALONE;
							$json['action'] = $this->environment_params['baseUrl'];
						}else{
							//hostedpay payment mode
							$json['integrationMode'] = self::PAYMENT_HOSTEDPAYPAGE;
							$json['action'] = $this->environment_params['baseUrl'];
						}
						$json['token'] = $res->token;
						$json['merchantId'] = trim($this->config->get('payment_universalpay_clientid'));
						$this->cart->clear();
						unset($this->session->data['order_id']);
						unset($this->session->data['payment_address']);
						unset($this->session->data['payment_method']);
						unset($this->session->data['payment_methods']);
						unset($this->session->data['shipping_address']);
						unset($this->session->data['shipping_method']);
						unset($this->session->data['shipping_methods']);
						unset($this->session->data['comment']);
						unset($this->session->data['coupon']);
						unset($this->session->data['reward']);
						unset($this->session->data['voucher']);
						unset($this->session->data['vouchers']);
					}else{
						$json['error'] = $this->language->get('text_error_message');
					}
				} catch (\Exception $e) {
					$json['error'] = $this->language->get('text_error_connect_gateway');
				}
			}else{
				$json['error'] = $this->language->get('error_order');
			}
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}
	//prepare the necessary data to send to the gateway
	private function prepareData($order_info){
		$this->load->model('localisation/country');
		$currency_code = $this->session->data['currency'];
		$currency_value = $this->currency->getValue($this->session->data['currency']);
		$post_data = array();
		$post_data['merchantId'] = trim($this->config->get('payment_universalpay_clientid'));
		$post_data['merchantTxId'] = substr(md5(uniqid(mt_rand(), true)), 0, 20);
		$post_data['password'] = trim($this->config->get('payment_universalpay_password'));
		$post_data['brandId'] = trim($this->config->get('payment_universalpay_brandid'));

		$post_data['customerId'] = $order_info['customer_id'];
		$post_data['allowOriginUrl'] = $this->getAllowOriginUrl();
		$post_data['merchantLandingPageUrl'] = str_replace('&amp;', '&', $this->url->link('extension/universalpay/payment/universalpay|redirectBack', 'order_id='.$this->session->data['order_id'].'&language=' . $this->config->get('config_language')));
		$post_data['merchantNotificationUrl'] = str_replace('&amp;', '&', $this->url->link('extension/universalpay/payment/universalpay|callback', 'order_id='.$this->session->data['order_id'].'&language=' . $this->config->get('config_language')));
		$post_data['channel'] = 'ECOM';
		$post_data['language'] = substr($this->config->get('config_language'), 0,2);
		$post_data['amount'] = $this->currency->format($order_info['total'], $currency_code, $currency_value, false);
		$post_data['paymentSolutionId'] = '';
		$post_data['currency'] = $currency_code;
		$country_info = $this->model_localisation_country->getCountry($this->config->get('config_country_id'));
		$post_data['country'] = $country_info['iso_code_2'];//get the country from the back office's setting
		$post_data['customerFirstName'] = $order_info['firstname'];
		$post_data['customerLastName'] = $order_info['lastname'];
		$post_data['customerEmail'] = $order_info['email'];
		$post_data['customerPhone'] = $order_info['telephone'];
		$post_data['customerId'] = $order_info['customer_id'];
		$post_data['userDevice'] = 'DESKTOP';
		$post_data['userAgent'] = $order_info['user_agent'];
		if (!empty($order_info['payment_firstname']) && !empty($order_info['payment_lastname']) && !empty($order_info['payment_address_1'])) {
			$post_data['customerAddressCountry'] = $order_info['payment_iso_code_2'];
			$post_data['customerAddressPostalCode'] = $order_info['payment_postcode'];
			$post_data['customerAddressCity'] = $order_info['payment_city'];
			$post_data['customerAddressStreet'] = $order_info['payment_address_1'];
		}else{
			$post_data['customerAddressCountry'] = $order_info['shipping_iso_code_2'];
			$post_data['customerAddressPostalCode'] = $order_info['shipping_postcode'];
			$post_data['customerAddressCity'] = $order_info['shipping_city'];
			$post_data['customerAddressStreet'] = $order_info['shipping_address_1'];
		}
		$ip = $this->getIp();
		if($ip == '::1'){
			$ip = '127.0.0.1';
		}
		$post_data['customerIPAddress'] = $ip;
		$post_data['merchantChallengeInd'] = '01';
		$post_data['merchantDecReqInd'] = 'N';
		$post_data['merchantLandingPageRedirectMethod'] = 'GET';
		return $post_data;
	}
	//iframe payment mode page
	public function iframe(){
		$data['errors']['error_order'] = $this->language->get('error_order');
		if (!isset($this->session->data['order_id'])) {
			$data['errors']['error_order'] = $this->language->get('error_order');
        }

        if (!isset($this->session->data['payment_method']) || $this->session->data['payment_method'] != self::PAYMENT_METHOD) {
			$data['errors']['error_payment_method'] = $this->language->get('error_payment_method');
        }

        if ($this->config->get('payment_universalpay_pay_type') != self::PAYMENT_IFRAME) {
			$data['errors']['error_payment_method'] = $this->language->get('error_payment_method');
        }
		$post = $this->request->post;
		if(!isset($post['token']) || !isset($post['merchantId'])){
			$this->response->redirect($this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language')));
		}
		$data = array();
		$data['token'] = $post['token'];
		$data['merchantId'] = $post['merchantId'];
		$testmode = $this->config->get('payment_universalpay_testmode');
		if ($testmode){
	        $data['baseUrl'] = $this->config->get('payment_universalpay_test_cashier_url');
			$data['java_script_url'] = $this->config->get('payment_universalpay_test_javascript_url');
	    }else{
	        $data['baseUrl'] = $this->config->get('payment_universalpay_cashier_url');
			$data['java_script_url'] = $this->config->get('payment_universalpay_javascript_url');
	    }
		$data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');
		$this->document->setTitle($this->config->get('config_meta_title'));
        $this->document->setDescription($this->config->get('config_meta_description'));
        $this->document->setKeywords($this->config->get('config_meta_keyword'));
		$this->response->setOutput($this->load->view('extension/universalpay/payment/iframe', $data));
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
	//call the Gateway SDK to check for the payment status, the option can be redirect or callback, only callback way will update the opencart system's status
	private function checkStatus($paymentData,$option){
		$universalpay_order_id = $paymentData['universalpay_order_id'];
		$order_id = $paymentData['order_id'];
		$merchantTxId = $paymentData['merchant_tx_id'];
		$amount = $paymentData['total'];
	    $this->load->model('checkout/order');
	    try {
	        $this->initConfig();
	        $payments = (new \Payments\Payments())->environmentUrls($this->environment_params);
	        $status_check = $payments->status_check();
	        $status_check->merchantTxId($merchantTxId)->
	        allowOriginUrl($this->getAllowOriginUrl());
	        $result = $status_check->execute();
	        $this->load->model('checkout/order');
	        $this->load->model('extension/universalpay/payment/universalpay');

	        if(!isset($result->result) || $result->result != 'success'){
	            //the order payment was declined or canceled.
				$this->model_checkout_order->addHistory($order_id, $this->config->get('payment_universalpay_failed_status_id'),'Failed',true);
				return false;
	        }else{
	            if($result->status == 'SET_FOR_CAPTURE' || $result->status == 'CAPTURED'){
					if($option == $this::CHECKSTATUS_CALLBACK){
						//PURCHASE was successful
						$this->model_extension_universalpay_payment_universalpay->updatePaymentData($universalpay_order_id,static::PURCHASED_STATUS);
						$this->model_extension_universalpay_payment_universalpay->addTransaction($universalpay_order_id,'payment', $amount);
						$this->model_checkout_order->addHistory($order_id, $this->config->get('payment_universalpay_success_status_id'),'Paid',true);
					}
					return true;
	            }else if($result->status == 'NOT_SET_FOR_CAPTURE'){
					if($option == $this::CHECKSTATUS_CALLBACK){
						// AUTH was successful
						$this->model_extension_universalpay_payment_universalpay->updatePaymentData($universalpay_order_id,static::AUTHORIZED_STATUS);
						$this->model_extension_universalpay_payment_universalpay->addTransaction($universalpay_order_id,'auth', $amount);
						$this->model_checkout_order->addHistory($order_id, $this->config->get('payment_universalpay_auth_status_id'),'Authorized',true);
					}
					return true;
	            }else{
	                if($result->status == "STARTED" || $result->status == "WAITING_RESPONSE" || $result->status == "INCOMPLETE"){
	                    //Do not handle these order status in the plugin system
						return false;
	                }else{
						if($option == $this::CHECKSTATUS_CALLBACK){
							$this->model_checkout_order->addHistory($order_id, $this->config->get('payment_universalpay_failed_status_id'),'Failed',true);
						}
						return false;
					}
	            }
	        }
	    } catch (\Exception $e) {
	        return false;
	    }
	}
	//redirect the user to this url when the transaction is done
	public function redirectBack(){
	    $this->load->model('checkout/order');
		$this->load->model('extension/universalpay/payment/universalpay');
	    if(!isset($this->request->get['merchantTxId']) || !isset($this->request->get['order_id'])){
	        $this->response->redirect($this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language')));
	    }else{
	        $order_id = $this->request->get['order_id'];
	        $merchantTxId = $this->request->get['merchantTxId'];
			$paymentData = $this->model_extension_universalpay_payment_universalpay->getPaymentData($order_id,$merchantTxId);
			if(empty($paymentData)){
				//there is no record of this transaction yet
				$this->response->redirect($this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language')));
			}else{
				if($paymentData['capture_status'] == static::PURCHASED_STATUS || $paymentData['capture_status'] == static::AUTHORIZED_STATUS){
					//this transaction was authorized or purchased already, so no need to check the status again
					$this->response->redirect($this->url->link('checkout/success', 'language=' . $this->config->get('config_language')));
				}else{
					$res = $this->checkStatus($paymentData,$this::CHECKSTATUS_REDIRECT);
					if($res){
						$this->response->redirect($this->url->link('checkout/success', 'language=' . $this->config->get('config_language')));
					}else{
						$this->response->redirect($this->url->link('checkout/failure', 'language=' . $this->config->get('config_language')));
					}
				}
			}
	    }
	}
    //Gateway callback to notify the server
	public function callback(){
	    $this->load->model('checkout/order');
		$this->load->model('extension/universalpay/payment/universalpay');
	    $post = $this->request->post;
	    if(!isset($post['merchantTxId']) || !isset($this->request->get['order_id'])){
			$this->response->addHeader('HTTP/1.1 200 OK');
			$this->response->addHeader('Content-Type: application/json');
	        die('Illegal Access');
	    }else{
	        $order_id = $this->request->get['order_id'];
	        $merchantTxId = $post['merchantTxId'];
	    }
	    //the server will also call back the notification when  refund are made, this is to ignore the other action, only purchase
	    if($post['action'] != 'PURCHASE' && $post['action'] != 'AUTH' && $post['action'] != 'CAPTURE'){
	        $this->response->addHeader('HTTP/1.1 200 OK');
	        $this->response->addHeader('Content-Type: application/json');
	    }else{
			$paymentData = $this->model_extension_universalpay_payment_universalpay->getPaymentData($order_id,$merchantTxId);
			if(empty($paymentData)){
				//there is no record of this transaction yet
				$this->response->addHeader('HTTP/1.1 200 OK');
	        	$this->response->addHeader('Content-Type: application/json');
			}else{
				if($paymentData['capture_status'] == static::PURCHASED_STATUS || $paymentData['capture_status'] == static::AUTHORIZED_STATUS){
					//this transaction was authorized or purchased already, so no need to check the status again
					$this->response->addHeader('HTTP/1.1 200 OK');
	        		$this->response->addHeader('Content-Type: application/json');
				}else{
					$this->checkStatus($paymentData,$this::CHECKSTATUS_CALLBACK);
					$this->response->addHeader('HTTP/1.1 200 OK');
	        		$this->response->addHeader('Content-Type: application/json');
				}
			}
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
	private function getIp() 
    {
        if (isset($_SERVER["HTTP_CLIENT_IP"])) {
            $ip = $_SERVER["HTTP_CLIENT_IP"];
        } elseif (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
        } else {
            $ip = $_SERVER["REMOTE_ADDR"];
        }

        return $ip;
    }
}
