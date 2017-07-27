<?php
defined('_JEXEC') or die('Restricted access');
include('PG_Signature.php');
include('Receipt'.DIRECTORY_SEPARATOR.'OfdReceiptItem.php');
include('Receipt'.DIRECTORY_SEPARATOR.'OfdReceiptRequest.php');

class pm_platron extends PaymentRoot
{
    const SERVISE_URL = 'https://www.platron.ru';
    /**
     * Вернуть адрес сервиса 
     * @return string
     */
    function getServiseUrl() 
    {
        return self::SERVISE_URL;
    }

    function showPaymentForm($params, $pmconfigs)
    {
        include(dirname(__FILE__)."/paymentform.php");
    }

    function showAdminFormParams($params)
    {
        $jmlThisDocument = & JFactory::getDocument();
        switch ($jmlThisDocument->language) 
        {
            case 'en-gb': include(JPATH_SITE.'/administrator/components/com_jshopping/lang/en-GB_platron.php'); $language = 'en'; break;
            case 'ru-ru': include(JPATH_SITE.'/administrator/components/com_jshopping/lang/ru-RU_platron.php'); $language = 'ru'; break;
            default: include(JPATH_SITE.'/administrator/components/com_jshopping/lang/ru-RU_platron.php');
        }
        $array_params = array('test_mode', 'merchant_id', 'secret_key', 'transaction_end_status', 'transaction_pending_status', 'transaction_failed_status');
        foreach ($array_params as $key)
            if (!isset($params[$key])) 
                $params[$key] = '';
        $orders = &JModel::getInstance('orders', 'JshoppingModel');
        $currency = &JModel::getInstance('currencies', 'JshoppingModel');
        
        include(dirname(__FILE__)."/adminparamsform.php");  
        
        jimport('joomla.html.pane');
        $pane =& JPane::getInstance('Tabs');
        echo $pane->endPanel();
    }

    function checkTransaction($pmconfigs, $order, $act)
    {
        switch ($act) {
            case 'check':
                unset($_GET['Itemid']);
                $arrParams = $_GET;
                $thisScriptName = PG_Signature::getOurScriptName();

                if ( !PG_Signature::check($arrParams['pg_sig'], $thisScriptName, $arrParams, $pmconfigs['secret_key']) )
                    die("Bad signature");

                $order_id = $arrParams['pg_order_id'];
                /*
                 * Проверка того, что заказ ожидает оплаты
                 */
                if($pmconfigs['transaction_pending_status'] == $order->order_status)
                    $is_order_available = true;
                else{
                    $is_order_available = false;
                    $error_desc = "Товар не доступен";
                }

                $arrResp['pg_salt']              = $arrParams['pg_salt']; // в ответе необходимо указывать тот же pg_salt, что и в запросе
                $arrResp['pg_status']            = $is_order_available ? 'ok' : 'error';
                $arrResp['pg_error_description'] = $is_order_available ?  ""  : $error_desc;
                $arrResp['pg_sig'] = PG_Signature::make($thisScriptName, $arrResp, $pmconfigs['secret_key']);

                $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
                $xml->addChild('pg_salt', $arrResp['pg_salt']); // в ответе необходимо указывать тот же pg_salt, что и в запросе
                $xml->addChild('pg_status', $arrResp['pg_status']);
                $xml->addChild('pg_error_description', htmlentities($arrResp['pg_error_description']));
                $xml->addChild('pg_sig', $arrResp['pg_sig']);
                echo $xml->asXML();
                die();
                break;
                
                
                case 'result':
                    unset($_GET['Itemid']);
                    $checkout = JModel::getInstance('checkout', 'jshop');
                    $arrParams = $_GET;
                    $thisScriptName = PG_Signature::getOurScriptName();
                    if ( !PG_Signature::check($arrParams['pg_sig'], $thisScriptName, $arrParams, $pmconfigs['secret_key']) )
                        die("Bad signature");

                    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><response/>');
                    $order_id = $arrParams['pg_order_id'];
                    $js_result_status = 'ok';
                    $pg_description = 'Оплата принята';
                    if ( $arrParams['pg_result'] == 1 ) {
                        if($pmconfigs['transaction_pending_status'] == $order->order_status){
                            $checkout->changeStatusOrder($order->order_id, $pmconfigs['transaction_end_status'], 1);
                        }
                        else{
                            $js_result_status = 'error';
                            $pg_description = 'Оплата не может быть принята';
                            $xml->addChild('pg_error_description', 'Оплата не может быть принята');
                            if($arrParams['pg_can_reject']){
                                $js_result_status = 'reject';
                            }
                        }
                    }
                    else {
                        $checkout->changeStatusOrder($order->order_id, $pmconfigs['transaction_failed_status'], 1);
                    }
                    // обрабатываем случай успешной оплаты заказа с номером $order_id
                    $xml->addChild('pg_salt', $arrParams['pg_salt']); // в ответе необходимо указывать тот же pg_salt, что и в запросе
                    $xml->addChild('pg_status', $js_result_status);
                    $xml->addChild('pg_description', $pg_description);
                    $xml->addChild('pg_sig', PG_Signature::makeXML($thisScriptName, $xml, $pmconfigs['secret_key']));
                    print $xml->asXML();
                    die();
                    break;
            
                    
            default:
                break;
        }
    }
    
