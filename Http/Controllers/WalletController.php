<?php

namespace App\Http\Controllers;

use App\Services\PatientService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Session;
use App\User;
use App\UserLog;
use App\Patient;
use App\PatientWallet;
use App\EgiftCardRedemption;
use App\PatientPackage;
use App\PatientPackageRedemption;
use App\PatientWalletRemoval;
use App\PosInvoiceItem;
use App\DiscountPackage;
use App\DiscountPackageProduct;



class WalletController extends Controller
{
	
	public function clientWallet(Request $request) {
		
		$database_name	= $request->session()->get('database');
		$patient_id 	= $request->session()->get('patient_id');
		$session = Session::all();
		$account_details = $session['account_detail'];
		$currency_code = $account_details->stripe_currency;
		$currency_symbol = $this->getCurrencySymbol($currency_code);
		
		config(['database.connections.juvly_practice.database'=> $database_name]);

        $cardVouchers = PatientService::getCardVouchers($patient_id);

        $egift_card_voucher_ids = [];
		foreach($cardVouchers as $key=>$redemptions){
			$egift_card_voucher_ids[] = $redemptions['egift_card_voucher_id'];
		}
		$patientData 	= Patient::with(['patientMembershipSubscription','patientsCardsOnFile','clientWallet.patientWalletCredit','monthlyMembershipInvoice'=>function($monthly_member_invoice){
			$monthly_member_invoice->where('payment_status','<>','pending')->orderBy('id','DESC');
		}])->find($patient_id);
	
		$package_type = array('package', 'bogo', 'percentage');
		$discount_type = array('package', 'bogo');
		
		$patientPackages = PatientPackage::where('patient_id',$patient_id)->whereIn('type',$package_type)->whereHas('discountPackage',function($discount_pack) use($discount_type){
			$discount_pack->whereIn('type',$discount_type);
		})->with(['patientPackageProduct'=>function($patient_pack_product){
			$patient_pack_product->with(['product','patientPackageRedemption.posInvoice']);
		},'discountPackage'])->get();
		
	
		$walletRemovals 	= PatientWalletRemoval::where('patient_id',$patient_id)->get();

		$data					= array();
		$logData				= array();
		
		$egiftcard_credit = PosInvoiceItem::where('product_type','egiftcard')
		->whereHas('posInvoice',function($pos_invoice_where) use($patient_id){
			$pos_invoice_where->where('patient_id',$patient_id);
		})
		->with(['posInvoice'=>function($pos_invoice) use($patient_id){
				$pos_invoice->where('patient_id',$patient_id)
				->with(['egiftCardsPurchase'=>function($eGiftCardPurchase){
					$eGiftCardPurchase->with(['egiftCardVoucher'=>function($e_gift_voucher){
						$e_gift_voucher->where('is_expired','<>',1)->where('balance','<>',0);
					}]);
				}]);
		}])
		->get();
		  
		$egiftcard_details = array();
		foreach($egiftcard_credit as $key=>$posInvoice){
			foreach($posInvoice['posInvoice']['egiftCardsPurchase'] as $key2=>$egiftCardVoucher){
				if(!in_array($egiftCardVoucher['egift_card_voucher_id'], $egift_card_voucher_ids) && $egiftCardVoucher['egiftCardVoucher']['balance'] > 0){
				$egiftcard_details[$egiftCardVoucher['egift_card_voucher_id']]['amount'] = $egiftCardVoucher['egiftCardVoucher']['amount'];
				$egiftcard_details[$egiftCardVoucher['egift_card_voucher_id']]['balance'] = $egiftCardVoucher['egiftCardVoucher']['balance'];
				
				$egiftcard_details[$egiftCardVoucher['egift_card_voucher_id']]['redemption_code'] = $egiftCardVoucher['egiftCardVoucher']['redemption_code'];
				}
			}
		}
		
		if ( !empty($patientPackages) ) {
			foreach ( $patientPackages as $eachPackage ) {
				$balance_doller_value	= 0;
				if ( !empty($eachPackage['patientPackageProduct'] ) ) {						
					foreach ( $eachPackage['patientPackageProduct'] as $eachProduct ) {
						
						$tempArray	= array();
						$balance_doller_value		= 0;
						$total_doller_value			= $eachProduct['dollar_value'];
						$total_units				= $eachProduct['total_units'];
						$balance_dollar				= 0;
						if ( $total_units > 0 ) {
							$dollar_value_per_unit	= $total_doller_value / $total_units;
							$balance_units			= $eachProduct['balance_units'];
							$balance_dollar			= $balance_units * $dollar_value_per_unit;
						}
						$balance_doller_value		= $balance_doller_value + $balance_dollar;
						//$balance_doller_value		= number_format($balance_doller_value, 2);
						if ( $balance_doller_value > 0 ) {
							$balance_doller_value	= $balance_doller_value;
						} else {
							$balance_doller_value	= 'Free';
						}
						$tempArray					= array('row_type' => 'product', 'product_name' => @$eachProduct['product']['product_name'], 'total_units' => $eachProduct['total_units'], 'balance_units' => $eachProduct['balance_units'], 'discount_package_name' => $eachPackage['discountPackage']['name'], 'discount_package_type' => $eachPackage['discountPackage']['type'], 'balance' => $balance_doller_value, 'date' => $eachPackage['date_purchased'], 'patient_package_id' => $eachPackage['id'], 'product_id' => @$eachProduct['product']['id'], 'credit_type' => '' );
						
						if ( $eachProduct['balance_units'] > 0 ) {
							if (array_key_exists($eachProduct['product']['id'], $data)) {
								$data[$eachProduct['product']['id']]['balance_units']	+= $eachProduct['balance_units'];
								if($balance_doller_value !== 'Free'){
									$balance_doller_value = (float) $balance_doller_value;
									if(is_numeric($balance_doller_value)){
										@$data[$eachProduct['product']['id']]['balance']			+= $balance_doller_value;
									}
								}
							} else {
								$data[$eachProduct['product']['id']]					= $tempArray;
							}
						}
						
						if ( !empty($eachProduct['patientPackageRedemption'])) {
							foreach ( $eachProduct['patientPackageRedemption'] as $eachRedemption ) {
								$description 	= 'Wallet Debits - ' . @$eachProduct['product']['product_name'];
								
								$pos_invoice_item  = $this->findByInvoiceIdAndProductId($eachRedemption['invoice_id'], $eachProduct['product']['id']);
								if($pos_invoice_item){
									$employee		= $this->getAddedBy($pos_invoice_item['user_id']);
									
									$logTempArray 	= array('log_date' => $eachRedemption['redemption_date'], 'employee' => $employee, 'description' => $description, 'amount' => $eachRedemption['amount'],  'date' => $eachRedemption['redemption_date'], 'type'	=> $eachRedemption['redemption_type'], 'units'	=> $eachRedemption['units']);
									array_push($logData, $logTempArray);
								}
							}
						}
					}
				}
			}
		}
		
		if ($patientData) {
			if ( isset($patientData['clientWallet']) ) {
				$creditbalance		= 0;

				$creditbalance 		= $patientData['clientWallet']['dollar_credit'];
				$bdbalance 			= $patientData['clientWallet']['bd_credit'];
				$aspirebalance		= $patientData['clientWallet']['aspire_credit'];
				$creditdate			= date('Y-m-d H:i:s', strtotime("+9 days"));
				$bdcreditdate		= date('Y-m-d H:i:s', strtotime("+7 days"));
				$aspirecreditdate	= date('Y-m-d H:i:s', strtotime("+5 days"));
				$egiftcreditdate	= date('Y-m-d H:i:s', strtotime("+3 days"));
				
				
				$tempArray			= array('row_type' => 'credit', 'product_name' => 'Dollar Credit', 'total_units' => '', 'balance_units' => '', 'discount_package_name' => '', 'discount_package_type' => '', 'balance' => $creditbalance, 'date' => $creditdate, 'credit_type' => 'dollar' );
				$data['credit']	= $tempArray;
				
				$tempArray			= array('row_type' => 'credit', 'product_name' => 'BD Credit', 'total_units' => '', 'balance_units' => '', 'discount_package_name' => '', 'discount_package_type' => '', 'balance' => $bdbalance, 'date' => $bdcreditdate, 'credit_type' => 'bd');
				$data['bd_credit']	= $tempArray;
				
				$tempArray			= array('row_type' => 'credit', 'product_name' => 'Aspire Credit', 'total_units' => '', 'balance_units' => '', 'discount_package_name' => '', 'discount_package_type' => '', 'balance' => $aspirebalance, 'date' => $aspirecreditdate, 'credit_type' => 'aspire');
				$data['aspire_credit'] = $tempArray;
				if(!empty($egiftcard_details)){ 
					foreach($egiftcard_details as $key=>$vouchers){
						
						$code = (string) $vouchers['redemption_code'];
						$redemption_code = implode("-", str_split($code, "4"));
						$tempArray			= array('row_type' => 'credit', 'product_name' => 'eGiftcard Credit worth '.getFormattedCurrency($vouchers['amount']).' ('.$redemption_code.')', 'total_units' => '', 'balance_units' => '', 'egiftcard_name' => '', 'egiftcard_name_type' => '', 'balance' => $vouchers['balance'], 'date' =>$egiftcreditdate, 'credit_type' => 'egiftcard');
						$data['egiftcard_credit'.$key] = $tempArray;
					}
				}
				
			}
		}
		
		if(!empty($cardVouchers)){ 
			foreach($cardVouchers  as $voucher){
				$voucherDate	= date('Y-m-d H:i:s', strtotime("+4 days"));
				$code 	= chunk_split($voucher['eGiftCardVoucher']['redemption_code'], 4, ' ');
				$tempArray			= array('row_type' => 'voucher', 'product_name' => 'eGiftcard with redemption code  '.$code, 'total_units' => '', 'balance_units' => '', 'discount_package_name' => '', 'discount_package_type' => '', 'balance' => $voucher['eGiftCardVoucher']['balance'], 'date' => $voucherDate, 'credit_type' => '');
				$data[] = $tempArray;
			}
		}
		

//~ 
		//~ if ( !empty($patientPackages) ) {
			//~ foreach ( $patientPackages as $eachPackage ) {
				//~ $employee 		= 'NA';
				//~ 
				//~ $employee		= $this->getAddedBy($eachPackage['purchased_by']);
				//~ 
				//~ if ( $eachPackage['purchased_from'] == 'treatment_plan' ) {
					//~ $packageDescription = "Added From Treatment Plan - " . $eachPackage['discountPackage']['name'];
				//~ } else {
					//~ $packageDescription = "Added Discount Package - " . $eachPackage['discountPackage']['name'];
				//~ }
				//~ 
				//~ $logTempArray 	= array('log_date' => $eachPackage['date_purchased'], 'employee' => $employee, 'description' => $packageDescription, 'amount' => $eachPackage['package_amount'],  'date' => $eachPackage['date_purchased']);
				//~ array_push($logData, $logTempArray);
			//~ }
		//~ }
		//~
		//~ if ( isset($patientData['clientWallet']) && $patientData['clientWallet'] ) {
			//~ if ( !empty($patientData['clientWallet']['patientWalletCredit']) ) {
				//~ foreach ( $patientData['clientWallet']['patientWalletCredit'] as $eachcredits ) {
					//~ if ( $eachcredits['credit_type'] == 'bd' ) {
						//~ $type	= "BD";
					//~ } else {
						//~ $type	= ucfirst($eachcredits['credit_type']);
					//~ }
					//~ if ( $eachcredits['type'] == 'credit' ) {
						//~ $description = $type.' credit added ( '.$eachcredits['reason'].' )';
					//~ } else if ( $eachcredits['type'] == 'debit' ) {
						//~ $description = 'Dollar credit used ';
					//~ } else {
						//~ $description = $type.' amount removed ( '.$eachcredits['reason'].' )';
					//~ }
					//~
					//~ $employee		= $this->getAddedBy($eachcredits['added_by']);
					//~ $logTempArray 	= array('log_date' => $eachcredits['added_on'], 'employee' => $employee, 'description' => $description, 'amount' => $eachcredits['balance'],  'date' => $eachcredits['added_on']);
					//~ array_push($logData, $logTempArray);
				//~ }
					//~ 
			//~ }
		//~ }
		//~ 
		//~ if ( isset($patientData['patientMembershipSubscription']) && $patientData['patientMembershipSubscription']['id'] > 0 && $patientData['patientMembershipSubscription']['start_type'] != 'future') {
			//~ 
			//~ $membership_start_date 	= $patientData['patientMembershipSubscription']['subscription_started_at'];
			//~ $date_format 			= config("constants.default.date_format");
			//~ if($date_format == 'd/m/Y' || $date_format == 'dd/mm/yyy') {
				//~ $membership_start_date = str_replace('/', '-', $membership_start_date);
			//~ }
			//~ $current_date = date('Y-m-d');
			//~ if($patientData['patientMembershipSubscription']['subscription_status'] == 1 || strtotime($membership_start_date) <= strtotime($current_date)) {
			//~ 
				//~ $description	= 'Monthly membership added';
				//~ $employee		= $this->getAddedBy($patientData['patientMembershipSubscription']['added_by']);
				//~ $logTempArray 	= array('log_date' => $patientData['patientMembershipSubscription']['subscription_started_at'], 'employee' => $employee, 'description' => $description, 'amount' => $patientData['clientWallet']['membership_fee'],  'date' => $patientData['patientMembershipSubscription']['subscription_started_at']);
				//~ array_push($logData, $logTempArray);
			//~ }
		//~ }
		//~ 
		//~ if ( !empty($walletRemovals) ) {
			//~ foreach ( $walletRemovals as $eachRemoval ) {
				//~ //$productDetails = $this->Product->find('first', array('conditions' => array('Product.id' => $eachRemoval['product_id'])));
				//~ 
				//~ $productDetails = Product::find($eachRemoval['product_id']);
				//~ 
				//~ if ($productDetails) {
					//~ $description	= 'Removed Product - ' . $productDetails['product_name'];
				//~ } else {
					//~ $description	= 'Removed Product';
				//~ }
//~ 
				//~ $employee		= $this->getAddedBy($eachRemoval['removed_by']);
				//~ $logTempArray 	= array('log_date' => $eachRemoval['date_removed'], 'employee' => $employee, 'description' => $description, 'amount' => $eachRemoval['amount'],  'date' => $eachRemoval['date_removed']);
				//~ array_push($logData, $logTempArray);
			//~ }
		//~ }
		//~ 
		//~ if ( !empty($logData)) {
			//~ usort($logData, array($this, "date_compare"));
		//~ }
		
		if ( !empty($data) ) {
			usort($data, array($this, "date_compare"));
		}


		//~ $allPackages 	= DiscountPackage::where(['status' => 0, 'type' =>'package', 'is_virtual_package' => 1, 'package_buy_type'	=> 'product'])
		//~ ->where('active_from', '<=', date('Y-m-d'))
		//~ ->where('active_untill', '>=', date('Y-m-d'))
		//~ ->with('discountPackageProduct')
		//~ ->orderBy('name')->get();
		
		//~ $allBogos 		= DiscountPackage::where(['status' => 0, 'type' =>'bogo']) 
		//~ ->where('bogo_buy_type', '!=', 'group')
		//~ ->where('active_from', '<=', date('Y-m-d'))
		//~ ->where('active_untill','>=', date('Y-m-d'))
		//~ ->with('discountPackageProduct')
		//~ ->orderBy('name')->get();
		
		//$allClinics		= Clinic::where('status',0)->orderBy('clinic_name')->select('id','clinic_name')->get();
		$session = Session::all();
		$account = $session['account_detail'];	
		
		$account_id = $account->id;
		
		$final_data = [];
		$final_data['patient_data'] = $patientData;
		$final_data['data'] = $data;
		$final_data['log_data'] = $logData;
		$final_data['patient_id'] = $patient_id;
		//$final_data['all_packages'] = $allPackages;
		//$final_data['all_bogos'] = $allBogos;
		
		//$final_data['countries'] = $countries;
		$final_data['account_id'] = $account_id;
		//$final_data['allClinics'] = $allClinics;
		//echo "<pre>"; print_r($final_data); die;
		
		return view('app.membership_details.client_wallet', compact('final_data','currency_symbol'))->render();
	
	}
	
