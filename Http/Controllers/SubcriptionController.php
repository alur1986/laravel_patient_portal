<?php

namespace App\Http\Controllers;

use App\Helpers\UploadExternalHelper;
use App\Services\MembershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Auth;
use Session;
use View;
use DB;
use Config;
use Twilio;

use App\Account;
use App\AccountPrefrence;
use App\Users;
use App\Patient;
use App\PatientAccount;
use App\PatientMembershipSubscription;
use App\PatientWallet;
use App\PatientWalletCredit;
use App\PosInvoice;
use App\PosInvoiceItem;
use App\PosTransaction;
use App\PosTransactionsPayment;
use App\MonthlyMembershipInvoice;
use App\MonthlyMembershipInvoicePayment;
use App\UserLog;
use App\Clinic;
use App\AccountStripeConfig;
use App\DiscountCoupon;
use App\DiscountCouponRedemption;
use App\MembershipTier;
use App\MembershipTierProduct;
use App\PatientMembershipSubscriptionProduct;
use App\Product;
use App\DiscountPackage;
use App\PatientPackage;
use App\PatientPackageProduct;
use App\MembershipAgreement;

use DateTime;
use DateTimeZone;
use Hashids\Hashids;
use Christhompsontldr\LaravelRecurly\ServiceProvider;
use Recurly_Account;
use Recurly_PlanList;
use Recurly_Client;

use App\Traits\Zoho;
use App\Traits\Integration;
use App\PatientIntegration;
use App\AccountZoho;
use App\AccountHubspot;
use App\AccountMailchimp;
use App\AccountClearentConfig;
use App\Traits\Clearent;
use App\Helpers\AccountHelper;
use App\Helpers\MembershipHelper;

class SubcriptionController extends Controller
{
    use Integration;
    use Zoho;
    use Clearent;

    public $userDB		= "";
    public $hashids;
    
    public function checkpdf(Request $request){
		$countries = DB::table('countries')->orderBy('country_name','asc')->get();
		$httpHost		= $_SERVER['HTTP_HOST'];
		$subDomainArr	= explode('.', $httpHost);
		$subDomain		= $subDomainArr[0];	
		$account_id = $this->getAccountID($subDomain);
		$account_preferences = AccountHelper::getAccountPreferences($account_id);
		$database_name = $this->getDatabase($subDomain);
		$this->switchDatabase($database_name);
			$data = MembershipService::sendMembershipInvoiceEmail(121, 233, 8, 7, 144);
			return \View::make('subscription.membership_invoice_template',  $data);

	}


