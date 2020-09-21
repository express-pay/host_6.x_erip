<?php
/*
Модуль оплаты Экссперс платежи: ЕРИП
 */
class Shop_Payment_System_Handler51 extends Shop_Payment_System_Handler
{
	// Идентификатор валюты в hostCMS
	private $_currency_id = 4;
	//тестовый режим (1 - да, 0 - нет)
	private $isTest = 0;
	/*
		Номер услуги
		Можно узнать в личном кабинете сервиса "Экспресс Платежи" в настройках услуги.
	*/
	private $serviceId = 4160;
	/*
		Токен
		Можно узнать в личном кабинете сервиса "Экспресс Платежи" в настройках услуги.
	*/
	private $token = "fa1343d66c2dc33a77900ef9b88a7aca";
	/*
		Использовать цифровую подпись для выставления счетов (1 - да, 0 - нет)
		Значение должно совпадать со значением, установленным в личном кабинете сервиса "Экспресс Платежи".
	*/
	private $isUseSignature = 1;
	/*
		Секретное слово
		Задается в личном кабинете, секретное слово должно совпадать с секретным словом, установленным в личном кабинете сервиса "Экспресс Платежи".
	*/
	private $secretWord = "express-pay.by";
	/*
		Использовать цифровую подпись для уведомлений (1 - да, 0 - нет)
		Значение должно совпадать со значением, установленным в личном кабинете сервиса "Экспресс Платежи".
	*/
	private $isUseSignatureForNotif = 0;
	/*
		Секретное слово для уведомлений
		Задается в личном кабинете, секретное слово должно совпадать с секретным словом, установленным в личном кабинете сервиса "Экспресс Платежи".
	*/
	private $secretWordForNotif = "";

	//Показывать ли QR-код (1 - да, 0 - нет)
	private $showQrCode = 0;

	//Путь по ветке ЕРИП
	private $eripPath = "Бла бла бла";

	//Разрешено изменять ФИО (1 - да, 0 - нет)
	private $isNameEdit = 1;

	//Разрешено изменять адрес (1 - да, 0 - нет)
	private $isAddressEdit = 1;

	//Разрешено изменять сумму (1 - да, 0 - нет)
	private $isAmountEdit = 1;


	/**
	 * Метод, вызываемый в коде настроек ТДС через Shop_Payment_System_Handler::checkBeforeContent($oShop);
	 */
	public function checkPaymentBeforeContent()
	{
		if (isset($_REQUEST['Data'])) {

			// Преобразуем из JSON в Array
			$data = json_decode($_REQUEST['Data'], true);

			$id = $data['AccountNo'];
			// Получаем ID заказа
			$order_id = intval($id);

			$oShop_Order = Core_Entity::factory('Shop_Order')->find($order_id);

			if (!is_null($oShop_Order->id)) {
				// Вызов обработчика платежной системы
				Shop_Payment_System_Handler::factory($oShop_Order->Shop_Payment_System)
					->shopOrder($oShop_Order)
					->paymentProcessing();
			}
		}
	}

	/* Вызывается на 4-ом шаге оформления заказа*/
	public function execute()
	{
		parent::execute();

		$this->printNotification();

		return $this;
	}

	protected function _processOrder()
	{
		parent::_processOrder();
		// Установка XSL-шаблонов в соответствии с настройками в узле структуры
		$this->setXSLs();
		// Отправка писем клиенту и пользователю
		$this->send();
		return $this;
	}

	// вычисление суммы товаров заказа 
	public function getSumWithCoeff()
	{
		return Shop_Controller::instance()->round(($this->_currency_id > 0
			&& $this->_shopOrder->shop_currency_id > 0
			? Shop_Controller::instance()->getCurrencyCoefficientInShopCurrency(
				$this->_shopOrder->Shop_Currency,
				Core_Entity::factory('Shop_Currency', $this->_currency_id)
			)
			: 0) * $this->_shopOrder->getAmount());
	}

	// обработка ответа от платёжной системы
	public function paymentProcessing()
	{
		$this->ProcessResult();
		return TRUE;
	}

