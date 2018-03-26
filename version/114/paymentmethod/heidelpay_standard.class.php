<?php
/*
 * SUMMARY
 *
 * DESC
 *
 * @license Use of this software requires acceptance of the License Agreement. See LICENSE file.
 * @copyright Copyright © 2016-present heidelpay GmbH. All rights reserved.
 * @link https://dev.heidelpay.de/JTL
 * @author Ronja Wann, Florian Evertz, David Owusu
 * @category JTL
 */
include_once PFAD_ROOT . PFAD_INCLUDES_MODULES . 'ServerPaymentMethod.class.php';
require_once PFAD_ROOT . PFAD_PLUGIN . 'heidelpay_standard/vendor/autoload.php';
require_once PFAD_ROOT . PFAD_CLASSES . "class.JTL-Shop.Jtllog.php";
require_once __DIR__ . '/helper/HeidelpayBasketHelper.php';
require_once __DIR__ . '/helper/HeidelpayTemplateHelper.php';

/*
 * heidelpay standard class
 */

abstract class heidelpay_standard extends ServerPaymentMethod
{
    public $paymentObject;
    public $pluginName = "heidelpay_standard";
    public $oPlugin;

    public function setLocal()
    {
        if ($_SESSION ['cISOSprache'] == 'ger') {
            #setlocale(LC_ALL, 'de_DE');
        } else {
            #setlocale(LC_ALL, 'en_US');
        }
    }

    /**
     * Sets Short-ID in database as comment for the order
     *
     * @param $shortId
     * @param $orderId
     * @return bool
     */
    public function setShortId($shortId, $orderId)
    {
        preg_match('/\d{4}[.]\d{4}[.]\d{4}/', $shortId) ? $shortId : false;

        if (!is_numeric($orderId)) {
            return false;
        }

        $sql = 'UPDATE `tbestellung`
        SET `cKommentar` = ?
          WHERE `cBestellNr` = ?';
        $GLOBALS ['DB']->executeQueryPrepared($sql, array($shortId, $orderId), 3);
    }

    /**
     * generates hash for criterion secret with secretPhrase and orderID
     *
     * @param $secret secret phrase from backend
     * @param $orderId
     * @return string hashed secret string
     */
    public function getHash($secret, $orderId)
    {
        return hash('sha256', $secret . $orderId);
    }

    /**
     * Initialize the payment process by set the payment method and the plugin.
     */
    public function initPaymentProcess()
    {
        $this->setPaymentObject();
        $this->oPlugin = $this->getPlugin($this->moduleID);
    }

    /**
     * Prepares process for payment
     *
     * @param Bestellung $order
     */
    public function preparePaymentProcess($order)
    {
        $this->initPaymentProcess();
        $this->init(0);

        $this->prepareRequest($order, $this->moduleID);
        $this->sendPaymentRequest();

        if ($this->paymentObject->getResponse()->isError()) {
            $errorCode = $this->paymentObject->getResponse()->getError();
            $this->redirect('bestellvorgang.php?heidelpayErrorCode=' . $errorCode['code']);
            return;
        }

        $this->setPaymentTemplate();
    }

    /**
     * Check whether order has same address for billing and shipping and
     * whether it ist b2c.
     *
     * @param $order
     */
    protected function b2cSecuredCheck($order)
    {
        if ($this->isEqualAddress($order) == false) {
            $this->redirect('warenkorb.php?hperroradd=1');
        }

        if ($_SESSION['Kunde']->cFirma != null) {
            $this->redirect('warenkorb.php?hperrorcom=1');
        }
    }