    public function becomeMember(Request $request, $status = null){
		$countries = DB::table('countries')->orderBy('country_name','asc')->get();
		$httpHost		= $_SERVER['HTTP_HOST'];
		$subDomainArr	= explode('.', $httpHost);
		$subDomain		= $subDomainArr[0];	
		$account_id = $this->getAccountID($subDomain);
		$account_preferences = AccountHelper::getAccountPreferences($account_id);
		
		$account = Account::with('user')->find($account_id);
		$is_pos_enabled = $this->isPosEnabled($account_id);
		$clinic_id = $account['user']->clinic_id;
		$database_name = $this->getDatabase($subDomain);
		$currency_code = $account->stripe_currency;
		$country = DB::table('stripe_countries')->where('currency_code', $account->stripe_currency)->first();
		$currency_symbol = $country->currency_symbol;
		$pos_gateway =  $account->pos_gateway;
		$this->switchDatabase($database_name);
        $clinicID = MembershipHelper::getClinicID($account);

		if($request->isMethod('post')){
			
			if(!$is_pos_enabled){
				$response['status'] = 0;
				$response['msg'] ='POS disabled, please try later';
				return $response;
			}

			$input = $request->all();
			if(!empty($input['zipcode'])){
				$input['pincode']= $input['zipcode'];
			}
			$postdata['upload_type'] 				= 'patient_signatures';
			$postdata['account']['storage_folder'] 	= $account->storage_folder;
			$postdata['user_data']['id'] 			= $account->admin_id;
			$postdata['image_data'] 				= $input['image_data'];
			$postdata['api_name'] 					= 'upload_patient_signature';
			
			if(!empty($postdata['image_data'])){
				$signatueResponse = (new HomeController($request))->uploadExternalData($postdata);
				if( $signatueResponse->status !=200 || empty($signatueResponse->data->file_name)){
					$response['status'] = 0;
					$response['msg'] ='Signature uploading failed';
					return $response;	
				}
			}
			$input['patient_signature'] = null;
			$input['signed_on'] = null;
			if( isset($signatueResponse) && !empty($signatueResponse->data->file_name)){
				$signed_on 					= date('Y-m-d H:i:s');
				$input['signed_on'] 		= $this->getCurrentTimeNewYork($signed_on);
				$input['patient_signature'] = $signatueResponse->data->file_name;
			}
			if(array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
				$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
			} else {
				$ip = $request->ip();
			}
			$input['purchased_ip'] = $ip;
			
			$input['accont_prefrences'] = $account_preferences;
			$input['clinic_id'] = $clinic_id;
			$patient_id = $this->createOrFindPatient($request,$database_name,$input);
			
			$patient_membership_data = PatientMembershipSubscription::where('patient_id',$patient_id);
			if($account_preferences->membership_tier == 'multiple'){
				if(empty($input['tierID'])){
					$response['status'] = 0;
					$response['msg'] ='Please select membership';
					return $response;
				}
				$patient_membership_data = $patient_membership_data->where('membership_tier_id',$input['tierID'])->first();
			}else{
				$patient_membership_data = $patient_membership_data->where('membership_tier_id',0)->first();
				
			}
			
			if(($patient_membership_data) && ($patient_membership_data->subscription_status == 1 || $patient_membership_data->start_type == 'future')){
				$response['status'] = 0;
				$response['msg'] ='You have already subscribed monthly membership plan';
				return $response;
			}

            if ($pos_gateway == 'clearent') {
                $stripe_config = $this->getAccountClearenteConfig($account_id, $account, $clinicID);
                $error_msg_config = 'Clearent user id not found';
            } else {
                $stripe_config = MembershipHelper::getStripeConfig($account_id, $account, $clinicID);
                $error_msg_config = 'Stripe user id not found';
            }

			if(!$stripe_config){
				$response['status'] = 0;
				$response['msg'] = $error_msg_config;
				return $response;
			}
			
			if($stripe_config->clinic_id > 0){
				$clinic_id = $stripe_config->clinic_id;
			}
			$input['clinic_id'] = $clinic_id;
			$membership_type		= $account_preferences->mothly_membership_type;
			$monthly_amount			= $account_preferences->mothly_membership_fees;
			$mothly_membership_fees			= $account_preferences->mothly_membership_fees;
			$membership_tier_id 				= 0;
			$input['membership_tier_discount'] 	= 0;
			$one_time_amount		= $account_preferences->one_time_membership_setup;
			$membership_options = $account_preferences->membership_payment_options;
			$membershipFrequency = null;
			if($account_preferences->membership_tier == 'single'){
				if($membership_options == 'monthly'){
					$monthYearAmount = $monthly_amount;
					$membershipFrequency = 'monthly';
				}
				elseif($membership_options == 'yearly'){
					$monthYearAmount =  $account_preferences->yearly_membership_fees;
					$membershipFrequency = 'yearly';
				}
				elseif($membership_options == 'both'){
					if(empty($input['frequncy'])){
						$response['status'] = 0;
						$response['msg'] ='Please select frequency';
						return $response;
					}
					if($input['frequncy'] == 'month'){
						$monthYearAmount = $monthly_amount;
						$membershipFrequency = 'monthly';
					}
					elseif($input['frequncy'] == 'year'){
						$monthYearAmount = $account_preferences->yearly_membership_fees;
						$membershipFrequency = 'yearly';
					}
				}
				$membership_tiers = null;
				$add_to_wallet_status	= $account_preferences->add_fees_to_client_wallet;
			}elseif($account_preferences->membership_tier == 'multiple'){
				
				if(isset($input['tierID'])){
					$membership_tier_id = $input['tierID'];
				}
				$membership_tiers= MembershipTier::where('id',$membership_tier_id)->where('status',0)
				->where('active',0)->where('show_membership_on_portal', 1)->first();
				if(!$membership_tiers){
					$response['status'] 	= $is_valid_coupon['status'];
					$response['msg'] 		= 'Membership tier invalid';
					return $response;
				}
				
				$membership_options 				= $membership_tiers->membership_payment_options;
				$mothly_membership_fees 			= $membership_tiers->price_per_month;
				$price_per_year 					= $membership_tiers->price_per_year;
				$one_time_setup_fees 	 			= $membership_tiers->one_time_setup_fee;
				$input['membership_tier_discount'] 	= $membership_tiers->discount_percentage;
				$one_time_amount					= $one_time_setup_fees;
				$monthly_amount						= $mothly_membership_fees;
				
				if($membership_options == 'monthly'){
					$monthYearAmount = $monthly_amount;
					$membershipFrequency = 'monthly';
				}
				elseif($membership_options == 'yearly'){
					$monthYearAmount =  $price_per_year;
					$membershipFrequency = 'yearly';
				}
				elseif($membership_options == 'both'){
					if(empty($input['frequncy'])){
						$response['status'] = 0;
						$response['msg'] ='Please select frequency';
						return $response;
					}
					if($input['frequncy'] == 'month'){
						$monthYearAmount = $monthly_amount;
						$membershipFrequency = 'monthly';
					}
					elseif($input['frequncy'] == 'year'){
						$monthYearAmount = $price_per_year;
						$membershipFrequency = 'yearly';
					}
				}
				$add_to_wallet_status	= $membership_tiers->add_fees_to_client_wallet;
			}else{
				$response['status'] = 0;
				$response['msg'] ='Membership tier invalid';
				return $response;
			}
			
			if(empty($membershipFrequency)){
				$response['status'] = 0;
				$response['msg'] ='Membership Frequency invalid';
				return $response;
			}
			
			$input['membership_tier_id'] = $membership_tier_id;
			$sub_total = $monthYearAmount + $one_time_amount;
			$monthYearAmountForInvoice = $monthYearAmount;
			$total_discount = 0;
			$total_discount = 0;
			$input['discount_type'] = null;
			$input['discount_value'] = 0;
			$input['discount_duration'] = 'limited';
			$input['limited_duration'] = 0;
			if( $membership_type == 'paid' && $input['applied_discount_coupon'] == 1 && !empty($input['coupon_code'])) {
				$is_valid_coupon = $this->isValidCoupon($patient_id,$input['coupon_code']);
				if(isset($is_valid_coupon['status']) && $is_valid_coupon['status'] == 0){
					$response['status'] 	= $is_valid_coupon['status'];
					$response['msg'] 		= $is_valid_coupon['msg'];
					return $response;
				}
				$coupon = $is_valid_coupon['data'];
			
				if($coupon['discount_type'] == 'percentage'){
					$discount_percent = $coupon['discount_value'];
					$total_discount   = ( $monthYearAmount * $discount_percent ) / 100;
					$monthYearAmount     =  $monthYearAmount - $total_discount;
							
				}elseif($coupon['discount_type'] == 'dollar') {
					$total_discount = $coupon['discount_value'];
					if( $total_discount >= $monthYearAmount ){
						$total_discount = $monthYearAmount; /*if total_discount is greater than monthYearAmount then giving the full discount = monthYearAmount */ 
					}
					$monthYearAmount   = $monthYearAmount - $total_discount;
					 
				}else {
					$response['status'] 	= 0;
					$response['msg'] 		= $is_valid_coupon['msg'];
					return $response;
				}
				$input['discount_type'] = $coupon['discount_type'];
				$input['discount_value'] = $coupon['discount_value'];
				$input['discount_duration'] = $coupon['discount_duration'];
				$input['limited_duration'] 	= $coupon['limited_duration'];
			}
			
			$total_amount			= ($monthYearAmount + $one_time_amount); 
			
			if($membership_type == 'free' && $account_preferences->membership_tier == 'single') {
				$total_amount	= $one_time_amount; 
				$monthYearAmount	= 0;
			}
			
			if($total_amount == 0 && $membership_type == 'free') {
				$finalStatus = $this->saveFreeMonthlyMembership($input, $patient_id, $add_to_wallet_status, $account);
				if($finalStatus){
					$response['thankyou_message'] = $account_preferences['thankyou_message'];
					$response['status'] = 1;
					$response['msg'] = 'Subscription added successfully!'; 
					return $response;
				}else{
					$response['status'] = 0;
					$response['msg'] = 'Something went wrong, please contact with support team';
					return $response;
				}
			}
			
			$default_currency = 'USD';
			if($account->stripe_currency){
				$default_currency = $account->stripe_currency;
			}
			$admin_id = $account->admin_id;
			if($pos_gateway == 'stripe'){
				$customer_data = MembershipService::createStripeCustomer($input);
				if(!$customer_data['status']){
					return $customer_data;
				}
			}
			if($total_amount > 0){
			
				if($pos_gateway == 'clearent'){
					$charge_data =  $this->createClearentCharge($input, $total_amount, $default_currency, $stripe_config, $account, $patient_id, $ip);
				}else{
					$charge_data =  MembershipService::createStripeCharge($input, $customer_data['data'], $total_amount, $default_currency, $stripe_config, $account);
				}
				if(!$charge_data['status']){
					return $charge_data;
				}
				$input['monthly_amount'] = $monthYearAmount;
				$input['one_time_amount'] = $one_time_amount;
				$input['membership_tiers'] = $membership_tiers;
				$input['membershipFrequency'] = $membershipFrequency;
				$save_subscription = $this->saveSubscription($charge_data, $patient_id, $input, $account_preferences, $account, $pos_gateway);
				if(!$save_subscription['status']){
					return $save_subscription;
				}
				
				
				$patient_wallet_id = 0;	
				$patient_wallet = PatientWallet::where('patient_id',$patient_id)->first();
				$walletAmount = $monthYearAmount;
				//~ if($membershipFrequency == 'yearly'){
					//~ $walletAmount = $monthYearAmount / 12;
				//~ }
				if(empty($patient_wallet)){
					$patient_wallet_id = $this->createPatientWallet($patient_id, $walletAmount, $add_to_wallet_status);
					
				}else{
					$patient_wallet_id = $this->updatePatientWallet($patient_id, $patient_wallet, $walletAmount, $add_to_wallet_status);
				}
				
				if($add_to_wallet_status == 1){	
					$amount = $account_preferences->mothly_membership_fees;
					if($patient_wallet_id > 0) {
						$this->addPatientWalletCredit($patient_id, $patient_wallet_id, $walletAmount, $admin_id, $membershipFrequency);
					}
				}
				
				$membership_id = $save_subscription['data']->id;
				MembershipService::addFreeProductsToClientWallet($membership_id, $patient_id, $admin_id);

                $customerCardBrand = '';
                $customerCardLast4 = '';
                if (!empty($pos_gateway) && $pos_gateway == "clearent") {
                    $customerCardBrand = $charge_data['data']["payload"]["transaction"]['card-type'];
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
                    $invoiceNumber = 'AR00' . $account_id . '0' . $patient_id . '0' . time();
                    $host_transaction_id = $charge_data['data']->id;
                    $platformFee = null;
                    $apriva_transaction_data = null;
                }
					
				$currentTime	= date('Y-m-d H:i:s');
				$currentTime	= $this->getCurrentTimeNewYork($currentTime);
				$posInvoiceData	= array(
					'invoice_number' 					=> $invoiceNumber,
					'customerCardBrand' 				=> $customerCardBrand,
					'customerCardLast4' 				=> $customerCardLast4,
					'patient_id' 						=> $patient_id,
					'clinic_id' 						=> $clinicID,
					'sub_total' 						=> $sub_total,
					'total_tax' 						=> 0,
					'total_amount' 						=> $total_amount,
					'treatment_invoice_id' 				=> 0,
					'patient_email' 					=> $input['email'],
					'status'							=> "paid",
					'created'							=> $currentTime,
					'paid_on'							=> $currentTime,
					'product_type'						=> 'monthly_membership',
					'monthly_amount'					=> $monthYearAmountForInvoice,
					'one_time_amount'					=> $one_time_amount,
					'total_discount'					=> $total_discount,
					'platformFee'						=> $platformFee,
					'apriva_transaction_data'			=> $apriva_transaction_data
				);
				try{	
					if($membershipFrequency == 'monthly'){
						$posInvoiceData['custom_product_name'] = 'monthly_membership';
					}
					if($membershipFrequency == 'yearly'){
						$posInvoiceData['custom_product_name'] = 'yearly_membership';
					}
					
					$posInvoiceData['host_transaction_id'] = $host_transaction_id;
					$this->createPosInvoice($posInvoiceData, 'monthly_membership', $admin_id, 'virtual',$pos_gateway);
					$zohoData['account'] = $account;
					$zohoData['patient_id'] = $patient_id;
					$zohoData['total_amount'] = $total_amount;
					$zohoData['posInvoiceData'] = $posInvoiceData;
					$zohoData['subject'] = ucfirst($membershipFrequency);
					$this->createZohoInvoice($zohoData);
					$subscriptionID = $save_subscription['data']->id;
					$input['account'] = $account;
					$input['accont_prefrences'] = $account_preferences;
					$this->saveMonthlyMembershipInvoice($posInvoiceData, $charge_data, $subscriptionID, $input,$pos_gateway);
					
					$this->savePatientLog($database_name, 0, 'patient', 0, 'add', 'membership plan', $patient_id, 'add');
					$save_subscription['thankyou_message']= $account_preferences['thankyou_message'];
					unset($save_subscription['data']);
					return $save_subscription;
				}catch(\Exception $e){
					$response['status'] = 0;
					$response['msg'] = $e->getLine().'--'.$e->getMessage().': Something went wrong, please contact with support team';
					return $response;
				}
			}else{
				if(!empty($pos_gateway) && $pos_gateway == "clearent"){
					$result_set = $this->createClearentToken($input,$stripe_config);
					if(!empty($result_set["status"]) && $result_set["status"] != 200){
						$response['status'] = 0;
						$response['msg'] = "An error occured - " . $result_set["message"];
						return $response;
					}
					$customerTokenID 	= $result_set["data"]["payload"]["tokenResponse"]["token-id"];
					$exp_date 			= $result_set["data"]["payload"]["tokenResponse"]["exp-date"];
					$charge_data['account_code'] = $customerTokenID;
					$charge_data['subscription_uuid'] = $customerTokenID;
					$charge_data['data']['payload']['tokenResponse']['token-id'] = $customerTokenID;
					$charge_data['data']['payload']['tokenResponse']['exp-date'] = $exp_date;
					
				}else{
					$charge_data['account_code'] = $customer_data['data']->id;
					$charge_data['subscription_uuid'] = $customer_data['data']->id;
				}
				$input['membership_tiers'] = $membership_tiers;
				$input['membershipFrequency'] = $membershipFrequency;
				$save_subscription = $this->saveSubscription($charge_data, $patient_id, $input, $account_preferences, $account, $pos_gateway);
				if(!$save_subscription['status']){
					return $save_subscription;
				}
				$save_subscription['thankyou_message']	= $account_preferences['thankyou_message'];
				unset($save_subscription['data']);
				return $save_subscription;
			}
		}else{
			if(!$is_pos_enabled){
				return Redirect::to('login');
			}
			
			$membership_tiers = MembershipTier::where('status',0)->where('show_membership_on_portal', 1)->where('active',0)->with(['multiTierproducts'=>function($q){
				$q->where('status',0)->with('product');
			}]);
			
			if(Auth::check()){
				$this->switchDatabase($database_name);
				$patient_id = $request->session()->get('patient_id');
				//$patient_user = Patient::where('user_id', Auth::user()->id)->first();
				$patient_user = PatientAccount::with('Patient')->where('patient_id', $patient_id)->first();
				if( null != $patient_user['patient_id'] && !empty($patient_user['Patient']) ){
			
					$membership_tiers_count = $membership_tiers->count();
					
					if($account_preferences->membership_tier == 'multiple'){
						$patientMultiMemberships = PatientMembershipSubscription::where('patient_id',$patient_id)->where('membership_tier_id','!=',0);
						
						$patientMultiMemberships = $patientMultiMemberships->get();
						$patientMultiMembershipsCount = count($patientMultiMemberships);
						$patient_membership_tier_ids = [];
						if(!empty($patientMultiMemberships)){
							foreach($patientMultiMemberships as $patient_member_tier){
								$patient_membership_tier_ids[] = $patient_member_tier->membership_tier_id;
							}
						}
						
						if($patientMultiMembershipsCount == $membership_tiers_count){
							#it means you can not create further membership
							$request->session()->put('patient_is_monthly_membership',1);
						}else{
							$request->session()->put('patient_is_monthly_membership',0);
						}
						$membership_tiers = $membership_tiers->whereNotIn('id',$patient_membership_tier_ids);
					}else{
						$patientSingleMembership = PatientMembershipSubscription::where('patient_id',$patient_id)->where('membership_tier_id','=',0)->count();
						if($patientSingleMembership){
							#it means you can not create further membership
							$request->session()->put('patient_is_monthly_membership',1);
						}else{
							$request->session()->put('patient_is_monthly_membership',0);
						}
					}
					$request->session()->put('patient',$patient_user['Patient']);
				}else{
					$request->session()->put('patient_is_monthly_membership',0);
				}
				
			}
			$membership_tiers = $membership_tiers->orderBy('tier_name', 'ASC')->get();
			
			if($account_preferences->membership_tier == 'multiple' && count($membership_tiers) == 0){
				Session::put('error', 'At least one membership setting is required');
				return Redirect::to('login');
			}
			
			if($account_preferences->membership_tier == 'single' && $account_preferences->show_membership_on_portal == 0){
				Session::put('error', 'At least one membership setting is required');
				return Redirect::to('login');
			}
			
			if($account_preferences->membership_tier == 'single'){
				$agreement_id = $account_preferences->membership_agreement_id;
				
			}elseif($account_preferences->membership_tier == 'multiple'){
				$agreement_id = @$membership_tiers[0]->membership_agreement_id;
				
			}		
			$membsership_agreement = MembershipAgreement::where('id',$agreement_id)->where('status',0)->first();
            $clearent_config = $this->getAccountClearenteConfig($account_id, $account, $clinicID);
			AccountHelper::setSessionAppointmentSettingForPatient($request);
			$thankyou_message = '';
			if($status == 'complete'){
				$thankyou_message = $account_preferences['thankyou_message'];
			}

            return view('subscription.become_a_member')
                ->with('countries', $countries)
                ->with('membership_tiers', $membership_tiers)
                ->with('membsership_agreement', $membsership_agreement)
                ->with('currency_symbol', $currency_symbol)
                ->with('pos_gateway', $pos_gateway)
                ->with('account_preferences',$account_preferences)
                ->with('clearent_config', $clearent_config)
                ->with('status', $status)
                ->with('thankyou_message', $thankyou_message)
                ->with('membsership_agreement', $membsership_agreement);
		}
	}
	
	
	public function createRecurlyAccount($input){
		
		try{
			$account = new \Recurly_Account();
			$string = $input['email'].time();
			$string_length = 40;
			$account->account_code = $this->quickRandom($string_length,$string);
			$account->email = $input['email'];
			$account->first_name = $input['first_name'];
			$account->last_name = $input['last_name'];
			$account->create();
			$response['status'] = 1;
			$response['account'] = $account;
				
		}catch (\Recurly_NotFoundError $e) {
			$response['status'] = 0;
			$response['msg'] = $e->getMessage();
		}catch (\Recurly_ValidationError $e) {
		    $response['status'] = 0;
		    $response['msg'] = $e->getMessage();
		}catch (\Recurly_ServerError $e) {
			$response['status'] = 0;
			$response['msg'] = $e->getMessage();
		}catch(\Exception $e){
			$response['status'] = 0;
			$response['msg'] = $e->getMessage();
		}
		 return $response;
	}
	
