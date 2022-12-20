<?php

namespace App\Services;

use App\AccountActiveCampaign;
use App\AccountConstantContact;
use App\AccountHubspot;
use App\AccountMailchimp;
use App\AccountPrefrence;
use App\AccountZoho;
use App\Clinic;
use App\Helpers\EmailHelper;
use App\Helpers\MembershipHelper;
use App\Helpers\UploadExternalHelper;
use App\MonthlyMembershipInvoice;
use App\MonthlyMembershipInvoicePayment;
use App\Patient;
use App\PatientMembershipSubscriptionProduct;
use App\PatientWallet;
use App\PosInvoice;
use App\PosTransaction;
use App\PosTransactionsPayment;
use App\Product;
use App\Users;
use Auth;
use Config;
use Hashids\Hashids;
use Session;
use View;
use App\Account;
use App\Helpers\AccountHelper;
use App\Helpers\PatientUserHelper;
use App\MembershipAgreement;
use App\MembershipTier;
use App\PatientAccount;
use App\PatientMembershipSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use App\Traits\Integration;
use App\Traits\Zoho;
use Exception;

class MembershipService
{
    use Integration;
    use Zoho;

    static public function getActiveMemberships($user_id, $account_id)
    {
        $database_name = AccountHelper::accountDatabaseName($account_id);
        $patient = PatientUserHelper::getPatient($user_id, $account_id);

        #switch DB
        switchDatabase($database_name);
        $membershipTiers = DB::connection('juvly_practice')->table('patient_membership_subscriptions as pms')
            ->join('membership_tiers as mt', 'pms.membership_tier_id', '=', 'mt.id')
            ->where('patient_id', $patient->patient_id)
            ->whereDate('subscription_valid_upto', '>=', date('Y-m-d'))
            ->where('subscription_status', 1)
            ->select('*', 'pms.id')
            ->get();

        #switch DB
        switchDatabase();
        return $membershipTiers;
    }

    static public function getMembershipAvailableTypes($account_id)
    {
        $database_name = AccountHelper::accountDatabaseName($account_id);

        #switch DB
        switchDatabase($database_name);

        $account_preferences = AccountHelper::getAccountPreferences($account_id);

        if ($account_preferences && $account_preferences->membership_tier == 'single') {
            $membershipAgreement = MembershipAgreement::find($account_preferences->membership_agreement_id);

            $acc_pref_membership_tier = (object)[
                "id" => "0",
                "tier_name" => $account_preferences->recurly_program_name,
                "discount_percentage" => "0",
                "membership_payment_options" => $account_preferences->membership_payment_options,
                "price_per_month" => $account_preferences->mothly_membership_fees,
                "price_per_year" => $account_preferences->yearly_membership_fees,
                "one_time_setup_fee" => $account_preferences->one_time_membership_setup,
                "membershipAgreement" => $membershipAgreement,
            ];

            $membershipTiers = [
                $acc_pref_membership_tier
            ];
        } else {
            $membershipTiers = MembershipTier::with('membershipAgreement')->where('status', 0)->where('active', 0)->where('show_membership_on_portal', 1)->get();
        }
        #switch DB
        switchDatabase();

        return $membershipTiers;
    }

    static public function getMembershipContract($user_id, $account_id, $membership_id)
    {
        $database_name = AccountHelper::accountDatabaseName($account_id);

        $patient = PatientUserHelper::getPatient($user_id, $account_id);
        $patient_id = $patient->patient_id;

        $account_detail = Account::where('id', $account_id)->first();

        $storage_folder = $account_detail->storage_folder;
        $data = null;

        #connect db
        switchDatabase($database_name);

        $membership = PatientMembershipSubscription::with(['patient', 'clinic'])->find($membership_id);

        if (!$membership) {
            return "nonexistent";
//            return view('errors.404');
        }

        if($membership->patient->id!=$patient_id){
            return "forbidden";
        };

        $to_timezone = 'America/New_York';
        $data['agreement_title'] = $membership->agreement_title;
        $data['agreement_text'] = $membership->agreement_text;
        $patient_signature = $membership->patient_signature;
        $signed_on = $membership->signed_on;
        $from_timezone = 'America/New_York';

        if (!empty($membership->clinic->timezone)) {
            $to_timezone = $membership->clinic->timezone;
        }

        $signed_on = convertTimeByTZ($signed_on, $from_timezone, $to_timezone, 'datetime');

        $date_time = explode(' ', $signed_on);
//        $aws_s3_storage_url = config("constants.default.aws_s3_storage_url");
        $dateFormat = AccountHelper::phpDateFormat($account_id);
        $dateSigned = getDateFormatWithOutTimeZone($date_time[0], $dateFormat, true);
        $signed_on = $dateSigned . ' ' . date('g:ia', strtotime($date_time[1]));
        $ar_media_path = env('MEDIA_URL');
        $ar_media_path = "https://dev-ar-media.s3-us-west-2.amazonaws.com/";
        if (isset($account_detail->logo) && $account_detail->logo != '') {
            $logo_img_src = $ar_media_path . $storage_folder . '/admin/' . $account_detail->logo;
        } else {
            $logo_img_src = env('NO_LOGO_FOR_PDF');
        }

        $data['signed_on'] = $signed_on;
        $data['patient_signature'] = $patient_signature;
        $data['siganture_url'] = $ar_media_path . $storage_folder . '/patient_signatures/' . $patient_signature;
        $data['patient_name'] = @$membership->patient->firstname . ' ' . $membership->patient->lastname;
        $data['account_id'] = $account_id;
        $data['account_logo'] = $logo_img_src;

        $media_path = public_path();
        $media_url = url('/');

        $view = view('patient_signature', ['data' => $data])->render();
        $view = preg_replace("/\t|\n/", "", $view);

        error_reporting(0);
        $mpdf = new \Mpdf\Mpdf();

        $mpdf->curlAllowUnsafeSslRequests = true;
        $mpdf->WriteHTML($view);

        $pdf_title = rand(10, 100) . $account_id . $patient_id . rand(10, 100) . date('ymdhis');
        $dir = $media_path . '/stripeinvoices/';
        $filename = $pdf_title . ".pdf";

        $fpath = $dir . $filename;
        $mpdf->Output($fpath, 'F');

        $membership_contract_url = $media_url . '/stripeinvoices/' . $pdf_title . '.pdf';

        switchDatabase();
        return $membership_contract_url;
    }

    static public function getCreateMembership($database_name, $account_preferences, $countries, $currency_symbol, $pos_gateway, $account_id, $user_id = null, $request = null)
    {
        $membership_tiers = MembershipTier::where('status', 0)->where('show_membership_on_portal', 1)->where('active', 0)->with(['multiTierproducts' => function ($q) {
            $q->where('status', 0)->with('product');
        }]);

        if (Auth::check()) {
            switchDatabase($database_name);

            if ($user_id) {
                $patient = PatientUserHelper::getPatient($user_id, $account_id);
                $patient_id = $patient->patient_id;
            } else {
                $patient_id = $request->session()->get('patient_id');
            }

            $patient_user = PatientAccount::with('Patient')->where('patient_id', $patient_id)->first();
            if (null != $patient_user['patient_id'] && !empty($patient_user['Patient'])) {

                $membership_tiers_count = $membership_tiers->count();

                if ($account_preferences->membership_tier == 'multiple') {
                    $patientMultiMemberships = PatientMembershipSubscription::where('patient_id', $patient_id)->where('membership_tier_id', '!=', 0);

                    $patientMultiMemberships = $patientMultiMemberships->get();
                    $patientMultiMembershipsCount = count($patientMultiMemberships);
                    $patient_membership_tier_ids = [];
                    if (!empty($patientMultiMemberships)) {
                        foreach ($patientMultiMemberships as $patient_member_tier) {
                            $patient_membership_tier_ids[] = $patient_member_tier->membership_tier_id;
                        }
                    }

                    if ($request) {
                        if ($patientMultiMembershipsCount == $membership_tiers_count) {
                            #it means you can not create further membership
                            $request->session()->put('patient_is_monthly_membership', 1);
                        } else {
                            $request->session()->put('patient_is_monthly_membership', 0);
                        }
                    }

                    $membership_tiers = $membership_tiers->whereNotIn('id', $patient_membership_tier_ids);
                } else {
                    if ($request) {
                        $patientSingleMembership = PatientMembershipSubscription::where('patient_id', $patient_id)->where('membership_tier_id', '=', 0)->count();
                        if ($patientSingleMembership) {
                            #it means you can not create further membership
                            $request->session()->put('patient_is_monthly_membership', 1);
                        } else {
                            $request->session()->put('patient_is_monthly_membership', 0);
                        }
                    }
                }
                if ($request) {
                    $request->session()->put('patient', $patient_user['Patient']);
                }
            } else {
                if ($request) {
                    $request->session()->put('patient_is_monthly_membership', 0);
                }
            }

        }
        $membership_tiers = $membership_tiers->orderBy('tier_name', 'ASC')->get();

        if ($account_preferences->membership_tier == 'multiple' && count($membership_tiers) == 0) {
            if ($request) {
                Session::put('error', 'At least one membership setting is required');
            }
            return Redirect::to('login');
        }

        if ($account_preferences->membership_tier == 'single' && $account_preferences->show_membership_on_portal == 0) {
            if ($request) {
                Session::put('error', 'At least one membership setting is required');
            }
            return Redirect::to('login');
        }

        if ($account_preferences->membership_tier == 'single') {
            $agreement_id = $account_preferences->membership_agreement_id;

        } elseif ($account_preferences->membership_tier == 'multiple') {
            $agreement_id = @$membership_tiers[0]->membership_agreement_id;

        }
        $membsership_agreement = MembershipAgreement::where('id', $agreement_id)->where('status', 0)->first();

        if ($request) {
            AccountHelper::setSessionAppointmentSettingForPatient($request);
        }

        return view('subscription.become_a_member')
            ->with('countries', $countries)
            ->with('membership_tiers', $membership_tiers)
            ->with('membsership_agreement', $membsership_agreement)
            ->with('currency_symbol', $currency_symbol)
            ->with('pos_gateway', $pos_gateway)
            ->with('account_preferences', $account_preferences);
    }

