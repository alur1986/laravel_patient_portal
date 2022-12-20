<?php

namespace App\Services;
use App\Account;
use App\Product;

class SalesService
{
    static public function exportInvoiceHTML($invoice_data, $PosInvoiceItem, $patient, $posTransaction, $clinic, $accountID, $user_db, $stripe_currency, $package_products_data = 0)
    {
        config(['database.connections.juvly_practice.database' => env("DB_DATABASE")]);

        $aws_s3_storage_url = env('MEDIA_FOLDER_PATH');
        $account_details = Account::with('accountPrefrence')->where('id', $accountID)->first();
        $product_name = '';

        $storagefolder = $account_details->storage_folder;
        $logo_img = $account_details->logo;
        $invoice_text = $account_details->invoice_text;

        if (UrlService::checkS3UrlValid($aws_s3_storage_url . '/' . $storagefolder . '/admin/thumb_' . $logo_img)) {
            $logo_img_src = $aws_s3_storage_url . '/' . $storagefolder . '/admin/thumb_' . $logo_img;
        } else if (UrlService::checkS3UrlValid($aws_s3_storage_url . '/' . $storagefolder . '/admin/' . $logo_img)) {
            $logo_img_src = $aws_s3_storage_url . '/' . $storagefolder . '/admin/' . $logo_img;
        } else {
            $logo_img_src = public_path() . '/img/new-images/logo.png';
        }

        $name = '';
        if (isset($invoice_data->title) && !empty($invoice_data->title)) {
            $name = $invoice_data->title;
        }

        $Clinicname = '';
        $contact_no = '';

        if (!empty($clinic)) {
            $Clinicname = $clinic->clinic_name;
            $contact_no = $clinic->contact_no;
        }

        $customer_name = "";

        if (isset($patient->firstname) && !empty($patient->firstname)) {
            $customer_name .= $patient->firstname;
        }
        if (isset($patient->lastname) && !empty($patient->lastname)) {
            $customer_name .= " ";
            $customer_name .= $patient->lastname;
        }
        if (empty($customer_name)) {
            if (!empty($invoice_data->from)) {
                $customer_name = $invoice_data->from;
            }
        }

        $address = "";
        if (isset($patient->address_line_1) && !empty($patient->address_line_1)) {
            $address .= $patient->address_line_1;
        }
        if (isset($patient->address_line_2) && !empty($patient->address_line_2)) {
            $address .= " , ";
            $address .= $patient->address_line_2;
        }
        if (isset($patient->city) && !empty($patient->city)) {
            $address .= " , ";
            $address .= $patient->city;
        }
        if (isset($patient->state) && !empty($patient->state)) {
            $address .= " , ";
            $address .= $patient->state;
        }
        if (isset($patient->pincode) && !empty($patient->pincode)) {
            $address .= " , ";
            $address .= $patient->pincode;
        }
        $patien_email = '';
        if (!empty($patient->email)) {
            $patien_email = $patient->email;
        } else {
            if (!empty($invoice_data->from_email)) {
                $patien_email = $invoice_data->from_email;
            }
        }

        #connect db
        config(['database.connections.juvly_practice.database' => $user_db]);

        $refund_amount = 0;
        if (!empty($posTransaction->posTransactionsPayments)) {
            foreach ($posTransaction->posTransactionsPayments as $mode) {
                if ($mode->payment_status == 'refunded') {
                    $refund_amount += $mode->refund_amount;
                }
            }
        }

        if (UrlService::checkS3UrlValid($aws_s3_storage_url . '/' . $storagefolder . '/signatures/thumb_' . $invoice_data->patient_signature)) {
            $patientSignature = $aws_s3_storage_url . '/' . $storagefolder . '/signatures/thumb_' . $invoice_data->patient_signature;
        } else if (UrlService::checkS3UrlValid($aws_s3_storage_url . '/' . $storagefolder . '/signatures/' . $invoice_data->patient_signature)) {
            $patientSignature = $aws_s3_storage_url . '/' . $storagefolder . '/signatures/' . $invoice_data->patient_signature;
        } else {
            $patientSignature = '';
        }

        $html = '';
        $html .= '<table border="0" cellpadding="0" cellspacing="0" width="700">
    <tr>
        <td valign="top">
            <table border="0" cellpadding="5" cellspacing="0" width="700">
                <tr>
                    <td valign="top" width="400">
                        <div style="padding:5px 0px;">
                            <img src="' . $logo_img_src . '" style="height:70px;"/>
                        </div>
                    </td>
                    <td valign="top" width="400" align="right">

                        <div style="font-size:24px;text-align:right;padding-top:22px;padding:10px 0px;">' . $name . '</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>';

        $html .= '<table border="0" cellpadding="0" cellspacing="0" width="700">
    <tr>
        <td colspan="3" valign="top">
            <table border="0" cellpadding="10" cellspacing="0" width="700">
                <tr>
                    <td valign="top">
                        <div style="font-size:14px;color:#777777;line-height:22px;padding:10px 0px;">' . $Clinicname . '<br/>' . $contact_no . '</div>
                    </td>
                </tr>
                <tr>
                    <td valign="top">
                        <div style="font-size:14px;color:#000000;padding:10px 0px 3px;">
                            Invoice to
                        </div>
                        <div style="font-size:20px;color:#777777;font-weight:100;">' . $customer_name . '</div>

                    </td>
                </tr>';

        if (!empty($address)) {

            $html .= '<tr>
                    <td valign="top" width="400">
                        <div style="font-size:14px;color:#000000;padding:10px 0px 3px;">
                            Address
                        </div>

                        <div style="font-size:14px;color:#777777;font-weight:100;">
                            ' . $address . '
                        </div>
                    </td>
                    <td valign="top" width="400"></td>
                </tr>';
        }

        $html .= '<tr>
                    <td valign="top" width="400">
                        <div style="font-size:14px;color:#000000;padding:10px 0px 3px;">
                            Email
                        </div>
                        <div style="font-size:14px;color:#777777;font-weight:100;">
                            ' . $patien_email . '
                        </div>
                    </td>
                    <td valign="top" width="400" align="right">
                        <table width="230" style="float:right;font-size:14px;padding:10px 0px;">
                            <tr>
                                <td align="left">Invoice No:</td>
                                <td style="color:#777777;text-align:right;">' . $invoice_data->invoice_number . '</td>
                            </tr>
                            <tr>
                                <td align="left">Invoice Date:</td>
                                <td style="color:#777777;text-align:right;">' . $invoice_data->created . '</td>
                            </tr>';

        if ($invoice_data->payment_datetime != '') {
            $html .= '<tr>
                                <td align="left">Payment on:</td>
                                <td style="color:#777777;text-align:right;">' . $invoice_data->payment_datetime . '</td>
                            </tr>';
        }
        $html .= '</table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>';

        $html .= '<table border="0" cellpadding="10" cellspacing="0" width="700" style="margin-top:25px;">
    <tr>
        <td style="border-bottom:1px solid #dddddd;"><div style="font-size:13px;color:#000000;text-transform:uppercase;padding:10px 0px;" width="500">';

        $html .= 'Item description';

        $html .= '</div></td>
        <td style="border-bottom:1px solid #dddddd;" align="right"><div style="font-size:13px;color:#000000;text-transform:uppercase;text-align:right;padding:10px 0px;" width="100">&nbsp;</div></td>
        <td style="border-bottom:1px solid #dddddd;" align="right"><div style="font-size:13px;color:#000000;text-transform:uppercase;text-align:right;padding:10px 0px;" width="100" align="right">Price</div></td>

    </tr>
</table>';

        if (!empty($PosInvoiceItem)) {

            foreach ($PosInvoiceItem as $item) {

                if ($item['product']['product_type_label'] == 'medical_supplies') {

                    continue;
                }
                if ($item->product_type == "custom") {
                    $product_name = @$item->custom_product_name;
                } else if ($item->product_type == "others" || $item->product_type == "injectable") {
                    $old_price = 0;
                    if ($item->product_units > 0) {
                        $old_price = $item->total_product_price / $item->product_units;
                    }
                    $productdata = Product::where('id', $item->product_id)->first();

                    if (!$productdata) {
                        return null;
                    }

                    $product_name = $productdata->product_name;
                    if (isset($item['chartingPackage']->name) && strlen($item['chartingPackage']->name) > 1) {
                        $product_name = $item['chartingPackage']->name . ' - ' . $productdata->product_name;
                    }
                    $product_name = $item->product_units . ' units of ' . $product_name . ' priced ' . self::getFormattedCurrency($old_price, $stripe_currency);
                } else if ($item->product_type == "package") {
                    $DiscountPackage_name = $item['discountPackage']->name;
                    $used_product = '';
                    $product_name = $DiscountPackage_name;
                    if (count($package_products_data)) {
                        $i = 1;

                        foreach ($package_products_data[$item->id] as $package) {
                            $used_product = $package['product']->product_name;
                            $i++;
                        }
                    }
                } else if ($item->product_type == "egiftcard") {

                    $redemption_code = !empty($invoice_data->egiftCardsPurchaseFirst->egiftCardVoucher->formatted_redemption_code) ? " (" . $invoice_data->egiftCardsPurchaseFirst->egiftCardVoucher->formatted_redemption_code . ")" : "";
                    $product_name = "eGift card worth amount $" . number_format((float)$item->total_product_price, 2, '.', '') . $redemption_code;
                } else if ($item->product_type == "consultation_fee") {
                    $product_name = "Consultation Fee";
                } else if ($item->product_type == "treatment_plan") {
                    $product_name = 'Treatment Plan';
                } else if ($item->product_type == "monthly_membership" && $item->custom_product_name == "one_time_setup_fee") {
                    $product_name = "One Time Setup Fee";
                } else if ($item->product_type == "monthly_membership" && $item->custom_product_name == "monthly_membership") {
                    $product_name = "Monthly Membership Fee";
                } else if ($item->product_type == "service") {
                    $old_price = $item->total_product_price / $item->product_units;
                    $product_name = $item->product_units . ' service of ' . $item['service']->name . ' priced ' . self::getFormattedCurrency($old_price, $stripe_currency);
                }


                $html .= '<table border="0" cellpadding="10" cellspacing="0" width="700" style="">';
                if ($item->product_type == "monthly_membership" && $item->custom_product_name == "one_time_setup_fee") {
                    if ($item->total_product_price > 0) {
                        $html .= '<tr>
							<td width="500">
							<div style="font-size:14px;color:#777777;padding:10px 0px;">' . $product_name;

                        if (!empty($used_product)) {
                            $html .= '<br/>(' . $used_product . ')';
                        }

                        $html .= '</div>
						</td>
						<td align="right" style="padding:10px 0px;" width="100"><div style="font-size:13px;color:#777777;text-transform:uppercase;text-align:right;">' . self::getFormattedCurrency($item->total_product_price, $stripe_currency) . '</div></td></tr>';
                    }
                } else {
                    $html .= '<tr>
						<td width="500">
							<div style="font-size:14px;color:#777777;padding:10px 0px;">' . ucfirst($product_name);

                    if (!empty($used_product)) {
                        $html .= '<br/>(' . $used_product . ')';
                    }

                    $html .= '</div>
						</td>
						<td align="right" style="padding:10px 0px;" width="100"><div style="font-size:13px;color:#777777;text-transform:uppercase;text-align:right;">' . self::getFormattedCurrency($item->total_product_price, $stripe_currency) . '</div></td></tr>';
                }
                if (!empty($item->per_unit_discount_text)) {
                    $per_unit_discount = '';
                    if ($item->per_unit_discount_amount > 0) {
                        $per_unit_discount = '(' . self::getFormattedCurrency($item->per_unit_discount_amount, $stripe_currency) . ')';
                    } elseif ($item->unit_discount_type == 'complimentry') {
                        $per_unit_discount = '(' . self::getFormattedCurrency($item->total_product_price, $stripe_currency) . ')';
                    }
                    $html .= '<tr>
						<td width="500" style="padding-top:0px">
							<div style="font-size:14px;color:#777777;padding:0px 0px; font-style: italic;">' . $item->per_unit_discount_text;

                    $html .= '</div>
						</td>
						<td align="right" style="padding:0px 0px;" width="100"><div style="font-size:13px;color:#777777;text-transform:uppercase;text-align:right; font-style: italic;">' . $per_unit_discount . '</div></td></tr>';
                }
                $html .= '<tr><td colspan="2" style="border-bottom:1px solid #dddddd;padding:0px;margin:0px;"></td></tr></table>';
            }

        }

        if (!empty((int)$invoice_data->aspire_discount) ||
            !empty((int)$invoice_data->bd_discount) ||
            !empty((int)$invoice_data->prepayment_adjustment) ||
            !empty((int)$invoice_data->package_discount) ||
            !empty((int)$invoice_data->egift_Card_amount)) {

            $html .= ' <table border="0" cellpadding="10" cellspacing="0" width="700" style="margin-top:25px;">
				<tr>
					<td style="border-bottom:1px solid #dddddd;"><div style="font-size:13px;color:#000000;text-transform:uppercase;padding:10px 0px;">Redemptions</div></td>
					<td style="border-bottom:1px solid #dddddd;" align="right"><div style="font-size:13px;color:#000000;text-transform:uppercase;text-align:right;padding:10px 0px;">Amount</div></td>
				</tr>';
            if (!empty((int)$invoice_data->aspire_discount)) {
                $html .= '<tr>
					<td style="border-bottom:1px solid #dddddd;">
						<div style="font-size:14px;color:#777777;padding:10px 0px;">Aspire Discount</div>

					</td>
					<td align="right" style="border-bottom:1px solid #dddddd;padding:10px 0px;"><div style="font-size:13px;color:#777777;text-transform:uppercase;text-align:right;">' . self::getFormattedCurrency($invoice_data->aspire_discount, $stripe_currency) . '</div></td>
				</tr>';
            }

            if (!empty((int)$invoice_data->bd_discount)) {
                $html .= '<tr>
					<td style="border-bottom:1px solid #dddddd;">
						<div style="font-size:14px;color:#777777;padding:10px 0px;">BD Discount</div>

					</td>
					<td align="right" style="border-bottom:1px solid #dddddd;padding:10px 0px;"><div style="font-size:13px;color:#777777;text-transform:uppercase;text-align:right;">' . self::getFormattedCurrency($invoice_data->bd_discount, $stripe_currency) . '</div></td>
				</tr>';
            }

            if (!empty((int)$invoice_data->prepayment_adjustment)) {
                $html .= '<tr>
					<td style="border-bottom:1px solid #dddddd;">
						<div style="font-size:14px;color:#777777;padding:10px 0px;">Wallet Debits</div>
					</td>
					<td align="right" style="border-bottom:1px solid #dddddd;padding:10px 0px;"><div style="font-size:13px;color:#777777;text-transform:uppercase;text-align:right;">' . self::getFormattedCurrency($invoice_data->prepayment_adjustment, $stripe_currency) . '</div></td>
				</tr>';
            }

            if (!empty((int)$invoice_data->package_discount)) {
                $html .= '<tr>
					<td style="border-bottom:1px solid #dddddd;">
						<div style="font-size:14px;color:#777777;padding:10px 0px;">Packages Discount</div>
					</td>
					<td align="right" style="border-bottom:1px solid #dddddd;padding:10px 0px;"><div style="font-size:13px;color:#777777;text-transform:uppercase;text-align:right;">' . self::getFormattedCurrency($invoice_data->package_discount, $stripe_currency) . '</div></td>
				</tr>';
            }

            if (!empty((int)$invoice_data->egift_Card_amount)) {
                foreach ($invoice_data->egiftCardsRedemptions as $eachCard) {
                    $html .= '<tr>
					<td style="border-bottom:1px solid #dddddd;">
						<div style="font-size:14px;color:#777777;padding:10px 0px;">eGiftcard Amount with redemption code ' . $eachCard->eGiftCardVoucher->redemption_code . '</div>
					</td>
					<td align="right" style="border-bottom:1px solid #dddddd;padding:10px 0px;"><div style="font-size:13px;color:#777777;text-transform:uppercase;text-align:right;">' . self::getFormattedCurrency($eachCard->amount, $stripe_currency) . '</div></td>
				</tr>';
                }
            }

            $html .= '</table>';
        }
        $html .= '<table border="0" cellpadding="10" cellspacing="0" width="700" style="margin-top:25px;border:1px solid #000;margin-bottom:25px;">
				<tr>
					<td width="120" style="padding:10px;">
						<div style="font-size:13px;color:#777777;">TAX</div>
						<div style="font-size:17px;color:#000000;font-weight:400;">' . self::getFormattedCurrency($invoice_data->total_tax, $stripe_currency) . '</div>
					</td>';

        $total_doscount = $invoice_data->total_discount + $invoice_data->prepayment_adjustment;
        $html .= '<td width="160">
						<div style="font-size:13px;color:#777777;">ITEM DISCOUNT</div>
						<div style="font-size:17px;color:#000000;font-weight:400;">' . self::getFormattedCurrency($total_doscount, $stripe_currency) . '</div>
					</td>';

        if (!empty((int)$invoice_data->custom_discount)) {
            $html .= '<td width="180">
						<div style="font-size:13px;color:#777777;">CUSTOM DISCOUNT</div>
						<div style="font-size:17px;color:#000000;font-weight:400;">' . self::getFormattedCurrency($invoice_data->custom_discount, $stripe_currency) . '</div>
					</td>';
        }
        if (!empty((int)$invoice_data->tip_amount)) {
            $html .= '<td width="120">
						<div style="font-size:13px;color:#777777;">TIP</div>
						<div style="font-size:17px;color:#000000;font-weight:400;">' . self::getFormattedCurrency($invoice_data->tip_amount, $stripe_currency) . '</div>
					</td>';
        }

        $html .= '<td width="220" style="background:#000000;padding:10px;" align="right">
						<div style="font-size:13px;color:#ffffff;text-align:right;">TOTAL</div>
						<div style="font-size:17px;color:#ffffff;font-weight:100;text-align:right;">' . self::getFormattedCurrency($invoice_data->total_amount) . '</div>
					</td>
				</tr>
			</table>';

        if (!empty($patient)) {
            if ($patient->is_monthly_membership == 1) {
                $html .= '<table border="0" cellpadding="10" cellspacing="0" width="700" style="margin-top:25px;">
				<tr>
					<td style="border-bottom:1px solid #dddddd;"><div style="font-size:13px;color:#000000;text-transform:uppercase;padding:10px 0px;"> Membership Benefits</div></td>
					<td style="border-bottom:1px solid #dddddd;" align="right"><div style="font-size:13px;color:#000000;text-transform:uppercase;text-align:right;padding:10px 0px;">Amount</div></td>
				</tr>
				<tr>
					<td style="border-bottom:1px solid #dddddd;">
						<div style="font-size:14px;color:#777777;padding:10px 0px;">
							Membership savings on today\'s visit
						</div>

					</td>
					<td align="right" style="border-bottom:1px solid #dddddd;padding:10px 0px;">
						<div style="font-size:13px;color:#777777;text-transform:uppercase;text-align:right;">' . self::getFormattedCurrency($invoice_data->membership_benefit, $stripe_currency) . '
						</div>
					</td>
				</tr>
				<tr>
					<td style="border-bottom:1px solid #dddddd;">
						<div style="font-size:14px;color:#777777;padding:10px 0px;">
							Your Membership savings this year
						</div>

					</td>
					<td align="right" style="border-bottom:1px solid #dddddd;padding:10px 0px;">
						<div style="font-size:13px;color:#777777;text-transform:uppercase;text-align:right;">' . self::getFormattedCurrency($patient->membership_benefits_this_year, $stripe_currency) . '
						</div>
					</td>
				</tr>	
			</table>';

            }
        }


        if (!empty($posTransaction->PosTransactionsPayments)) {
            $refund_show = false;
            $html .= '<table border="0" cellpadding="10" cellspacing="0" width="700" style="margin-top:25px;">
				<tr>
					<td style="border-bottom:1px solid #dddddd;"><div style="font-size:13px;color:#000000;text-transform:uppercase;padding:10px 0px;">Payments</div></td>
					<td style="border-bottom:1px solid #dddddd;" align="right"><div style="font-size:13px;color:#000000;text-transform:uppercase;text-align:right;padding:10px 0px;">Amount</div></td>
				</tr>';

            foreach ($posTransaction->PosTransactionsPayments as $mode) {
                if ($mode->payment_status == 'refunded') {
                    $refund_show = true;

                }
                $html .= '<tr>
					<td style="border-bottom:1px solid #dddddd;">
						<div style="font-size:14px;color:#777777;padding:10px 0px;">';
                if ($mode->payment_mode == 'cc') {
                    $payment_mode = 'Credit Card ending ' . substr($mode->cc_number, -4);
                    $html .= $payment_mode;
                } elseif ($mode->payment_mode == 'care_credit') {
                    $html .= 'Care Credit';
                    if ($mode->care_credit_note != '') {
                        $html .= "<div style='font-size:13px;color:#aaaaaa;font-style:italic;font-weight:100';>Note - " . $mode->care_credit_note . " </div>";
                    }
                } elseif ($mode->payment_mode == 'greensky') {
                    $html .= 'Greensky';
                    if ($mode->care_credit_note != '') {
                        $html .= "<div style='font-size:13px;color:#aaaaaa;font-style:italic;font-weight:100';>Note - " . $mode->greensky_note . " </div>";
                    }
                } else {
                    if ($mode->payment_mode == 'check') {
                        $cheque_no = !empty($mode->cheque_no) ? " #" . $mode->cheque_no : "";
                        $html .= ucfirst($mode->payment_mode) . $cheque_no;
                    } else {
                        $html .= ucfirst($mode->payment_mode);
                    }
                }
                $html .= '</div>

					</td>
					<td align="right" style="border-bottom:1px solid #dddddd;padding:10px 0px;"><div style="font-size:13px;color:#777777;text-transform:uppercase;text-align:right;">' . self::getFormattedCurrency($mode->total_amount, $stripe_currency) . '</div></td>
				</tr>';
            }

            $html .= '</table>';


        }

        if (!empty($posTransaction->PosTransactionsPayments) && $refund_show == true) {

            $html .= '<table border="0" cellpadding="10" cellspacing="0" width="700" style="margin-top:80px;">
					<tr>
						<td style="border-bottom:1px solid #dddddd;"><div style="font-size:13px;color:#000000;text-transform:uppercase;padding:10px 0px;">REFUND</div></td>
						<td style="border-bottom:1px solid #dddddd;" align="right"></td>
					</tr>';


            foreach ($posTransaction->PosTransactionsPayments as $mode) {

                if ($mode->payment_status == 'refunded') {


                    if ($mode->payment_mode == 'cc') {
                        $html .= 'Credit Card';
                    } else if ($mode->payment_mode == 'wallet') {
                        $html .= 'Wallet';
                    } else if ($mode->payment_mode == 'care_credit') {
                        $html .= 'Care Credit';
                    } else {
                        $html .= 'Ca
								sh';
                    }

                    $payment_mode = $mode->payment_mode;
                    if ($mode->payment_mode == 'check') {
                        $payment_mode = !empty($mode->cheque_no) ? "Check #" . $mode->cheque_no : "Check";
                    } elseif ($mode->payment_mode == 'care_credit') {
                        $payment_mode = 'Care Credit';
                    } elseif ($mode->payment_mode == 'greensky') {
                        $payment_mode = 'Greensky';
                    } elseif ($mode->payment_mode == 'cash') {
                        $payment_mode = 'Cash';
                    }
                    $html .= '<tr>
							<td style="border-bottom:1px solid #dddddd;">
								<div style="font-size:14px;color:#777777;padding-top:10px;">' . $payment_mode . '</div>
								<div style="font-size:13px;color:#aaaaaa;font-style:italic;font-weight:100;padding-bottom:10px;">Reason: ' . str_replace('_', ' ', $mode->refund_reason) . '</div>
							</td>
							<td align="right" style="border-bottom:1px solid #dddddd;"><div style="font-size:14px;color:#000000;text-transform:uppercase;text-align:right;">' . self::getFormattedCurrency($mode->refund_amount, $stripe_currency) . '</div></td>
						</tr>';


                }

            }
            $html .= '</table>';

        }

        if ($patientSignature != "") {
            $html .= '<table border="0" cellpadding="10" cellspacing="0" width="700" style="margin-top:0px;border-top:1px solid #dddddd;">
			   <tr>
				   <td style="padding:20px 0px;" width="400">
					   <div style="font-size:13px;color:#777777;width:400px;">
						   <img src="' . $patientSignature . '" style="width:300px;display:inline-block;"><br/>
						   <b>(' . $customer_name . ')</b>
					   </div>
				   </td>
				   <td width="400">
					   &nbsp;
					</td>
			   </tr>
		   </table>';
        }
        $html .= ' <table border="0" cellpadding="10" cellspacing="0" width="700" style="margin-top:0px;margin-bottom:20px;border-top:1px solid #dddddd;border-bottom:1px solid #dddddd;">
			   <tr>
				   <td style="padding:10px 10px;">
					   <div style="font-size:13px;color:#777777;">
						   ' . $invoice_text . '
						   
					   </div>
				   </td>
			   </tr>
		   </table>';


        $html .= '<table border="0" cellpadding="10" cellspacing="0" width="700" style="margin-top:-10px;">
				<tr>
					<td width="400">
						<div style="font-size:18px;font-weight:600;color:#000000;">' . $Clinicname . '</div>
					</td>
					<td width="400" align="right">
						<div style="font-size:14px;font-weight:600;color:#777777;text-align:right;">' . $contact_no . '</div>
					</td>
				</tr>
			</table>';

        return $html;

    }

    static public function getFormattedCurrency($amount = 0, $symbol = null)
    {
        if (empty($symbol)) {
            $currency = '$';
        } else {
            $currency = $symbol;
        }

        if ($amount) {
            $amount = (float)$amount;
            return $currency . number_format($amount, 2);
        } else {
            return $currency . '0.00';
        }
    }
}