	public function createSubscription($account_code,$input) {
		
		try{
			$subscription = new \Recurly_Subscription();
			$subscription->plan_code = getenv('PLAN_CODE');
			$subscription->currency = getenv('RECURLY_CURRENCY');
			
			if($input['applied_discount_coupon'] == 1 && !empty($input['coupon_code'])) {
				$subscription->coupon_code = $input['coupon_code'];
			}
			
			$account = new \Recurly_Account();
			$account->account_code = $account_code;
			$account->email = $input['email'];
			$account->phone = $input['phone'];
			$account->first_name = $input['first_name'];
			$account->last_name = $input['last_name'];

			$billing_info = new \Recurly_BillingInfo();
			$billing_info->number = $input['number'];
			$billing_info->month = $input['month'];
			$billing_info->year = $input['year'];
			$billing_info->verification_value = $input['cvv'];
			$billing_info->address1 = $input['street_address'];
			$billing_info->city = $input['city'];
			$billing_info->state = $input['state'];
			$billing_info->country = $input['country'];
			$billing_info->zip = $input['zipcode'];

			$account->billing_info = $billing_info;
			$subscription->account = $account;

			$subscription->create();
			
			$response['status'] = 1;
			$response['subscription'] = $subscription;
		}catch (\Recurly_NotFoundError $e) {
			$response['status'] = 0;
			$response['msg'] = $e->getMessage();
		}catch (\Recurly_ValidationError $e) {
		    $response['status'] = 0;
		    $response['msg'] = $e->getMessage();
		}catch (\Recurly_ServerError $e) {
			$response['status'] = 0;
			$response['msg'] = $e->getMessage();
		}catch(\Exception $e){
			$response['status'] = 0;
			$response['msg'] = $e->getMessage();
		}
		return $response;
	}
	
	public static function quickRandom($length = 16,$string) {
		
		return substr(str_replace(array('/', '+', '=','@','.'), '_',str_shuffle(str_repeat($string, $length))), 0, $length);
	}
	
	private function createOrFindPatient($request,$database_name,$input) {
		
		$account_preferences = $input['account_preferences'];
		$patient = new Patient(); 
		$patient_id = $request->session()->get('patient_id');
		if(Auth::check() && $patient->where('id', $patient_id)->where('status', 0)->exists()){
			$patient_user = $patient->where('id', $patient_id)->where('status', 0)->first();
		}else{
			$patient_user = $patient->where('firstname', trim($input['first_name']))->where('email', trim($input['email']))->where('status', 0)->first();
		}
	
		if(count($patient_user) ==0){
			$patient->user_id = 0;
			$patient->firstname	= $input['first_name'];
			$patient->lastname	= $input['last_name'];
			$patient->email	= $input['email'];
			$patient->gender	= 2;
			$patient->phoneNumber	= $input['phone'];
			$patient->address_line_1	= @$input['street_address'];
			$patient->pincode	= @$input['zipcode'];
			$patient->city	= @$input['city'];
			$patient->state	= @$input['state'];
			$patient->country	= @$input['country'];
			if($patient->save()) {
				//echo "<pre>"; print_r($accont_prefrences); die;
				$this->patientIntegrationProcess($accont_prefrences, $patient);
				if($accont_prefrences->patient_portal_activation_link){
					$formData = array(
									'selClinic' => $input['clinic_id'],
									'formData' => array(
										'firstname' => $input['first_name'],
										'lastname' => $input['last_name'],
										'email' => $input['email'],
									)
								);
					$ppl_email_sent_status = (new BookController($request))->enablePatientPortalAccessAndSendMail($formData, $patient->id);
				}
				return $patient->id;
			}else {
				return 0;
			}
		}else{
			return $patient_user->id;
		}
	}
	
	private function saveSubscription($charge_data, $patient_id, $input, $accont_prefrences, $account, $pos_gateway = null){
		$subcription_membership = array();
		$patient_membership = new PatientMembershipSubscription;
		//~ $patient_membership_data = PatientMembershipSubscription::where('patient_id',$patient_id)->first();
		//~ if(!empty($patient_membership_data)){
			//~ $patient_membership = $patient_membership->find($patient_membership_data->id);
		//~ }
		$valid_upto	= date('Y-m-d', strtotime('+1 month'));
		$agreement_id = 0;
		$agreement_name = null;
		$agreement_text = null;
		if(isset($input['agreement_id']) && !empty($input['agreement_id'])){
			$agreement_id = $input['agreement_id'];
			$membsership_agreement = MembershipAgreement::where('id',$agreement_id)->where('status',0)->first();
			$agreement_name = @$membsership_agreement->name;
			$agreement_text = @$membsership_agreement->agreement_text;
		}
		$patient_membership->membership_agreement_id = $agreement_id;
		$patient_membership->agreement_title = $agreement_name;
		$patient_membership->agreement_text = $agreement_text;
		$patient_membership->agreement_signed_date = date('Y-m-d H:i:s');
		if($input['membershipFrequency'] == 'yearly'){
			$valid_upto	= date('Y-m-d', strtotime('+1 year'));
		}
		
		$draw_day 	= (int) date('d');
		if($draw_day > 28) {
			$draw_day = 28;
		}
		$patient_membership->draw_day = $draw_day;
		$patient_membership->patient_id = $patient_id;
		$patient_membership->subscription_status = 1;

        if (!empty($pos_gateway) && $pos_gateway == "clearent") {
            $patient_membership->recurly_account_code = $charge_data['account_code'];
            $patient_membership->subscription_uuid = $charge_data['account_code'];
            $patient_membership->card_expiry_date = $charge_data['data']['payload']['transaction']['exp-date'];
            $patient_membership->billing_zip = $input['pincode'] ?? '';
            if (!empty($charge_data["data"]["payload"]["tokenResponse"]) && isset($charge_data["data"]["payload"]["tokenResponse"])) {
                $cardDetails = $charge_data["data"]["payload"]["tokenResponse"]["card-type"] . ' ending ' . $charge_data["data"]["payload"]["tokenResponse"]["last-four-digits"];
            } else {
                $cardDetails = $charge_data["data"]["payload"]["transaction"]["card-type"] . ' ending ' . $charge_data["data"]["payload"]["transaction"]["last-four"];
            }
        } else {
            $patient_membership->recurly_account_code = $charge_data['account_code'];
            $patient_membership->subscription_uuid = $charge_data['subscription_uuid'];
            $cardDetails = $input['CardDetails'];
        }
		$currentTime	= date('Y-m-d H:i:s');
		//$currentTime	= $this->getCurrentTimeNewYork($currentTime);
		$patient_membership->subscription_started_at = $currentTime;
		$patient_membership->subscription_valid_upto = $valid_upto;
		if($accont_prefrences->membership_tier == 'multiple'){
			if($input['membershipFrequency'] == 'monthly'){
				$patient_membership->mothly_membership_fees = $input['membership_tiers']->price_per_month;
			}
			if($input['membershipFrequency'] == 'yearly'){
				$patient_membership->yearly_membership_fees = $input['membership_tiers']->price_per_year;
			}
			$patient_membership->payment_frequency = $input['membershipFrequency'];
			$patient_membership->one_time_membership_setup =  $input['membership_tiers']->one_time_setup_fee;
		}else{
			if($accont_prefrences->mothly_membership_type == 'free'){
				$patient_membership->mothly_membership_fees = 0;
			}else{
				if($input['membershipFrequency'] == 'monthly'){
					$patient_membership->mothly_membership_fees = $accont_prefrences->mothly_membership_fees;
				}
				if($input['membershipFrequency'] == 'yearly'){
					$patient_membership->yearly_membership_fees = $accont_prefrences->yearly_membership_fees;
				}
				$patient_membership->payment_frequency = $input['membershipFrequency'];
			}
			$patient_membership->one_time_membership_setup =  $accont_prefrences->one_time_membership_setup;
		}
		$patient_membership->stripe_card_details = $cardDetails;
		$patient_membership->added_by = $account->admin_id;
		$patient_membership->start_type = 'immediate';
		//$patient_membership->tos_accepted_on = date('Y-m-d h:i:s');
		//$patient_membership->tos_signature = $signature_image;
		$patient_membership->membership_tier_discount = $input['membership_tier_discount'];
		$patient_membership->membership_tier_id = $input['membership_tier_id'];
		if(isset($input['applied_discount_coupon']) && $input['applied_discount_coupon'] == 1 && !empty($input['coupon_code'])){
			$patient_membership->coupon_code = $input['coupon_code'];
			$patient_membership->discount_type = $input['discount_type'];
			$patient_membership->discount_value = $input['discount_value'];
			$patient_membership->discount_duration = $input['discount_duration'];
			$patient_membership->limited_duration = $input['limited_duration'];
		}
		$patient_membership->patient_signature = @$input['patient_signature'];
		$patient_membership->signed_on 		   = @$input['signed_on'];
		$patient_membership->purchased_ip 	   = @$input['purchased_ip'];
		$patient_membership->clinic_id = $input['clinic_id'];
		if($patient_membership->save()){
			$this->saveMembershipFreeProducts($input['membership_tier_id'], $patient_membership->id);
			$patient = new Patient;
			$patient_data = Patient::where('id',$patient_id)->first();
			if(!empty($patient_data)){
				$patient = Patient::find($patient_data->id);
			}
			$patient->is_monthly_membership = 1;
			$patient->save();
			$response['status'] = 1;
			$response['msg'] = 'Subscription added successfully!'; 
			$response['data'] = $patient_membership; 
		}else{
			$response['status'] = 0;
			$response['msg'] = 'Subscription not saved!'; 
		}
		return $response;
	}
	
	public function getDatabase($subDomain) {
		$db				= "";
		$account 		= \App\Account::where('pportal_subdomain', $subDomain)->where('status', 'active')->first();
		
		if ( $account ) {
			$db 		= $account->database_name;
			Session::put('account', $account);

		}
		
		$this->userDB 		= $db;
		return $db;
	}
	