	private function findByInvoiceIdAndProductId($invoice_id, $product_id){
		$pos_invoice_item  = PosInvoiceItem::where('invoice_id',$invoice_id)->where('invoice_id',$invoice_id)->first();
		return $pos_invoice_item;
	}
	
	private function getAddedBy($user_id){
		$employee 		= 'NA';
		if ( $user_id > 0 ) {
			$user = User::where('id',$user_id)->select('id','firstname','lastname')->first();
			
			if($user){
				$employee = ucwords(@$user['firstname'] . ' ' . @$user['lastname']);
			}
		}
		return $employee;
	}
	
	private function date_compare($a, $b){
		$session = Session::all();
		if(isset($session['account_detail'])){
			$account = $session['account_detail'];	
		}else if(isset($session['account'])){
			$account = $session['account'];	
		}
		$account_prefrences = DB::table('account_prefrences')->where('account_id',$account->id)->first();
		$date_format = trim($account_prefrences->date_format);
		
		if($date_format == 'd/m/Y' || $date_format == 'dd/mm/yyyy') {
		   $a = $this->refineDMYDateFormat($date_format, $a);
		   $b = $this->refineDMYDateFormat($date_format, $b);
		}
		
		$t1 = strtotime($a['date']);
		$t2 = strtotime($b['date']);

		return $t2 - $t1; // For descending (reverse for ascending)
	}
	
	protected function refineDMYDateFormat($date_format, $date){
		if($date_format == 'd/m/Y' || $date_format == 'dd/mm/yyyy') {
			$date = str_replace('/', '-', $date);
		}
		return $date;
	}
}