    static public function postCreateMembership($input, $database_name, $ip, $user_id, $account, $account_preferences, $pos_gateway, $clinic_id)
    {
        $clinicID = MembershipHelper::getClinicID($account);

        if (!empty($input['zipcode'])) {
            $input['pincode'] = $input['zipcode'];
        }
        $postdata['upload_type'] = 'patient_signatures';
        $postdata['account']['storage_folder'] = $account->storage_folder;
        $postdata['user_data']['id'] = $account->admin_id;
        $postdata['api_name'] = 'upload_patient_signature';

        $postdata['image_data'] = $input['image_data'];
        $file = file_get_contents($postdata['image_data']);
        $file_url = "data:image/png;base64," . base64_encode($file);
        $postdata['image_data'] = $file_url;

        if (!empty($postdata['image_data'])) {
            $signatureResponse = UploadExternalHelper::uploadExternalData($postdata);

            if ($signatureResponse->status != 200 || empty($signatureResponse->data->file_name)) {
                $response['status'] = 0;
                $response['msg'] = 'Signature uploading failed';
                return $response;
            }
        }

        $input['patient_signature'] = null;
        $input['signed_on'] = null;

        if (isset($signatureResponse) && !empty($signatureResponse->data->file_name)) {
            $signed_on = date('Y-m-d H:i:s');
            $input['signed_on'] = getCurrentTimeNewYork($signed_on);
            $input['patient_signature'] = $signatureResponse->data->file_name;
        }

        $input['purchased_ip'] = $ip;

        $input['account_preferences'] = $account_preferences;
        $input['clinic_id'] = $clinic_id;

        $patient = PatientUserHelper::getPatient($user_id, $account->id);

        $patient_id = $patient->patient_id;

        $patient_info = Patient::find($patient_id);
        $phone_number = $patient_info->phoneNumber;
        $patient_email = $patient_info->email;

        $patient_first_name = $patient_info->firstname;
        $patient_last_name = $patient_info->lastname;
        $patient_email = $patient_info->email;

        $patient_membership_data = PatientMembershipSubscription::where('patient_id', $patient_id);
        if ($account_preferences->membership_tier == 'multiple') {
            if (empty($input['membership_type_id'])) {
                $response['status'] = 0;
                $response['msg'] = 'Please select membership';
                return $response;
            }
            $patient_membership_data = $patient_membership_data->where('membership_tier_id', $input['membership_type_id'])->first();
        } else {
            $patient_membership_data = $patient_membership_data->where('membership_tier_id', 0)->first();
        }

        if (($patient_membership_data) && ($patient_membership_data->subscription_status == 1 || $patient_membership_data->start_type == 'future')) {
            $response['status'] = 0;
            $response['msg'] = 'You have already subscribed monthly membership plan';
            return $response;
        }

        $stripe_config = MembershipHelper::getStripeConfig($account->id, $account, $clinicID);
        $error_msg_config = 'Stripe user id not found';

        if (!$stripe_config) {
            $response['status'] = 0;
            $response['msg'] = $error_msg_config;
            return $response;
        }

        if ($stripe_config->clinic_id > 0) {
            $clinic_id = $stripe_config->clinic_id;
        }
        $input['clinic_id'] = $clinic_id;
        $membership_type = $account_preferences->mothly_membership_type;
        $monthly_amount = $account_preferences->mothly_membership_fees;
        $membership_tier_id = 0;
        $input['membership_tier_discount'] = 0;
        $one_time_amount = $account_preferences->one_time_membership_setup;
        $membership_options = $account_preferences->membership_payment_options;
        $membershipFrequency = null;

        if ($account_preferences->membership_tier == 'single') {
            $membership_type_name = $account_preferences->recurly_program_name;
            if ($membership_options == 'monthly') {
                $monthYearAmount = $monthly_amount;
                $membershipFrequency = 'monthly';
            } elseif ($membership_options == 'yearly') {
                $monthYearAmount = $account_preferences->yearly_membership_fees;
                $membershipFrequency = 'yearly';
            } elseif ($membership_options == 'both') {
                if (empty($input['frequency'])) {
                    $response['status'] = 0;
                    $response['msg'] = 'Please select frequency';
                    return $response;
                }
                if ($input['frequency'] == 'month') {
                    $monthYearAmount = $monthly_amount;
                    $membershipFrequency = 'monthly';
                } elseif ($input['frequency'] == 'year') {
                    $monthYearAmount = $account_preferences->yearly_membership_fees;
                    $membershipFrequency = 'yearly';
                }
            }
            $membership_tiers = null;
            $add_to_wallet_status = $account_preferences->add_fees_to_client_wallet;
        } elseif ($account_preferences->membership_tier == 'multiple') {
            if (isset($input['membership_type_id'])) {
                $membership_tier_id = $input['membership_type_id'];
            }

            $membership_tiers = MembershipTier::where('id', $membership_tier_id)->where('status', 0)
                ->where('active', 0)->where('show_membership_on_portal', 1)->first();
            if (!$membership_tiers) {
                $response['status'] = 0;
                $response['msg'] = 'Membership tier invalid';
                return $response;
            }

            $membership_type_name = $membership_tiers->tier_name;
            $membership_options = $membership_tiers->membership_payment_options;
            $mothly_membership_fees = $membership_tiers->price_per_month;
            $price_per_year = $membership_tiers->price_per_year;
            $one_time_setup_fees = $membership_tiers->one_time_setup_fee;
            $input['membership_tier_discount'] = $membership_tiers->discount_percentage;
            $one_time_amount = $one_time_setup_fees;
            $monthly_amount = $mothly_membership_fees;

            if ($membership_options == 'monthly') {
                $monthYearAmount = $monthly_amount;
                $membershipFrequency = 'monthly';
            } elseif ($membership_options == 'yearly') {
                $monthYearAmount = $price_per_year;
                $membershipFrequency = 'yearly';
            } elseif ($membership_options == 'both') {
                if (empty($input['frequency'])) {
                    $response['status'] = 0;
                    $response['msg'] = 'Please select frequency';
                    return $response;
                }
                if ($input['frequency'] == 'month') {
                    $monthYearAmount = $monthly_amount;
                    $membershipFrequency = 'monthly';
                } elseif ($input['frequency'] == 'year') {
                    $monthYearAmount = $price_per_year;
                    $membershipFrequency = 'yearly';
                }
            }
            $add_to_wallet_status = $membership_tiers->add_fees_to_client_wallet;
        } else {
            $response['status'] = 0;
            $response['msg'] = 'Membership tier invalid';
            return $response;
        }

        if (empty($membershipFrequency)) {
            $response['status'] = 0;
            $response['msg'] = 'Membership Frequency invalid';
            return $response;
        }

        $input['membership_tier_id'] = $membership_tier_id;
        $sub_total = $monthYearAmount + $one_time_amount;
        $monthYearAmountForInvoice = $monthYearAmount;
        $total_discount = 0;
        $input['discount_type'] = null;
        $input['discount_value'] = 0;
        $input['discount_duration'] = 'limited';
        $input['limited_duration'] = 0;
//        if( $membership_type == 'paid' && $input['applied_discount_coupon'] == 1 && !empty($input['coupon_code'])) {
//            $is_valid_coupon = $this->isValidCoupon($patient_id,$input['coupon_code']);
//            if(isset($is_valid_coupon['status']) && $is_valid_coupon['status'] == 0){
//                $response['status'] 	= $is_valid_coupon['status'];
//                $response['msg'] 		= $is_valid_coupon['msg'];
//                return $response;
//            }
//            $coupon = $is_valid_coupon['data'];
//
//            if($coupon['discount_type'] == 'percentage'){
//                $discount_percent = $coupon['discount_value'];
//                $total_discount   = ( $monthYearAmount * $discount_percent ) / 100;
//                $monthYearAmount     =  $monthYearAmount - $total_discount;
//
//            }elseif($coupon['discount_type'] == 'dollar') {
//                $total_discount = $coupon['discount_value'];
//                if( $total_discount >= $monthYearAmount ){
//                    $total_discount = $monthYearAmount; /*if total_discount is greater than monthYearAmount then giving the full discount = monthYearAmount */
//                }
//                $monthYearAmount   = $monthYearAmount - $total_discount;
//
//            }else {
//                $response['status'] 	= 0;
//                $response['msg'] 		= $is_valid_coupon['msg'];
//                return $response;
//            }
//            $input['discount_type'] = $coupon['discount_type'];
//            $input['discount_value'] = $coupon['discount_value'];
//            $input['discount_duration'] = $coupon['discount_duration'];
//            $input['limited_duration'] 	= $coupon['limited_duration'];
//        }

        $total_amount = ($monthYearAmount + $one_time_amount);

        if ($membership_type == 'free' && $account_preferences->membership_tier == 'single') {
            $total_amount = $one_time_amount;
            $monthYearAmount = 0;
        }

        if ($total_amount == 0 && $membership_type == 'free') {
            $finalStatus = self::saveFreeMonthlyMembership($input, $patient_id, $add_to_wallet_status, $account, $patient_email);
            if ($finalStatus) {
                $response['thankyou_message'] = $account_preferences['thankyou_message'];
                $response['status'] = 1;
                $response['msg'] = 'Subscription added successfully!';
                return $response;
            } else {
                $response['status'] = 0;
                $response['msg'] = 'Something went wrong, please contact with support team';
                return $response;
            }
        }

        $default_currency = 'USD';
        if ($account->stripe_currency) {
            $default_currency = $account->stripe_currency;
        }
        $admin_id = $account->admin_id;
        if ($pos_gateway == 'stripe') {
            $customer_data = self::createStripeCustomer($input, $patient_email);
            if (!$customer_data['status']) {
                return $customer_data;
            }
        }
        if ($total_amount > 0) {

            $charge_data = self::createStripeCharge($input, $customer_data['data'], $total_amount, $default_currency, $stripe_config, $account, $patient_email);

            if (!$charge_data['status']) {
                return $charge_data;
            }
            $input['monthly_amount'] = $monthYearAmount;
            $input['one_time_amount'] = $one_time_amount;
            $input['membership_tiers'] = $membership_tiers;
            $input['agreement_id'] = $membership_tiers->membership_agreement_id;
            $input['membershipFrequency'] = $membershipFrequency;
            $save_subscription = self::saveSubscription($charge_data, $patient_id, $input, $account_preferences, $account, $pos_gateway);
            if (!$save_subscription['status']) {
                return $save_subscription;
            }

            $patient_wallet = PatientWallet::where('patient_id', $patient_id)->first();
            $walletAmount = $monthYearAmount;

            if (empty($patient_wallet)) {
                $patient_wallet_id = self::createPatientWallet($patient_id, $walletAmount, $add_to_wallet_status);

            } else {
                $patient_wallet_id = self::updatePatientWallet($patient_id, $patient_wallet, $walletAmount, $add_to_wallet_status);
            }

            if ($add_to_wallet_status == 1) {
                if ($patient_wallet_id > 0) {
                    MembershipHelper::addPatientWalletCredit($patient_id, $patient_wallet_id, $walletAmount, $admin_id, $membershipFrequency);
                }
            }

            $membership_id = $save_subscription['data']->id;
            self::addFreeProductsToClientWallet($membership_id, $patient_id, $admin_id);

            $customerCardBrand = '';
            $customerCardLast4 = '';
            if (!empty($pos_gateway) && $pos_gateway == "clearent") {
                $customerCardBrand = $charge_data['data']["payload"]["tokenResponse"]['card-type'];
                $customerCardLast4 = $charge_data['data']["payload"]["transaction"]['last-four'];
                $host_transaction_id = $charge_data['data']["payload"]["transaction"]['id'];
                $invoiceNumber = $charge_data['data']['invoice_number'];
                $platformFee = $charge_data['data']['platformFee'];
                $apriva_transaction_data = json_encode($charge_data['data']["payload"]);
            } else {
                if (isset($charge_data['data']->source->brand)) {
                    $customerCardBrand = $charge_data['data']->source->brand;
                }
                if (isset($charge_data['data']->source->last4)) {
                    $customerCardLast4 = $charge_data['data']->source->last4;
                }
                $host_transaction_id = $charge_data['data']->id;
                $invoiceNumber = 'AR00' . $account->id . '0' . $patient_id . '0' . time();
                $platformFee = null;
                $apriva_transaction_data = null;
            }

            $currentTime = date('Y-m-d H:i:s');
            $currentTime = getCurrentTimeNewYork($currentTime);
            $posInvoiceData = array(
                'invoice_number' => $invoiceNumber,
                'customerCardBrand' => $customerCardBrand,
                'customerCardLast4' => $customerCardLast4,
                'patient_id' => $patient_id,
                'clinic_id' => $clinicID,
                'sub_total' => $sub_total,
                'total_tax' => 0,
                'total_amount' => $total_amount,
                'treatment_invoice_id' => 0,
                'patient_email' => $patient_email,
                'status' => "paid",
                'created' => $currentTime,
                'paid_on' => $currentTime,
                'product_type' => 'monthly_membership',
                'monthly_amount' => $monthYearAmountForInvoice,
                'one_time_amount' => $one_time_amount,
                'total_discount' => $total_discount,
                'platformFee' => $platformFee,
                'apriva_transaction_data' => $apriva_transaction_data
            );
            try {
                if ($membershipFrequency == 'monthly') {
                    $posInvoiceData['custom_product_name'] = 'monthly_membership';
                }
                if ($membershipFrequency == 'yearly') {
                    $posInvoiceData['custom_product_name'] = 'yearly_membership';
                }

                $posInvoiceData['host_transaction_id'] = $host_transaction_id;
                self::createPosInvoice($posInvoiceData, 'monthly_membership', $admin_id, 'virtual', $pos_gateway);
                $zohoData['account'] = $account;
                $zohoData['patient_id'] = $patient_id;
                $zohoData['total_amount'] = $total_amount;
                $zohoData['posInvoiceData'] = $posInvoiceData;
                $zohoData['subject'] = ucfirst($membershipFrequency);
                self::createZohoInvoice($zohoData);
                $subscriptionID = $save_subscription['data']->id;
                $input['account'] = $account;
                $input['account_preferences'] = $account_preferences;
                self::saveMonthlyMembershipInvoice($posInvoiceData, $charge_data, $subscriptionID, $input, $pos_gateway);

                MembershipHelper::savePatientLog($database_name, 0, 'patient', 0, 'add', 'membership plan', $patient_id, 'add');
                $save_subscription['thankyou_message'] = $account_preferences['thankyou_message'];
                $res_data = [];
                $res_data['membership_type_id'] = (string)$membership_tier_id;

                $res_data['membership_type_name'] = $membership_type_name;
                $res_data['first_name'] = $patient_first_name;
                $res_data['last_name'] = $patient_last_name;
                $res_data['email'] = $patient_email;
//                $res_data['sign_file'] = "";
//                $res_data['payment_token'] = "";

                $save_subscription['data'] = $res_data;

                return $save_subscription;
            } catch (\Exception $e) {
                $response['status'] = 0;
                $response['msg'] = $e->getLine() . '--' . $e->getMessage() . ': Something went wrong, please contact with support team';
                return $response;
            }
        } else {
            $charge_data['account_code'] = $customer_data['data']->id;
            $charge_data['subscription_uuid'] = $customer_data['data']->id;
            $charge_data['stripe_card_details'] = $customer_data['data']->sources->data[0]->brand . " ending " . $customer_data['data']->sources->data[0]->last4;

            $input['membership_tiers'] = $membership_tiers;
            $input['membershipFrequency'] = $membershipFrequency;
            $save_subscription = self::saveSubscription($charge_data, $patient_id, $input, $account_preferences, $account, $pos_gateway);

            if (!$save_subscription['status']) {
                return $save_subscription;
            }

            $save_subscription['thankyou_message'] = $account_preferences['thankyou_message'];
            unset($save_subscription['data']);

            $res_data = [];
            $res_data['membership_type_id'] = (string)$membership_tier_id;
            $res_data['membership_type_name'] = $membership_type_name;
            $res_data['first_name'] = $patient_first_name;
            $res_data['last_name'] = $patient_last_name;
            $res_data['email'] = $patient_email;
//            $res_data['sign_file'] = "";
//            $res_data['payment_token'] = "";

            $save_subscription['data'] = $res_data;

            return $save_subscription;
        }
    }

