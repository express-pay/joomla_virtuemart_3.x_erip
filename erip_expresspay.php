<?php

if (!defined('_VALID_MOS') && !defined('_JEXEC'))
    die('Direct Access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists('vmPSPlugin')) require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');


class plgVMPaymentErip_Expresspay extends vmPSPlugin {
	public static $_this = false;
	private $template = "";

	function __construct(& $subject, $config) {
		parent::__construct($subject, $config);
		$this->_loggable = true;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id'; 
		$this->_tableId = 'id'; 
		$varsToPush = $this->getVarsToPush();
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

    protected function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment ERIP ExpressPay Table');
    }
    
    function getTableSQLFields() {
		$SQLfields = array(
	    	'id' 							=> 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id' 			=> 'int(1) UNSIGNED', 
	    	'order_number'              	=> 'char(64)',
	    	'virtuemart_paymentmethod_id' 	=> 'mediumint(1) UNSIGNED',
	    	'payment_name' 					=> 'char(255) NOT NULL DEFAULT \'\' ',
	    	'payment_order_total'       	=> 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
	    	'payment_currency' 				=> 'char(3) ',
	    	'cost_per_transaction' 			=> 'decimal(10,2)',
	    	'cost_percent_total' 			=> 'char(10)',
	    	'tax_id' 						=> 'smallint(1)'
		);

		return $SQLfields;
    }
    
