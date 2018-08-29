<?php
/*
 * Santander invoice paymentmethod
 *
 * @license Use of this software requires acceptance of the License Agreement. See LICENSE file.
 * @copyright Copyright © 2016-present heidelpay GmbH. All rights reserved.
 * @link https://dev.heidelpay.de/JTL
 * @author David Owusu
 * @category JTL
 */
require_once PFAD_ROOT . PFAD_PLUGIN . $oPlugin->cVerzeichnis . '/version/' .$oPlugin->nVersion. '/paymentmethod/heidelpay_standard.class.php';

use Heidelpay\PhpPaymentApi\PaymentMethods\SantanderInvoicePaymentMethod;

class heidelpay_sa extends heidelpay_standard
{

    public function setPaymentObject()
    {
        $this->paymentObject = new SantanderInvoicePaymentMethod();
    }
}