    public static function createOrFindPatient($input, $user_id, $account_id, $patient_phone, $patient_email)
    {

        $account_preferences = $input['account_preferences'];

        $patient = PatientUserHelper::getPatient($user_id, $account_id);
        $patient_id = $patient->patient_id;

        $patient = new Patient();

        if (Auth::check() && $patient->where('id', $patient_id)->where('status', 0)->exists()) {
            $patient_user = $patient->where('id', $patient_id)->where('status', 0)->first();
        } else {
            $patient_user = $patient->where('firstname', trim($input['first_name']))->where('email', trim($patient_email))->where('status', 0)->first();
        }

        if (!$patient_user) {
            $patient->user_id = 0;
            $patient->firstname = $input['first_name'];
            $patient->lastname = $input['last_name'];
            $patient->email = $patient_email;
            $patient->gender = 2;
            $patient->phoneNumber = $patient_phone;
            $patient->address_line_1 = @$input['street_address'];
            $patient->pincode = @$input['zipcode'];
            $patient->city = @$input['city'];
            $patient->state = @$input['state'];
            $patient->country = @$input['country'];
            if ($patient->save()) {
                self::patientIntegrationProcess($account_preferences, $patient);
                if ($account_preferences->patient_portal_activation_link) {
                    $formData = array(
                        'selClinic' => $input['clinic_id'],
                        'formData' => array(
                            'firstname' => $input['first_name'],
                            'lastname' => $input['last_name'],
                            'email' => $patient_email,
                        )
                    );
                    $ppl_email_sent_status = self::enablePatientPortalAccessAndSendMail($formData, $patient->id, $account_id);
                }
                return $patient->id;
            } else {
                return 0;
            }
        } else {
            return $patient_user->id;
        }
    }