	private function getAccountID($subDomain) {
		$accountID		= 0;
		$account 		= Account::where('pportal_subdomain', $subDomain)->where('status', 'active')->first();

		if ( $account ) {
			$accountID 		= $account->id;
		}
		return $accountID;
	}
	
	
    private function getAccountPreferences($account_id) {
		$account_preference =  AccountPrefrence::with('account')->where('account_id',$account_id)->first();
		return $account_preference;
	}
	public function thankyou(){
		$httpHost		= $_SERVER['HTTP_HOST'];
		$subDomainArr	= explode('.', $httpHost);
		$subDomain		= $subDomainArr[0];	
		$account_id = $this->getAccountID($subDomain);
		
		$account_preference = AccountPrefrence::where('account_id',$account_id)->select('id','recurly_program_name')->first();
		return view('subscription.thankyou')->with('account_preference',$account_preference);
	}
	
	public function createPatientWallet($pateint_id, $amount, $add_to_wallet_status){

		$patient_wallet = new PatientWallet;
		$patient_wallet->patient_id = $pateint_id;
		if($add_to_wallet_status == 1){
			$patient_wallet->balance = $amount;
			$patient_wallet->dollar_credit = $amount;
		}
		$patient_wallet->membership_fee = $amount;
		$currentTime	= date('Y-m-d h:i:s');
		$currentTime	= $this->getCurrentTimeNewYork($currentTime);
		$patient_wallet->created = $currentTime;
		$patient_wallet->modified = $currentTime;
		$patient_wallet->save();
		return $patient_wallet->id;
	}

	public function updatePatientWallet($pateint_id, $patient_wallet, $mothly_membership_fees, $add_to_wallet_status){
		$old_balance = $patient_wallet->balance;
		$old_balance_dollar = $patient_wallet->dollar_credit;
		$total_balance = $old_balance + $mothly_membership_fees;
		$total_balance_dollar = $old_balance_dollar + $mothly_membership_fees;
		
		$patient_wallet = PatientWallet::find($patient_wallet->id);
		if($add_to_wallet_status == 1){
			$patient_wallet->balance = $total_balance;
			$patient_wallet->dollar_credit = $total_balance_dollar;
		}
		$patient_wallet->membership_fee = $mothly_membership_fees;
		$currentTime	= date('Y-m-d h:i:s');
		$currentTime	= $this->getCurrentTimeNewYork($currentTime);
		$patient_wallet->modified = $currentTime;
		$patient_wallet->save();
		return $patient_wallet->id;
	}
	
	public function validateCouponCode(Request $request){
		$input = $request->all();
		$httpHost		= $_SERVER['HTTP_HOST'];
		$subDomainArr	= explode('.', $httpHost);
		$subDomain		= $subDomainArr[0];	
		$account_id = $this->getAccountID($subDomain);
		$account = Account::where('id',$account_id)->first();
		
		if(!$account){
			 $response['status'] 	= 0;
			 $response['msg'] 		= 'Database not found';
		}
		$database_name  = $account->database_name;
		$accont_prefrences = $this->getAccountPreferences($account_id);
		
		//~ if(empty($input['first_name']) || empty($input['email'])){
			//~ $response['status'] 	= 0;
			 //~ $response['msg'] 		= 'First name and email is required';
			 //~ return $response;
		//~ }
		
		if(empty($input['coupon_code'])){
			$response['status'] 	= 0;
			 $response['msg'] 		= 'Coupon required';
			 return $response;
		}
		# switch DB
		$this->switchDatabase($database_name);
		$patient_id = $this->findPatient($request,$database_name,$input);
		
		$is_valid_coupon = $this->isValidCoupon($patient_id,$input['coupon_code']);
		if(isset($is_valid_coupon['status']) && $is_valid_coupon['status'] == 0){
			$response['status'] 	= $is_valid_coupon['status'];
			$response['msg'] 		= $is_valid_coupon['msg'];
			return $response;
		}
		$coupon = $is_valid_coupon['data'];
		
		try { 
			$membership_options = $accont_prefrences->membership_payment_options;
			$mothly_membership_type = $accont_prefrences->mothly_membership_type;
			if($accont_prefrences->membership_tier == 'multiple'){
				$tier_id = $input['tierID'];
				$membership_tiers= MembershipTier::where('id',$tier_id)->where('status',0)
				->where('active',0)->first();
				if(!$membership_tiers){
					$response['status'] 	= $is_valid_coupon['status'];
					$response['msg'] 		= 'Membership tier invalid';
					return $response;
				}
				
				$membership_options = $membership_tiers->membership_payment_options;
				if($membership_options == 'monthly'){
					$monthYearFee = $membership_tiers->price_per_month;
				}elseif($membership_options == 'yearly'){
					$monthYearFee = $membership_tiers->price_per_year;
				}else{
					$monthYearFee = 0;
					if(!empty($input['frequncy'])){
						if($input['frequncy'] == 'month'){
							$monthYearFee = $membership_tiers->price_per_month;
						}
						if(!empty($input['frequncy']) && $input['frequncy'] == 'year'){
							$monthYearFee = $membership_tiers->price_per_year;
						}
					}else{
						$response['status'] 	= 0;
						$response['msg'] 		= 'Please select frequency';
						return $response;
					}
					
				}
				
				//$mothly_membership_fees = $membership_tiers->price_per_month;
				$one_time_setup_fees = $membership_tiers->one_time_setup_fee;
				
			}else{
				
				if($membership_options == 'monthly'){
					$monthYearFee = $accont_prefrences->mothly_membership_fees;
				}elseif($membership_options == 'yearly'){
					$monthYearFee = $accont_prefrences->yearly_membership_fees;
				}else{
					if(!empty($input['frequncy'])){
						if(!empty($input['frequncy']) && $input['frequncy'] == 'month'){
							$monthYearFee = $accont_prefrences->mothly_membership_fees;
						}
						if(!empty($input['frequncy']) && $input['frequncy'] == 'year'){
							$monthYearFee = $accont_prefrences->yearly_membership_fees;
						}
					}else{
						$response['status'] 	= 0;
						$response['msg'] 		= 'Please select frequency';
						return $response;
					}
				}
				
			  //$mothly_membership_fees = $accont_prefrences->mothly_membership_fees;
			  $one_time_setup_fees = $accont_prefrences->one_time_membership_setup;
			}  
			  if(isset($input['membership_type']) && $input['membership_type'] =='yearly'){	
				//$mothly_membership_fees	=   env('ONE_YEAR_FEE');
			  }
			  
				if($coupon->discount_type == 'percentage'){
					$discount_percent = $coupon->discount_value;
					$total_discount   = ( $monthYearFee * $discount_percent ) / 100;
					$final_amount     	=  ( $monthYearFee ) - $total_discount;
					$final_amount		= $one_time_setup_fees + $final_amount;

				}elseif($coupon->discount_type == 'dollar') {
					$total_discount = $coupon->discount_value;
					if( $total_discount >= $monthYearFee ){
						$total_discount = $monthYearFee; /*if total_discount is greater than mothly_membership_fees then giving the full discount = mothly_membership_fees */ 
					}
					$final_amount   	= ( $monthYearFee ) - $total_discount;
					$final_amount		= $one_time_setup_fees + $final_amount;

				}else {
					/*this section run when user entered trial coupon*/
					$response['status'] 	= 0;
					$response['msg'] 		= 'Invalid coupon'; 
					return $response;
				}
				$final_amount   = number_format($final_amount, 2);
				$total_discount   = number_format($total_discount, 2);
				$response['status'] 	= 1;
				$response['data'] 		= array('total_discount'=>$total_discount, 'final_amount'=>$final_amount);
				$response['msg'] 		= 'Coupon is valid';
							
		} catch (\Exception $e) {
		  $response['status'] 	= 0;
		  $response['msg'] 		= $e->getMessage();
		}
		return $response;
		
	}

	public function getStatesUS(){

		$us_state_abbrevs_names = array(
			'AL'=>'ALABAMA',
			'AK'=>'ALASKA',
			'AS'=>'AMERICAN SAMOA',
			'AZ'=>'ARIZONA',
			'AR'=>'ARKANSAS',
			'CA'=>'CALIFORNIA',
			'CO'=>'COLORADO',
			'CT'=>'CONNECTICUT',
			'DE'=>'DELAWARE',
			'DC'=>'DISTRICT OF COLUMBIA',
			'FM'=>'FEDERATED STATES OF MICRONESIA',
			'FL'=>'FLORIDA',
			'GA'=>'GEORGIA',
			'GU'=>'GUAM GU',
			'HI'=>'HAWAII',
			'ID'=>'IDAHO',
			'IL'=>'ILLINOIS',
			'IN'=>'INDIANA',
			'IA'=>'IOWA',
			'KS'=>'KANSAS',
			'KY'=>'KENTUCKY',
			'LA'=>'LOUISIANA',
			'ME'=>'MAINE',
			'MH'=>'MARSHALL ISLANDS',
			'MD'=>'MARYLAND',
			'MA'=>'MASSACHUSETTS',
			'MI'=>'MICHIGAN',
			'MN'=>'MINNESOTA',
			'MS'=>'MISSISSIPPI',
			'MO'=>'MISSOURI',
			'MT'=>'MONTANA',
			'NE'=>'NEBRASKA',
			'NV'=>'NEVADA',
			'NH'=>'NEW HAMPSHIRE',
			'NJ'=>'NEW JERSEY',
			'NM'=>'NEW MEXICO',
			'NY'=>'NEW YORK',
			'NC'=>'NORTH CAROLINA',
			'ND'=>'NORTH DAKOTA',
			'MP'=>'NORTHERN MARIANA ISLANDS',
			'OH'=>'OHIO',
			'OK'=>'OKLAHOMA',
			'OR'=>'OREGON',
			'PW'=>'PALAU',
			'PA'=>'PENNSYLVANIA',
			'PR'=>'PUERTO RICO',
			'RI'=>'RHODE ISLAND',
			'SC'=>'SOUTH CAROLINA',
			'SD'=>'SOUTH DAKOTA',
			'TN'=>'TENNESSEE',
			'TX'=>'TEXAS',
			'UT'=>'UTAH',
			'VT'=>'VERMONT',
			'VI'=>'VIRGIN ISLANDS',
			'VA'=>'VIRGINIA',
			'WA'=>'WASHINGTON',
			'WV'=>'WEST VIRGINIA',
			'WI'=>'WISCONSIN',
			'WY'=>'WYOMING',
			'AE'=>'ARMED FORCES AFRICA \ CANADA \ EUROPE \ MIDDLE EAST',
			'AA'=>'ARMED FORCES AMERICA (EXCEPT CANADA)',
			'AP'=>'ARMED FORCES PACIFIC'
		);
		return $us_state_abbrevs_names;
	}

	private function saveExtraMembershipGift($input, $patientID ){
		$requestType		= 'product';
		$finalStatus		= false;
		$proPackStatus		= false;

		if ( $requestType && $requestType == 'product' ) {
			$proPackageID		= $input['membership_extra_gift'];
			$proPackageUnits	= 1;

			if ( $proPackageID ) {
				$package 		= DiscountPackage::find($proPackageID);
				if ($package ) {
					$discount_pack_products = DiscountGroupProduct::where('discount_group_id',$package->package_product_id)->get();
					if($discount_pack_products){
						$packagePrice			= $package->package_bogo_price;

						$patPackParams			= array('patientID' => $patientID, 'packageID' => $proPackageID, 'type' => 'package', 'packageAmount' => $packagePrice, 'purchasedFrom' => '');

						$patPackID 				= $this->savePatientPackageOld($patPackParams);

						if ( $patPackID ) {
							$proPackStatus		= true;
						}

						if ( $proPackStatus ) {
							$savedUnits					= $package->package_product_quantity;
							$dollarValue				= ($packagePrice/$savedUnits) * $proPackageUnits;

							foreach($discount_pack_products as $discountProduct ){
								$patPackProParams			= array('patPackID' => $patPackID, 'productID' => $discountProduct->product_id, 'productType' => 'free', 'totalUnits' => $proPackageUnits, 'dollarValue' => $dollarValue, 'balanceUnits' => $proPackageUnits, 'balanceDollarValue' => $dollarValue, 'discountPercentage' => 0, 'addedBy' => 0);

								if ( $this->savePatientPackageProductsOld($patPackProParams) ) {
									$walletParams			= array('patientID' => $patientID, 'dollarValue' => $dollarValue, 'type' => "");
									if ( $this->updateWalletBalanceOld($walletParams) ) {
										$finalStatus = true;
									}
								}
							}
						}
					}
				}
			}
		}

	}

