<?php
class ControllerExtensionPaymentTwentyfourpay extends Controller {

  public function index() {
    $this->language->load('extension/payment/twentyfourpay');
    $this->load->model('checkout/order');

    if ($this->config->get('payment_twentyfourpay_debug'))
      $data['debug'] = true;
    else
      $data['debug'] = false;


    if ($this->config->get('payment_twentyfourpay_notify')){
      $data['notify'] = true;
      $data['NotifyEmail'] = $this->config->get('payment_twentyfourpay_email');
    }
    else{
      $data['notify'] = false;
      $data['NotifyEmail'] = "";
    }

    if ($this->config->get('payment_twentyfourpay_test'))
      $data['action'] = 'https://test.24-pay.eu/pay_gate/paygt';
    else
      $data['action'] = 'https://admin.24-pay.eu/pay_gate/paygt';

    $data['button_confirm'] = $this->language->get('button_confirm');

    /*
    echo "<pre>";
    print_r($this->generateData());
    echo "</pre>";
    */

    $formData = $this->generateData();

    $data['token_error'] = $this->language->get('token_error');

    $data['Mid'] = $formData['config']['mid'];
    $data['EshopId'] = $formData['config']['eshopid'];
    $data['MsTxnId'] = $formData['order']['mstxnid'];
    $data['Amount'] = $formData['order']['amount'];
    $data['CurrAlphaCode'] = $formData['order']['currency'];
    $data['ClientId'] = $formData['customer']['id'];
    $data['FirstName'] = $formData['customer']['firstname'];
    $data['FamilyName'] = $formData['customer']['familyname'];
    $data['Email'] = $formData['customer']['email'];
    $data['Country'] = $formData['customer']['country'];
    $data['Timestamp'] = $formData['secret']['timestamp'];
    $data['Sign'] = $formData['secret']['sign'];

    $data['NURL'] = $formData['settings']['nurl'];
    $data['RURL'] = $formData['settings']['rurl'];

    if ($this->config->get('payment_twentyfourpay_notify_client')) {
      $data['clientNotify'] = true;
      $data['NotifyClient'] = $formData['customer']['email'];
    }
    return $this->load->view('extension/payment/twentyfourpay', $data);
  }

  private function generateData(){

    $this->load->model('checkout/order');
    $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
    $orderAmount = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
    $orderAmount = (float)$orderAmount * pow(10,(int)$this->currency->getDecimalPlace($order_info['currency_code']));
    $orderAmount = intval(strval($orderAmount));

    if($order_info['customer_id'] == 0)
      $customer_id = "GUEST". (1000+rand(10,999));
    else
      $customer_id = $order_info['customer_id'];

    $customer_array =  array (
      'id'          => $customer_id,
      'firstname'   => strlen($order_info['payment_firstname']) > 0 ? $order_info['payment_firstname'] : "empty",
      'familyname'  => strlen($order_info['payment_lastname']) > 0 ? $order_info['payment_lastname'] : "empty",
      'country'     => strlen($order_info['payment_iso_code_3']) > 0 ? $order_info['payment_iso_code_3'] : "SVK",
      'phone'       => strlen($order_info['telephone']) > 0 ? $order_info['telephone'] : null,
      'email'       => strlen($order_info['email']) > 0 ? $order_info['email'] : null,
      'ip'          => $this->request->server['REMOTE_ADDR']
    );

    if (in_array($order_info['payment_iso_code_2'], array('US','CA'))) {
      $customer_array['state'] = $order_info['payment_zone_code'];
    }

    $order_array = array (
      'currency'=> $order_info['currency_code'],
      'amount'      => number_format(($orderAmount/100), 2, '.', ''),
      'description' => $this->language->get('text_order'). ' ' .$order_info['order_id'],
      'mstxnid'     => $order_info['order_id']);

    $setting_array = array (
      'rurl'      =>  $this->url->link('extension/payment/twentyfourpay/rurl', '', 'SSL'),
      'nurl'      =>  $this->url->link('extension/payment/twentyfourpay/nurl', '', 'SSL'),
      'language'  =>  $this->_language($this->session->data['language']),
    );

    $config_array = array(
      'mid'     => $this->config->get('payment_twentyfourpay_mid'),
      'eshopid' => $this->config->get('payment_twentyfourpay_eshopid'),
      'key'     => $this->config->get('payment_twentyfourpay_key'),
    );

    $timestamp = date("Y-m-d H:i:s");

    $secret_array = array(
      'message'   => $config_array['mid'].$order_array['amount'].$order_array['currency'].$order_array['mstxnid'].$customer_array['firstname'].$customer_array['familyname'].$timestamp,
      'timestamp' => $timestamp,
      'sign'      => '',
    );

    $checkout = array(
      'config'    => $config_array,
      'settings'  => $setting_array,
      'order'     => $order_array,
      'customer'  => $customer_array,
      'secret'    => $secret_array,
      'fullorder' => $order_info,
      );

    $checkout = $this->sign($checkout);

    return $checkout;
  }

