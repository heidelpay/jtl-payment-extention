<?php
/*
 * Giropay paymentmethod
 *
 * @license Use of this software requires acceptance of the License Agreement. See LICENSE file.
 * @copyright Copyright © 2016-present heidelpay GmbH. All rights reserved.
 * @link https://dev.heidelpay.de/JTL
 * @author David Owusu
 * @category JTL
 */
require_once $oPlugin->cPluginPfad . 'paymentmethod/heidelpay_standard.class.php';

use Heidelpay\PhpPaymentApi\PaymentMethods\GiropayPaymentMethod;

class heidelpay_gp extends heidelpay_standard
{
    public function setPaymentObject()
    {
        $this->paymentObject = new GiropayPaymentMethod();
    }
}