	private function savePatientPackageOld($params)
	{
		$patPackID		 = false;
		$patientPackage  = new PatientPackage();
		$patientPackage->patient_id 		= $params['patientID'];
		$patientPackage->package_id 		= $params['packageID'];
		$patientPackage->type 				= $params['type'];
		$patientPackage->date_purchased 	= date('Y-m-d H:i:s');
		$patientPackage->purchased_from 	= $params['purchasedFrom'];
		$patientPackage->package_amount		= $params['packageAmount'];

		if ( $patientPackage->save() ) {
			$patPackID		= $patientPackage->id;
		}
		return $patPackID;
	}

	private function savePatientPackageProductsOld($params)
	{
		$patPackProID		= false;
		$patientPackageProduct = new PatientPackageProduct();
		$patientPackageProduct->patient_package_id 	 = $params['patPackID'];
		$patientPackageProduct->product_id 			 = $params['productID'];
		$patientPackageProduct->product_type		 = $params['productType'];
		$patientPackageProduct->total_units 		 = $params['totalUnits'];
		$patientPackageProduct->dollar_value 		 = $params['dollarValue'];
		$patientPackageProduct->balance_units 		 = $params['balanceUnits'];
		$patientPackageProduct->balance_dollar_value = $params['balanceDollarValue'];
		$patientPackageProduct->discount_percentage  = $params['discountPercentage'];
		$patientPackageProduct->added_by			 = $params['addedBy'];

		if ( $patientPackageProduct->save() ) {
			$patPackProID		= $patientPackageProduct->id;
		}

		return $patPackProID;
	}

	private function updateWalletBalanceOld($params, $updateDollarCredit=false)
	{
		$status					= false;
		$patientWallet 			= PatientWallet::where('patient_id',$params['patientID'])->first();
		$dollarValue			= $params['dollarValue'];
		$type					= array('bd'=>'bd_credit','dollar'=>'dollar_credit','aspire'=>'aspire_credit');
		$credit_type			= 'dollar_credit';

		if ( !empty($params['type']) ) {
			if ( array_key_exists($params['type'], $type) ) {
				$credit_type		= $type[$params['type']];
			}
		}

		//~ $walletData['PatientWallet'] = array(
			//~ 'balance' 			=> $dollarValue,
			//~ 'modified' 			=> date('Y-m-d H:i:s')
		//~ );
		$wallet = PatientWallet::find($patientWallet->id);
		$wallet->balance = $dollarValue;
		$wallet->modified = date('Y-m-d H:i:s');

		if ( count((array)$patientWallet) ) {
			if ( $updateDollarCredit ) {
				$dollarCredit									= $patientWallet->$credit_type;
				$newDollarCredit								= $dollarCredit + $dollarValue;
				//$walletData['PatientWallet'][$credit_type]		= $newDollarCredit;
				$wallet->$credit_type = $newDollarCredit;
			}
			$walletBalance							= $patientWallet->balance;
			$newWalletBalance						= $walletBalance + $dollarValue;
			//$walletData['PatientWallet']['balance'] = $newWalletBalance;
			$wallet->balance 						= $newWalletBalance;
			//$walletID								= $patientWallet['PatientWallet']['id'];
			//$this->PatientWallet->id 				= $walletID;

			if ( $wallet->save() ) {
				$status = true;
			}
		}

		return $status;
	}

	private function createStripeCustomer($input){
		$patientEmail = $input['email'];
		$createCustomerArr	= array(
		  "email" 			=> $patientEmail,
		  "source" 			=> $input['stripeToken']
		);
		try{
			$createCustomer	= callStripe('customers', $createCustomerArr);
			if ( isset($createCustomer->id) && !empty($createCustomer->id) ) {
				$response['status'] = 1;
				$response['data'] = $createCustomer;
			}else{
				$response['status'] = 0;
				$response['msg'] = 'We are unable to authorize your card, please try again.';
			}
		}catch(\Exception $e){
			$response['status'] = 0;
			$response['msg'] = 'Something went wrong,stripe error.';
		}
		return $response;

	}

	private function createStripeCharge($input, $customer_data, $total_amount, $defaultAccountCurrency, $stripe_config,$account){
        if (!$this->checkJuvlyDomainName()) {
            $accountName = isset($account['name']) && !empty($account['name']) ? $account['name'] : config('stripe.descriptor');
            $accountName = substr(strtoupper(str_replace(' ', '', $accountName)), 0, 20);
            $accountName = $this->cleanString($accountName);
            $accountName = preg_replace('/[^A-Za-z0-9\-]/', '', $accountName);
            $statement_descriptor = (strlen($accountName) > 20) ? substr($accountName, 0, 20) : $accountName;
        }
		$currency_code = strtoupper($defaultAccountCurrency);
		$stripe_country = DB::table('stripe_countries')->where('currency_code',$currency_code)->first();
		$minimum_amount = 50;
		if($stripe_country->minimum_amount){
			$minimum_amount = $stripe_country->minimum_amount;
		}
		$patientEmail = $input['email'];
		$customerTokenID		= $customer_data->id;
		$recurly_account_code 	= $customerTokenID;
		$recurly_uuid			= $customerTokenID;
        $statement_descriptor = config('stripe.descriptor');
		$stripe_user_id 		= $stripe_config->stripe_user_id;
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
		$chargeArr	= array(
			"amount" 	  	=> $minimum_amount,
			"capture"		=> 'false',
			"customer" 	  	=> $customerTokenID,
			"currency"	  	=> $defaultAccountCurrency,
			//"statement_descriptor"	=> strtoupper($statement_descriptor),
			"statement_descriptor_suffix"	=> strtoupper($statement_descriptor),
			"description" 	=> $patientEmail . ' : monthly membership on Aesthetic Record',
			//"application_fee" 		=> round($stripe_config->platform_fee, 2) * 100,
			"on_behalf_of" => $stripe_user_id,
			"transfer_data" => array(
				"destination" => $stripe_user_id
			)
		);

		$stripe_platform_fee    = $stripe_config->platform_fee;
        $platformFee            = 0;
        if($stripe_platform_fee > 0){
            $platformFee    = ($stripe_platform_fee * $total_amount) / 100;
        }

		try{

			$initial_charge = callStripe('charges', $chargeArr);
			if(!is_object($initial_charge)){
				$response['status'] = 0;
				$response['msg'] = 'Connected account is invalid';
				return $response;
			}

			if($initial_charge){

				//~ $chargeData	= array(
					//~ "amount" 	  			=> ($total_amount * 100),
					//~ "customer" 	  			=> $customerTokenID,
					//~ "currency"	  			=> $defaultAccountCurrency,
					//~ "statement_descriptor"	=> strtoupper($statement_descriptor),
					//~ "description" 			=> 'Payment for monthly membership : '.$patientEmail,
					//~ "application_fee" 		=> round($platformFee, 2) * 100,
					//~ "destination" 			=> array("account" => $stripe_user_id)
				//~ );
				$chargeData	= array(
					"amount" 	  			=> ($total_amount * 100),
					"customer" 	  			=> $customerTokenID,
					"currency"	  			=> $defaultAccountCurrency,
					//"statement_descriptor"	=> strtoupper($statement_descriptor),
					"statement_descriptor_suffix"	=> strtoupper($statement_descriptor),
					"description" 			=> 'Payment for monthly membership : '.$patientEmail,
					"application_fee_amount" 		=> round($platformFee, 2) * 100,
					"on_behalf_of" => $stripe_user_id,
					"transfer_data" => array(
						"destination" => $stripe_user_id
					)
				);

				$final_charge 	= callStripe('charges', $chargeData);
				if(!is_object($final_charge)){
					$response['status'] = 0;
					$response['msg'] = 'Something went wrong while making charge, please contact with support team';
					return $response;
				}
				$response['status'] = 1;
				$response['account_code'] =  $customerTokenID;
				$response['subscription_uuid'] =  $customerTokenID;
				$response['data'] = $final_charge;
			}

		}catch(\Exception $e){
			$response['status'] = 0;
			$response['msg'] = 'Something went wrong,stripe error.'.$e->getLine();
		}
		return $response;
	}
	
	private function addPatientWalletCredit($patient_id, $patient_wallet_id, $amount, $admin_id, $membershipFrequency = 'monthly'){
		$patient_wallet_credit 						= new PatientWalletCredit();
		$patient_wallet_credit->patient_wallet_id 	= $patient_wallet_id;
		$patient_wallet_credit->patient_id 			= $patient_id;
		$patient_wallet_credit->credit_type 		= 'membership_fee';
		$patient_wallet_credit->type 				= 'credit';
		$patient_wallet_credit->amount_credited 	= $amount;
		$patient_wallet_credit->balance 			= $amount;
		$currentTime	= date('Y-m-d H:i:s');
		$currentTime	= $this->getCurrentTimeNewYork($currentTime);
		$patient_wallet_credit->added_on 			= $currentTime;
		$patient_wallet_credit->reason 				= $membershipFrequency.' membership fees';
		$patient_wallet_credit->added_by 			= $admin_id;
		$patient_wallet_credit->save();
	}
	
