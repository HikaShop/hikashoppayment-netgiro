<?php
/**
 * @version	1.0.0
 * @author	Stefan Novakovic
 * @copyright Copyright (C) 2013 Program5 - All rights reserved.
 * @license	GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');

// Signals which pyment option to show
class paymentOptionsCls {
	public $showP1;
	public $showP2;
	public $showP3;
};

/*
 *
 */
class plgHikashoppaymentNetgiro extends hikashopPaymentPlugin {
	
	//Important! plugin back-end seting page will not work without it
	var $multiple = true;

	var $accepted_currencies = array('ISK');
	var $name = 'netgiro';

	var $pluginConfig = array (
		'application_id' => array('Application Id', 'input'),
		'secret_key' => array('SECRET_KEY', 'input'),
		'max_installments' => array('Max Number Of Installments', 'input'),
		'mode' => array('MODE', 'list',array(
			'TEST' => 'Test',
			'LIVE' => 'Live')
		),
		'payment_opt1' => array('14 days payment (default)', 'boolean','0'),
		'payment_opt2' => array('Partial payments.', 'boolean','0'),
		'payment_opt3' => array('Partial payments without interest.', 'boolean','0'),
	);

	/*
	 * Check for required configuration before proceeding
	 */
	function onBeforeOrderCreate(&$order,&$do) {
		if (parent::onBeforeOrderCreate($order, $do) === true)
			return true;

		if (empty($this->payment_params->secret_key) || empty($this->payment_params->application_id)) {
			$this->app->enqueueMessage('Missing merchant identifier! Please check your &quot;Netgiro&quot; plugin configuration.');
			$do = false;
		}

	}

	/*
	 * Generate POST values for submitting to Netgiro
	 */
	function onAfterOrderConfirm(&$order,&$methods,$method_id) {
		parent::onAfterOrderConfirm($order,$methods,$method_id);

		//Order Header values
		$totalAmount = round($order->order_full_price, (int)$this->currency->currency_locale['int_frac_digits']);
		$orderId = $order->order_id;

		//Option values
		$paymentSuccessfulURL = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout&task=notify&notif_payment=netgiro&lang=' . $this->locale . $this->url_itemid;
		$cancelUrl = HIKASHOP_LIVE.'index.php?option=com_hikashop&ctrl=order&task=cancel_order&order_id='. $orderId . $this->url_itemid;

		$applicationId = $this->payment_params->application_id;
		$secretKey = $this->payment_params->secret_key;
		$maxInstallments = $this->payment_params->max_installments;

		$returnCustomerInfo = 'false';

		/*showP1 = show pay later option (14 days delay in payment)
		 *showP2 = show partial payments
		 *showP3 = show partial payments without interest
		 */
		$paymentOptions = new paymentOptionsCls();
   		$paymentOptions->showP1 = $this->payment_params->payment_opt1;
   		$paymentOptions->showP2 = $this->payment_params->payment_opt2;
   		$paymentOptions->showP3 = $this->payment_params->payment_opt3;

 		//Signature - Signature for the message, calculated as SHA256(SecretKey + OrderId + TotalAmount + ApplicationId)
		$signature = hash('sha256', $secretKey . $orderId . $totalAmount . $applicationId);

		switch( $this->payment_params->mode ) {
			case 'LIVE':
				$netGiropaymentUrl = 'https://www.netgiro.is/SecurePay';
				break;
			case 'TEST':
				$netGiropaymentUrl = 'http://test.netgiro.is/user/securepay';
				break;
			default:
				$netGiropaymentUrl = 'http://test.netgiro.is/user/securepay';
				break;
		}

		// Values that will be posted to Netgiro
		$vars = array (
			'ApplicationID' => $applicationId,
			'Signature' => $signature,
			'PaymentSuccessfulURL' => $paymentSuccessfulURL,
			'PaymentCancelledURL' => $cancelUrl,
			'ReturnCustomerInfo' => 'false',
			'Iframe' => 'false',
			'OrderId' => $orderId,
			'TotalAmount' => $totalAmount,
			'MaxNumberOfInstallments' => $maxInstallments
		);

		//Order Item values
		$n = 0;
		foreach ($order->cart->products as $product) {

			$productPrice = round($product->order_product_price, (int)$this->currency->currency_locale['int_frac_digits']);
			$tax = round($product->order_product_tax, (int)$this->currency->currency_locale['int_frac_digits']);
			$unitPrice = $productPrice + $tax;

			$productAmount = round($product->order_product_total_price, (int)$this->currency->currency_locale['int_frac_digits']);

			//Quantity should be passed in 1/1000 units. For example if the quantity is 2 then it should be represented as 2000
			$quantity = $product->order_product_quantity * 1000;

			$vars["Items[$n].ProductNo"] = $product->order_product_code;
			$vars["Items[$n].Name"] = $product->order_product_name;
			$vars["Items[$n].UnitPrice"] = $unitPrice;
			$vars["Items[$n].Quantity"] = $quantity;
			$vars["Items[$n].Amount"] = $productAmount;

			$n++;
		}

		$this->vars = $vars;
		$this->netGiropaymentUrl = $netGiropaymentUrl;
		$this->paymentOptions = $paymentOptions;
		$this->appId = $applicationId;

		//call the netgiro_end.php
		return $this->showPage('end');
	}