    /**
     * Prepare transaction request.
     * The preparations will apply to $this->paymentObject
     * @param Bestellung $order
     * @param string $currentPaymentMethod
     */
    protected function prepareRequest(Bestellung $order, $currentPaymentMethod)
    {
        $oPlugin = $this->oPlugin;

        $hash = $this->generateHash($order);
        /*if (property_exists($order, 'cId')) {
            $hash = $order->cId;
        }*/
        $notifyURL = $this->getNotificationURL($hash);

        $this->paymentObject->getRequest()->authentification(
            $oPlugin->oPluginEinstellungAssoc_arr ['sender'],
            $oPlugin->oPluginEinstellungAssoc_arr ['user'],
            $oPlugin->oPluginEinstellungAssoc_arr ['pass'],
            $oPlugin->oPluginEinstellungAssoc_arr [$currentPaymentMethod . '_channel'],
            $this->isSandboxMode($oPlugin, $currentPaymentMethod)
        );

        $this->paymentObject->getRequest()->getContact()->set('ip', $this->getIp());
        $this->paymentObject->getRequest()->customerAddress(...$this->getCustomerData($oPlugin, $currentPaymentMethod));
        $this->paymentObject->getRequest()->basketData(...$this->getBasketData($order, $oPlugin));
        $this->paymentObject->getRequest()->async($this->getLanguageCode(), $notifyURL);
        $this->paymentObject->getRequest()->getCriterion()->set('PAYMETHOD', $currentPaymentMethod);
    }

    /**
     * Send the payment request using authorize as default.
     * Override this method in the child class if another transaction mode should be used.
     */
    protected function sendPaymentRequest()
    {
        $this->paymentObject->authorize();
    }

    /**
     * Build and send a basket to the hPP. If successful the basketId will be added to the payment transaction.
     * @param string $currentPaymentMethod
     * @param Bestellung $order
     */
    protected function addBasketId($currentPaymentMethod, Bestellung $order) {
        $oPlugin = $this->getPlugin($currentPaymentMethod);
        $response = HeidelpayBasketHelper::sendBasketFromOrder($order, $oPlugin->oPluginEinstellungAssoc_arr);

        if($response->isSuccess()) {
            $this->paymentObject->getRequest()->getBasket()->setId($response->getBasketId());
        } else {
            Jtllog::writeLog('No basket could be added to the order. Order number: '
                .$order->cBestellNr, JTLLOG_LEVEL_NOTICE);
        }
    }

    /**
     * returns plugin depending on current payment method
     *
     * @param $moduleID
     * @return bool|Plugin
     */
    public function getPlugin($moduleID)
    {
        $kPlugin = gibkPluginAuscModulId($moduleID);
        if ($kPlugin > 0) {
            $oPlugin = new Plugin($kPlugin);
        } else {
            return false;
        }

        return $oPlugin;
    }

    /**
     * Initial function
     *
     * @param int $nAgainCheckout
     */
    public function init($nAgainCheckout = 0)
    {
        parent::init($nAgainCheckout);

        $this->name = 'Heidelpay';
        $this->caption = 'Heidelpay';

        $sql = "SELECT * FROM `tzahlungsart` WHERE `cModulId` = '{$this->moduleID}'";
        $this->info = $GLOBALS ['DB']->executeQuery($sql, 1);
    }

    /**
     * Gets prefix of current payment method
     *
     * @param $oPlugin
     * @param $moduleId
     * @return string current payment method prefix
     */
    public function getCurrentPaymentMethodPrefix($oPlugin, $moduleId)
    {
        $payCode = strtolower($oPlugin->oPluginEinstellungAssoc_arr [$moduleId . '_paycode']);
        return strtoupper('HP' . $payCode);
    }

    /**
     * Sets payment object for the chosen payment method
     */
    abstract public function setPaymentObject();

    /**
     * Checks if Sandbox-Mode active or not
     *
     * @param $oPlugin
     * @return bool true = sandbox mode active, false = live mode active (productive system)
     */
    public function isSandboxMode($oPlugin, $currentPaymentMethod)
    {
        if ($oPlugin->oPluginEinstellungAssoc_arr [$currentPaymentMethod . '_transmode'] == 'LIVE') {
            return false;
        }
        return true;
    }