	public function createPosInvoice($posInvoiceData, $product_type, $admin_id, $appointment_type = null, $gatewayType = null){
		$title = 'Virtual appointment';
		$is_cancellation_fee = 0;
		if($posInvoiceData['custom_product_name'] == 'monthly_membership'){
			$title = 'Monthly Membership';
		}
		if($posInvoiceData['custom_product_name'] == 'yearly_membership'){
			$title = 'Yearly Membership';
		}
		if($posInvoiceData['product_type'] == 'custom' && @$posInvoiceData['title'] == 'cancellation_fee'){
			$title = null;
			$is_cancellation_fee = 1;
		}
		$appointment_cacellation_transaction_id = 0; 
		if(!empty($posInvoiceData['appointment_cacellation_transaction_id'])){ 
			$appointment_cacellation_transaction_id = $posInvoiceData['appointment_cacellation_transaction_id']; 
		} 
		$posInvoice = new PosInvoice();
		$posInvoice->user_id				= $admin_id;
		$posInvoice->procedure_id 			= 0;
		$posInvoice->patient_id				= $posInvoiceData['patient_id'];
		$posInvoice->clinic_id				= $posInvoiceData['clinic_id'];
		$posInvoice->invoice_number			= $posInvoiceData['invoice_number'];
		$posInvoice->patient_email			= $posInvoiceData['patient_email'];
		$posInvoice->title					= $title;
		$posInvoice->total_amount			= $posInvoiceData['total_amount'];
		$posInvoice->sub_total				= $posInvoiceData['sub_total'];
		$posInvoice->total_tax				= $posInvoiceData['total_tax'];
		$posInvoice->created				= $posInvoiceData['created'];
		$posInvoice->modified				= $posInvoiceData['created'];
		$posInvoice->payment_datetime		= $posInvoiceData['paid_on'];
		$posInvoice->custom_discount		= $posInvoiceData['total_discount'];
		$posInvoice->invoice_status			= 'paid';
		$posInvoice->is_cancellation_fee	= $is_cancellation_fee;
		$posInvoice->appointment_cancellation_transaction_id			= $appointment_cacellation_transaction_id; 
		
		if ( !$posInvoice->save() ) {
			throw new Exception("Unable to create invoice, please try again later");
		}
		
		$invoiceId	= $posInvoice->id;
		$posInvoiceData['invoice_id'] = $posInvoice->id;
		$posInvoiceData['product_type'] = $product_type;
		 
		if($product_type == 'monthly_membership'){
			
			$posInvoiceData['item_amount'] 			= $posInvoiceData['monthly_amount'];
			//$posInvoiceData['custom_product_name'] 	= 'monthly_membership';
			if ( !$this->savePosItem($posInvoiceData, $admin_id) ) {	
				throw new Exception("Unable to create invoice, please try again later");
			}
			
			$posInvoiceData['item_amount'] 			= $posInvoiceData['one_time_amount'];
			$posInvoiceData['custom_product_name'] 	= 'one_time_setup_fee';
			if ( !$this->savePosItem($posInvoiceData, $admin_id) ) {
				throw new Exception("Unable to create invoice, please try again later");
			}
		}else{
			$posInvoiceData['item_amount'] = $posInvoiceData['total_amount'];
			
			if($appointment_type && $appointment_type =='virtual'){
				$posInvoiceData['custom_product_name'] 	= $posInvoiceData['custom_product_name'];
$posInvoiceData['product_units'] = 1;

			}elseif($posInvoiceData['product_type'] == 'custom' && @$posInvoiceData['title'] == 'cancellation_fee'){
				$posInvoiceData['custom_product_name'] 	= $posInvoiceData['custom_product_name'];
				
			}else{
				$posInvoiceData['custom_product_name'] 	= null;
			}
			
			if ( !$this->savePosItem($posInvoiceData, $admin_id) ) {
				throw new Exception("Unable to create invoice, please try again later");
			}
		}
		
		$posTransaction = new PosTransaction();
		$posTransaction->invoice_id				= $invoiceId;
		$posTransaction->payment_mode			= 'cc';
		$posTransaction->total_amount			= $posInvoiceData['total_amount'];
		$posTransaction->payment_status			= 'paid';
		$posTransaction->transaction_datetime	= $posInvoiceData['created'];
		$posTransaction->receipt_id				= $posInvoiceData['invoice_number'];
		
		
		if ( !$posTransaction->save() ) {
			throw new Exception("Unable to create invoice, please try again later");
		}
		
		$posTransactionId			= $posTransaction->id;
		
		$posTransactionsPayment = new PosTransactionsPayment();
		if(isset($posInvoiceData['host_transaction_id']) && !empty($posInvoiceData['host_transaction_id'])) {
			$posTransactionsPayment->host_transaction_id	= $posInvoiceData['host_transaction_id'];
		}
		$posTransactionsPayment->pos_transaction_id	= $posTransactionId;
		$posTransactionsPayment->payment_mode			= 'cc';
		$posTransactionsPayment->cc_mode				= 'manual';
		$posTransactionsPayment->cc_type				= $posInvoiceData['customerCardBrand'];
		$posTransactionsPayment->cc_number				= $posInvoiceData['customerCardLast4'];
		$posTransactionsPayment->total_amount			= $posInvoiceData['total_amount'];
		$posTransactionsPayment->created				= $posInvoiceData['created'];
		$posTransactionsPayment->payment_status			= 'paid';
		if(isset( $posInvoiceData['apriva_transaction_data'])){
			$posTransactionsPayment->apriva_transaction_data	= $posInvoiceData['apriva_transaction_data'];
		}
		if($gatewayType == 'clearent'){
			$amount 				= $posInvoiceData['total_amount'];
			$clearentProcessingFee 	= ($amount * $posInvoiceData['platformFee']) / 100;
			$posTransactionsPayment->processing_fees			= $clearentProcessingFee;
		}
		if ( !$posTransactionsPayment->save() ) {
			throw new Exception("Unable to create invoice, please try again later");
		}
		return $invoiceId;
	} 
	
	private function saveMonthlyMembershipInvoice($posInvoiceData, $response, $subscriptionID, $input,$pos_gateway=null)
	{
		
		$account = $input['account'];
		$accont_prefrences = $input['accont_prefrences'];
		$account_id = $input['account']->id;
		
		$invoiceNumber 		= $posInvoiceData['invoice_number'];
		$patientID 			= $posInvoiceData['patient_id'];
		$amount 			= $posInvoiceData['total_amount'];
		$total_discount 			= $posInvoiceData['total_discount'];
		$customerTokenID 	= $response['account_code'];
		
		//~ $patient_subsription = PatientMembershipSubscription::where('patient_id',$patientID)->select('id')->first();
		$patient_subsription = PatientMembershipSubscription::where('id',$subscriptionID)->select('id')->first();
		$patient_subsription_id = 0;
		if($patient_subsription){
			$patient_subsription_id = $patient_subsription->id;
		}
		
		$admin_id		= $account->admin_id;
		$monthlyMembershipInvoice = new MonthlyMembershipInvoice();
		$monthlyMembershipInvoice->patient_id 			 = $patientID;
		$monthlyMembershipInvoice->payment_status		 = 'paid';
		$monthlyMembershipInvoice->amount 				 = $amount;
		$monthlyMembershipInvoice->stripe_customer_token = $customerTokenID;
		if(!empty($pos_gateway) && $pos_gateway == 'clearent'){
			$monthlyMembershipInvoice->stripe_charge_id 	 = $response['data']['payload']['transaction']['id'];
			$monthlyMembershipInvoice->stripe_response 		 = json_encode($response['data']['payload']);
		}else{
			$monthlyMembershipInvoice->stripe_charge_id 	 = $response['data']->id;
			$monthlyMembershipInvoice->stripe_response 		 = json_encode($response['data']);
		}
		$monthlyMembershipInvoice->invoice_status 		 = 'sent';
		$monthlyMembershipInvoice->payment_frequency	 = $input['membershipFrequency'];
		$monthlyMembershipInvoice->created 				 = date('Y-m-d H:i:s');
		$monthlyMembershipInvoice->modified 			 = date('Y-m-d H:i:s');
		$monthlyMembershipInvoice->patient_membership_subscription_id = $patient_subsription_id;
		$monthlyMembershipInvoice->total_discount 		 = $total_discount;
		if(isset($input['applied_discount_coupon']) && $input['applied_discount_coupon'] == 1 && !empty($input['coupon_code'])){
			$discounted_data = $this->calculateCouponCode($input, $account_preferences);
			if($patient_subsription){
				$patient_subsription->discount_duration  	= $discounted_data['data']['discount_duration'];
				$patient_subsription->limited_duration  	= $discounted_data['data']['limited_duration'];
				$patient_subsription->save();
			}
		}
		$monthlyMembershipInvoice->save();
		
		$invoice_id 							= $monthlyMembershipInvoice->id;
		
		(new MonthlyMembershipInvoice)->where('id',$invoice_id)->update(['invoice_number'=> $invoiceNumber]);
		
		$card_details = @$response['data']->source->brand.' ending '.@$response['data']->source->last4;
		$monthlyMembershipInvoicePayment = new MonthlyMembershipInvoicePayment();
		
		$monthlyMembershipInvoicePayment->monthly_membership_invoice_id = $invoice_id;
		$monthlyMembershipInvoicePayment->amount 						= $amount;
		if(!empty($pos_gateway) && $pos_gateway == 'clearent'){
			$card_details = @$response['data']["payload"]["transaction"]['card-type'].' ending '.$response['data']["payload"]["transaction"]['last-four'];
			$monthlyMembershipInvoicePayment->card_details 					= $card_details;
			$monthlyMembershipInvoicePayment->stripe_charge_id 				= $response['data']['payload']['transaction']['id'];
			$monthlyMembershipInvoicePayment->stripe_response 				= json_encode($response['data']['payload']);
		}else{
			$monthlyMembershipInvoicePayment->card_details 					= $card_details;
			$monthlyMembershipInvoicePayment->stripe_charge_id 				= $response['data']->id;
			$monthlyMembershipInvoicePayment->stripe_response 				= json_encode($response['data']);
		}
		$monthlyMembershipInvoicePayment->payment_status 				= 'paid';
		$monthlyMembershipInvoicePayment->payment_datetime 				= date('Y-m-d H:i:s');
		
		
		$monthlyMembershipInvoicePayment->save();
		$posInvoiceData['subscriptionID'] = $subscriptionID;
		$posInvoiceData['invoice_id'] = $invoice_id;
		$posInvoiceData['account_id'] = $account_id;
		$posInvoiceData['admin_id'] = $admin_id;
		return $this->sendMembershipInvoiceEmail($posInvoiceData);
	}
	
	private function sendMembershipInvoiceEmail($posInvoiceData)
	{
		$patientID 		= 	$posInvoiceData['patient_id']; 
		$amount 		=	$posInvoiceData['total_amount']; 
		$subscriptionID =	$posInvoiceData['subscriptionID']; 
		$invoice_id		=	$posInvoiceData['invoice_id'];  
		$account_id		=	$posInvoiceData['account_id'];  
		$admin_id		=	$posInvoiceData['admin_id']; 
		
		$user 				= Users::find($admin_id);
		$accountData 		= Account::with('accountPrefrence')->find($account_id);
		$from_email         = env('MAIL_FROM_EMAIL'); 
		$replyToEmail      	= env('MAIL_FROM_EMAIL'); 
		if($accountData['accountPrefrence']->from_email){
			$replyToEmail  	= $accountData['accountPrefrence']->from_email;
		}
		$patientData 		= Patient::find($patientID);
		$patientMemSub 		= PatientMembershipSubscription::find($subscriptionID);
		$Invoices 			= MonthlyMembershipInvoice::with(array('monthlyMembershipInvoicePayment'=>function($invoice_payment){
			$invoice_payment->where('payment_status','paid');
		}))->find($invoice_id);
		$business_name = "";
		$clinic = Clinic::where('id',$user->clinic_id)->first();
		$clinic_name = $clinic->clinic_name;
		$patientEmail		= $patientData->email;
		if(!empty($patientEmail)) {
			$storagefolder			= '';
			$storagefolder 			= $accountData->storage_folder;
			$logo_img_src 			= '';
			$media_path = public_path();
			//$media_url = url('/');
			$media_url = public_path();
			$ar_media_path = env('MEDIA_URL');
			if(isset($accountData->logo) && $accountData->logo != '') {
				$logo_img_src 		= $ar_media_path.$storagefolder.'/admin/'.$accountData->logo;
			} else {
				$logo_img_src 		= env('NO_LOGO_FOR_PDF');
			}
			$filename		= '';
			$attachments	= null;
			$email_content 	= '';
			$subject 		= "AR - Membership Payment Confirmation";
			$data = [];
			$data['invoice_amount'] = $amount;
			$data['invoice_data'] = $Invoices;
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
			if($posInvoiceData['custom_product_name'] == 'yearly_membership' ){
				$data['custom_product_label'] = 'You subscribed for yearly membership';
			}
			elseif($posInvoiceData['custom_product_name'] == 'monthly_membership'){
				$data['custom_product_label'] = 'You subscribed for monthly membership';
			}
			//$data['stripe_currency'] = $stripe_currency;
			
			$clinic_address		= @$clinic->address;		
			$account_logo  		= @$accountData->logo;
			$account_name		= @$accountData->name;
			$storage_folder		= @$accountData->storage_folder;
			$appointment_status = $subject;
			$site_url			= getenv('SITE_URL');
			
			
			$clinic_location_tmp 		= [];
			$clinic_location 			= '';
			if(!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
				$clinic_location_tmp[] = $clinic->clinic_city;
				$clinic_location_tmp[] = $clinic->clinic_state;
				$clinic_location_tmp[] = $clinic->clinic_zipcode;
				$clinic_location  = implode(", ",$clinic_location_tmp);
			} else {
				if($clinic->city!=''){
					$clinic_location_tmp[] = $clinic->city;
				}
				if($clinic->country!=''){
					$clinic_location_tmp[] = $clinic->country;
				}
				$clinic_location  = implode(", ",$clinic_location_tmp);
			}	
			
			//$view 	=  \View::make('subscription.membership_email_template', ['data' => $data]);
			$view 	= \View::make('subscription.membership_email_template', ['data' => $data,'clinic_location' => $clinic_location,'account_logo' => $account_logo, 'site_url' => $site_url,'storage_folder' => $storage_folder,'appointment_status' => $appointment_status, 'clinic_address'=>$clinic_address]);
			
			
			$email_content 	= $view->render();
			if($amount > 0 ){
				$pdf = \PDF::loadView('subscription.membership_invoice_template', ['data' => $data]);
				$invoive_title 		= rand(10,100).$account_id.$patientID.$invoice_id.rand(10,100).date('ymdhis');
				$dir 			= $media_path.'/stripeinvoices/';
				$filename 			= $dir.$invoive_title.".pdf";
				$pdf->save($filename,'F');
				$attachments 	= $media_url.'/stripeinvoices/'.$invoive_title.'.pdf';
			}
			return $this->sendEmail($from_email, $patientEmail, $replyToEmail, $email_content, $subject, $attachments, $posInvoiceData['invoice_number']);
		}
	}
	