    function plgVmConfirmedOrder($cart, $order) {
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)))
	    	return null;

		if (!$this->selectedThisElement($method->payment_element))
	    	return false;

	    $this->log_info('plgVmConfirmedOrder', 'Initialization request for add invoice');
		
		$session = JFactory::getSession();
		$return_context = $session->getId();
		
		if (!class_exists('VirtueMartModelOrders'))
	    	require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );

	    if (!class_exists('VirtueMartModelCurrency'))
	    	require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');

		$this->getPaymentCurrency($method);
		$query = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = JFactory::getDBO();
		$db->setQuery($query);
		$currency_code_3 = $db->loadResult();
		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency);

		$this->_virtuemart_paymentmethod_id      = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['payment_name']                = $method->payment_name;
		$dbValues['order_number']                = $order['details']['BT']->order_number;
		$dbValues['virtuemart_paymentmethod_id'] = $this->_virtuemart_paymentmethod_id;
		$dbValues['cost_per_transaction']        = $method->cost_per_transaction;
		$dbValues['cost_percent_total']          = $method->cost_percent_total;
		$dbValues['payment_currency']            = $currency_code_3;
		$dbValues['payment_order_total']         = $totalInPaymentCurrency;
		$dbValues['tax_id']                      = $method->tax_id;
		$this->storePSPluginInternalData($dbValues);

		$secret_word = $method->secret_key;
		$is_use_signature = ( $method->sign_invoices == 1 ) ? true : false;
		$url = ( $method->test_mode == 1 ) ? $method->url_sandbox_api : $method->url_api;
		$url .= "/v1/invoices?token=" . $method->token;

		$order_id = $order['details']['BT']->virtuemart_order_id; //virtuemart_order_id or order_number

		$currency = (date('y') > 16 || (date('y') >= 16 && date('n') >= 7)) ? '933' : '974';

        $request_params = array(
            "AccountNo" => $order_id, 
            "Amount" => $totalInPaymentCurrency['value'],
            "Currency" => $currency,
            "Surname" => $order['details']['BT']->last_name,
            "FirstName" => $order['details']['BT']->first_name,
            "City" => $order['details']['BT']->city,
            "IsNameEditable" => ( ( $method->name_editable == 1 ) ? 1 : 0 ),
            "IsAddressEditable" => ( ( $method->address_editable == 1 ) ? 1 : 0 ),
            "IsAmountEditable" => ( ( $method->amount_editable == 1 ) ? 1 : 0 )
        );

        if($is_use_signature)
        	$url .= "&signature=" . $this->compute_signature_add_invoice($request_params, $secret_word, $method);

        $request_params = http_build_query($request_params);

        $this->log_info('plgVmConfirmedOrder', 'Send POST request; ORDER ID - ' . $order_id . '; URL - ' . $url . '; REQUEST - ' . $request_params);

        $response = "";

        try {
	        $ch = curl_init();
	        curl_setopt($ch, CURLOPT_URL, $url);
	        curl_setopt($ch, CURLOPT_POST, 1);
	        curl_setopt($ch, CURLOPT_POSTFIELDS, $request_params);
	        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	        $response = curl_exec($ch);
	        curl_close($ch);
    	} catch (Exception $e) {
			$this->log_error_exception('plgVmConfirmedOrder', 'Get response; ORDER ID - ' . $order_id . '; RESPONSE - ' . $response, $e);
    	}

    	$this->log_info('plgVmConfirmedOrder', 'Get response; ORDER ID - ' . $order_id . '; RESPONSE - ' . $response);

		try {
        	$response = json_decode($response);
    	} catch (Exception $e) {
			$this->log_error_exception('plgVmConfirmedOrder', 'Get response; ORDER ID - ' . $order_id . '; RESPONSE - ' . $response, $e);
    	}

        if(isset($response->InvoiceNo)) {
        	$status = $this->success($cart, $method, $order_id);

        	$this->processConfirmedOrderPaymentResponse(true, $cart, $order, $this->template, $method->payment_name, $status);
        } else {
			$this->log_info('plgVmConfirmedOrder', 'End request for add invoice');
			$this->log_info('plgVmConfirmedOrder', 'Initialization render fail page; ORDER ID - ' . $order_id);

			$this->handlePaymentUserCancel($order_id);

        	$this->log_info('plgVmConfirmedOrder', 'End render fail page; ORDER ID - ' . $order_id);

			VmError('VMPAYMENT_MESSAGE_ERROR', 'VMPAYMENT_MESSAGE_ERROR');

			$this->processConfirmedOrderPaymentResponse(false, $cart, $order, $this->template, $method->payment_name, $method->status_canceled);
        }

		return false;
    }

	private function success($cart, $method, $order_id) {
		$this->log_info('plgVmConfirmedOrder', 'End request for add invoice');
		$this->log_info('success', 'Initialization render success page; ORDER ID - ' . $order_id);
		$cart->emptyCart();

		$signature_success = $signature_cancel = "";

		if($method->is_use_signature) {
			$secret_word = $method->secret_key_notify;
			$signature_success = $this->compute_signature('{"CmdType": 1, "AccountNo": ' . $order_id . '}', $secret_word);
			$signature_cancel = $this->compute_signature('{"CmdType": 2, "AccountNo": ' . $order_id . '}', $secret_word);
		}

		$url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component');

		$message_success = nl2br($method->message_success, true);
		$message_success = str_replace("##order_id##", $order_id, $message_success);

		$virtuemart_category_id = shopFunctionsF::getLastVisitedCategoryId();
		
		$categoryLink = $ItemIdLink = '';

		if ($virtuemart_category_id)
			$categoryLink = '&virtuemart_category_id=' . $virtuemart_category_id;

		$ItemId = shopFunctionsF::getLastVisitedItemId();

		if ($ItemId)
			$ItemIdLink = '&Itemid=' . $ItemId;

		$continue_link = JRoute::_('index.php?option=com_virtuemart&view=category' . $categoryLink . $ItemIdLink, FALSE);

		$this->template = "<p>" . JText::_('VMPAYMENT_YOU_ORDER_NUMBER') . $order_id . "</p>";
		$this->template .= "<div class='info'>{$message_success}</div>";
		$this->template .= '<div class="buttonBar-right"><a  href="' . $continue_link . '" class="vm-button-correct">' . JText::_('VMPAYMENT_CONTINUE') . '</a></div>';

		$test_mode_label = JText::_('VMPAYMENT_TEST_MODE_FRONTEND');
		$success_label = JText::_('VMPAYMENT_SUCCESS_BTN_LABEL');
		$cancel_label = JText::_('VMPAYMENT_CANCEL_BTN_LABEL');

		if($method->test_mode) {
			$this->template .= <<<EOD
  <div style="display: inline-block; margin-top: 10px;border: 1px solid #333;border-radius: 10px;padding: 10px;">
    {$test_mode_label}<br/>
    <input style="margin: 10px 0; width: 100%;" type="button" id="send_notify_success" class="vm-button-correct" value="{$success_label}" />
    <input type="button" style="width: 100%;" id="send_notify_cancel" class="vm-button-correct" value="{$cancel_label}" />
  </div>

  <script type="text/javascript">
    jQuery(document).ready(function() {
      jQuery('#send_notify_success').click(function() {
        send_notify(1, '{$signature_success}');
      });

      jQuery('#send_notify_cancel').click(function() {
        send_notify(2, '{$signature_cancel}');
      });

      function send_notify(type, signature) {
        jQuery.post('{$url}', 'Data={"CmdType": ' + type + ', "AccountNo": "{$order_id}"}&Signature=' + signature, function(data) {alert(data);})
        .fail(function(data) {
          alert(data.responseText);
        });
      }
    });
  </script>
<?php endif; ?>
EOD;
		}

		$this->log_info('success', 'End render success page; ORDER ID - ' . $order_id);

		return $method->status_pending;
	}

	private function compute_signature($json, $secret_word) {
	    $hash = NULL;
	    $secret_word = trim($secret_word);
	    
	    if(empty($secret_word))
			$hash = strtoupper(hash_hmac('sha1', $json, ""));
	    else
	        $hash = strtoupper(hash_hmac('sha1', $json, $secret_word));

	    return $hash;
	}	

    private function compute_signature_add_invoice($request_params, $secret_word, $method) {
    	$secret_word = trim($secret_word);
        $normalized_params = array_change_key_case($request_params, CASE_LOWER);
        $api_method = array(
                "accountno",
                "amount",
                "currency",
                // "expiration",
                // "info",
                "surname",
                "firstname",
                // "patronymic",
                "city",
                // "street",
                // "house",
                // "building",
                // "apartment",
                "isnameeditable",
                "isaddresseditable",
                "isamounteditable"
        );

        $result = $method->token;

        foreach ($api_method as $item)
            $result .= ( isset($normalized_params[$item]) ) ? $normalized_params[$item] : '';

        $hash = strtoupper(hash_hmac('sha1', $result, $secret_word));

        return $hash;
    }

	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) {
		if (!$this->selectedThisByMethodId($virtuemart_payment_id))
	    	return null;
    
		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` ' . 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery($q);

		if (!($paymentTable = $db->loadObject())) {
	    	vmWarn(500, $q . " " . $db->getErrorMsg());
	    	return '';
		}

		$this->getPaymentCurrency($paymentTable);

		$html = '<table class="adminlist table">' . "\n";
		$html .= $this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('ERIP_EXPRESSPAY_PAYMENT_NAME', $paymentTable->payment_name);
		$html .= '</table>' . "\n";

		return $html;
    }

	function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
		if (preg_match ('/%$/', $method->cost_percent_total))
			$cost_percent_total = substr ($method->cost_percent_total, 0, -1);
		else
			$cost_percent_total = $method->cost_percent_total;
		
		return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
	}

	protected function checkConditions($cart, $method, $cart_prices) {
	    return true;
    }

    function plgVmOnPaymentResponseReceived(&$html) {
		return true;
    }
     
    function plgVmOnUserPaymentCancel() {
		return null;
    }
	
    function plgVmOnPaymentNotification() {
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			if (!class_exists('VirtueMartModelOrders'))
		    	require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );

		    $post = JRequest::get('post');
		    $order_id = 0;

		    try {
			    $order_id = $post['Data']->AccountNo;
			} catch (Exception $e) {

			}

			$payment = $this->getDataByOrderId($order_id);
			$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);

			$secret_word = $method->secret_key_notify;
			$is_use_signature = ( $method->sign_notify == 1 ) ? true : false;
			$data = ( isset($post['Data']) ) ? htmlspecialchars_decode($post['Data']) : '';
			$signature = ( isset($post['Signature']) ) ? $post['Signature'] : '';
		    
		    if($is_use_signature) {
		    	if($signature == $this->compute_signature($data, $secret_word))
			        $this->notify_success($data);
			   else  
			    	$this->notify_fail($data);
		    } else 
		    	$this->notify_success($data);
		}

		die();
    }

	private function notify_success($dataJSON) {
		try {
        	$data = json_decode($dataJSON);
    	} catch(Exception $e) {
    		$this->log_error('notify_success', "Fail to parse the server response; RESPONSE - " . $dataJSON);
    		$this->notify_fail($dataJSON);
    	}

        if(isset($data->CmdType)) {
        	switch ($data->CmdType) {
        		case '1':
					if (!class_exists('VirtueMartModelOrders'))
				    	require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );

					$order_mer_code = $data->AccountNo;

					$payment = $this->getDataByOrderId($order_mer_code);
					$method = $this->getVmPluginMethod($payment->virtuemart_paymentmethod_id);

					if (!class_exists('CurrencyDisplay'))
						require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');

					$this->getPaymentCurrency($method);
					$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
					$order_model  = new VirtueMartModelOrders();
					$order_info   = $order_model->getOrder($order_mer_code);
					$new_status = $method->status_success;	
					$modelOrder = VmModel::getModel('orders');					
					$order = array();
					$order['order_status'] = $new_status;
					$order['customer_notified'] = 1;
					$order['comments'] = 'expresspay';

					try {
						$modelOrder->updateStatusForOneOrder($order_mer_code, $order, true);	
					} catch (Exception $e) {

				    	header("HTTP/1.0 200 OK");
				    	echo 'SUCCESS';
	    	
						die();
					}
					
        			break;
        		case '2':
        			$this->handlePaymentUserCancel($data->AccountNo);

        			break;
        		default:
					$this->notify_fail($dataJSON);
					
					die();
        	}

	    	header("HTTP/1.0 200 OK");
	    	echo 'SUCCESS';
        } else
			$this->notify_fail($dataJSON);	

		return true;
	}

	private function notify_fail($dataJSON) {
		header("HTTP/1.0 400 Bad Request");
		echo 'FAILED | Incorrect digital signature';
	}
    
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
    }
    
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
		return $this->OnSelectCheck($cart);
    }
    
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
    }	
	
    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }
    
    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)))
	    	return null;

		if (!$this->selectedThisElement($method->payment_element))
	    	return false;

	 	$this->getPaymentCurrency($method);

		$paymentCurrencyId = $method->payment_currency;
    }

    function log_error_exception($name, $message, $e) {
    	$this->log($name, JLog::ERROR , $message . '; EXCEPTION MESSAGE - ' . $e->getMessage() . '; EXCEPTION TRACE - ' . $e->getTraceAsString());
    }

    function log_error($name, $message) {
    	$this->log($name, JLog::ERROR , $message);
    }

    function log_info($name, $message) {
    	$this->log($name, JLog::INFO , $message);
    }

    function log($name, $type, $message) {
    	jimport('joomla.log.log');

    	$file_name = 'erip_expresspay/express-pay-' . date('Y.m.d') . '.php';

		JLog::addLogger(
			array(
				'text_file' => $file_name
			),
			JLog::ALL,
			array('plugin_erip_expresspay')
		);

    	$text = $type . " - IP - " . $_SERVER['REMOTE_ADDR'] . "; USER AGENT - " . $_SERVER['HTTP_USER_AGENT'] . "; FUNCTION - " . $name . "; MESSAGE - " . $message . ';';
		
		JLog::add($text, $type, 'plugin_erip_expresspay');
    }
    
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array()) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }
    
    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
    
    function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
		return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
    }

	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}
}