	// оплачивает заказ 
	function ProcessResult()
	{
		$json = Core_Array::getPost('Data');
		$notify_signature = Core_Array::getPost('Signature');

		// Преобразуем из JSON в Array
		$data = json_decode($json, true);

		$id = $data['AccountNo'];

		if ($this->isUseSignatureForNotif) {

			$secretWord = $this->secretWordForNotif;

			if ($notify_signature == $this->computeSignature($json, $secretWord)) {
				if ($data['CmdType'] == '3' && $data['Status'] == '3' || $data['Status'] == '6') { // Оплачен
					$this->_shopOrder->system_information  = "Товар оплачен через Эксперес платежи.\n";
					$this->_shopOrder->paid();
					$this->setXSLs();
					$this->send();
					header("HTTP/1.0 200 OK");
					print 'OK | the notice is processed';
					die();
				} elseif ($data['CmdType'] == '3' && $data['Status'] == '5') {
					$this->_shopOrder->system_information  = 'Эксперес платежи счёт отменён!';
					$this->_shopOrder->save();
					header("HTTP/1.0 400 Bad Request");
					print 'OK | payment aborted';
					die();
				}
			} else {
				$this->_shopOrder->system_information = 'Эксперес платежи хэш не совпал!';
				$this->_shopOrder->save();
				header("HTTP/1.0 400 Bad Request");
				print 'FAILED | wrong notify signature  '; //Ошибка в параметрах
				die();
			}
		} elseif ($data['CmdType'] == '3' && $data['Status'] == '3' || $data['Status'] == '6') {
			$this->_shopOrder->system_information = "Товар оплачен через Эксперес платежи.\n";
			$this->_shopOrder->paid();
			$this->setXSLs();
			$this->send();
			header("HTTP/1.0 200 OK");
			print 'OK | the notice is processed';
			die();
		} elseif ($data['CmdType'] == '3' && $data['Status'] == '5') {
			$this->_shopOrder->system_information = 'Эксперес платежи счёт отменён!';
			$this->_shopOrder->save();
			header("HTTP/1.0 400 Bad Request");
			print 'OK | payment aborted';
			die();
		}
	}

	// печатает форму отправки запроса на сайт платёжной системы
	public function getNotification()
	{
		$baseUrl = "https://api.express-pay.by/v1/";

		if ($this->isTest)
			$baseUrl = "https://sandbox-api.express-pay.by/v1/";

		$url = $baseUrl . "web_invoices";

		$sum = $this->getSumWithCoeff();

		$request_params = $this->getInvoiceParam();

		$response = $this->sendRequestPOST($url, $request_params);

		$response = json_decode($response, true);

		$this->log_info('Response', print_r($response, 1));

		$oShop_Currency = Core_Entity::factory('Shop_Currency')->find($this->_currency_id);

		if (!is_null($oShop_Currency->id)) {
			if (isset($response['Errors'])) {
				$output_error =
					'<br />
				<h3>Ваш номер заказа: ##ORDER_ID##</h3>
				<p>При выполнении запроса произошла непредвиденная ошибка. Пожалуйста, повторите запрос позже или обратитесь в службу технической поддержки магазина</p>
				<input type="button" value="Продолжить" onClick=\'location.href="##HOME_URL##"\'>';
				
				$oSite_Alias = $this->_shopOrder->Shop->Site->getCurrentAlias();
				$site_alias = !is_null($oSite_Alias) ? $oSite_Alias->name : '';
				$shop_path = $this->_shopOrder->Shop->Structure->getPath();
				$result_url = 'http://' . $site_alias . $shop_path;

				$output_error = str_replace('##ORDER_ID##', $this->_shopOrder->id,  $output_error);
				$output_error = str_replace('##HOME_URL##', $result_url,  $output);

				$this->log_info('Errors', $response);

				echo $output_error;
			} else {
				$output =
					'<table style="width: 100%;text-align: left;">
				<tbody>
						<tr>
							<td valign="top" style="text-align:left;">
							<h3>Ваш номер заказа: ##ORDER_ID##</h3>
								Вам необходимо произвести платеж в любой системе, позволяющей проводить оплату через ЕРИП (пункты банковского обслуживания, банкоматы, платежные терминалы, системы интернет-банкинга, клиент-банкинга и т.п.).
								<br />
								<br />1. Для этого в перечне услуг ЕРИП перейдите в раздел:  <b>##ERIP_PATH##</b> <br />
								<br />2. В поле <b>"Номер заказа"</b>введите <b>##ORDER_ID##</b> и нажмите "Продолжить" <br />
								<br />3. Укажите сумму для оплаты <b>##AMOUNT##</b><br />
								<br />4. Совершить платеж.<br />
							</td>
								<td style="text-align: center;padding: 70px 20px 0 0;vertical-align: middle">
									##OR_CODE##
									<p><b>##OR_CODE_DESCRIPTION##</b></p>
									</td>
							</tr>
					</tbody>
				</table>
				<br />
				<input type="button" value="Продолжить" onClick=\'location.href="##HOME_URL##"\'>';

				$oSite_Alias = $this->_shopOrder->Shop->Site->getCurrentAlias();
				$site_alias = !is_null($oSite_Alias) ? $oSite_Alias->name : '';
				$shop_path = $this->_shopOrder->Shop->Structure->getPath();
				$result_url = 'http://' . $site_alias . $shop_path;

				$output = str_replace('##ORDER_ID##', $this->_shopOrder->id,  $output);
				$output = str_replace('##ERIP_PATH##', $this->eripPath,  $output);
				$output = str_replace('##AMOUNT##', $sum,  $output);
				$output = str_replace('##HOME_URL##', $result_url,  $output);;

				if ($this->showQrCode) {
					$qr_code = $this->getQrCode($response['ExpressPayInvoiceNo']);
					$output = str_replace('##OR_CODE##', '<img src="data:image/jpeg;base64,' . $qr_code . '"  width="100" height="100"/>',  $output);
					$output = str_replace('##OR_CODE_DESCRIPTION##', 'Отсканируйте QR-код для оплаты',  $output);
				} else {
					$output = str_replace('##OR_CODE##', '',  $output);
					$output = str_replace('##OR_CODE_DESCRIPTION##', '',  $output);
				}
				echo $output;
			}
		}
	}