    function showEndForm($pmconfigs, $order)
    {
        $paramsQuery  = $this->generateParamsQueryByTransactionFromOrder($order,$pmconfigs);
        $paramsQuery['pg_sig'] = PG_Signature::make('init_payment.php', $paramsQuery, $pmconfigs['secret_key']);
        $response  = $this->createQuery('init_payment.php', $paramsQuery);

        $responseElement = new SimpleXMLElement($response);        
        $checkResponse   = PG_Signature::checkXML('init_payment.php', $responseElement, $pmconfigs['secret_key']);
        $redirectUrl     = (string) $responseElement->pg_redirect_url;
        if ($this->checkResponseFromCreateTransaction($checkResponse,$responseElement)) {
            $paymentId  = (string) $responseElement->pg_payment_id;
            // создание чека 
            if ($this->isCreateOfdCheck($pmconfigs)) {
               $orderItems        = $this->createItemsOfOrderByCheck($order,$pmconfigs);
               $ofdReceiptRequest = new OfdReceiptRequest($pmconfigs['merchant_id'], $paymentId);
               $ofdReceiptRequest->setItems($orderItems);
               $ofdReceiptRequest->createParamSign($pmconfigs['secret_key']);
               $responseOfd = $this->createQuery($ofdReceiptRequest->getAction(), $ofdReceiptRequest->getParams());
               $responseElementOfd = new SimpleXMLElement($responseOfd);
               if ((string) $responseElementOfd->pg_status != 'ok') {
                   die('Platron check create error. ' . $responseElementOfd->pg_error_description);
               }
           }
        }
        header("Location: ".$redirectUrl);
    }
    /**
     * Проверка все ли удачно прошло, при создании транзакции
     * @param  bool $checkResponse
     * @param  SimpleXMLElement $responseElement 
     * @return bool 
     */
    protected function checkResponseFromCreateTransaction($checkResponse,$responseElement)
    {
        return $checkResponse && (string) $responseElement->pg_status === 'ok';
    }
    /**
     * Проверка, сделать ли чек 
     * @param  array настройки 
     * @return boolean [description]
     */
    protected function isCreateOfdCheck($pmconfigs)
    {
        return array_key_exists('create_ofd_check', $pmconfigs) && $pmconfigs['create_ofd_check'] == 1;
    }
    /**
     * Создание списка товаров для чека
     * @param  Order $order заказ
     * @return array
     */
    public function createItemsOfOrderByCheck($order,$pmconfigs)
    {
        $ofdReceiptItems = [];
        foreach($order->getAllItems()as $item) {
            $ofdReceiptItem           = new OfdReceiptItem();
            $ofdReceiptItem->label    = $item->product_name;
            $ofdReceiptItem->amount   = round($item->product_item_price * $item->product_quantity, 2);
            $ofdReceiptItem->price    = round($item->product_item_price, 2);
            $ofdReceiptItem->quantity = $item->product_quantity;
            $ofdReceiptItem->vat      = 18;
            $ofdReceiptItems[]        = $ofdReceiptItem;
        }
        if (!is_null($order->getShippingTaxExt())) {
            $ofdReceiptItems[] = $this->addShippingByOrder($order);
        }
        return $ofdReceiptItems;
    }
    protected function addShippingByOrder($order)
    {
        $ofdReceiptItem           = new OfdReceiptItem();
        $ofdReceiptItem->label    = 'Доставка';
        $ofdReceiptItem->amount   = (float) $order->order_shipping; 
        $ofdReceiptItem->price    = (float) $order->order_shipping;
        $ofdReceiptItem->vat      = 18;
        $ofdReceiptItem->quantity = 1;
        return $ofdReceiptItem;
    }
    /**
     * Создание http запроса  
     * @param  string $action url относительный url платрон
     * @param  array $params
     * @return xml
     */
    protected function createQuery($action, $params = []) 
    {
        //Инициализирует сеанс
        $connection = curl_init();
        $url = $this->getServiseUrl().'/'. $action;
        if (count($params)) {
            $url = $url.'?'.http_build_query($params);
        }
        curl_setopt($connection, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($connection, CURLOPT_URL, $url);
        $response = curl_exec($connection);
        curl_close($connection);
        return $response;
    }
    /**
     * генерация пареметров запроса для создания транзакции 
     * @param  Order $order     заказ
     * @param  array $pmconfigs настройки платежной системы
     * @return array параметры запроса           
     */
    private function generateParamsQueryByTransactionFromOrder($order,$pmconfigs)
    {
        $check_url  = JURI::root() . "index.php?option=com_jshopping&controller=checkout&task=step7&act=check&js_paymentclass=pm_platron&type=check&order_id=".$order->order_id;
        $result_url = JURI::root() . "index.php?option=com_jshopping&controller=checkout&task=step7&act=result&js_paymentclass=pm_platron&type=check&order_id=".$order->order_id;
        $arrReq   = [];
        /* Обязательные параметры */
        $arrReq['pg_merchant_id']  = $pmconfigs['merchant_id']; // Идентификатор магазина
        $arrReq['pg_order_id']     = $order->order_id;  // Идентификатор заказа в системе магазина
        $arrReq['pg_amount']       = sprintf("%01.2f",$order->order_total / $order->currency_exchange); // Сумма заказа
        $arrReq['pg_description']  = "Оплата заказа ".$_SERVER['HTTP_HOST']; // Описание заказа (показывается в Платёжной системе)
        $arrReq['pg_site_url']     = $_SERVER['HTTP_HOST']; // Для возврата на сайт
        $arrReq['pg_lifetime']     = $pmconfigs['lifetime']*60*60; // Время жизни в секундах
        $arrReq['pg_check_url']    = $check_url; // Проверка заказа
        $arrReq['pg_result_url']   = $result_url; // Оповещение о результатах
        $arrReq['pg_language']     = $this->getLangCMS();
        // $arrReq['pg_user_ip']   = $_SERVER['REMOTE_ADDR'];
        $arrReq['pg_testing_mode'] = $pmconfigs['test_mode'] ? 1 : 0;
        $arrReq['pg_currency']     = $order->currency_code_iso;
        $arrReq['pg_salt'] = rand(21,43433);
        $arrReq['cms_payment_module'] = 'JOOMSHOPING';
        if (isset($order->d_phone)) { // Телефон в 11 значном формате
            $strUserPhone = preg_replace('/\D+/','',$order->d_phone);
            if (strlen($strUserPhone) == 10)    {
                $strUserPhone .= "7";
            }
            $arrReq['pg_user_phone'] = $strUserPhone;
        }
        
        if(isset($order->d_email)){
            $arrReq['pg_user_contact_email'] = $order->d_email;
            $arrReq['pg_user_email'] = $order->d_email; // Для ПС Деньги@Mail.ru
        }
        return $arrReq;
    }
    
    function getUrlParams($pmconfigs)
    {                        
        $params = array(); 
        $params['order_id'] = JRequest::getInt("order_id");
        return $params;
    }
    
    /**
     * получить язык CMS системы
     * @return string
     */
    private function getLangCMS()
    {
        $language = 'EN';
        switch (JFactory::getLanguage()) 
        {
            case 'en-gb': 
                $language = 'EN'; 
            break;
            case 'ru-ru':
                $language = 'RU';
            break;
        }
        return $language;
    }
}
?>