	private function savePatientLog($db, $user_id, $object, $object_id, $action, $child, $patient_id, $child_action)
	{
		$this->switchDatabase($db);
		$userLog						= new UserLog();
		
		$userLog->user_id			= 0;
		$userLog->child				= $child;
		$userLog->child_id			= $patient_id;
		$userLog->child_action		= $child_action;
		$userLog->object			= $object;
		$userLog->object_id			= $object_id;
		$userLog->action			= $action;
		$currentTime 				= date('Y-m-d h:i:s');
		$userLog->created			= $this->getCurrentTimeNewYork($currentTime);

		$saved 						= $userLog->save();
		
		if ( $saved ) {
			return $userLog->id;
		} else {
			return 0;
		}
		
	}
	
	private function getStripeConfig($account_id, $account, $clinicID){
		if($account->stripe_connection == 'clinic'){
			$stripe_config = AccountStripeConfig::where('account_id',$account_id)->where('clinic_id',$clinicID)->first();
			if(!$stripe_config){
				$stripe_config = AccountStripeConfig::where('account_id',$account_id)->where('clinic_id','!=',0)->first();
			}
		}else{
			// GLOBAL where clinic id 0
			$stripe_config = AccountStripeConfig::where('account_id',$account_id)->where('clinic_id',0)->first();
		}	
		return $stripe_config;
	}
	
	private function getClinicID($account){
		$clinic = Clinic::where('status',0)->first();
		$clinicID = $clinic->id;
		if(isset($account['user']) && !empty($account['user'])) {
			if($account['user']->clinic_id){
				$clinic = Clinic::where('status',0)->where('id',$account['user']->clinic_id)->first();
				if($clinic){
					$clinicID = $clinic->id;
				}
			}
		}
		return $clinicID;
	}