    public static function patientIntegrationProcess($account_prefrence, $patient)
    {

        $data = array();
        $id = $patient['id'];
        $data['id'] = $patient['id'];
        $data['First_Name'] = $patient['firstname'];
        $data['Last_Name'] = $patient['lastname'];
        $data['Email'] = $patient['email'];
        if (isset($patient['phoneNumber'])) {
            $data['phoneNumber'] = $patient['phoneNumber'];
        }
        if (isset($patient['user_image'])) {
            $data['user_image'] = $patient['user_image'];
        }
        if (isset($patient['address_line_1'])) {
            $data['address'] = $patient['address_line_1'];
        }
        $account_id = $account_prefrence->account_id;
        $temp = array();
        $temp[0] = $data;

        $AccountZoho = AccountZoho::where('account_id', $account_id)->where('sync_new', 1)->first();
        $access_token = '';

        if (!empty($AccountZoho)) {
            try {
                $data = array();
                $data = json_encode($temp);
                $access_token = $AccountZoho->access_token;
                $refresh_token = Integration::createZohoAccessTokenFromRefreshToken($access_token);
                $res = Integration::createPatientOnZoho($data, $refresh_token);
                //$this->switchDatabase($database_name);
                MembershipHelper::insert_patient_integrations($id, 'zoho');
            } catch (\Exception $e) {
                echo "<pre>";
                print_r($e->getMessage() . $e->getLine());
            }
        }

        // Hubspot Create Patient

        $AccountHubspot = AccountHubspot::where('account_id', $account_id)->where('sync_new', 1)->first();
        $access_token = '';

        if (!empty($AccountHubspot)) {
            try {
                $data = array();
                $data = $temp;
                $access_token = $AccountHubspot->access_token;
                $refreshToken = Integration::createHubspotAccessTokenFromRefreshToken($access_token);
                $access_token = $refreshToken->access_token;
                Integration::createPatientOnHubspot($data, $access_token);
                //	$this->switchDatabase($database_name);
                MembershipHelper::insert_patient_integrations($id, 'hubspot');
            } catch (\Exception $e) {
                echo "<pre>";
                print_r($e->getMessage() . $e->getLine());
            }

        }

        // mailchimp Create Patient

        $AccountMailchimp = AccountMailchimp::where('account_id', $account_id)->first();
        $access_token = '';

        if (!empty($AccountMailchimp)) {
            try {
                $code = $AccountMailchimp->access_token;
                $result = Integration::getAccessTokenMailchimp($code);
                if (!empty($result->dc)) {
                    $location = $result->dc;
                    $mailchimp_key = $code . '-' . $location;
                    Integration::mailchimpCreateContact($account_id, $mailchimp_key, $location, $temp);
                    //$this->switchDatabase($database_name);
                    MembershipHelper::insert_patient_integrations($id, 'mailchimp');
                }
            } catch (\Exception $e) {
                echo "<pre>";
                print_r($e->getMessage() . $e->getLine());
            }
        }

        //account active campain
        $AccountActiveCampaign = AccountActiveCampaign::where('account_id', $account_id)->where('sync_new', 1)->first();
        $access_token = '';

        if (!empty($AccountActiveCampaign)) {
            try {
                $code = $AccountActiveCampaign->access_token;
                $url = $AccountActiveCampaign->url;
                $result = Integration::create_active_content_user($url, $code, $temp);
                //$this->switchDatabase($database_name);
                MembershipHelper::insert_patient_integrations($id, 'active_campaign');
            } catch (\Exception $e) {
                echo "<pre>";
                print_r($e->getMessage() . $e->getLine());
            }
        }

        //account constant contact
        $AccountConstantContact = AccountConstantContact::where('account_id', $account_id)->where('sync_new', 1)->first();
        $access_token = '';

        if (!empty($AccountConstantContact)) {
            try {
                $code = $AccountConstantContact->access_token;
                $result = Integration::create_constant_content_user($code, $temp);
                //$this->switchDatabase($database_name);
                MembershipHelper::insert_patient_integrations($id, 'constant_contact');
            } catch (\Exception $e) {
                echo "<pre>";
                print_r($e->getMessage() . $e->getLine());
            }
        }
        return true;
    }

    public static function enablePatientPortalAccessAndSendMail($appointmentData, $patientID, $account_id)
    {
        if ($patientID) {
            $accountID = $account_id;
            $accountPrefData = AccountPrefrence::where('account_id', $accountID)->first();
            $account = Account::where('id', $accountID)->where('status', '!=', 'inactive')->first();
            $database_name = $account->database_name;
            $buisnessName = !empty($account->name) ? ucfirst(trim($account->name)) : "Aesthetic Record";

            $dbSubject = trim($accountPrefData->clinic_patient_portal_access_subject);
            $dbBody = trim($accountPrefData->clinic_patient_portal_access_body);
            $template_used = false;

            switchDatabase($database_name);

            $update_arr = array(
                'access_portal' => 1
            );

            $status = Patient::where('id', $patientID)->update($update_arr);

            $clinic = Clinic::findOrFail(@$appointmentData['selClinic']);
            $sender = EmailHelper::getSenderEmail();
            $subject = !empty($dbSubject) ? $dbSubject : "Welcome to {{BUSINESSNAME}}";
            $subject = str_replace("{{BUSINESSNAME}}", $buisnessName, $subject);

            $body_content = Config::get('app.mail_body');
            $mail_body = !empty($dbBody) ? $dbBody : $body_content['SEND_PATIENT_REGISTER_LINK'];
            $mail_body = str_replace("{{BUSINESSNAME}}", $buisnessName, $mail_body);
            $mail_body = str_replace("{{NAME}}", ucfirst($appointmentData['formData']['firstname']), $mail_body);

            $secret_key = "juvly12345";
            $hashids = new Hashids($secret_key, 30);

            $pportalSubdomain = $account->pportal_subdomain;

            $p_encoded = $hashids->encode($patientID);
            $u_encoded = $hashids->encode($account->id);
            $encoded = $u_encoded . ':' . $p_encoded;

            $isBeta = env('IS_BETA');

            if ($isBeta > 0) {
                $hostName = env('BETA_HOST_NAME');
                $domainName = env('BETA_DOMAIN_NAME');
            } else {
                $hostName = env('LIVE_HOST_NAME');
                $domainName = env('LIVE_DOMAIN_NAME');
            }

            if (strstr($mail_body, 'href="{{CLIENTPATIENTURL}}"')) {
                $template_used = true;
            }

            $link = $hostName . @$pportalSubdomain . '.' . $domainName . '.com/register?key=' . $encoded;
            $mail_body = str_replace("{{CLIENTPATIENTURL}}", $link, $mail_body);

            $email_content = MembershipHelper::getWelcomeEmailTemplate($mail_body, $account, $clinic, $subject, $template_used);
            $noReply = getenv('MAIL_FROM_EMAIL');

            EmailHelper::sendEmail($noReply, trim($appointmentData['formData']['email']), $sender, $email_content, $subject);

        }
        return true;
    }