    /**
     * gets IP from client
     *
     * @return ip address
     */
    public function getIp()
    {
        $ip = '127.0.0.1';

        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            if (filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP) !== FALSE) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            }
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            if (filter_var($_SERVER['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP) !== FALSE) {
                $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            }
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            if (filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) !== FALSE) {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
        }
        return $ip;
    }

    /**
     * Gets customer data from current session
     * sets customer address on shipping address in case of PayPal for PayPal buyer protection
     *
     * @param $oPlugin
     * @return array with user data (name, address and mail)
     */
    public function getCustomerData($oPlugin, $moduleId)
    {
        $payCode = $this->getCurrentPaymentMethodPrefix($oPlugin, $moduleId);
        //PayPal Case
        if ($payCode == 'HPVA') {
            $user = $_SESSION ['Lieferadresse'];
            $mail = $_SESSION ['Kunde'];
            $userStreet = $user->cStrasse . ' ' . $user->cHausnummer;
            $userData = array(empty($user->cVorname) ? null : $user->cVorname,
                empty($user->cNachname) ? null : $user->cNachname,
                empty($user->cFirma) ? null : $user->cFirma,
                empty($user->kKunde) ? null : $user->kKunde,
                empty($userStreet) ? null : $userStreet,
                empty($user->cBundesland) ? null : $user->cBundesland,
                empty($user->cPLZ) ? null : $user->cPLZ,
                empty($user->cOrt) ? null : $user->cOrt,
                empty($user->cLand) ? null : $user->cLand,
                empty($mail->cMail) ? null : $mail->cMail);
        } else {
            $user = $_SESSION ['Kunde'];
            $userStreet = $user->cStrasse . ' ' . $user->cHausnummer;
            $userData = array(empty($user->cVorname) ? null : $user->cVorname,
                empty($user->cNachname) ? null : $user->cNachname,
                empty($user->cFirma) ? null : $user->cFirma,
                empty($user->kKunde) ? null : $user->kKunde,
                empty($userStreet) ? null : $userStreet,
                empty($user->cBundesland) ? null : $user->cBundesland,
                empty($user->cPLZ) ? null : $user->cPLZ,
                empty($user->cOrt) ? null : $user->cOrt,
                empty($user->cLand) ? null : $user->cLand,
                empty($user->cMail) ? null : $user->cMail);
        }
        return $this->encodeData($userData);
    }

    /**
     * Encodes data to UTF8
     *
     * @param $data
     * @return insert $data in UTF8
     */
    public function encodeData($data)
    {
        foreach ($data as $k => $v) {
            if (!$this->isUTF8($v)) {
                $data [$k] = utf8_encode($v);
            }
        }
        return $data;
    }

    /**
     * Checks if string is UTF8
     *
     * @param $string
     * @return bool
     */
    public function isUTF8($string)
    {
        return (utf8_encode(utf8_decode($string)) == $string);
    }

    /**
     * Gets order information of current session
     *
     * @return array order information
     */
    public function getBasketData($order, $oPlugin)
    {
        $orderId = $order->cBestellNr;
        if (empty($orderId)) {
            $orderId = baueBestellnummer();
        }

        $amount = $order->fGesamtsummeKundenwaehrung; // In Kunden Währung
        if (empty($amount)) {
            $amount = $_SESSION ["Warenkorb"]->gibGesamtsummeWaren(1);
        }

        $amount = sprintf('%1.2f', $amount);
        $basketData = array(
            $orderId, $amount, $_SESSION ['Waehrung']->cISO, $oPlugin->oPluginEinstellungAssoc_arr['secret']
        );
        return $basketData;
    }

    /**
     * Gets language code depending on language in session
     *
     * @return string language code
     */
    public function getLanguageCode()
    {
        $language = $_SESSION ['cISOSprache'] == 'ger' ? 'DE' : 'EN';
        return $language;
    }

    /**
     * billing and shipping address has to be equal
     *
     * @param $order
     * @return bool
     */
    public function isEqualAddress($order)
    {
        $keyList = array(
            'cVorname',
            'cNachname',
            'cAnrede',
            'cFirma',
            'cStrasse',
            'cHausnummer',
            'cOrt',
            'cPLZ',
            'cLand'
        );
        foreach ($keyList as $key) {
            // key exists only in billing address
            if (array_key_exists($key, (array)$order->oRechnungsadresse) and
                !array_key_exists($key, (array)$order->Lieferadresse)) {
                return false;
            }
            // key exists only in delivery address
            if (array_key_exists($key, (array)$order->Lieferadresse) and
                !array_key_exists($key, (array)$order->oRechnungsadresse)) {
                return false;
            }
            // merge keys
            if (array_key_exists($key, (array)$order->Lieferadresse) and
                array_key_exists($key, (array)$order->oRechnungsadresse)) {
                // return false on unmatched
                if ($order->Lieferadresse->$key != $order->oRechnungsadresse->$key) {
                    return false;
                }
            }
        }
        // if everything is equal return true
        return true;
    }

    /**
     * Redirects customer
     */
    public function redirect($url)
    {
        header('Location: ' . $url);
    }

    /**
     * Get booking mode
     *
     * @param $oPlugin
     * @param $currentPaymentMethod
     * @return mixed returns booking mode
     */
    public function getBookingMode($oPlugin, $currentPaymentMethod)
    {
        $bookingMode = $oPlugin->oPluginEinstellungAssoc_arr [$currentPaymentMethod . '_bookingmode'];
        return $bookingMode;
    }

    /**
     * Gets payment frame origin
     *
     * @return string url with the origin of the payment frame
     */
    public function getPaymentFrameOrigin()
    {
        $parse_url = parse_url(Shop::getURL());
        $paymentFrameOrigin = $parse_url['scheme'] . '://' . $parse_url['host'];
        return $paymentFrameOrigin;
    }

    /**
     * Sets payment template depending on chosen payment method
     *
     * @param $paymentMethodPrefix
     *
     */
    public function setPaymentTemplate()
    {
        global $smarty;
        $templateHelper = new HeidelpayTemplateHelper($this);

        $smarty->assign('pay_button_label', $this->getPayButtonLabel());
        $smarty->assign('paytext', utf8_decode($this->getPayText()));

        #setlocale(LC_TIME, $this->getLanguageCode());
        $formFields = $this->getFormFields();
        if($formFields) {
            $templateHelper->addFieldsets($smarty, $formFields);
        } else {
            $this->redirect($this->paymentObject->getResponse()->getPaymentFormUrl());
        }
    }

    /**
     * @return array|null
     */
    public function getFormFields()
    {
        $paymentMethodPrefix = $this->getCurrentPaymentMethodPrefix($this->oPlugin, $this->moduleID);
        switch ($paymentMethodPrefix) {
            case 'HPCC':
            case 'HPDC':
            case 'HPDD':
                return ['holder'];
                break;
            case 'HPDDPG':
            case 'HPIVPG':
                return [
                    'holder',
                    'birthdate',
                    'salutation',
                    'is_PG',
                ];
                break;
            case 'HPIDL':
            case 'HPEPS':
                return ['account'];
                break;
            case 'HPSA':
                return [
                    'birthdate',
                    'privacy',
                    'salutation',
                    'holder',
                ];
                break;
            default:
                return null;
        }
    }

    /**
     * Gets label on pay button depending on selected language
     *
     * @return string with text for pay button
     */
    public function getPayButtonLabel()
    {
        $payButtonLabel = 'Pay now';
        if ($_SESSION ['cISOSprache'] == 'ger') {
            $payButtonLabel = 'Jetzt zahlen';
        }
        return $payButtonLabel;
    }

    /**
     * Gets payment text depending on selected language
     *
     * @return string with payment text
     */
    public function getPayText()
    {
        $payText = 'Please complete the following data and complete the order process.';
        if ($_SESSION ['cISOSprache'] == 'ger') {
            $payText = 'Bitte vervollständigen Sie die unten aufgeführten Daten und schließen Sie den Bestellprozess ab.';
        }
        return $payText;
    }

    /**
     * Gets label for holder depending on selected language
     *
     * @return string with text for holder label
     */
    public function getHolderLabel()
    {
        $holderLabel = 'Holder';
        if ($_SESSION ['cISOSprache'] == 'ger') {
            $holderLabel = 'Kontoinhaber';
        }
        return $holderLabel;
    }

    /**
     * Gets label for birthdate depending on selected language
     *
     * @return string with text for birthdate label
     */
    public function getBirthdateLabel()
    {
        $birthdateLabel = 'Birthdate';
        if ($_SESSION ['cISOSprache'] == 'ger') {
            $birthdateLabel = 'Geburtsdatum';
        }
        return $birthdateLabel;
    }

    /**
     * Creates salutation array for template depending on session language
     *
     * @return array with salutation options
     */
    public function getSalutationArray()
    {
        $salutationArray = array('MR' => 'Mr', 'MRS' => 'Mrs');
        if ($_SESSION ['cISOSprache'] == 'ger') {
            $salutationArray = array('MR' => 'Herr', 'MRS' => 'Frau');
        }
        return $salutationArray;
    }

    /**
     * Gets salutation from session for payment
     *
     * @return string 'MR' or 'MRS' depending on the salutation in session
     */
    public function getSalutation()
    {
        $salutation = 'MRS';
        if ($_SESSION['Kunde']->cAnrede == 'm') {
            $salutation = 'MR';
        }
        return $salutation;
    }

    /**
     * Gets private policy depending on language
     *
     * @param $oPlugin
     * @return mixed text with private policy
     */
    public function getPrivatePolicyLabel($oPlugin)
    {
        if ($_SESSION ['cISOSprache'] == 'ger') {
            $privatePolicyLabel = $oPlugin->oPluginSprachvariable_arr['0']->oPluginSprachvariableSprache_arr['GER'];
        } else {
            $privatePolicyLabel = $oPlugin->oPluginSprachvariable_arr['0']->oPluginSprachvariableSprache_arr['ENG'];
        }
        return $privatePolicyLabel;
    }

    /**
     * Handles notification and redirects customer
     *
     * @param $order
     * @param $paymentHash
     * @param $args
     */
    public function handleNotification($order, $paymentHash, $args)
    {
        $this->init();

        $HeidelpayResponse = new  Heidelpay\PhpPaymentApi\Response($args);

        if (array_key_exists('CRITERION_PAYMETHOD', $args)) {
            $oPlugin = $this->getPlugin($args['CRITERION_PAYMETHOD']);
            $secretPass = $oPlugin->oPluginEinstellungAssoc_arr ['secret'];
            $identificationTransactionId = $HeidelpayResponse->getIdentification()->getTransactionId();
            try {
                $HeidelpayResponse->verifySecurityHash($secretPass, $identificationTransactionId);
            } catch (\Exception $e) {
                /* If the verification does not match this can mean some kind of manipulation or
                 * miss configuration. So you can log $e->getMessage() for debugging.*/
                $callers = debug_backtrace();
                Jtllog::writeLog("Heidelpay - " . $callers [0] ['function'] . ": Invalid response hash from " .
                    $_SERVER ['REMOTE_ADDR'] . ", suspecting manipulation", JTLLOG_LEVEL_NOTICE, false, 'Notify');
                exit();
            }
        } else {
            $this->redirect('bestellvorgang.php');
        }
        if ($HeidelpayResponse->isSuccess()) {
            /* save order and transaction result to your database */
            if ($this->verifyNotification($order, $args)) {
                $payCode = explode('.', $args ['PAYMENT_CODE']);
                if (strtoupper($payCode [0]) == 'DD' && !isset($args ['TRANSACTION_SOURCE'])) {
                    $language = $_SESSION ['cISOSprache'] == 'ger' ? 'DE' : 'EN';
                    if ($language == 'DE') {
                        include_once PFAD_ROOT . PFAD_PLUGIN . $oPlugin->cVerzeichnis . '/version/' .
                            $oPlugin->nVersion . '/paymentmethod/template/heidelpay_ddMail_de.tpl';
                    } else {
                        include_once PFAD_ROOT . PFAD_PLUGIN . $oPlugin->cVerzeichnis . '/version/' .
                            $oPlugin->nVersion . '/paymentmethod/template/heidelpay_ddMail_en.tpl';
                    }
                    $repl = array(
                        '{ACC_IBAN}' => $args ['ACCOUNT_IBAN'],
                        '{ACC_BIC}' => $args ['ACCOUNT_BIC'],
                        '{ACC_IDENT}' => $args ['ACCOUNT_IDENTIFICATION'],
                        '{AMOUNT}' => $args ['PRESENTATION_AMOUNT'],
                        '{CURRENCY}' => $args ['PRESENTATION_CURRENCY'],
                        '{HOLDER}' => $args ['ACCOUNT_HOLDER']
                    );
                    if (isset($args ['IDENTIFICATION_CREDITOR_ID']) && ($args ['IDENTIFICATION_CREDITOR_ID'] != '')) {
                        $repl ['{IDENT_CREDITOR}'] = $args ['IDENTIFICATION_CREDITOR_ID'];
                    } else {
                        $repl ['{IDENT_CREDITOR}'] = '-';
                    }
                    mail(
                        $order->oRechnungsadresse->cMail,
                        constant('DD_MAIL_SUBJECT'),
                        strtr(constant('DD_MAIL_TEXT'), $repl),
                        constant('DD_MAIL_HEADERS')
                    );
                } elseif (strtoupper($payCode [0]) == 'PP' && !isset($args ['TRANSACTION_SOURCE'])) {
                    $language = $_SESSION ['cISOSprache'] == 'ger' ? 'DE' : 'EN';
                    if ($language == 'DE') {
                        include_once PFAD_ROOT . PFAD_PLUGIN . $oPlugin->cVerzeichnis . '/version/' .
                            $oPlugin->nVersion . '/paymentmethod/template/heidelpay_ppMail_de.tpl';
                    } else {
                        include_once PFAD_ROOT . PFAD_PLUGIN . $oPlugin->cVerzeichnis . '/version/' .
                            $oPlugin->nVersion . '/paymentmethod/template/heidelpay_ppMail_en.tpl';
                    }
                    $repl = array(
                        '{ACC_IBAN}' => $args ['CONNECTOR_ACCOUNT_IBAN'],
                        '{ACC_BIC}' => $args ['CONNECTOR_ACCOUNT_BIC'],
                        '{ACC_OWNER}' => $args ['CONNECTOR_ACCOUNT_HOLDER'],
                        '{AMOUNT}' => $args ['PRESENTATION_AMOUNT'],
                        '{CURRENCY}' => $args ['PRESENTATION_CURRENCY'],
                        '{USAGE}' => $args ['IDENTIFICATION_SHORTID']
                    );
                    mail(
                        $order->oRechnungsadresse->cMail,
                        constant('PP_MAIL_SUBJECT'),
                        strtr(constant('PP_MAIL_TEXT'), $repl),
                        constant('PP_MAIL_HEADERS')
                    );
                } elseif ((strtoupper($payCode [0]) == 'IV') && (!isset($args ['TRANSACTION_SOURCE']))) {
                    $this->setPayInfo($args, $order->cBestellNr);
                }
                // Nur wenn nicht Vorkasse od. Rechnung
                if (strtoupper($payCode [0]) != 'PP' AND strtoupper($payCode [0]) != 'IV') {
                    try {
                        $this->setOrderStatusToPaid($order);
                    } catch (Exception $e) {
                        $e = 'Update order status failed on order: ' . $order . ' in file: ' .
                            $e->getFile() . ' on line: ' . $e->getLine() . ' with message: ' . $e->getMessage();
                        $logData = array(
                            'module' => 'Heidelpay Standard',
                            'order' => $order,
                            'error_msg' => $e
                        );
                        Jtllog::writeLog((string)$logData, JTLLOG_LEVEL_ERROR, false);
                    }
                    try {
                        $this->sendConfirmationMail($order);
                    } catch (Exception $e) {
                        $e = 'Update order status failed on order: ' . $order . ' in file: ' .
                            $e->getFile() . ' on line: ' . $e->getLine() . ' with message: ' . $e->getMessage();
                        $logData = array(
                            'module' => 'Heidelpay Standard',
                            'order' => $order,
                            'error_msg' => $e
                        );
                        Jtllog::writeLog((string)$logData, JTLLOG_LEVEL_ERROR, false);
                    }
                }

                if (strtoupper($payCode [1]) != 'PA' AND strtoupper($payCode [1]) != 'RG') {
                    $incomingPayment = new stdClass();
                    $incomingPayment->fBetrag = number_format($order->fGesamtsummeKundenwaehrung, 2, '.', '');
                    $incomingPayment->cISO = $order->Waehrung->cISO;
                    $incomingPayment->cHinweis = (array_key_exists('IDENTIFICATION_UNIQUEID', $args)
                    ? $args['IDENTIFICATION_UNIQUEID'] : '');
                    $this->addIncomingPayment($order, $incomingPayment);
                }
                $this->updateNotificationID($order->kBestellung, $args['IDENTIFICATION_UNIQUEID']);
            }
            /* redirect customer to success page */
            echo $this->getReturnURL($order);

            /*save order */
        } elseif ($HeidelpayResponse->isError()) {
            $error = $HeidelpayResponse->getError();
            echo $this->getErrorReturnURL($order) . '&hperror=' . $error['code'] .
                $this->disableInvoiceSecured($args);
        } elseif ($HeidelpayResponse->isPending()) {
            echo $this->getReturnURL($order);
        }
    }

    /**
     * Verifies notification
     *
     * @return boolean
     * @param Bestellung $order
     * @param array $post
     */
    public function verifyNotification($order, $post)
    {
        if ($post['CLEARING_AMOUNT'] != number_format($order->fGesamtsummeKundenwaehrung, 2, '.', '')) {
            return false;
        }

        if ($post['CLEARING_CURRENCY'] != $order->Waehrung->cISO) {
            return false;
        }
        return true;
    }

    /**
     * Sets payment information as comment in database
     *
     * @param $post response form payment
     * @param $orderId
     */
    public function setPayInfo($post, $orderId)
    {
        $bookingtext = 'Bitte überweisen Sie uns den Betrag von ' . $post['PRESENTATION_AMOUNT'] . ' ' .
            $post['PRESENTATION_CURRENCY'] . ' nach Erhalt der Ware auf folgendes Konto:
        
  Kontoinhaber: ' . $post['CONNECTOR_ACCOUNT_HOLDER'] . '
  IBAN: ' . $post['CONNECTOR_ACCOUNT_IBAN'] . '
  BIC: ' . $post['CONNECTOR_ACCOUNT_BIC'] . '
  
  Geben Sie als Verwendungszweck bitte ausschließlich folgende Identifikationsnummer an:
  ' . $post['IDENTIFICATION_SHORTID'];

        $sql = 'UPDATE `tbestellung` SET 
			`cKommentar` ="' . htmlspecialchars(utf8_decode($bookingtext)) . '" 
			WHERE `cBestellNr` ="' . htmlspecialchars($orderId) . '";';
        $GLOBALS ['DB']->executeQuery($sql, 1);
    }

    /**
     * Error return url clone from PaymentMethod.class because of bestellabschluss case
     * @param Bestellung $order
     * @return string
     */
    public function getErrorReturnURL($order)
    {
        if (!isset($_SESSION['Zahlungsart']->nWaehrendBestellung) ||
            $_SESSION['Zahlungsart']->nWaehrendBestellung == 0) {
            return $order->BestellstatusURL;
        }

        return Shop::getURL() . '/bestellvorgang.php';
    }

    public function disableInvoiceSecured($response)
    {
        if (array_key_exists('CRITERION_INSURANCE-RESERVATION', $response) &&
            $response['CRITERION_INSURANCE-RESERVATION'] === 'DENIED') {
            return '&disableInvoice=true';
        }
        return '';
    }

    public function finalizeOrder($order, $hash, $args)
    {
        global $cEditZahlungHinweis;

        if ($args['PROCESSING_RESULT'] == "ACK") {
            return true;
        } else {
            $cEditZahlungHinweis = rawurlencode($args['PROCESSING_RETURN']) .
                '&hperror=' . $args['PROCESSING_RETURN_CODE'];

            if (isset($args['CRITERION_INSURANCE-RESERVATION']) &&
                $args['CRITERION_INSURANCE-RESERVATION'] === 'DENIED') {
                $cEditZahlungHinweis .= $this->disableInvoiceSecured($args);
            }
            return false;
        }
    }

    /**
     * prepares payment text
     *
     * @param $res
     * @param string $lang
     * @return string
     */
    public function prepaymentText($res, $lang = 'EN')
    {
        if ($lang == 'DE') {
            define(
                'PREPAYMENT_TEXT',
                '<b>Bitte &uuml;berweisen Sie uns den Betrag von {CURRENCY} {AMOUNT} auf folgendes Konto:</b><br /><br />
			Land :         {ACC_COUNTRY}<br>
			Kontoinhaber : {ACC_OWNER}<br>
			Konto-Nr. :    {ACC_NUMBER}<br>
			Bankleitzahl:  {ACC_BANKCODE}<br>
			IBAN:   	   {ACC_IBAN}<br>
			BIC:           {ACC_BIC}<br>
			<br /><br /><b>Geben sie bitte im Verwendungszweck UNBEDINGT die Identifikationsnummer<br />
			{SHORTID}<br />
			und NICHTS ANDERES an.</b>'
            );
        } else {
            define(
                'PREPAYMENT_TEXT',
                '<b>Please transfer the amount of {CURRENCY} {AMOUNT} to the following account:</b><br /><br />
					Country :         {ACC_COUNTRY}<br>
					Account holder :  {ACC_OWNER}<br>
					Account No. :     {ACC_NUMBER}<br>
					Bank Code:        {ACC_BANKCODE}<br>
					IBAN:   		  {ACC_IBAN}<br>
					BIC:              {ACC_BIC}<br>
					<br><br /><b>Please use the identification number <br />
					{SHORTID}<br />
					as the descriptor and nothing else. Otherwise we cannot match your transaction!</b>'
            );
        }

        $repl = array(
            '{CURRENCY}' => $res ['all'] ['PRESENTATION_CURRENCY'],
            '{AMOUNT}' => $res ['all'] ['PRESENTATION_AMOUNT'],
            '{ACC_COUNTRY}' => $res ['all'] ['CONNECTOR_ACCOUNT_COUNTRY'],
            '{ACC_OWNER}' => $res ['all'] ['CONNECTOR_ACCOUNT_HOLDER'],
            '{ACC_NUMBER}' => $res ['all'] ['CONNECTOR_ACCOUNT_NUMBER'],
            '{ACC_BANKCODE}' => $res ['all'] ['CONNECTOR_ACCOUNT_BANK'],
            '{ACC_IBAN}' => $res ['all'] ['CONNECTOR_ACCOUNT_IBAN'],
            '{ACC_BIC}' => $res ['all'] ['CONNECTOR_ACCOUNT_BIC'],
            '{SHORTID}' => $res ['all'] ['IDENTIFICATION_SHORTID']
        );

        return strtr(constant('PREPAYMENT_TEXT'), $repl);
    }
}
