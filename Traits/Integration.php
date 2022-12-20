<?php

namespace App\Traits;
use App\Traits\Mailchimp;
use App\Traits\Intercom;
use App\Traits\Hubspot;
use App\Model\AccountZoho;
use App\Model\AccountKeap;
use App\Model\AccountHubspot;
use App\Model\AccountIntercom;
use App\Model\AccountMailchimp;
use App\Model\Patient;

trait Integration {
	
	protected $platFormKey;
	protected $is_port_required;
	protected $oauth_url;
	protected $api_url;
	protected $oauthdata;
	protected $application_id;
	protected $accessToken;
	protected $endpoint;

	public function __construct()
	{	
		
	}
		
	public static function createZohoAccessToken($code)
	{
		
		$zoho_client_id = env('INTEGRATION_ZOHO_CLIENT_ID');
		$zoho_client_secret = env('INTEGRATION_ZOHO_CLIENT_SECRET'); 
		$url = env('INTEGRATION_ZOHO_REDIRECT_URI'); 
		
		//~ $ch = curl_init();

		//~ curl_setopt($ch, CURLOPT_URL, 'https://accounts.zoho.in/oauth/v2/token');
		//~ curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		//~ curl_setopt($ch, CURLOPT_POST, 1);
		
		
		$data_array = array(
			'grant_type'=>'authorization_code',
			'client_id'=> $zoho_client_id,
			'client_secret'=> $zoho_client_secret,
			'redirect_uri'=> $url,
			'code'=> $code
		);
		
		
		//~ curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data_array));
		//~ curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));	
		
		//~ $result = curl_exec($ch);
		//~ print_r($result);	
		//~ $info = curl_getinfo($ch);
		//~ curl_close($ch);

		
		//~ die('aa');

		//~ return $result;

	
		$ch 				= curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://accounts.zoho.com/oauth/v2/token');
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS,http_build_query($data_array));	
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);

		$response			= json_decode(curl_exec($ch));
		$info				= curl_getinfo($ch);

		curl_close ($ch);
		
		return $response;
				  
		  
	}
	
	public static function createZohoAccessTokenFromRefreshToken($refresh_token)
	{
		
		$zoho_client_id = env('INTEGRATION_ZOHO_CLIENT_ID');
		$zoho_client_secret = env('INTEGRATION_ZOHO_CLIENT_SECRET'); 
		$url = env('INTEGRATION_ZOHO_REDIRECT_URI'); 
		
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, 'https://accounts.zoho.com/oauth/v2/token');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		$post = "grant_type=refresh_token&client_id=".$zoho_client_id."&client_secret=".$zoho_client_secret."&redirect_uri=".$url."&refresh_token=".$refresh_token;
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

		$result = json_decode(curl_exec($ch));
		if (isset($result->access_token)) {
			return $result->access_token;
		} else {
			return false;
		}
		curl_close($ch);			  
		  
	}
	
	public static function revokeRefreshToken($refresh_token)
	{
		
		$zoho_client_id = env('INTEGRATION_ZOHO_CLIENT_ID');
		$zoho_client_secret = env('INTEGRATION_ZOHO_CLIENT_SECRET'); 
		$url = env('INTEGRATION_ZOHO_REDIRECT_URI'); 
		
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, 'https://accounts.zoho.com/oauth/v2/token/revoke');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
			$data_array = array(
				'token'=> $refresh_token
			);

		
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data_array));

		$result = curl_exec($ch);
		//print_r($result);die('aaa');
		if (curl_errno($ch)) {
			return '';
		} else {
			return $result;
		}
		curl_close($ch);			  
		  
	}
	
	public static function createPatientOnZoho($data,$access_token)
	{
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, 'https://www.zohoapis.com/crm/v2/Contacts');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		$post = '{
					"data": '.$data.',
					"trigger": [
						"approval",
						"workflow",
						"blueprint"
					]
				}';
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

		$headers = array();
		$headers[] = 'Authorization: Zoho-oauthtoken '.$access_token;
		//$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = curl_exec($ch);
		return $result;
		curl_close($ch);			  
			  
		  
		  
	}
	
	public static function createPatientOnHubspot($data,$access_token)
	{

		$arr['properties'] = array();
		if(isset($data[0]['Email']) && !empty($data[0]['Email'])){
			$email_data        =    array(
			'property' => 'email',
			'value' => $data[0]['Email']
			);
			array_push($arr['properties'], $email_data);
		}

		if(isset($data[0]['First_Name']) && !empty($data[0]['First_Name'])){
			$f_name_data    =    array(
			'property' => 'firstname',
			'value' => $data[0]['First_Name']
			);
			array_push($arr['properties'], $f_name_data);
		}
		if(isset($data[0]['Last_Name']) && !empty($data[0]['Last_Name'])){
			$l_name_data    =    array(
			'property' => 'lastname',
			'value' => $data[0]['Last_Name']
			);
			array_push($arr['properties'], $l_name_data);
		}

		$post_json     = json_encode($arr);

		$endpoint = 'https://api.hubapi.com/contacts/v1/contact';
		$ch = @curl_init();
		@curl_setopt($ch, CURLOPT_POST, true);
		@curl_setopt($ch, CURLOPT_POSTFIELDS, $post_json);
		@curl_setopt($ch, CURLOPT_URL, $endpoint);
		$headers = array();
		$headers[] = 'Content-Type: application/json';
		$headers[] = 'Authorization:Bearer ' . $access_token;
		@curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		@curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = json_decode(curl_exec($ch));
		$status_code = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curl_errors = curl_error($ch);
		@curl_close($ch);



	}	
	public static function createHubspotRefreshToken($code) {
		
        $ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, 'https://api.hubapi.com/oauth/v1/token');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=authorization_code&client_id=".env('INTEGRATION_HUBSPOT_CLIENT_ID')."&client_secret=".env('INTEGRATION_HUBSPOT_CLIENT_SECRET')."&redirect_uri=".urlencode(env('INTEGRATION_HUBSPOT_REDIRECT_URI'))."&code=".$code);
		curl_setopt($ch, CURLOPT_POST, 1);

		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded;charset=utf-8';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = json_decode(curl_exec($ch));

		curl_close($ch);
		
		if(isset($result->access_token)) {
			return $result; 
		} else {
			return ''; 	
		}
      
	}
	
	public static function createHubspotAccessTokenFromRefreshToken($refresh_token) {
		
        $ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, 'https://api.hubapi.com/oauth/v1/token');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=refresh_token&client_id=".env('INTEGRATION_HUBSPOT_CLIENT_ID')."&client_secret=".env('INTEGRATION_HUBSPOT_CLIENT_SECRET')."&refresh_token=".$refresh_token);
		curl_setopt($ch, CURLOPT_POST, 1);

		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded;charset=utf-8';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = json_decode(curl_exec($ch));
		//print_r($result);die('aaa');
		curl_close($ch);
		
		if(isset($result->access_token)) {
			return $result; 
		} else {
			return ''; 	
		}
      
	}
	
	public static function createPatientHubspot($code) {
		
        $ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, 'https://api.hubapi.com/oauth/v1/token');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=authorization_code&client_id=".env('INTEGRATION_HUBSPOT_CLIENT_ID')."&client_secret=".env('INTEGRATION_HUBSPOT_CLIENT_SECRET')."&redirect_uri=".urlencode(env('HUBSPOT_REDIRECT_URI'))."&code=".$code);
		curl_setopt($ch, CURLOPT_POST, 1);

		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded;charset=utf-8';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = json_decode(curl_exec($ch));
		
		print_r($result);die;
		if (curl_errno($ch)) {
			return curl_error($ch);
		}
		curl_close($ch);
		
		if(isset($result->access_token)) {
			return $result; 
		} else {
			return $result->message; 	
		}
      
	}
	
	public static function intercomAccessToken($code) {
		$arr		= array(
						'code' => $code,
						'client_id' => env('INTEGRATION_INTERCOM_CLIENT_ID'),
						'client_secret' => env('INTEGRATION_INTERCOM_CLIENT_SECRET')
					);
		$postdata 	= json_encode($arr);
		$ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, 'https://api.intercom.io/auth/eagle/token');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
		curl_setopt($ch, CURLOPT_POST, 1);

		$headers = array();
		$headers[] = 'Content-Type: application/json';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = json_decode(curl_exec($ch));
		if (curl_errno($ch)) {
			return curl_error($ch);
		}
		curl_close($ch);
		
		if(isset($result->token)) {
			return $result; 
		} else {
			return $result; 	
		}
				
	}	
	
	public static function intercomCreateContact($patient,$access_token) {
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, 'https://api.intercom.io/contacts');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		//~ $post = '{
				  //~ "phone": "123987456",
				  //~ "email": "winstonsmith@truth.org",
				  //~ "name": "Winston Smith"
				//~ }';
				
		$firstname = '';
		if(isset($patient[0]['First_Name'])){
			$firstname = $patient[0]['First_Name'];
		}
		$lastname = '';
		if(isset($patient[0]['Last_name'])){
			$lastname = $patient[0]['Last_name'];
		}
		$email = '';
		if(isset($patient[0]['Email'])){
			$email = $patient[0]['Email'];
		}				
		$phone = '';
		if(isset($patient[0]['phoneNumber'])){
			$phone = $patient[0]['phoneNumber'];
		}				
				
		$post = '{
				  "phone": "'.$phone.'",
				  "email": "'.$email.'",
				  "name": "'.$firstname.' '.$lastname.'"
				}';				
				
				
				
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

		$headers = array();
		$headers[] = 'Authorization: Bearer '.$access_token;
		$headers[] = 'Accept: application/json';
		$headers[] = 'Content-Type: application/json';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = curl_exec($ch);
		//print_r($result);
		if (curl_errno($ch)) {
			echo 'Error:' . curl_error($ch);
		}
		curl_close($ch);			  

				
	}	
	
	public static function mailchimpAuthorizeWithCode($code){
	
		$ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, 'https://login.mailchimp.com/oauth2/token');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=authorization_code&client_id=".env('INTEGRATION_MAIlCHIMP_CLIENT_ID')."&client_secret=".env('INTEGRATION_MAIlCHIMP_CLIENT_SECRET')."&redirect_uri=".urlencode(env('INTEGRATION_MAIlCHIMP_REDIRECT_URI'))."&code=$code");
		curl_setopt($ch, CURLOPT_POST, 1);

		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = json_decode(curl_exec($ch));
		//print_r($result);die;
		if (curl_errno($ch)) {
			return curl_error($ch);
		}
		curl_close($ch);
		
		return $result; 
	
	
	}
	
	public static function createMailChimpContact($data,$access_token) {
		
        $endpoint 	= 'https://'.$location.'.api.mailchimp.com/3.0/lists/'.$list_id.'/members/';
		$postdata 	= json_encode($arr);
		
        $ch = @curl_init();
        @curl_setopt($ch, CURLOPT_POST, true);
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        @curl_setopt($ch, CURLOPT_URL, $endpoint);
		$headers = array();
		$headers[] = 'Content-Type: application/json';
		@curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_USERPWD, 'shinewebservices' . ':' . env('INTEGRATION_MAIlCHIMP_API_KEY'));
        $response 		= json_decode(curl_exec($ch));
        $status_code 	= @curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errors 	= curl_error($ch);
        @curl_close($ch);
		
        if(isset($response->id) && !empty($response->id)) {
			return $response;	
		} else {
			return $response->detail;	
		}
        
    }
    
	public static function createKeapAccessToken($code) {
		
        $ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, 'https://api.infusionsoft.com/token');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=authorization_code&client_id=".env('INTEGRATION_KEAP_CLIENT_ID')."&client_secret=".env('INTEGRATION_KEAP_CLIENT_SECRET')."&redirect_uri=".urlencode(env('KEAP_REDIRECT_URI'))."&code=".$code);
		curl_setopt($ch, CURLOPT_POST, 1);

		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded;charset=utf-8';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = json_decode(curl_exec($ch));

		curl_close($ch);
		//print_r($result);die('aaaa');
		if(isset($result->access_token)) {
			return $result; 
		} else {
			return ''; 	
		}
      
	}	
	
	public static function createKeapRefreshToken($refresh_token) {
		
        $ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, 'https://api.infusionsoft.com/token');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, "grant_type=refresh_token&refresh_token=".$refresh_token);
		curl_setopt($ch, CURLOPT_POST, 1);

		$headers = array();
		$headers[] = 'Content-Type: application/x-www-form-urlencoded;charset=utf-8';
		$headers[] = 'Authorization: Basic '.base64_encode(env('INTEGRATION_KEAP_CLIENT_ID') . ':' . env('INTEGRATION_KEAP_CLIENT_SECRET'));
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = json_decode(curl_exec($ch));

		curl_close($ch);
		print_r($result);die('aaaa');
		if(isset($result->access_token)) {
			return $result; 
		} else {
			return ''; 	
		}
      
	}	
	
	public static function createPatientssss($account_id,$patient,$id,$database_name){
		
		$data = array();
		
		$data['id'] = $id;
		$data['First_Name'] = $patient['firstname'];
		$data['Last_Name'] = $patient['lastname'];
		$data['Email'] = $patient['email'];
		if(isset($patient['phoneNumber'])){
			$data['phoneNumber'] = $patient['phoneNumber'];
		}
		if(isset($patient['user_image'])){
			$data['user_image'] = $patient['user_image'];
		}
		if(isset($patient['address_line_1'])){
			$data['address'] = $patient['address_line_1'];
		}
		$temp = array();
		$temp[0] = $data;
		
		// Zoho Create Patient
		$this->switchDatabase($database_name);
		$checkzoho = self::check_patient($id,'zoho',$database_name);
		if($checkzoho>0){
			//
		} else {
			$default_database 	= env('DB_DATABASE');
			$this->switchDatabase($default_database);

			$AccountZoho = AccountZoho::where('account_id',$account_id)->where('sync_new',1)->first();
			$access_token = '';
			
			if(!empty($AccountZoho)){
				$data = array();
				$data = json_encode($temp);
				$access_token = $AccountZoho->access_token;
				$refresh_token = self::createZohoAccessTokenFromRefreshToken($access_token);
				$res = self::createPatientOnZoho($data,$refresh_token);	
				$this->switchDatabase($database_name);
				self::insert_patient_integrations($patient_id,'zoho');
			}
		}
		
		// Hubspot Create Patient
		$this->switchDatabase($database_name);
		$checkzoho = self::check_patient($id,'hubspot',$database_name);
		if($checkzoho>0){
			//
		} else {		
			$AccountHubspot = AccountHubspot::where('account_id',$account_id)->where('sync_new',1)->first();
			$access_token = '';
			
			if(!empty($AccountHubspot)){
				$data = array();
				$data = $temp;
				$access_token = $AccountHubspot->access_token;
				$refreshToken = self::createHubspotAccessTokenFromRefreshToken($access_token);
				$access_token = $refreshToken->access_token;
				self::createPatientOnHubspot($data,$access_token);
				$this->switchDatabase($database_name);
				self::insert_patient_integrations($patient_id,'hubspot');
				
			}
		}
		
		// Intercom Create Patient
		$this->switchDatabase($database_name);
		$checkzoho = self::check_patient($id,'intercom',$database_name);
		if($checkzoho>0){
			//
		} else {		
			$AccountIntercom = AccountIntercom::where('account_id',$account_id)->where('sync_new',1)->first();
			$access_token = '';
			
			if(!empty($AccountIntercom)){
				$data = array();
				$data = $temp;
				$access_token = $AccountIntercom->access_token;
				self::intercomCreateContact($data,$access_token);
				$this->switchDatabase($database_name);
				self::insert_patient_integrations($patient_id,'intercom');
			}
		}
		
		// mailchimp Create Patient
		$this->switchDatabase($database_name);
		$checkzoho = self::check_patient($id,'mailchimp',$database_name);
		if($checkzoho>0){
			//
		} else {			
			$AccountMailchimp = AccountMailchimp::where('account_id',$account_id)->first();
			$access_token = '';
			
			if(!empty($AccountMailchimp)){

				$code = $AccountMailchimp->access_token;
				$result = self::getAccessTokenMailchimp($code);
				if(!empty($result->dc)){
					$location 	= $result->dc;
					$mailchimp_key = $code.'-'.$location;
					self::mailchimpCreateContact($account_id, $mailchimp_key, $location, $temp);
					$this->switchDatabase($database_name);
					self::insert_patient_integrations($patient_id,'mailchimp');
				}
			}
		}
		
		// Keap Create Patient

		//~ $AccountKeap = AccountKeap::where('account_id',$account_id)->where('sync_new',1)->first();
		//~ $access_token = '';
		
		//~ if(!empty($AccountKeap)){
			//~ $data = json_encode($data);
			//~ $access_token = $AccountKeap->access_token;
			//~ $result = self::createKeapRefreshToken($access_token);
			//~ $refresh_token = $result->refresh_token;
			
			//~ $User = AccountKeap::find($AccountKeap->id);
			//~ $User->access_token = $refresh_token;
			//~ $User->save();	
					
			//~ $this->keap_add_contact($data,$refresh_token);	
		//~ }		
		
			
	}
	
	public function keap_add_contact($data,$access_token){
		$postdata = $data;
		$uRL = 'https://api.infusionsoft.com/crm/rest/v1/contacts?access_token='.$access_token;
		$headers = array('Content-Type: application/json');
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $uRL);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $PostData);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		$result = json_decode(curl_exec($ch));
		
		$response = curl_getinfo( $ch );

		print_r($response);die;
		curl_close ( $ch );
	}	
	
    public static function mailchimpCreateContact($account_id, $mailchimp_key, $location, $arr) {
		
		$resultlist = self::mailchimp_get_lists($mailchimp_key,$location);
		if( !empty($resultlist->lists) ) {
			$list_id = $resultlist->lists[0]->id;
		} else {
			$list_id = self::mailchimp_create_list($account_id,$mailchimp_key,$location);
		}
		if(!empty($list_id)){
			
			$contact 	= [
							'email_address' => $arr[0]['Email'],
							'status'    	=> 'subscribed'
						];	
			$endpoint 	= 'https://'.$location.'.api.mailchimp.com/3.0/lists/'.$list_id.'/members/';
			
			$contact = json_encode($contact);
			
			$ch = @curl_init();
			@curl_setopt($ch, CURLOPT_POST, true);
			@curl_setopt($ch, CURLOPT_POSTFIELDS, $contact);
			@curl_setopt($ch, CURLOPT_URL, $endpoint);
			$headers = array();
			$headers[] = 'Content-Type: application/json';
			@curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
			@curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			@curl_setopt($ch, CURLOPT_USERPWD, env('MAILCHIMP_USERNAME') . ':' . $mailchimp_key);
			$response 		= json_decode(curl_exec($ch));
			$status_code 	= @curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$curl_errors 	= curl_error($ch);
			@curl_close($ch);
			
			if(isset($response->id) && !empty($response->id)) {
				return $response;	
			} else {
				return $response->detail;	
			}
		}
        
    }	
    
	public static function getAccessTokenMailchimp($access_token) {
		
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, 'https://login.mailchimp.com/oauth2/metadata');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, false);

		$headers = array();
		$headers[] = 'Content-Type: application/json';
		$headers[] = 'Accept: application/json';
		$headers[] = 'Authorization: OAuth '.$access_token;
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = json_decode(curl_exec($ch));
		
		curl_close($ch);	
		
		return $result; 
		
	}	
	
	public static function mailchimp_create_list($account_id,$mailchimp_key,$location){
			$list_name 	= 'Aesthetic-record-contacts';
			
			$arr_data	= array(
							'name' => $list_name,
							'contact' => (object) array(
											'company' => '',
											'address1' => '',
											'city' => '',
											'state' => '',
											'zip' => '',
											'country' => '',
										),
							'email_type_option' => true,
							'campaign_defaults' => (object) array(
											'from_name' => 'Aesthetic Record',
											'from_email' => 'ar@info.com',
											'subject' => '',
											'language' => 'en',
										),
							'defaults' => (object) array(
											'from_name' => 'Aesthetic Record',
											'from_email' => 'ar@info.com',
											'language' => 'en',
										),
							'permission_reminder' => 'true'	
		);	
			
        $endpoint 	= 'https://'.$location.'.api.mailchimp.com/3.0/lists';
		
		$postdata = json_encode($arr_data);
		
        $ch = @curl_init();
        @curl_setopt($ch, CURLOPT_POST, true);
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        @curl_setopt($ch, CURLOPT_URL, $endpoint);
		$headers = array();
		$headers[] = 'Content-Type: application/json';
		@curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_USERPWD, env('MAILCHIMP_USERNAME') . ':' . $mailchimp_key);
        $response 		= json_decode(curl_exec($ch));
        $status_code 	= @curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errors 	= curl_error($ch);
        @curl_close($ch);
		
        if(isset($response->id) && !empty($response->id)) {
			return $response->id;	
		} else {
			return '';	
		}
	
	}
	
	public static function check_patient_old($patient_id,$type){
		
		$allData = Patient::where('patient_integrations.integration',  $type)
		->leftJoin('patient_integrations', function($join) {
				$join->on('patient_integrations.patient_id', '=', 'patients.id');
		})->select('patients.*','patient_integrations.patient_id')->get();
		
		if($allData){
			$allData	= $allData->toArray();
		}else{
			$allData	= array();
		}		
		
		if(count($allData) > 0){	
			return count($allData);
		} else {
			return 0;
		}
		
	}
	
	public static function insert_patient_integrations_old($patient_id,$type){
		
		$allData = PatientIntegration::where('patient_id',$patient_id)->where('integration',$type)->first();
		
		if(!empty($allData)){
			//
		} else {
			$data = array(
				'integration' => $type,
				'patient_id' => $patient_id
				);
	
			PatientIntegration::insert($data);					
		}
		
	}

	public static function mailchimp_get_lists($mailchimp_key,$location){
			
        $endpoint 	= 'https://'.$location.'.api.mailchimp.com/3.0/lists';
		
        $ch = @curl_init();
        @curl_setopt($ch, CURLOPT_POST, false);
        @curl_setopt($ch, CURLOPT_URL, $endpoint);
		$headers = array();
		$headers[] = 'Content-Type: application/json';
		@curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        @curl_setopt($ch, CURLOPT_USERPWD, env('MAILCHIMP_USERNAME') . ':' . $mailchimp_key);
        $response 		= json_decode(curl_exec($ch));
        $status_code 	= @curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errors 	= curl_error($ch);
        @curl_close($ch);
		
        return $response;	
	
	}
	
	public static function create_active_content_user($url,$key,$data){
		$postdata = array();			
		$postdata['contact'] = array('email'=>$data[0]['Email'],'firstName'=>$data[0]['First_Name'],'lastName'=>$data[0]['Last_Name']);
		$postdata = json_encode($postdata);		
		
		$postUrl = $url."/api/3/contacts";
	
        $ch = @curl_init();
        @curl_setopt($ch, CURLOPT_POST, true);
        @curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
        @curl_setopt($ch, CURLOPT_URL, $postUrl);
		$headers = array();
		$headers[] = 'Content-Type: application/json';
		$headers[] = 'Api-Token: '.$key;
		@curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response 		= json_decode(curl_exec($ch));
        $status_code 	= @curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_errors 	= curl_error($ch);
        @curl_close($ch);
		
	}
	
	public static function create_constant_content_user($access_token,$data){
		
		$ch = curl_init();
		$request_data['list_name'] = 'AR';
		$CONSTANT_CONTACT_CLIENT_ID = env('BI_CONSTANT_CONTACT_CLIENT_ID');
		$list_id 	= self::createConstantContactList($access_token,$request_data,$CONSTANT_CONTACT_CLIENT_ID);
		$CONSTANT_CONTACT_CLIENT_ID = env('BI_CONSTANT_CONTACT_CLIENT_ID');
		$postdata = '{
						"addresses": [
						{
						  "address_type": "BUSINESS",
						  "city": "",
						  "country_code": "",
						  "line1": "",
						  "line2": "",
						  "postal_code": "",
						  "state_code": "ON"
						}
						],
						"lists": [
							{
							"id": "'.$list_id.'"
							}
						],
						  "cell_phone": "",
						  "company_name": "Ar",
						  "confirmed": false,
						  "email_addresses": [
							{
							"email_address": "'.$data[0]['Email'].'"
							}
						],
					  "fax": "",
					  "first_name": "'.$data[0]['First_Name'].'",
					  "home_phone": "",
					  "job_title": "",
					  "last_name": "'.$data[0]['Last_Name'].'",
					  "prefix_name": "",
					  "work_phone": ""
					}';		

		curl_setopt($ch, CURLOPT_URL, 'https://api.constantcontact.com/v2/contacts?action_by=ACTION_BY_OWNER&api_key='.$CONSTANT_CONTACT_CLIENT_ID);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);

		$headers = array();
		$headers[] = 'Accept: application/json';
		$headers[] = 'Authorization: Bearer '.$access_token;
		$headers[] = 'Cache-Control: no-cache';
		$headers[] = 'Content-Type: application/json';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = curl_exec($ch);
		if (curl_errno($ch)) {
			//echo 'Error:' . curl_error($ch);
		}
		curl_close($ch);	
	}
	
	public static function createConstantContactList($access_token,$rquest_data,$CONSTANT_CONTACT_CLIENT_ID){
		
		$list_name = $rquest_data['list_name'];
		
		$ch = curl_init();  
		
		$url = 'https://api.constantcontact.com/v2/lists?api_key='.$CONSTANT_CONTACT_CLIENT_ID;
		curl_setopt($ch,CURLOPT_URL,$url);
		curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
		$headers = array();
		$headers[] = 'Accept: application/json';
		$headers[] = 'Authorization: Bearer '.$access_token;
		$headers[] = 'Cache-Control: no-cache';
		$headers[] = 'Content-Type: application/json';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	 
	 
		$output=curl_exec($ch);
		$result = json_decode($output);
		
		curl_close($ch);
		
		$list_id = '';
		
		if(!empty($result)) {
			
			foreach($result as $row){
				$id = $row->id;
				$name = $row->name;
				
				if($name == $list_name){
					$list_id = $id;
					break;
				}
			}
		}
		
		
		if(!empty($list_id)){
			//print $list_id;die('aaa');
			return $list_id;
			
		} else {
		
			$chrl = curl_init();
			$postData = '{
			"name": "'.$list_name.'",
			"status": "ACTIVE"
			}';
			
			curl_setopt($chrl, CURLOPT_URL, 'https://api.constantcontact.com/v2/lists?api_key='.$CONSTANT_CONTACT_CLIENT_ID);
			curl_setopt($chrl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($chrl, CURLOPT_POST, 1);
			curl_setopt($chrl, CURLOPT_POSTFIELDS, $postData);

			$headers = array();
			$headers[] = 'Accept: application/json';
			$headers[] = 'Authorization: Bearer '.$access_token;
			$headers[] = 'Cache-Control: no-cache';
			$headers[] = 'Content-Type: application/json';
			curl_setopt($chrl, CURLOPT_HTTPHEADER, $headers);

			$result = json_decode(curl_exec($chrl));
			//print_r($headers);
			//print $result->id;
			
			
			if (curl_errno($chrl)) {
				//echo 'Error:' . curl_error($ch);
				return '';
			} else {
				return $result->id;
			}
			curl_close($chrl);	
		}
		
	}

	
}

?>