    public static function saveFreeMonthlyMembership($input, $patient_id, $add_to_wallet_status, $account, $patient_email)
    {

        $addedBy = $account->admin_id;
        $admin_id = $account->admin_id;
        $patientID = $patient_id;
        $patientEmail = trim($patient_email);
        $finalStatus = false;
        $account_id = $account->id;
        $patient = Patient::find($patient_id);
        $patient->is_monthly_membership = 1;
        $patient->email = $patientEmail;

        if ($patient->save()) {

            $amount = 0;
            $one_time_amount = 0;
            $CardDetails = '--';
            $recurly_account_code = '--';
            $recurly_uuid = '--';
            $startedAt = date('Y-m-d h:i:s');
            $validUpto = date('Y-m-d', strtotime('+1 month'));
            $agreement_id = 0;
            $agreement_name = null;
            $agreement_text = null;
            if (isset($input['agreement_id']) && !empty($input['agreement_id'])) {
                $agreement_id = $input['agreement_id'];
                $membsership_agreement = MembershipAgreement::where('id', $agreement_id)->where('status', 0)->first();
                $agreement_name = @$membsership_agreement->name;
                $agreement_text = @$membsership_agreement->agreement_text;
            }
            $patientMemSub = PatientMembershipSubscription::where('patient_id', $patient_id)->first();

            if ($patientMemSub) {

                $subscriptionData = array(
                    'subscription_status' => 1,
                    'subscription_started_at' => $startedAt,
                    'subscription_valid_upto' => $validUpto,
                    'mothly_membership_fees' => $amount,
                    'one_time_membership_setup' => $one_time_amount,
                    'stripe_card_details' => $CardDetails,
                    'recurly_account_code' => $recurly_account_code,
                    'subscription_uuid' => $recurly_uuid,
                    'modified' => date('Y-m-d'),
                    'added_by' => $addedBy,
                    'start_type' => 'immediate',
                    'clinic_id' => $input['clinic_id'],
                    'membership_agreement_id' => $agreement_id,
                    'agreement_title' => $agreement_name,
                    'agreement_text' => (string)$agreement_text,
                    'agreement_signed_date' => date('Y-m-d H:i:s'),
                    'patient_signature' => $input['patient_signature'],
                    'signed_on' => $input['signed_on'],
                    'purchased_ip' => $input['purchased_ip']
                );

                if ($patientMemSub->update($subscriptionData)) {
                    $finalStatus = true;
                }
            } else {
                $subscriptionData = array(
                    'patient_id' => $patientID,
                    'recurly_account_code' => $recurly_account_code,
                    'subscription_uuid' => $recurly_uuid,
                    'subscription_status' => 1,
                    'subscription_started_at' => $startedAt,
                    'subscription_valid_upto' => $validUpto,
                    'mothly_membership_fees' => $amount,
                    'one_time_membership_setup' => $one_time_amount,
                    'stripe_card_details' => $CardDetails,
                    'created' => date('Y-m-d'),
                    'modified' => date('Y-m-d'),
                    'added_by' => $addedBy,
                    'start_type' => 'immediate',
                    'clinic_id' => $input['clinic_id'],
                    'membership_agreement_id' => $agreement_id,
                    'agreement_title' => $agreement_name,
                    'agreement_text' => (string)$agreement_text,
                    'agreement_signed_date' => date('Y-m-d H:i:s'),
                    'patient_signature' => $input['patient_signature'],
                    'signed_on' => $input['signed_on'],
                    'purchased_ip' => $input['purchased_ip']
                );
                $patientMemSubObj = new PatientMembershipSubscription;
                if ($patientMemSubObj->create($subscriptionData)) {
                    $finalStatus = true;
                }
            }

            //~ if($finalStatus) {
            //~ $this->sendMembershipInvoiceEmail($patientID, $amount, 0, 0, $account_id, $admin_id);
            //~ }
        }
        $database_name = $account->database_name;
        #DB switch
        switchDatabase($database_name);
        $patient_wallet_id = 0;
        $patient_wallet = PatientWallet::where('patient_id', $patient_id)->first();
        if (empty($patient_wallet)) {
            $patient_wallet_id = MembershipService::createPatientWallet($patient_id, 0, $add_to_wallet_status);

        } else {
            $patient_wallet_id = MembershipService::updatePatientWallet($patient_id, $patient_wallet, 0, $add_to_wallet_status);
        }
        return $finalStatus;
    }

    public static function createPatientWallet($patient_id, $total_amount, $add_to_wallet_status)
    {
        $amount = $total_amount;

        $patient_wallet = new PatientWallet;
        $patient_wallet->patient_id = $patient_id;
        if ($add_to_wallet_status == 1) {
            $patient_wallet->balance = $amount;
            $patient_wallet->dollar_credit = $amount;
        }
        $patient_wallet->membership_fee = $amount;
        $currentTime = date('Y-m-d h:i:s');
        $currentTime = getCurrentTimeNewYork($currentTime);
        $patient_wallet->created = $currentTime;
        $patient_wallet->modified = $currentTime;
        $patient_wallet->save();
        return $patient_wallet->id;
    }

    public static function updatePatientWallet($patient_id, $patient_wallet, $total_amount, $add_to_wallet_status)
    {
        $old_balance = $patient_wallet->balance;
        $old_balance_dollar = $patient_wallet->dollar_credit;
        $mothly_membership_fees = $total_amount;
        $total_balance = $old_balance + $mothly_membership_fees;
        $total_balance_dollar = $old_balance_dollar + $mothly_membership_fees;

        $patient_wallet = PatientWallet::find($patient_wallet->id);
        if ($add_to_wallet_status == 1) {
            $patient_wallet->balance = $total_balance;
            $patient_wallet->dollar_credit = $total_balance_dollar;
        }
        $patient_wallet->membership_fee = $mothly_membership_fees;
        $currentTime = date('Y-m-d h:i:s');
        $currentTime = getCurrentTimeNewYork($currentTime);
        $patient_wallet->modified = $currentTime;
        $patient_wallet->save();
        return $patient_wallet->id;
    }

    public static function createStripeCustomer($input, $patient_email = null)
    {
        if(isset($patient_email)){
            $patientEmail = $patient_email;
        }else{
            $patientEmail = $input['email'];
        }

        $createCustomerArr = array(
            "email" => $patientEmail,
            "source" => $input['stripeToken']
        );
        try {
            $createCustomer = callStripe('customers', $createCustomerArr);

            if (isset($createCustomer->id) && !empty($createCustomer->id)) {
                $response['status'] = 1;
                $response['data'] = $createCustomer;
            } else {
                $response['status'] = 0;
                $response['msg'] = 'We are unable to authorize your card, please try again.';
            }
        } catch (\Exception $e) {
            $response['status'] = 0;
            $response['msg'] = 'Something went wrong,stripe error.';
        }
        return $response;
    }