	public function getInvoice()
	{
		return $this->getNotification();
	}

	// Отправка POST запроса
	public function sendRequestPOST($url, $params)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
		$response = curl_exec($ch);
		curl_close($ch);
		return $response;
	}

	// Отправка GET запроса
	public function sendRequestGET($url)
	{
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		$response = curl_exec($ch);
		curl_close($ch);
		return $response;
	}

	//Получение Qr-кода
	public function getQrCode($ExpressPayInvoiceNo)
	{
		$request_params_for_qr = array(
			"Token" => $this->token,
			"InvoiceId" => $ExpressPayInvoiceNo,
			'ViewType' => 'base64'
		);
		$request_params_for_qr["Signature"] = $this->compute_signature($request_params_for_qr, $this->secretWord, 'get_qr_code');

		$request_params_for_qr  = http_build_query($request_params_for_qr);
		$response_qr = $this->sendRequestGET('https://api.express-pay.by/v1/qrcode/getqrcode/?' . $request_params_for_qr);
		$response_qr = json_decode($response_qr);
		$qr_code = $response_qr->QrCodeBody;
		return $qr_code;
	}

	//Получение данных для JSON
	public function getInvoiceParam()
	{
		$id = $this->_shopOrder->id;
		$info = 'Оплата заказа номер ' . $id . ' в интернет-магазине ';

        $request = array(
            "ServiceId"          => $this->serviceId,
            "AccountNo"          => $id,
            "Amount"             => number_format($this->getSumWithCoeff(), 2, ',', ''),
            "Currency"           => 933,
            'ReturnType'         => "json",
            'ReturnUrl'          => '',
            'FailUrl'            => '',
            'Expiration'         => '',
            "Info"               => $info,
            "Surname"            => $this->_shopOrder->surname,
            "FirstName"          => $this->_shopOrder->name,
            "Patronymic"         => $this->_shopOrder->patronymic,
            "IsNameEditable"     => $this->isNameEdit,
            "IsAddressEditable"  => $this->isAddressEdit,
            "IsAmountEditable"   => $this->isAmountEdit,
            "EmailNotification"  => $this->_shopOrder->email,
            "SmsPhone"           => preg_replace('/[^0-9]/', '', $this->_shopOrder->phone),
		);
		
		$secretWord = $this->isUseSignature ? $this->secretWord : "";

		$request['Signature'] = $this->compute_signature($request, $secretWord, 'add_invoice');

		return $request;
	}

	//Вычисление цифровой подписи
	public function compute_signature($request_params, $secret_word, $method = 'add_invoice')
	{
		$secret_word = trim($secret_word);
		$normalized_params = array_change_key_case($request_params, CASE_LOWER);
		$api_method = array(
			'add_invoice' => array(
				"serviceid",
				"accountno",
				"amount",
				"currency",
				"expiration",
				"info",
				"surname",
				"firstname",
				"patronymic",
				"city",
				"street",
				"house",
				"building",
				"apartment",
				"isnameeditable",
				"isaddresseditable",
				"isamounteditable",
				"emailnotification",
				"smsphone",
				"returntype",
				"returnurl",
				"failurl"
			),
			'get_qr_code' => array(
				"invoiceid",
				"viewtype",
				"imagewidth",
				"imageheight"
			),
			'add_invoice_return' => array(
				"accountno",
				"invoiceno"
			)
		);

		$result = $this->token;

		foreach ($api_method[$method] as $item)
			$result .= (isset($normalized_params[$item])) ? $normalized_params[$item] : '';

		$hash = strtoupper(hash_hmac('sha1', $result, $secret_word));

		return $hash;
	}

	// Проверка электронной подписи
	function computeSignature($json, $secretWord)
	{
		$hash = NULL;

		$secretWord = trim($secretWord);

		if (empty($secretWord))
			$hash = strtoupper(hash_hmac('sha1', $json, ""));
		else
			$hash = strtoupper(hash_hmac('sha1', $json, $secretWord));
		return $hash;
	}


	private function log_info($name, $message)
	{
		$this->log($name, "INFO", $message);
	}

	private function log($name, $type, $message)
	{
		$log_url = dirname(__FILE__) . '/log';

		if (!file_exists($log_url)) {
			$is_created = mkdir($log_url, 0777);

			if (!$is_created)
				return;
		}

		$log_url .= '/express-pay-' . date('Y.m.d') . '.log';

		file_put_contents($log_url, $type . " - IP - " . $_SERVER['REMOTE_ADDR'] . "; DATETIME - " . date("Y-m-d H:i:s") . "; USER AGENT - " . $_SERVER['HTTP_USER_AGENT'] . "; FUNCTION - " . $name . "; MESSAGE - " . $message . ';' . PHP_EOL, FILE_APPEND);
	}
}