	function onPaymentNotification(&$statuses) {

		$vars = array();

		$filter = JFilterInput::getInstance();

		foreach($_REQUEST as $key => $value) {
			$key = $filter->clean($key);
			$value = JRequest::getString($key);
			$vars[$key]=$value;
		}

		if(!isset($vars['orderid'])) {
			$this->redirect(HIKASHOP_LIVE . "index.php");
		}

		$orderId = (int)@$vars['orderid'];

		//Check return values from Netgiro
		if(!isset($vars['signature']) || !isset($vars['invoiceNumber']) || !isset($vars['confirmationCode']) ) {
			$this->cancelPayment("Missing required parameters from Netgiro response", $orderId);
		}

		$invoiceNumber = $vars['invoiceNumber'];
		$confirmationCode = $vars['confirmationCode'];
		$netproSignature = $vars['signature'];

		$dbOrder = $this->getOrder($orderId);
	 	$this->loadPaymentParams($dbOrder);

		if(empty($this->payment_params) ) {
			$this->cancelPayment("Payment params are empty for order ID: $orderId", $orderId);
		}

		$this->loadOrderData($dbOrder);

		if(empty($dbOrder)) {
			$this->cancelPayment("There is no data in database for order ID: $orderId", $orderId);
		}

		$history = new stdClass();
		$history->notified=0;
		$history->data = "Netgiro Invoice Number: $invoiceNumber <br>
						  Netgiro Confirmation Code: $confirmationCode";

		$url = HIKASHOP_LIVE . "administrator/index.php?option=com_hikashop&ctrl=order&task=edit&order_id=$orderId";
		$order_text = "\r\n" . JText::sprintf('NOTIFICATION_OF_ORDER_ON_WEBSITE', $dbOrder->order_number, HIKASHOP_LIVE);
		$order_text .= "\r\n" . str_replace('<br/>', "\r\n", JText::sprintf('ACCESS_ORDER_WITH_LINK', $url));

		$order_text .= "\r\n \r\nNetgiro Link: https://www.netgiro.is/\r\nNetgiro Invoice Number: $invoiceNumber\r\nNetgiro Confirmation Code: $confirmationCode";

		$order_status = 'confirmed';

		$email = new stdClass();
		$email->subject = JText::sprintf('PAYMENT_NOTIFICATION_FOR_ORDER', 'Netgiro', 'Success', $dbOrder->order_number);
		$body = str_replace('<br/>', "\r\n", JText::sprintf('PAYMENT_NOTIFICATION_STATUS', 'Netgiro', 'Success')) . ' ' . JText::sprintf('ORDER_STATUS_CHANGED', $order_status) . "\r\n\r\n" . $order_text;
		$email->body = $body;

		$this->modifyOrder($orderId, $order_status , $history, $email);

		$returnUrl = HIKASHOP_LIVE."index.php?option=com_hikashop&ctrl=checkout&task=after_end&orderId=$orderId";
		$this->app->redirect($returnUrl);

		return true;

	}

	function getPaymentDefaultValues(&$element) {
		$element->payment_name = 'Netgíró';
		$element->payment_description = 'Öll erum við mismunandi. Greiðslumáti sem hentar einum hentar kannski ekki öðrum. Þess vegna bjóðum við upp á mismunandi greiðslumöguleika, þú velur það sem hentar þér best.';
		$element->payment_images = '';
		$element->payment_images= 'netgiro';

		$element->payment_params->invalid_status = 'cancelled';
		$element->payment_params->pending_status = 'created';
		$element->payment_params->verified_status = 'confirmed';

	}

	function cancelPayment($notificationErrorText , $orderId) {

		$orderUrl = HIKASHOP_LIVE . "administrator/index.php?option=com_hikashop&ctrl=order&task=edit&order_id=$orderId";

		$email = new stdClass();
		$email->subject = JText::sprintf('NOTIFICATION_REFUSED_FOR_THE_ORDER', ' Netgiro '.'invalid response,');
		$email->body = JText::sprintf("Hello,\r\nA payment notification was refused because the response from the Netgiro server was invalid") .
					   "\r\n" . str_replace('<br/>', "\r\n", JText::sprintf('ACCESS_ORDER_WITH_LINK', $orderUrl));
					  // "\r\n\r\n" . $notificationErrorText;

		$history = new stdClass();
		$history->notified=0;
		$history->data = $notificationErrorText;

		$this->modifyOrder($orderId, 'cancelled', $history, $email);

		$this->redirect(HIKASHOP_LIVE . "index.php");
	}

	function redirect($url) {
		header("Location: $url");
		die();
	}
}