    public static function createStripeCharge($input, $customer_data, $total_amount, $defaultAccountCurrency, $stripe_config, $account, $patient_email = null)
    {
        //$statement_descriptor	= env('STRIPE_DESCRIPTOR');
        $accountName = isset($account['name']) && !empty($account['name']) ? $account['name'] : env('STRIPE_DESCRIPTOR');
        $accountName = substr(strtoupper(str_replace(' ', '', $accountName)), 0, 20);
        $accountName = cleanString($accountName);
        $accountName = preg_replace('/[^A-Za-z0-9\-]/', '', $accountName);
        $statement_descriptor = (strlen($accountName) > 20) ? substr($accountName, 0, 20) : $accountName;

        $currency_code = strtoupper($defaultAccountCurrency);
        $stripe_country = DB::table('stripe_countries')->where('currency_code', $currency_code)->first();
        $minimum_amount = 50;
        if ($stripe_country->minimum_amount) {
            $minimum_amount = $stripe_country->minimum_amount;
        }

        if(isset($patient_email)){
            $patientEmail = $patient_email;
        }else{
            $patientEmail = $input['email'];
        }

        $customerTokenID = $customer_data->id;
        $recurly_account_code = $customerTokenID;
        $recurly_uuid = $customerTokenID;

        $stripe_user_id = $stripe_config->stripe_user_id;
        //~ $chargeArr	= array(
        //~ "amount" 	  	=> $minimum_amount,
        //~ "capture"		=> 'false',
        //~ "customer" 	  	=> $customerTokenID,
        //~ "currency"	  	=> $defaultAccountCurrency,
        //~ "statement_descriptor"	=> strtoupper($statement_descriptor),
        //~ "description" 	=> $patientEmail . ' : monthly membership on Aesthetic Record',
        //~ //"application_fee" 		=> round($stripe_config->platform_fee, 2) * 100,
        //~ "destination" 			=> array("account" => $stripe_user_id)
        //~ );

        $chargeArr = array(
            "amount" => $minimum_amount,
            "capture" => 'false',
            "customer" => $customerTokenID,
            "currency" => $defaultAccountCurrency,
            //"statement_descriptor"	=> strtoupper($statement_descriptor),
            "statement_descriptor_suffix" => strtoupper($statement_descriptor),
            "description" => $patientEmail . ' : monthly membership on Aesthetic Record',
            //"application_fee" 		=> round($stripe_config->platform_fee, 2) * 100,
            "on_behalf_of" => $stripe_user_id,
            "transfer_data" => array(
                "destination" => $stripe_user_id
            )
        );

        $stripe_platform_fee = $stripe_config->platform_fee;
        $platformFee = 0;
        if ($stripe_platform_fee > 0) {
            $platformFee = ($stripe_platform_fee * $total_amount) / 100;
        }

        try {


            $initial_charge = callStripe('charges', $chargeArr);
            if (!is_object($initial_charge)) {
                $response['status'] = 0;
                $response['msg'] = 'Connected account is invalid';
                return $response;
            }
            if ($initial_charge) {

                //~ $chargeData	= array(
                //~ "amount" 	  			=> ($total_amount * 100),
                //~ "customer" 	  			=> $customerTokenID,
                //~ "currency"	  			=> $defaultAccountCurrency,
                //~ "statement_descriptor"	=> strtoupper($statement_descriptor),
                //~ "description" 			=> 'Payment for monthly membership : '.$patientEmail,
                //~ "application_fee" 		=> round($platformFee, 2) * 100,
                //~ "destination" 			=> array("account" => $stripe_user_id)
                //~ );
                $chargeData = array(
                    "amount" => ($total_amount * 100),
                    "customer" => $customerTokenID,
                    "currency" => $defaultAccountCurrency,
                    //"statement_descriptor"	=> strtoupper($statement_descriptor),
                    "statement_descriptor_suffix" => strtoupper($statement_descriptor),
                    "description" => 'Payment for monthly membership : ' . $patientEmail,
                    "application_fee_amount" => round($platformFee, 2) * 100,
                    "on_behalf_of" => $stripe_user_id,
                    "transfer_data" => array(
                        "destination" => $stripe_user_id
                    )
                );

                $final_charge = callStripe('charges', $chargeData);
                $response['status'] = 1;
                $response['account_code'] = $customerTokenID;
                $response['subscription_uuid'] = $customerTokenID;
                $response['data'] = $final_charge;
                $response['stripe_card_details'] = ucfirst($final_charge->payment_method_details->card->brand) . " ending " . $final_charge->payment_method_details->card->last4;
            }

        } catch (\Exception $e) {
            $response['status'] = 0;
            $response['msg'] = 'Something went wrong,stripe error.';
        }
        return $response;
    }

    public static function saveSubscription($charge_data, $patient_id, $input, $account_preferences, $account, $pos_gateway = null)
    {
        $patient_membership = new PatientMembershipSubscription;
        $valid_upto = date('Y-m-d', strtotime('+1 month'));
        $agreement_id = 0;
        $agreement_name = null;
        $agreement_text = null;

        if (isset($input['agreement_id']) && !empty($input['agreement_id'])) {
            $agreement_id = $input['agreement_id'];
            $membsership_agreement = MembershipAgreement::where('id', $agreement_id)->where('status', 0)->first();
            $agreement_name = @$membsership_agreement->name;
            $agreement_text = @$membsership_agreement->agreement_text;
        }
        $patient_membership->membership_agreement_id = $agreement_id;
        $patient_membership->agreement_title = $agreement_name;
        $patient_membership->agreement_text = $agreement_text;
        $patient_membership->agreement_signed_date = date('Y-m-d H:i:s');
        if ($input['membershipFrequency'] == 'yearly') {
            $valid_upto = date('Y-m-d', strtotime('+1 year'));
        }

        $draw_day = (int)date('d');
        if ($draw_day > 28) {
            $draw_day = 28;
        }
        $patient_membership->draw_day = $draw_day;
        $patient_membership->patient_id = $patient_id;
        $patient_membership->subscription_status = 1;

        if (!empty($pos_gateway) && $pos_gateway == "clearent") {
            $patient_membership->recurly_account_code = $charge_data['data']['payload']['tokenResponse']['token-id'];
            $patient_membership->subscription_uuid = $charge_data['data']['payload']['tokenResponse']['token-id'];
            $patient_membership->card_expiry_date = $charge_data['data']['payload']['tokenResponse']['exp-date'];

            if (!empty($charge_data["data"]["payload"]["tokenResponse"]) && isset($charge_data["data"]["payload"]["tokenResponse"])) {
                $cardDetails = $charge_data["data"]["payload"]["tokenResponse"]["card-type"] . ' ending ' . $charge_data["data"]["payload"]["tokenResponse"]["last-four-digits"];
            } else {
                $cardDetails = $charge_data["data"]["payload"]["transaction"]["card-type"] . ' ending ' . $charge_data["data"]["payload"]["transaction"]["last-four"];
            }

        } else {
            $patient_membership->recurly_account_code = $charge_data['account_code'];
            $patient_membership->subscription_uuid = $charge_data['subscription_uuid'];

            $cardDetails = isset($charge_data['stripe_card_details']) ? $charge_data['stripe_card_details'] : $input['CardDetails'];
        }
        $currentTime = date('Y-m-d H:i:s');
        //$currentTime	= getCurrentTimeNewYork($currentTime);
        $patient_membership->subscription_started_at = $currentTime;
        $patient_membership->subscription_valid_upto = $valid_upto;
        if ($account_preferences->membership_tier == 'multiple') {
            if ($input['membershipFrequency'] == 'monthly') {
                $patient_membership->mothly_membership_fees = $input['membership_tiers']->price_per_month;
            }
            if ($input['membershipFrequency'] == 'yearly') {
                $patient_membership->yearly_membership_fees = $input['membership_tiers']->price_per_year;
            }
            $patient_membership->payment_frequency = $input['membershipFrequency'];
            $patient_membership->one_time_membership_setup = $input['membership_tiers']->one_time_setup_fee;
        } else {
            if ($account_preferences->mothly_membership_type == 'free') {
                $patient_membership->mothly_membership_fees = 0;
            } else {
                if ($input['membershipFrequency'] == 'monthly') {
                    $patient_membership->mothly_membership_fees = $account_preferences->mothly_membership_fees;
                }
                if ($input['membershipFrequency'] == 'yearly') {
                    $patient_membership->yearly_membership_fees = $account_preferences->yearly_membership_fees;
                }
                $patient_membership->payment_frequency = $input['membershipFrequency'];
            }
            $patient_membership->one_time_membership_setup = $account_preferences->one_time_membership_setup;
        }
        $patient_membership->stripe_card_details = $cardDetails;
        $patient_membership->added_by = $account->admin_id;
        $patient_membership->start_type = 'immediate';
        $patient_membership->membership_tier_discount = $input['membership_tier_discount'];
        $patient_membership->membership_tier_id = $input['membership_tier_id'];
        if (isset($input['applied_discount_coupon']) && $input['applied_discount_coupon'] == 1 && !empty($input['coupon_code'])) {
            $patient_membership->coupon_code = $input['coupon_code'];
            $patient_membership->discount_type = $input['discount_type'];
            $patient_membership->discount_value = $input['discount_value'];
            $patient_membership->discount_duration = $input['discount_duration'];
            $patient_membership->limited_duration = $input['limited_duration'];
        }
        $patient_membership->patient_signature = @$input['patient_signature'];
        $patient_membership->signed_on = @$input['signed_on'];
        $patient_membership->purchased_ip = @$input['purchased_ip'];
        $patient_membership->clinic_id = $input['clinic_id'];
        if ($patient_membership->save()) {
            MembershipHelper::saveMembershipFreeProducts($input['membership_tier_id'], $patient_membership->id);
            $patient = new Patient;
            $patient_data = Patient::where('id', $patient_id)->first();
            if (!empty($patient_data)) {
                $patient = Patient::find($patient_data->id);
            }
            $patient->is_monthly_membership = 1;
            $patient->save();
            $response['status'] = 1;
            $response['msg'] = 'Subscription added successfully!';
            $response['data'] = $patient_membership;
        } else {
            $response['status'] = 0;
            $response['msg'] = 'Subscription not saved!';
        }
        return $response;
    }