	function uploadSignatureImage($input, $account_id, $patient_id){
		$postdata = [];
		$postdata['image_data'] = $input['image_data'];
		$postdata['account_id'] = $account_id;
		$postdata['patient_id'] = $patient_id;

		$url = env('SIGNATURE_UPLOAD_PATH').'/dashboard/uploadImage';
		//echo $url; die('here');
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL,$url);
		if(count((array)$postdata) > 0){
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS,$postdata);
		}
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		$response=json_decode(curl_exec($ch));

		$info=curl_getinfo($ch);
		curl_close ($ch);

		return $response;

	}

	public function saveFreeMonthlyMembership($input, $patient_id, $add_to_wallet_status, $account){
		
		$addedBy		= $account->admin_id;
		$admin_id		= $account->admin_id;
		$patientID		= $patient_id;
		$patientEmail	= trim($input['email']);
		$finalStatus 	= false;
		$account_id		= $account->id; 	
		$patient = Patient::find($patient_id);
		$patient->is_monthly_membership 	= 1;
		$patient->email 					= $patientEmail;
		
		if ( $patient->save() ) {
			
			$amount					= 0;
			$one_time_amount		= 0;
			$CardDetails			= '--';
			$recurly_account_code	= '--';
			$recurly_uuid			= '--';
			$startedAt				= date('Y-m-d h:i:s');
			$validUpto				= date('Y-m-d', strtotime('+1 month'));			
			$agreement_id = 0;
			$agreement_name = null;
			$agreement_text = null;
			if(isset($input['agreement_id']) && !empty($input['agreement_id'])){
				$agreement_id = $input['agreement_id'];
				$membsership_agreement = MembershipAgreement::where('id',$agreement_id)->where('status',0)->first();
				$agreement_name = @$membsership_agreement->name;
				$agreement_text = @$membsership_agreement->agreement_text;
			}
			$patientMemSub 		= PatientMembershipSubscription::where('patient_id',$patient_id)->first();
						
			if ($patientMemSub) {
				
				$subscriptionData = array(
					'subscription_status' 			=> 1,
					'subscription_started_at' 		=> $startedAt,
					'subscription_valid_upto'		=> $validUpto,
					'mothly_membership_fees'		=> $amount,
					'one_time_membership_setup'		=> $one_time_amount,
					'stripe_card_details'			=> $CardDetails,
					'recurly_account_code' 			=> $recurly_account_code,
					'subscription_uuid' 			=> $recurly_uuid,
					'modified' 						=> date('Y-m-d'),
					'added_by' 						=> $addedBy,
					'start_type' 					=> 'immediate',
					'clinic_id'						=> $input['clinic_id'],
					'membership_agreement_id'		=> $agreement_id,
					'agreement_title'				=> $agreement_name,
					'agreement_text'				=> $agreement_text,
					'agreement_signed_date'			=> date('Y-m-d H:i:s'),
					'patient_signature' 			=> $input['patient_signature'],
					'signed_on' 					=> $input['signed_on'],
					'purchased_ip' 					=> $input['purchased_ip']
				);
				
				if($patientMemSub->update($subscriptionData)) {
					$finalStatus = true;
				}
			} else {
				$subscriptionData = array(
					'patient_id' 					=> $patientID,
					'recurly_account_code' 			=> $recurly_account_code,
					'subscription_uuid' 			=> $recurly_uuid,
					'subscription_status' 			=> 1,
					'subscription_started_at' 		=> $startedAt,
					'subscription_valid_upto'		=> $validUpto,
					'mothly_membership_fees'		=> $amount,
					'one_time_membership_setup'		=> $one_time_amount,
					'stripe_card_details'			=> $CardDetails,
					'created' 						=> date('Y-m-d'),
					'modified' 						=> date('Y-m-d'),
					'added_by' 						=> $addedBy,
					'start_type' 					=> 'immediate',
					'clinic_id'						=> $input['clinic_id'],
					'membership_agreement_id'		=> $agreement_id,
					'agreement_title'				=> $agreement_name,
					'agreement_text'				=> $agreement_text,
					'agreement_signed_date'			=> date('Y-m-d H:i:s'),
					'patient_signature' 			=> $input['patient_signature'],
					'signed_on' 					=> $input['signed_on'],
					'purchased_ip' 					=> $input['purchased_ip']
				);
				$patientMemSubObj = new PatientMembershipSubscription;
				if($patientMemSubObj->create($subscriptionData)) {
					$finalStatus = true;
				}
			}
			
			//~ if($finalStatus) {
				//~ $this->sendMembershipInvoiceEmail($patientID, $amount, 0, 0, $account_id, $admin_id);	
			//~ }
		}
		$database_name 		= $account->database_name;
		#DB switch
 		$this->switchDatabase($database_name);
		$patient_wallet_id = 0;	
		$patient_wallet = PatientWallet::where('patient_id',$patient_id)->first();
		if(empty($patient_wallet)){
			$patient_wallet_id = $this->createPatientWallet($patient_id, 0, $add_to_wallet_status);
			
		}else{
			$patient_wallet_id = $this->updatePatientWallet($patient_id, $patient_wallet, 0, $add_to_wallet_status);
		}
		return $finalStatus;
	}
	
	private function findPatient($request,$database_name,$input) {
		$this->switchDatabase($database_name);
		$patient = new Patient(); 
		$patient_id = $request->session()->get('patient_id');
		if(Auth::check() && $patient->where('id', $patient_id)->where('status', 0)->exists()){
			$patient_user = $patient->where('id', $patient_id)->where('status', 0)->first();
		}else{
			$patient_user = $patient->where('firstname', trim($input['first_name']))->where('email', trim($input['email']))->where('status', 0)->first();
		}
		if($patient_user){
			return $patient_user->id;
		}else{
			return 0;
		}
	}
	
	private function isValidCoupon($patient_id, $coupon_code){
		$today = date('Y-m-d');
		$coupon = DiscountCoupon::where('coupon_code', $coupon_code)->where('is_deleted',0)->where('is_expired',0)->where('expiry_date','>=',$today)->first();
		if(!$coupon){
			$response['status'] 	= 0;
			$response['msg'] 		= 'Invalid coupon'; 
			return $response;
		}	
		
		if($patient_id){	
			$coupon_redemtion = DiscountCouponRedemption::where('discount_coupon_id', $coupon->id)->where('redeemed_by',$patient_id)->first();
			if($coupon_redemtion){
				$response['status'] 	= 0;
				$response['msg'] 		= 'Coupon already used';
				return $response;
			}
		}
		$response['status'] 	= 1;
		$response['msg'] 		= 'Coupon valid';
		$response['data'] 		= $coupon;
		return $response;
	}
	
	private function savePosItem($posInvoiceData, $user_id){
		$posInvoiceItem =  new PosInvoiceItem();
		$posInvoiceItem->invoice_id				= $posInvoiceData['invoice_id'];
		$posInvoiceItem->product_type			= $posInvoiceData['product_type'];
		$posInvoiceItem->product_id				= $posInvoiceData['treatment_invoice_id'];
		$posInvoiceItem->total_product_price	= $posInvoiceData['item_amount'];
		$posInvoiceItem->modified				= $posInvoiceData['created'];
		$posInvoiceItem->created				= $posInvoiceData['created'];
		$posInvoiceItem->user_id				= $user_id;
		$posInvoiceItem->custom_product_name	= $posInvoiceData['custom_product_name'];
		if(!empty($posInvoiceData['product_units'])){
			$posInvoiceItem->product_units			= $posInvoiceData['product_units'];
		}
		//~ if($posInvoiceData['custom_product_name'] ==  'monthly_membership'){
			//~ $posInvoiceItem->per_unit_discount = $posInvoiceData['total_discount'];
		//~ }
		
		if(!$posInvoiceItem->save()){
			return false;
		}else{
			return true;
		}
	}
	
	public function calculateCouponCode($input, $accont_prefrences){
		$account_id = $input['account']['id'];
		$account = $input['account'];

		$response = [];
		if(empty($input['coupon_code'])){
			 $response['status'] = 400;
			 $response['msg'] = 'coupon_required';
			 return $response;
		}
		
		try {
			$coupon = DiscountCoupon::where('coupon_code', $input['coupon_code'])->where('is_deleted',0)->where('is_expired',0)->first();
			if(!$coupon){
				 $response['status'] = 400;
				 $response['msg'] = 'coupon_not_found';
				 return $response;
			}
			$mothly_membership_fees = $accont_prefrences->mothly_membership_fees;
			$one_time_setup_fees = $accont_prefrences->one_time_membership_setup;

			if(isset($input['membership_type']) && $input['membership_type'] =='yearly'){	
				$mothly_membership_fees	=   env('ONE_YEAR_FEE');
			}

			if($coupon->discount_type == 'percentage'){
				 $discount_percent = $coupon->discount_value;
				 $total_discount   = ( $mothly_membership_fees * $discount_percent ) / 100;
				 $final_amount     	=  ( $mothly_membership_fees ) - $total_discount;
				 $final_amount		= $one_time_setup_fees + $final_amount;
					
			}elseif($coupon->discount_type == 'dollar') {
				 $total_discount = $coupon->discount_value;
				 if( $total_discount >= $mothly_membership_fees ){
					$total_discount = $mothly_membership_fees; /*if total_discount is greater than mothly_membership_fees then giving the full discount = mothly_membership_fees */ 
				 }
				 $final_amount   	= ( $mothly_membership_fees ) - $total_discount;
				 $final_amount		= $one_time_setup_fees + $final_amount;
			 
			}else {
			  /*this section run when user entered trial coupon*/
				 $response['status'] = 400;
				 $response['msg'] = 'invalid_coupon';
				 return $response;	
			}
			$final_amount   = number_format($final_amount, 2);
			$total_discount   = number_format($total_discount, 2);
			$data 		= array('total_discount'=>$total_discount, 'final_amount'=>$final_amount,'discount_duration' =>$coupon->discount_duration, 'limited_duration' =>$coupon->limited_duration);
			$response['status'] = 200;
			$response['msg'] = 'coupon_is_valid';
			$response['data'] = $data;
			return $response;			
		} catch (\Exception $e) {
			 $response['status'] = 500;
			 $response['msg'] = "something_went_wrong";
			 return $response;			 
		}
	}
	
	public function getMultierData(Request $request, $id)
	{
		$subDomain		= $this->getSubdomain();	
		$account_id = $this->getAccountID($subDomain);
		$accont_prefrences = $this->getAccountPreferences($account_id);
		
		$account = Account::with('user')->find($account_id);
		$is_pos_enabled = $this->isPosEnabled($account_id);
		$currency_code = $account->stripe_currency;
		$country = DB::table('stripe_countries')->where('currency_code', $account->stripe_currency)->first();
		$currency_symbol = $country->currency_symbol;
		$database_name = $this->getDatabase($subDomain);
		$this->switchDatabase($database_name);
		$membership_tiers = MembershipTier::where('id',$id)->where('status',0)->where('active',0)->with(['multiTierproducts'=>function($q){
			$q->where('status',0)->with('product');
		}])->first();
		//echo "<pre>"; print_r($membership_tiers); die;
		
		$agreement_id = $membership_tiers->membership_agreement_id;
		$membsership_agreement = MembershipAgreement::where('id',$agreement_id)->where('status',0)->first();
		
		$response['status'] 	= 0;
		$response['data'] 		= null;
		$monthYearFee = 0;
		if($membership_tiers){
			$membership_options = $membership_tiers->membership_payment_options;
			if($membership_options == 'monthly'){
				$monthYearFee = $membership_tiers->price_per_month;
			}elseif($membership_options == 'yearly'){
				$monthYearFee = $membership_tiers->price_per_year;
			}
			$total = $monthYearFee + $membership_tiers->one_time_setup_fee;
			$response['status'] 				= 1;
			$response['data'] 					= $membership_tiers;
			$response['data']['total_amount'] 	= number_format($total,2);
			$response['data']['stripe_currency']= strtoupper($accont_prefrences['account']->stripe_currency);
			$response['data']['currency_symbol'] = $currency_symbol;
			$response['data']['agreement_text'] = @$membsership_agreement->agreement_text;
			$response['data']['agreement_id'] = @$membsership_agreement->id;
			return $response;
		}
		return $response;
	}
	
	public function getSubdomain()
	{
		$httpHost		= $_SERVER['HTTP_HOST'];
		$subDomainArr	= explode('.', $httpHost);
		$subDomain		= $subDomainArr[0];
		return $subDomain;
	}


    public function getAccountClearenteConfig($account_id, $account, $clinicID)
    {
        $this->switchDatabase(config('database.default_database_name'));
        if (count((array)$account) > 0) {
            $stripeConnectionType = $account->stripe_connection;

            if ($stripeConnectionType == 'global') {
                $clinicID = 0;
            } else {
                $clinicID = $clinicID;
            }

            $accountClearentConfig = AccountClearentConfig::with(['clearent_setup' => function ($q) {
                $q->where('status', 'completed');
            }])->where('account_id', $account->id)->where('clinic_id', $clinicID)->first();
        }
        return $accountClearentConfig;
    }

    private function createClearentCharge($input, $total_amount, $defaultAccountCurrency, $stripe_config, $account, $patientID, $ip)
    {
        //$statement_descriptor	= config('stripe.descriptor');
        $accountName = isset($account['name']) && !empty($account['name']) ? $account['name'] : config('stripe.descriptor');
        $accountName = substr(strtoupper(str_replace(' ', '', $accountName)), 0, 20);
        $accountName = cleanString($accountName);
        $accountName = preg_replace('/[^A-Za-z0-9\-]/', '', $accountName);
        $statement_descriptor = (strlen($accountName) > 20) ? substr($accountName, 0, 20) : $accountName;

        $currency_code = strtoupper($defaultAccountCurrency);
        $patientEmail = $input['email'];
        $stripeUserID = $stripe_config->merchant_id;
        $stripe_platform_fee = $stripe_config->platform_fee;

        try {
            if (strlen($stripeUserID) > 0) {
                if (!empty($input['clearentToken'])) {
                    $result_set = $this->createClearentToken($input, $stripe_config);
                    if (count((array)$result_set)) {
                        if (!empty($result_set["status"]) && $result_set["status"] == 200) {
                            $clearent_array = $result_set["data"];
                            //~ $customerTokenID = $clearent_array["payload"]["tokenResponse"]["token-id"];
                            //~ $cardExpiryDate  = $clearent_array["payload"]["tokenResponse"]["exp-date"];
                            $input['clearent_email_id'] = $input["email"];
                            $headers = [
                                'content-type: application/json',
                                'accept: application/json',
                                'api-key: ' . $stripe_config['apikey'],
                                'mobilejwt: ' . $input['clearentToken']
                            ];
                            $endPoint = rtrim(config('clearent.payment_url'), '/') . '/mobile/transactions/sale';
                            $invoice_number = 'AR00' . $account['id'] . '0' . $patientID . '0' . time();
                            $postData = array(
                                "type" => 'SALE',
                                //~ "exp-date" => $cardExpiryDate,
                                "amount" => number_format((float)$total_amount, 2, '.', ''),
                                //~ "card" => $customerTokenID,
                                "description" => strtoupper($statement_descriptor),
                                "order-id" => 0,
                                "invoice" => $invoice_number ?? '',
                                "email-address" => $input['clearent_email_id'] ?? $patientEmail,
                                "customer-id" => $this->getClearentCustomerData($patientID) ?? '',
                                'software-type' => config('clearent.software.type'),
                                'software-type-version' => config('clearent.software.version'),
                                "client-ip" => isset($ip) ?? null,
                                "billing" => ["zip" => $input['pincode'] ?? ''],
                                "create-token" => true
                            );
                            $response_data = Clearent::curlRequest($endPoint, $headers, $postData, 'POST');
                            $clearent_array = json_decode(json_encode($response_data["result"]), true);
                            if ($clearent_array['status'] == 'fail') {
                                $response['status'] = 0;
                                $response['msg'] = "An error occured - " . $clearent_array['payload']['error']['error-message'];
                                (new BookController)->clearentFailedTransactions(0, $response_data);
                            } else {
                                $customerTokenID = $this->getClearentLinksData($clearent_array['links']);
                                $response['status'] = 1;
                                $response['account_code'] = $customerTokenID;
                                $response['subscription_uuid'] = $customerTokenID;
                                $response['data'] = $clearent_array;
                                if (isset($invoice_number)) {
                                    $response["data"]['platformFee'] = $stripe_platform_fee;
                                    $response["data"]['invoice_number'] = $invoice_number;
                                }
                            }
                        } else {
                            $response['status'] = 0;
                            $response['msg'] = "An error occured - " . $result_set["message"];
                        }
                    } else {
                        $response['status'] = 0;
                        $response['msg'] = 'We are unable to authorize your card, please try again.';
                    }
                } else {
                    $response['status'] = 0;
                    $response['msg'] = 'invaild card details.';
                }
            } else {
                $response['status'] = 0;
                $response['msg'] = 'clearent connection not found.';
            }
        } catch (\Exception $e) {
            $response['status'] = 0;
            //~ $response['msg'] = 'Something went wrong,clearent error.';
            $response['msg'] = $e->getLine() . $e->getMessage();
        }
        return $response;
    }

    public function getClearentCustomerData($id, $type = null)
    {
        $patients = Patient::where('id', $id)->first();
        if ($type == 'email') {
            return $patients->email ?? '';
        } else {
            $name_data = [];
            if (!empty($patients->id)) {
                $name_data[] = $patients->id;
            }
            if (!empty($patients->firstname)) {
                $name_data[] = $patients->firstname;
            }
            if (!empty($patients->lastname)) {
                $name_data[] = $patients->lastname;
            }
            $name = implode(' ', $name_data);
            return $name;
        }
    }

    public function createClearentToken($input, $stripe_config)
    {
        $result_set = [];
        $result_set = Clearent::createToken($input['clearentToken'], $stripe_config['apikey']);
        return $result_set;
    }

    public function getClearentLinksData($links)
    {
        if (isset($links) && !empty($links)) {
            foreach ($links as $k => $v) {
                if (!empty($v['rel']) && $v['rel'] == "token") {
                    $id = $v['id'];
                    break;
                }
            }
        }
        return $id ?? '';
    }
	
	private function validateStripeToken($stripeToken)
	{
		$apiKey = config('stripe.secret_key');
		$event 		= 'tokens';
		$url 		= 'https://api.stripe.com/v1/'.$event.'/'.$stripeToken;

		$ch 		= curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');

		curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ':' . '');

		$response	= json_decode(curl_exec($ch));
		if (curl_errno($ch)) {
			echo 'Error:' . curl_error($ch);
		}
		curl_close($ch);
		if( isset($response->id) )
		{
			return $response;
		}
		else
		{
			if ( isset($response->error) ) {
				$error = $response->error->message;
			} else {
				$error = 'Unable to contact payment gateway right now';
			}
			return $error;
		}
	}

}