  private function sign($data){
    $message = $data['secret']['message'];
    $mid = $data['config']['mid'];
    $key = $data['config']['key'];

    $hash = hash("sha1", $message, true);
  	$iv = $mid . strrev($mid);

  	$key = pack('H*', $key);

  	if ( PHP_VERSION_ID >= 50303 && extension_loaded( 'openssl' ) ) {
  		$crypted = openssl_encrypt( $hash, 'AES-256-CBC', $key, 1, $iv );
  	} else {
  		$crypted = mcrypt_encrypt( MCRYPT_RIJNDAEL_128, $key, $hash, MCRYPT_MODE_CBC, $iv );
  	}

    $data['secret']['sign'] = strtoupper(bin2hex(substr($crypted, 0, 16)));
    return $data;
  }

  //RURL
  public function rurl() {

    if (isset($this->session->data['order_id'])) {
      $order_id = $this->session->data['order_id'];
    } else {
      $order_id = 0;
    }

    $this->load->model('checkout/order');
    $order_info = $this->model_checkout_order->getOrder($order_id);

    $get_order_id = $this->request->get["MsTxnId"];

    $total = $this->request->get["Amount"];
    $currency_code = $this->request->get["CurrCode"];
    $result = $this->request->get["Result"];

    switch ($result) {
        case "OK":
        case "PENDING":
                $this->response->redirect($this->url->link('checkout/success'));
                break;

        case "FAIL":
                $this->response->redirect($this->url->link("checkout/cart"));
                break;
    }
    die("Invalid arguments");

  }

  //NURL
  public function nurl() {

    if (isset($_POST["params"])) {
        $params = $_POST["params"];
    } else {
        echo "Invalid notification params";
        die();
    }

    $twentyfourpay_notification = $this->parseNotification($params);

    if (!$twentyfourpay_notification['Valid']){
        die("Invalid response from 24pay gateway");
        print_r($twentyfourpay_notification);
    }

    $this->load->model('checkout/order');

    $order_id = $twentyfourpay_notification['MsTxnId'];

    $order_info = $this->model_checkout_order->getOrder($order_id);
    $result = $twentyfourpay_notification['Result'];
    $transaction_id = $twentyfourpay_notification['PspTxnId'];

    $this->log->write("Webhook received: $postData");

    $this->load->model('checkout/order');

    $order_info = $this->model_checkout_order->getOrder($order_id);

    if ($order_info) {

      switch ($result) {
          case "PENDING":
            $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_twentyfourpay_pending_status_id'), "24PAY-ID: $transaction_id. Processor message: PENDING", true);
          break;
          case "OK":
            $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_twentyfourpay_completed_status_id'), "24PAY-ID: $transaction_id. Processor message: OK", true);
          break;
          case "FAIL": default:
            $this->model_checkout_order->addOrderHistory($order_id, $this->config->get('payment_twentyfourpay_failed_status_id'), "24PAY-ID: $transaction_id. Processor message: FAIL", true);
          break;
      }
    }
  }

  private function parseNotification($params){
            if (get_magic_quotes_gpc())
                $params = stripslashes($params);

            $params = trim(preg_replace("/^\s*<\?xml.*?\?>/i", "", $params));

            $xml = new SimpleXMLElement($params);

            $result = array();

            if ($xml->count()==1){

                $this->load->model('setting/setting');

                $setting = $this->model_setting_setting->getSetting('opc24pay');

                $mid = $this->config->get('payment_twentyfourpay_mid');
                $key = $this->config->get('payment_twentyfourpay_key');

                // SIGN
                $node = $xml[0];
                $attributes = $node->attributes();
                $result['Sign'] = (string) $attributes["sign"];
                // AMOUNT
                $result['Amount'] = (string) $xml->Transaction->Presentation->Amount;
                // CURRENCY
                $result['Currency'] = (string) $xml->Transaction->Presentation->Currency;
                // PSPTXNID
                $result['PspTxnId'] = $xml->Transaction->Identification->PspTxnId;
                // MSTXNID
                $result['MsTxnId'] = (string) $xml->Transaction->Identification->MsTxnId;
                // TIMESTAMP
                $result['Timestamp'] =  (string) $xml->Transaction->Processing->Timestamp;
                // RESULT
                $result['Result'] = (string) $xml->Transaction->Processing->Result;

                $message = $mid.$result['Amount'].$result['Currency'].$result['PspTxnId'].$result['MsTxnId'].$result['Timestamp'].$result['Result'];

                $signCandidate = $this->computeSign($message,$mid,$key);
                if ($signCandidate == $result['Sign']){
                    $result['Valid'] = true;
                }
                else{
                    $result['ValidSign'] = $signCandidate;
                }
            }
            else{
                $result['Valid'] = false;
            }

            return $result;
  }

  private function computeSign($message, $mid, $key){
        $hash = hash("sha1", $message, true);
        $iv = $mid . strrev($mid);

        $key = pack('H*', $key);

        if ( PHP_VERSION_ID >= 50303 && extension_loaded( 'openssl' ) ) {
                $crypted = openssl_encrypt( $hash, 'AES-256-CBC', $key, 1, $iv );
        } else {
                $crypted = mcrypt_encrypt( MCRYPT_RIJNDAEL_128, $key, $hash, MCRYPT_MODE_CBC, $iv );
        }
        $sign = strtoupper(bin2hex(substr($crypted, 0, 16)));

        return $sign;
  }

  private function _language($lang_id) {
    $lang = substr($lang_id, 0, 2);
    $lang = strtolower($lang);
    return $lang;
  }
}