    public static function addFreeProductsToClientWallet($membership_id, $patientID, $user_id)
    {

        $data = PatientMembershipSubscriptionProduct::where('patient_membership_subscription_id', $membership_id)->get();
        if (!is_null($data)) {
            foreach ($data as $row) {
                $product_id = $row->product_id;
                $productUnits = $row->units;
                $proPricePerUnit = 0;
                $proPackagePrice = $proPricePerUnit * $productUnits;

                $product = Product::where('id', $product_id)->first();

                if ($product) {
                    $productName = $product['product_name'];
                    $discountPackParams = array('product_name' => $productName, 'product_id' => $product_id, 'type' => 'package', 'product_units' => $productUnits, 'price' => $proPackagePrice, 'is_virtual' => 0);

                    if ($discountPackageID = MembershipHelper::saveDiscountPackage($discountPackParams)) {
                        $patPackParams = array('patientID' => $patientID, 'packageID' => $discountPackageID, 'type' => 'package', 'packageAmount' => $proPackagePrice, 'purchasedFrom' => 'membership_benefits', 'purchased_by' => $user_id);

                        if ($patPackID = MembershipHelper::savePatientPackage($patPackParams)) {
                            $savedUnits = $productUnits;
                            $dollarValue = $proPackagePrice;

                            $patPackProParams = array('patPackID' => $patPackID, 'productID' => $product_id, 'productType' => 'free', 'totalUnits' => $productUnits, 'dollarValue' => $dollarValue, 'balanceUnits' => $productUnits, 'balanceDollarValue' => $dollarValue, 'discountPercentage' => 0, 'addedBy' => $user_id);

                            if (MembershipHelper::savePatientPackageProducts($patPackProParams)) {
                                $walletParams = array('patientID' => $patientID, 'dollarValue' => $dollarValue, 'type' => "");
                                if (MembershipHelper::updateWalletBalance($walletParams)) {
                                    $finalStatus = true;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public static function createPosInvoice($posInvoiceData, $product_type, $admin_id, $appointment_type = null, $gatewayType = null)
    {
        $title = 'Virtual appointment';
        $is_cancellation_fee = 0;
        if ($posInvoiceData['custom_product_name'] == 'monthly_membership') {
            $title = 'Monthly Membership';
        }
        if ($posInvoiceData['custom_product_name'] == 'yearly_membership') {
            $title = 'Yearly Membership';
        }
        if ($posInvoiceData['product_type'] == 'custom' && @$posInvoiceData['title'] == 'cancellation_fee') {
            $title = null;
            $is_cancellation_fee = 1;
        }
        $appointment_cacellation_transaction_id = 0;
        if (!empty($posInvoiceData['appointment_cacellation_transaction_id'])) {
            $appointment_cacellation_transaction_id = $posInvoiceData['appointment_cacellation_transaction_id'];
        }
        $posInvoice = new PosInvoice();
        $posInvoice->user_id = $admin_id;
        $posInvoice->procedure_id = 0;
        $posInvoice->patient_id = $posInvoiceData['patient_id'];
        $posInvoice->clinic_id = $posInvoiceData['clinic_id'];
        $posInvoice->invoice_number = $posInvoiceData['invoice_number'];
        $posInvoice->patient_email = $posInvoiceData['patient_email'];
        $posInvoice->title = $title;
        $posInvoice->total_amount = $posInvoiceData['total_amount'];
        $posInvoice->sub_total = $posInvoiceData['sub_total'];
        $posInvoice->total_tax = $posInvoiceData['total_tax'];
        $posInvoice->created = $posInvoiceData['created'];
        $posInvoice->modified = $posInvoiceData['created'];
        $posInvoice->payment_datetime = $posInvoiceData['paid_on'];
        $posInvoice->custom_discount = $posInvoiceData['total_discount'];
        $posInvoice->invoice_status = 'paid';
        $posInvoice->is_cancellation_fee = $is_cancellation_fee;
        $posInvoice->appointment_cancellation_transaction_id = $appointment_cacellation_transaction_id;

        if (!$posInvoice->save()) {
            throw new Exception("Unable to create invoice, please try again later");
        }

        $invoiceId = $posInvoice->id;
        $posInvoiceData['invoice_id'] = $posInvoice->id;
        $posInvoiceData['product_type'] = $product_type;

        if ($product_type == 'monthly_membership') {

            $posInvoiceData['item_amount'] = $posInvoiceData['monthly_amount'];
            //$posInvoiceData['custom_product_name'] 	= 'monthly_membership';
            if (!MembershipHelper::savePosItem($posInvoiceData, $admin_id)) {
                throw new Exception("Unable to create invoice, please try again later");
            }

            $posInvoiceData['item_amount'] = $posInvoiceData['one_time_amount'];
            $posInvoiceData['custom_product_name'] = 'one_time_setup_fee';
            if (!MembershipHelper::savePosItem($posInvoiceData, $admin_id)) {
                throw new Exception("Unable to create invoice, please try again later");
            }
        } else {
            $posInvoiceData['item_amount'] = $posInvoiceData['total_amount'];

            if ($appointment_type && $appointment_type == 'virtual') {
                $posInvoiceData['custom_product_name'] = $posInvoiceData['custom_product_name'];

            } elseif ($posInvoiceData['product_type'] == 'custom' && @$posInvoiceData['title'] == 'cancellation_fee') {
                $posInvoiceData['custom_product_name'] = $posInvoiceData['custom_product_name'];

            } else {
                $posInvoiceData['custom_product_name'] = null;
            }

            if (!MembershipHelper::savePosItem($posInvoiceData, $admin_id)) {
                throw new Exception("Unable to create invoice, please try again later");
            }
        }

        $posTransaction = new PosTransaction();
        $posTransaction->invoice_id = $invoiceId;
        $posTransaction->payment_mode = 'cc';
        $posTransaction->total_amount = $posInvoiceData['total_amount'];
        $posTransaction->payment_status = 'paid';
        $posTransaction->transaction_datetime = $posInvoiceData['created'];
        $posTransaction->receipt_id = $posInvoiceData['invoice_number'];


        if (!$posTransaction->save()) {
            throw new Exception("Unable to create invoice, please try again later");
        }

        $posTransactionId = $posTransaction->id;

        $posTransactionsPayment = new PosTransactionsPayment();
        if (isset($posInvoiceData['host_transaction_id']) && !empty($posInvoiceData['host_transaction_id'])) {
            $posTransactionsPayment->host_transaction_id = $posInvoiceData['host_transaction_id'];
        }
        $posTransactionsPayment->pos_transaction_id = $posTransactionId;
        $posTransactionsPayment->payment_mode = 'cc';
        $posTransactionsPayment->cc_mode = 'manual';
        $posTransactionsPayment->cc_type = $posInvoiceData['customerCardBrand'];
        $posTransactionsPayment->cc_number = $posInvoiceData['customerCardLast4'];
        $posTransactionsPayment->total_amount = $posInvoiceData['total_amount'];
        $posTransactionsPayment->created = $posInvoiceData['created'];
        $posTransactionsPayment->payment_status = 'paid';
        if (isset($posInvoiceData['apriva_transaction_data'])) {
            $posTransactionsPayment->apriva_transaction_data = $posInvoiceData['apriva_transaction_data'];
        }
        if ($gatewayType == 'clearent') {
            $amount = $posInvoiceData['total_amount'];
            $clearentProcessingFee = ($amount * $posInvoiceData['platformFee']) / 100;
            $posTransactionsPayment->processing_fees = $clearentProcessingFee;
        }
        if (!$posTransactionsPayment->save()) {
            throw new Exception("Unable to create invoice, please try again later");
        }
        return $invoiceId;
    }

    public static function createZohoInvoice($zohoData)
    {
        //CREATE ZOHO INVOICE

        $account = $zohoData['account'];
        $patient_id = $zohoData['patient_id'];
        $total_amount = $zohoData['total_amount'];
        $posInvoiceData = $zohoData['posInvoiceData'];
        $subject = $zohoData['subject'];

        $accountID = $account->id;
        $accountName = $account->name;
        $accountDB = $account->database_name;
        $refreshToken = "";
        $productID = 0;
        $accessToken = "";
        $accountZoho = AccountZoho::where('account_id', $account->id)->first();

        if (!empty($accountZoho)) {
            $refreshToken = $accountZoho->access_token;
        }
        try {
            if ($refreshToken && !empty($accountZoho)) {
                $accessToken = Integration::createZohoAccessTokenFromRefreshToken($refreshToken);
                //~ echo "<pre>"; print_r($accessToken); die;
                if ($accessToken) {
                    //Find or Create AR Product
                    $productID = Zoho::findOrCreateARProduct("AR Product", $accessToken);
                    //Find or Create AR Product

                    if ($productID) {
                        $finalInvoiceArray = array(
                            array(
                                "Account_Name" => $accountName,
                                "Invoice_Date" => date("Y-m-d"),
                                "Status" => "Approved",
                                "Subject" => $subject . " membership invoice for client ID - $patient_id",
                                "Grand_Total" => $total_amount,
                                "Sub_Total" => $total_amount,
                                "Product_Details" => array(
                                    array(
                                        "product" => array(
                                            "id" => $productID,
                                        ),
                                        "quantity" => 1,
                                        "Discount" => "0",
                                        "total_after_discount" => "0",
                                        "net_total" => $total_amount,
                                        "Tax" => "0",
                                        "list_price" => $total_amount,
                                        "unit_price" => $total_amount,
                                        "total" => $total_amount,
                                        "product_description" => "Demo Product",
                                    )
                                ),
                                "Discount" => "0"
                            )
                        );

                        Zoho::createZohoModule("Invoices", $accessToken, $finalInvoiceArray);
                    }
                }
            }
        } catch (\Exception $e) {

        }
        //CREATE ZOHO INVOICE
    }

    public static function saveMonthlyMembershipInvoice($posInvoiceData, $response, $subscriptionID, $input, $pos_gateway = null)
    {

        $account = $input['account'];
        $account_id = $input['account']->id;

        $invoiceNumber = $posInvoiceData['invoice_number'];
        $patientID = $posInvoiceData['patient_id'];
        $amount = $posInvoiceData['total_amount'];
        $total_discount = $posInvoiceData['total_discount'];
        $customerTokenID = $response['account_code'];

        $patient_subsription = PatientMembershipSubscription::where('id', $subscriptionID)->select('id')->first();
        $patient_subsription_id = 0;
        if ($patient_subsription) {
            $patient_subsription_id = $patient_subsription->id;
        }

        $admin_id = $account->admin_id;
        $monthlyMembershipInvoice = new MonthlyMembershipInvoice();
        $monthlyMembershipInvoice->patient_id = $patientID;
        $monthlyMembershipInvoice->payment_status = 'paid';
        $monthlyMembershipInvoice->amount = $amount;
        $monthlyMembershipInvoice->stripe_customer_token = $customerTokenID;
        if (!empty($pos_gateway) && $pos_gateway == 'clearent') {
            $monthlyMembershipInvoice->stripe_charge_id = $response['data']['payload']['transaction']['id'];
            $monthlyMembershipInvoice->stripe_response = json_encode($response['data']['payload']);
        } else {
            $monthlyMembershipInvoice->stripe_charge_id = $response['data']->id;
            $monthlyMembershipInvoice->stripe_response = json_encode($response['data']);
        }
        $monthlyMembershipInvoice->invoice_status = 'sent';
        $monthlyMembershipInvoice->payment_frequency = $input['membershipFrequency'];
        $monthlyMembershipInvoice->created = date('Y-m-d H:i:s');
        $monthlyMembershipInvoice->modified = date('Y-m-d H:i:s');
        $monthlyMembershipInvoice->patient_membership_subscription_id = $patient_subsription_id;
        $monthlyMembershipInvoice->total_discount = $total_discount;

        $monthlyMembershipInvoice->save();

        $invoice_id = $monthlyMembershipInvoice->id;

        (new MonthlyMembershipInvoice)->where('id', $invoice_id)->update(['invoice_number' => $invoiceNumber]);

        $card_details = @$response['data']->source->brand . ' ending ' . @$response['data']->source->last4;
        $monthlyMembershipInvoicePayment = new MonthlyMembershipInvoicePayment();

        $monthlyMembershipInvoicePayment->monthly_membership_invoice_id = $invoice_id;
        $monthlyMembershipInvoicePayment->amount = $amount;
        if (!empty($pos_gateway) && $pos_gateway == 'clearent') {
            $card_details = @$response['data']["payload"]["transaction"]['card-type'] . ' ending ' . $response['data']["payload"]["transaction"]['last-four'];
            $monthlyMembershipInvoicePayment->card_details = $card_details;
            $monthlyMembershipInvoicePayment->stripe_charge_id = $response['data']['payload']['transaction']['id'];
            $monthlyMembershipInvoicePayment->stripe_response = json_encode($response['data']['payload']);
        } else {
            $monthlyMembershipInvoicePayment->card_details = $card_details;
            $monthlyMembershipInvoicePayment->stripe_charge_id = $response['data']->id;
            $monthlyMembershipInvoicePayment->stripe_response = json_encode($response['data']);
        }
        $monthlyMembershipInvoicePayment->payment_status = 'paid';
        $monthlyMembershipInvoicePayment->payment_datetime = date('Y-m-d H:i:s');

        $monthlyMembershipInvoicePayment->save();

        $posInvoiceData['monthlyMembershipInvoicePayment'] = $monthlyMembershipInvoicePayment;
        $posInvoiceData['subscriptionID'] = $subscriptionID;
        $posInvoiceData['invoice_id'] = $invoice_id;
        $posInvoiceData['account_id'] = $account_id;
        $posInvoiceData['admin_id'] = $admin_id;
        return self::sendMembershipInvoiceEmail($posInvoiceData);
    }

    public static function sendMembershipInvoiceEmail($posInvoiceData)
    {
        $patientID = $posInvoiceData['patient_id'];
        $amount = $posInvoiceData['total_amount'];
        $subscriptionID = $posInvoiceData['subscriptionID'];
        $invoice_id = $posInvoiceData['invoice_id'];
        $account_id = $posInvoiceData['account_id'];
        $admin_id = $posInvoiceData['admin_id'];

        $user = Users::find($admin_id);
        $accountData = Account::with('accountPrefrence')->find($account_id);
        $from_email = env('MAIL_FROM_EMAIL');
        $replyToEmail = env('MAIL_FROM_EMAIL');
        if ($accountData['accountPrefrence']->from_email) {
            $replyToEmail = $accountData['accountPrefrence']->from_email;
        }
        $patientData = Patient::find($patientID);

        $Invoice = DB::connection('juvly_practice')->table('monthly_membership_invoices as mmi')
            ->join('monthly_membership_invoice_payments as mmip', 'mmip.monthly_membership_invoice_id', '=', 'mmi.id')
            ->where('mmi.payment_status', 'paid')
            ->where('mmi.id', $invoice_id)
            ->first();

        $Invoice->monthlyMembershipInvoicePayment = $posInvoiceData['monthlyMembershipInvoicePayment'];

        $clinic = Clinic::where('id', $user->clinic_id)->first();
        $clinic_name = $clinic->clinic_name;
        $patientEmail = $patientData->email;
        if (!empty($patientEmail)) {
            $storagefolder = $accountData->storage_folder;
            $media_path = public_path();
            $media_url = public_path();
            $ar_media_path = env('MEDIA_URL');
            if (isset($accountData->logo) && $accountData->logo != '') {
                $logo_img_src = $ar_media_path . $storagefolder . '/admin/' . $accountData->logo;
            } else {
                $logo_img_src = env('NO_LOGO_FOR_PDF');
            }
            $attachments = null;
            $subject = "AR - Membership Payment Confirmation";
            $data = [];
            $data['invoice_amount'] = $amount;
            $data['invoice_data'] = $Invoice;
            $data['logo_img_src'] = $logo_img_src;
            $data['name'] = env('AR_NAME');
            $data['address'] = env('AR_ADDRESS');
            $data['patient_data'] = $patientData;
            $data['account_data'] = $accountData;
            $data['clinic_name'] = $clinic_name;
            $data['monthly_amount'] = $posInvoiceData['monthly_amount'];
            $data['one_time_amount'] = $posInvoiceData['one_time_amount'];
            $data['date_format'] = $accountData['accountPrefrence']->date_format;
            $data['custom_product_label'] = '';
            if ($posInvoiceData['custom_product_name'] == 'yearly_membership') {
                $data['custom_product_label'] = 'You subscribed for yearly membership';
            } elseif ($posInvoiceData['custom_product_name'] == 'monthly_membership') {
                $data['custom_product_label'] = 'You subscribed for monthly membership';
            }

            $clinic_address = @$clinic->address;
            $account_logo = @$accountData->logo;
            $storage_folder = @$accountData->storage_folder;
            $appointment_status = $subject;
            $site_url = getenv('SITE_URL');


            $clinic_location_tmp = [];
            if (!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
                $clinic_location_tmp[] = $clinic->clinic_city;
                $clinic_location_tmp[] = $clinic->clinic_state;
                $clinic_location_tmp[] = $clinic->clinic_zipcode;
                $clinic_location = implode(",", $clinic_location_tmp);
            } else {
                if ($clinic->city != '') {
                    $clinic_location_tmp[] = $clinic->city;
                }
                if ($clinic->country != '') {
                    $clinic_location_tmp[] = $clinic->country;
                }
                $clinic_location = implode(",", $clinic_location_tmp);
            }

            $view = \View::make('subscription.membership_email_template', ['data' => $data, 'clinic_location' => $clinic_location, 'account_logo' => $account_logo, 'site_url' => $site_url, 'storage_folder' => $storage_folder, 'appointment_status' => $appointment_status, 'clinic_address' => $clinic_address]);

            $email_content = $view->render();
            if ($amount > 0) {
                $pdf = \PDF::loadView('subscription.membership_invoice_template', ['data' => $data]);
                $invoive_title = rand(10, 100) . $account_id . $patientID . $invoice_id . rand(10, 100) . date('ymdhis');
                $dir = $media_path . '/stripeinvoices/';
                $filename = $dir . $invoive_title . ".pdf";
                $pdf->save($filename, 'F');
                $attachments = $media_url . '/stripeinvoices/' . $invoive_title . '.pdf';
            }

            return EmailHelper::sendEmail($from_email, $patientEmail, $replyToEmail, $email_content, $subject, $attachments, $posInvoiceData['invoice_number']);
        }
    }

}
