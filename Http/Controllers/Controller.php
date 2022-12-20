<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Helpers\EmailHelper;
use App\Helpers\AccountHelper;
use App\MonthlyMembershipInvoice;
use App\PatientMembershipSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesResources;
use Illuminate\Support\Facades\Hash;
use DB;
use Auth;
use Validator;
use App\Http\Requests;
use Illuminate\Http\Request;
use App\User;
use App\PatientAccount;
use Mockery\Matcher\Any;
use phpDocumentor\Reflection\Types\Boolean;
use Tymon;
use Session;
use App\Account;
use Illuminate\Support\Facades\View;
use DateTime;
use DateTimeZone;
use Twilio;
use App\AccountPrefrence;
use App\AccountSubscription;

use App\Patient;
use App\AppointmentReminderConfiguration;
use App\AppointmentReminderLog;
use App\SurveySmsLog;
use App\ServiceSurvey;
use App\userGooglecalSync;
use App\Appointment;
use App\UserNotification;
use App\Users;
use App\SubscriptionEmailSmsLog;

use App\Traits\Integration;

use App\PatientIntegration;
use App\AccountZoho;
use App\AccountHubspot;
use App\AccountMailchimp;
use App\SmsOtpLog;
use App\AccountActiveCampaign;
use App\AccountConstantContact;

use App\Clinic;

use App\StripeCountry;

/**
 * Class Controller
 * @package App\Http\Controllers
 */
use App\Services\RequestTypesService;
use App\Services\ProcedureService;
/**
 * @SWG\Info(
 *  title="AR",
 *  version="1.0.0",
 *  @SWG\Contact(name="David Kykharchyk | Roman Kovalchyk",email="david@evasoft.tech | roman@evasoft.tech"),
 * )
 */
class Controller extends BaseController
{
    use AuthorizesRequests, AuthorizesResources, DispatchesJobs, ValidatesRequests;
    use Integration;

    public function checkJuvlyDomainName(): bool
    {
        $httpHost = $_SERVER['HTTP_HOST'];
        $subDomainArr = explode('.', $httpHost);
        $subDomain = $subDomainArr[0];
        $domain = $subDomainArr[1];

        return $subDomain == config('recurly.subdomain') && $domain == config('constants.juvly.domain');
    }

    public function checkEmailExist(Request $request)
	{
		$input = $request->input();
		$email = $input['email'];
		$user = Auth::user();
		if ($user->id) {

			$user = User :: find($user->id);
			$old_email = $user->email;

			if($old_email == $email){

				$response_data =  true;
			}else{

				$user = User :: where('email',$email)->count();

				if ($user) {
					$response_data =  false;

				 }
				else {
					$response_data =  true;

				}
			}

		}
		echo json_encode($response_data);
	}


	public function matchPassword(Request $request)
	{
		$input = $request->input();
		if($input != null) {

			$input_password = $input['old_password'];
			$user = Auth::user();

			$user_password = $user->password;
			if(Hash::check($input_password, $user_password)){
				$response = true;
			}else{
				$response = false;
			}

			return json_encode($response);
		}
	}

	public function checkEmailAtSignup(Request $request)
	{
		$input = $request->input();
		$email = $input['email'];
		if($this->checkJuvlyDomainName()) {
            $account_id = $this->getAccountsDetails();
        } else {
            $account_id = $this->getNotInactiveAccountsDetails();
        }
		$email = $input['email'];
		$account_id = $this->getNotInactiveAccountsDetails();
		$user = User :: where('email',$email)->whereHas('patientAccount',function($q) use($account_id){
			$q->where('account_id', $account_id);
		})->first();
		if ($user) {

			$response_data =  false;
		} else {
			$response_data =  true;
		}

		echo json_encode($response_data);
	}

	public function getPackageByClinic($params)
	{
        $url = rtrim(config('constants.urls.package_by_clinic'), '/') . "/{$params['clinic_id']}";
        $curl = curl_init();

        $fields = array(
            'account_id' => $params['account_id']
        );
		$url 		= env("PACKAGE_BY_CLINIC_URL") . '/' . $params['clinic_id'];
		//$url		= "https://app.aestheticrecord.com/appointments/get_provider_by_clinic/" . $params['clinic_id'];
		//$url		= "http://localhost/Aesthetic-Record-Production/appointments/get_provider_by_clinic/" . $params['clinic_id'];

		$curl 	= curl_init();

		$fields = array(
			'account_id' 		=> $params['account_id']
		);

		$field_string = http_build_query($fields);

		curl_setopt_array($curl, array(
		  CURLOPT_URL => $url,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_SSL_VERIFYPEER => false,
		  CURLOPT_SSL_VERIFYHOST => false,
		  CURLOPT_ENCODING => "",
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 30,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => "POST",
		  CURLOPT_POSTFIELDS => $field_string,
		  CURLOPT_SSL_VERIFYHOST=>0,
		  CURLOPT_SSL_VERIFYPEER=>0,
		  CURLOPT_HTTPHEADER => array(
			"accept-language: en;q=1",
			"cache-control: no-cache",
			"content-type: application/x-www-form-urlencoded",
		  ),
		));

		$response = curl_exec($curl);
		$err = curl_error($curl);

		curl_close($curl);

		if ($err) {
			return json_encode(array());
		} else {
			return $response;

		}
	}

    public function getPatientByMobileAuthToken($authTokenBase64, $accountId)
    {
        $passphrase = env('MOBILE_AUTH_SECRET');
        $authTokenEncrypted = base64_decode($authTokenBase64);

        // decrypt token to patient obj
        $sha2len            = 48;
        $ivlen		        = openssl_cipher_iv_length($cipher="AES-128-CBC");
        $initVector         = substr($authTokenEncrypted, 0, $ivlen);
        $ciphertextRaw 	    = substr($authTokenEncrypted, $ivlen, $ivlen+$sha2len);
        $decryptedToken     = openssl_decrypt($ciphertextRaw, $cipher, $passphrase, $options=OPENSSL_RAW_DATA, $initVector);

        // get patient phone from token
        $jsonDecodedToken = json_decode($decryptedToken);
        if(isset($jsonDecodedToken->phone)) {
            $patientPhone = $jsonDecodedToken->phone;
        } else {
            return;
        }

        // get PatientUser by patient phone and account id
        $patientUser = DB::table('patient_users')->leftJoin('patient_accounts', function ($join) {
            $join->on('patient_users.id', '=', 'patient_accounts.patient_user_id');
        })
            ->where('account_id', $accountId)->where('phone', $patientPhone)->first();

        return $patientUser;
    }

	public function getRandomProvider($params)
	{
		$url 	= env("RANDOM_PROVIDER_URL");
		$curl 	= curl_init();

		$fields = array(
			'appointment_id' 		=> $params['appointment_id'],
			'date' 					=> $params['date'],
			'time' 					=> $params['time'],
			'clinic_id' 			=> $params['clinic_id'],
			'account_id' 			=> $params['account_id'],
			'appointment_service' 	=> $params['appointment_service'],
			//'timezone' 				=> $params['timezone'],
			'package_id'			=> $params['package_id'],
			'patient_id'			=> $params['patient_id'],
			'appointment_type'		=> $params['appointment_type']
		);

		$field_string = http_build_query($fields);

		curl_setopt_array($curl, array(
		  CURLOPT_URL => $url,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_SSL_VERIFYPEER => false,
		  CURLOPT_SSL_VERIFYHOST => false,
		  CURLOPT_ENCODING => "",
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 30,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => "POST",
		  CURLOPT_POSTFIELDS => $field_string,
		  CURLOPT_SSL_VERIFYHOST=>0,
		  CURLOPT_SSL_VERIFYPEER=>0,
		  CURLOPT_HTTPHEADER => array(
			"accept-language: en;q=1",
			"cache-control: no-cache",
			"content-type: application/x-www-form-urlencoded",
		  ),
		));

		$response = curl_exec($curl);

		$err = curl_error($curl);

		curl_close($curl);

		if ($err) {
		  return json_encode(array());
		} else {
		  return $response;
		}
	}

	public function getProviderTime($params)
	{
        $url = config('constants.urls.provider_time');
        $curl = curl_init();

		$fields = array(
			'provider_id' 			=> $params['provider_id'],
			'appointment_id' 		=> $params['appointment_id'],
			'date' 					=> $params['date'],
			'clinic_id' 			=> $params['clinic_id'],
			'account_id' 			=> $params['account_id'],
			'appointment_service' 	=> $params['appointment_service'],
			//'timezone' 				=> $params['timezone'],
			'package_id'			=> $params['package_id'],
			'patient_id'			=> $params['patient_id'],
			'appointment_type'		=> $params['appointment_type']
		);

		$field_string = http_build_query($fields);

		curl_setopt_array($curl, array(
		  CURLOPT_URL => $url,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_SSL_VERIFYPEER => false,
		  CURLOPT_SSL_VERIFYHOST => false,
		  CURLOPT_ENCODING => "",
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 60,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => "POST",
		  CURLOPT_POSTFIELDS => $field_string,
		  CURLOPT_SSL_VERIFYHOST=>0,
		  CURLOPT_SSL_VERIFYPEER=>0,
		  CURLOPT_HTTPHEADER => array(
			"accept-language: en;q=1",
			"cache-control: no-cache",
			"content-type: application/x-www-form-urlencoded",
		  ),
		));

		$response = curl_exec($curl);

		$err = curl_error($curl);

		curl_close($curl);

		if ($err) {
		  return json_encode(array());
		} else {
		  return $response;
		}
	}
	
    public function getNewProviderAvailability($params)
    {
        $url = env("PROVIDER_TIME_NEW_URL") . "/{$params['provider_id']}";
        $curl = curl_init();

        $startMonth = Carbon::now()->startOfMonth()->format('Y-m-d');
        $fields = array(
            'account_id' => $params['account_id'],
            'clinic_id' => $params['clinic_id'],
            'appointment_service' => $params['appointment_service'],
            'package_id' => $params['package_id'],
            'appointment_type' => $params['appointment_type'],
            'month_start' => empty($params['month_start']) ? $startMonth : $params['month_start'],
            'month_end' => empty($params['month_end']) ? Carbon::parse($startMonth)->endOfMonth()->format('Y-m-d') : $params['month_end'],
        );
        $field_string = http_build_query($fields);

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POSTFIELDS => $field_string,
            CURLOPT_HTTPHEADER => array(
                "accept-language: en;q=1",
                "cache-control: no-cache",
                "content-type: application/x-www-form-urlencoded",
            ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);

        if ($err) {
            return json_encode(array());
        } else {
            return $response;
        }
    }
	
	public function getProviderAvailability($params)
	{
		$url 		= env("PROVIDER_DATE_URL") . '/' . $params['provider_id'];
		//$url		= "https://app.aestheticrecord.com/appointments/get_provider_availability/" . $params['provider_id'];
		//$url		= "http://192.168.1.201/Aesthetic-Record-Production/appointments/get_provider_availability/" . $params['provider_id'];
		
		$curl 	= curl_init();
		
		$fields = array(
			'account_id' 			=> $params['account_id'],
			'clinic_id' 			=> $params['clinic_id'],
			'appointment_service' 	=> $params['appointment_service'],
			'package_id'			=> $params['package_id'],
			'appointment_type'		=> $params['appointment_type']
		);
        if(!empty($params['is_provider_availability'])) {
            $fields['is_provider_availability'] = $params['is_provider_availability'];
        }

		$field_string = http_build_query($fields);

		curl_setopt_array($curl, array(
		  CURLOPT_URL => $url,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_SSL_VERIFYPEER => false,
		  CURLOPT_SSL_VERIFYHOST => false,
		  CURLOPT_ENCODING => "",
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 60,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => "POST",
		  CURLOPT_POSTFIELDS => $field_string,
		  CURLOPT_SSL_VERIFYHOST=>0,
		  CURLOPT_SSL_VERIFYPEER=>0,
		  CURLOPT_HTTPHEADER => array(
			"accept-language: en;q=1",
			"cache-control: no-cache",
			"content-type: application/x-www-form-urlencoded",
		  ),
		));

		$response = curl_exec($curl);

		$err = curl_error($curl);

		curl_close($curl);

		if ($err) {
			return json_encode(array());
		} else {
			return $response;

		}
	}

	public function getProviderByClinic($params)
	{
        $url = rtrim(config('constants.urls.provider_by_clinic'), '/') . "/{$params['clinic_id']}";
        $curl = curl_init();

		$fields = array(
			'account_id' 		=> $params['account_id'],
			'service' 			=> $params['serviceArr']
		);

		$field_string = http_build_query($fields);

		curl_setopt_array($curl, array(
		  CURLOPT_URL => $url,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_SSL_VERIFYPEER => false,
		  CURLOPT_SSL_VERIFYHOST => false,
		  CURLOPT_ENCODING => "",
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 30,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => "POST",
		  CURLOPT_POSTFIELDS => $field_string,
		  CURLOPT_SSL_VERIFYHOST=>0,
		  CURLOPT_SSL_VERIFYPEER=>0,
		  CURLOPT_HTTPHEADER => array(
			"accept-language: en;q=1",
			"cache-control: no-cache",
			"content-type: application/x-www-form-urlencoded",
		  ),
		));

		$response = curl_exec($curl);
		$err = curl_error($curl);

		curl_close($curl);

		if ($err) {
			return json_encode(array());
		} else {
			return $response;

		}
	}

	public function checkIfApptCanBeBooked($params)
	{
        $url = config('constants.urls.check_appointment_is_valid');
        $curl = curl_init();

		$fields = array(
			'patient_id'			=> $params['patient_id'],
			'package'				=> $params['package_id'],
			'provider' 				=> $params['provider_id'],
			'appointment_id' 		=> $params['appointment_id'],
			'appointment_date' 		=> $params['appointment_date'],
			'clinic' 				=> $params['clinic'],
			'account_id' 			=> $params['account_id'],
			'service' 				=> $params['service'],
			'appointment_time'		=> $params['appointment_time'],
			'appointment_type'		=> $params['appointment_type']
		);

		$field_string = http_build_query($fields);

		curl_setopt_array($curl, array(
		  CURLOPT_URL => $url,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_SSL_VERIFYPEER => false,
		  CURLOPT_SSL_VERIFYHOST => false,
		  CURLOPT_ENCODING => "",
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 30,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => "POST",
		  CURLOPT_POSTFIELDS => $field_string,
		  CURLOPT_SSL_VERIFYHOST=>0,
		  CURLOPT_SSL_VERIFYPEER=>0,
		  CURLOPT_HTTPHEADER => array(
			"accept-language: en;q=1",
			"cache-control: no-cache",
			"content-type: application/x-www-form-urlencoded",
		  ),
		));

		$response = curl_exec($curl);

		$err = curl_error($curl);

		curl_close($curl);

		if ($err) {
		  return json_encode(array());
		} else {
		  return $response;
		}
	}

	public function getCampaignDetail($campaign_id)
	{
		if($campaign_id)
		{
			$apiKey = config('mail.sendgrid_api_key');
			$sg = new \SendGrid($apiKey);
			$response         = $sg->client->campaigns()->_($campaign_id)->get();
			$api_status     = $response->statusCode();
			if($api_status == 200) {
				$campaign_detail = json_decode($response->body());
				$subject 	= 	$campaign_detail->subject;
				$body 		= 	$campaign_detail->html_content;
				return $campagin_data = array('sg'=>$sg,'body'=>$body,'subject'=>$subject);
			} else {
				return  $campagin_data = array();
			}
		} else {
				return  $campagin_data = array();
		}
	}

	public function checkAccountStatus($request)
	{
        $user 		= Auth::user();

		if(count((array)$user)>0) {

            if($user->status == 1){
				Session::put('error', 'Please verify your email to login');
				Auth :: logout();
			}

            $account 	     = Session::get('account_detail');

            if(!$account) {
                Session::put('error', 'Account not found');
                return;
            }

			$patient_account =	PatientAccount::where('patient_user_id', @$user['id'])->where('account_id', $account->id)->first();

			if(count((array)$patient_account) == 0) {
				Session::put('error', 'Invalid credentials or your patient portal is not configured for this office');
				Auth :: logout();
			}

			if(count((array)$patient_account)>0 && $patient_account->access_portal == 0 ){
				Session::put('error', 'Your Account has been Blocked');
				Auth :: logout();
			}

			$account_detail =	Account::where('id', $account->id)->first();

			if(count((array)$account_detail)>0 && $account_detail->status == 'inactive' ){
				Session::put('error', 'Unable to login. Please contact office for details');
				Auth :: logout();
			}

			if($account_detail){
				config(['database.connections.juvly_practice.database'=> $account_detail->database_name]);
				$patient = Patient::where('id',@$patient_account->patient_id)->where('status',0)->first();
				if(!$patient){
					Session::put('error', 'Patient not found');
					Auth :: logout();
				}

				if($patient->status){
                    Session::put('error', 'Please activate your account');
                    Auth :: logout();
                }

			}

		}
		//echo "<pre>"; print_r($user); die;
	}

	public function checkEmailAtResetPassword(Request $request)
	{

		$input = $request->input();
		$email = $input['email'];
		$user = User :: where('email',$email)->count();
		if ($user) {

			$response_data =  true;
		} else {
			$response_data =  false;
		}

		echo json_encode($response_data);
	}

	public function getEmailTemplate($mailBody,$account,$clinic,$subject)
	{

		$email_content 		= $mailBody;
		$location 			= array();

		$clinic_location_tmp 		= [];
		$clinic_location 			= '';
		if(!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
			$clinic_location_tmp[] = $clinic->clinic_city;
			$clinic_location_tmp[] = $clinic->clinic_state;
			$clinic_location_tmp[] = $clinic->clinic_zipcode;
			$clinic_location  = implode(",",$clinic_location_tmp);
		} else {
			if($clinic->city!=''){
				$clinic_location_tmp[] = $clinic->city;
			}
			if($clinic->country!=''){
				$clinic_location_tmp[] = $clinic->country;
			}
			$clinic_location  = implode(", ",$clinic_location_tmp);
		}

		$clinic_address		= @$clinic->address;
		$account_logo  		= @$account->logo;
		$account_name		= @$account->name;
		$storage_folder		= @$account->storage_folder;
		$appointment_status = $subject;
		$site_url			= config('constants.urls.site');
	//	$view 				= View::make('appointments.email_template', ['email_content' => $email_content,'clinic_location' => $clinic_location,'account_logo' => $account_logo, 'site_url' => $site_url,'storage_folder' => $storage_folder,'appointment_status' => $appointment_status,'account_name' => $account_name]);
		$view 				= View::make('appointments.email_template', ['email_content' => $email_content,'clinic_location' => $clinic_location,'account_logo' => $account_logo, 'site_url' => $site_url,'storage_folder' => $storage_folder,'appointment_status' => $appointment_status,'clinic_address' => $clinic_address]);
		$contents			= $view->render();
		return $contents;

	}

    public function getDefaultEmailTemplate($mailBody,$subject)
    {
        $email_content 		= $mailBody;
        $appointment_status = $subject;
        $site_url			= getenv('SITE_URL');
        $view 				= View::make('appointments.default_email_template', ['email_content' => $email_content,'site_url' => $site_url,'appointment_status' => $appointment_status]);
        $contents			= $view->render();
        return $contents;
    }


	public function generateRandomString($length = 10)
	{
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
				$randomString .= $characters[rand(0, $charactersLength - 1)];
		}
		return $randomString;
	}

   public function setAccountLogo($account)
   {

		if(!empty($account->storage_folder)) {
			$storage_folder = $account->storage_folder;
		} else {
			$storage_folder = '';
		}

		if(!empty($account->logo)){
			$logo = $account->logo;
		} else {
			$logo = '';
		}

		if($logo)
		{
            $file = rtrim(config('constants.urls.media.bucket'), '/') ."/{$storage_folder}/admin/thumb_{$logo}";

			$file_headers = @get_headers($file);
			if(!$file_headers || $file_headers[0] == 'HTTP/1.1 302 Found')
			{
				$logourl = url("/")."/assets/logo/logo.png";
			} else {
				$logourl = $file;

			}
		} else {
			$logourl = url("/")."/assets/logo/logo.png";
		}
		return $logourl;

   }
	public function convertTZ($toTimezone)
	{
		$fromTimezone	= trim("America/New_York");
		date_default_timezone_set($fromTimezone);
		$todayDateTime 			= new DateTime(date('Y-m-d H:i:s'));
		$todayTimeZone 			= new DateTimeZone($toTimezone);
		$todayDateTime->setTimezone($todayTimeZone);

		$todayInClinicTZ		= $todayDateTime->format('Y-m-d H:i:s');

		return $todayInClinicTZ;
	}

	public function stopSmsCheck($patient_id =null){

		if(Patient::where('id',$patient_id)->exists()){
			$patient = Patient::find($patient_id);
			return $patient->do_not_sms;
		}else{
			return 1;

		}
	}

	public function convertTzToSystemTz($aptDateTime,$aptDateTimeZone) {

		$appointment_datetime_with_timezone = new DateTime($aptDateTime, new DateTimeZone($aptDateTimeZone));
		$system_appointment_datetime = $appointment_datetime_with_timezone->setTimezone(new DateTimeZone('America/New_York'));
		return $system_appointment_datetime->format('Y-m-d H:i:s');
	}

	public function saveAppointmentReminderLogs($appointment_id =null, $combinedDT=null) {

		/*NOTE: Please include models at top of the file
		 * 1. AppointmentReminderConfiguration
		 * 2. AppointmentReminderLog */

		$appointment_reminders_config = AppointmentReminderConfiguration::get();
		if($combinedDT){
			if(count($appointment_reminders_config) > 0) {
				foreach($appointment_reminders_config as $config) {
					$reminderType	= $config->reminder_type;
					$remindBefore	= $config->reminder_before;
					$scheduleOn 	= date('Y-m-d H:i:s', strtotime('-'.$remindBefore.' '.$reminderType, strtotime($combinedDT)));
					$currentTime	= date('Y-m-d H:i:s');
					$currentTime	= $this->getCurrentTimeNewYork($currentTime);
					if ($scheduleOn > $currentTime) {
						$appoinment_reminder_log = new AppointmentReminderLog;
						$appoinment_reminder_log->appointment_id	= $appointment_id;
						$appoinment_reminder_log->appointment_date	= $combinedDT;
						$appoinment_reminder_log->schedule_on	= $scheduleOn;
						$appoinment_reminder_log->reminder_type	= $reminderType;
						$appoinment_reminder_log->reminder_before	= $remindBefore;
						$appoinment_reminder_log->send_status	= 'pending';
						$appoinment_reminder_log->date_created	= date('Y-m-d H:i:s');
						$appoinment_reminder_log->save();
					}
				}
			}
		}
		return true;
	}

	public function getCurrentTimeNewYork($currentTime, $type ='date') {
		$fromTimezone = date_default_timezone_get();

		$date 		= new DateTime($currentTime, new DateTimeZone($fromTimezone));
		$toTimezone = "America/New_York";
		$date->setTimezone(new DateTimeZone($toTimezone));

		if($type == 'time'){
			return $date->format('H:i:s');
		}else{
			return $date->format('Y-m-d H:i:s');
		}
	}




	public function sendSMS($to, $smsBody, $account)
	{
		if ( count($account) ) {
			$sid				= env("TWILIO_SID");
			$token				= env("TWILIO_TOKEN");
			$from				= env("TWILIO_FROM"); /*hard code value for AR patient portal*/
			$to 			= "+" . preg_replace('/\D+/', '', $to);
			if(!empty($account->twilio_from_number)){
				$from				= trim($account->twilio_from_number);
			}
			$twilio 			= new \Aloha\Twilio\Twilio($sid, $token, $from);
			$to = str_replace('-','', $to);
			$to = str_replace('(','', $to);
			$to = str_replace(')','', $to);
			$twilio_response 	= array();

			try {
				$twilio_response = $twilio->message($to, $smsBody);
			} catch ( \Exception $e ) {
				if ( $e->getCode() == 21211 ) {
					$message = $e->getMessage();
				}
			}

			if ( count($twilio_response) > 0 ) {
				if ( $twilio_response->media->client->last_response->error_code != '' ) {
					return false;
				} else {
					return true;
				}
			}
		} else {
			return false;
		}
	}

	public function getSenderEmail() {
		$accountID = $this->getAccountsDetails();
		if(isset($accountID) && !empty($accountID)){
			$account_prefrence = AccountPrefrence::where('account_id',$accountID)->first();
			if(isset($account_prefrence->from_email) && !empty($account_prefrence->from_email)){
				$from_email =  $account_prefrence->from_email;
			}else{
				$from_email = getenv('MAIL_FROM_EMAIL');
			}
		}else{
				$from_email = getenv('MAIL_FROM_EMAIL');
		}
		return $from_email;
	}

	public function getAccountsDetails() {
		$httpHost					= $_SERVER['HTTP_HOST'];
		$subDomainArr				= explode('.', $httpHost);
		$subDomain					= $subDomainArr[0];

		$accountID		= 0;
		$account 		= Account::where('pportal_subdomain', $subDomain)->where('status', 'active')->first();

		if ( $account ) {
			$accountID 		= $account->id;
		}

		return $accountID;
	}

	public function checkSmsLimit() {
		$accountID = $this->getAccountsDetails();
		$account_subscription = AccountSubscription::where('account_id',$accountID)->first();
		$response = false;
		if ($account_subscription) {
			$sms_limit = $account_subscription->sms_limit + $account_subscription->add_on_sms ;
			if($account_subscription->sms_used < $sms_limit) {
				$response = true;
			}
		}
		return $response;
	}

	public function saveSmsCount() {
		$accountID = $this->getAccountsDetails();
		$account_subscription = AccountSubscription::where('account_id',$accountID)->first();
		if($account_subscription){
			$new_sms_used_count = $account_subscription->sms_used + 1;
			$this->saveSmsEmailLog('sms',$accountID);

		}

	}

	public function checkEmailLimit() {
		$accountID = $this->getAccountsDetails();
		$account_subscription = AccountSubscription::where('account_id',$accountID)->first();
		$response = false;
		if ($account_subscription) {
			$email_limit = $account_subscription->email_limit + $account_subscription->add_on_email ;
			if($account_subscription->email_used < $email_limit) {
				$response = true;
			}
		}
		return $response;
	}

	public function saveEmailCount() {
		$accountID = $this->getAccountsDetails();
		$account_subscription = AccountSubscription::where('account_id',$accountID)->first();
		if($account_subscription){
			$new_email_used_count = $account_subscription->email_used + 1;
			(new AccountSubscription)->where('account_id',$accountID)->update(['email_used'=>$new_email_used_count]);
			$this->saveSmsEmailLog('email',$accountID);
		}

	}

	protected function checkSmsAutofill() {
		$accountID = $this->getAccountsDetails();
		$account_subscription = AccountSubscription::where('account_id',$accountID)->first();
		$response = false;
		if ($account_subscription) {
			if($account_subscription->refill_sms_status) {
				$response = true;
			}
		}
		return $response;
	}

	protected function checkEmailAutofill() {
		$accountID = $this->getAccountsDetails();
		$account_subscription = AccountSubscription::where('account_id',$accountID)->first();
		$response = false;
		if ($account_subscription) {
			if($account_subscription->refill_email_status) {
				$response = true;
			}
		}
		return $response;
	}

	protected function paidAccount(){
		$httpHost					= $_SERVER['HTTP_HOST'];
		$subDomainArr				= explode('.', $httpHost);
		$subDomain					= $subDomainArr[0];

		$accountType		= '';
		$account 		= Account::where('pportal_subdomain', $subDomain)->where('status', 'active')->first();

		if ( $account ) {
			$accountType 		= $account->account_type;
		}
		return $accountType;
	}

	protected function updateUnbilledSms(){
		$accountID 				 = $this->getAccountsDetails();
		$account_subscription	 = AccountSubscription::where('account_id',$accountID)->first();

		if($account_subscription){
			$new_unbilled_sms = $account_subscription->unbilled_sms + 1;
			(new AccountSubscription)->where('account_id',$accountID)->update(['unbilled_sms'=>$new_unbilled_sms]);
			$this->saveSmsEmailLog('sms',$accountID);
		}
	}

	protected function updateUnbilledEmail(){
		$accountID 				 = $this->getAccountsDetails();
		$account_subscription	 = AccountSubscription::where('account_id',$accountID)->first();

		if($account_subscription){
			$new_unbilled_email = $account_subscription->unbilled_email + 1;
			(new AccountSubscription)->where('account_id',$accountID)->update(['unbilled_email'=>$new_unbilled_email]);
			$this->saveSmsEmailLog('email',$accountID);
		}
	}

	function cleanString($text)
	{
		$utf8 = array(
			'/[áàâãªä]/u'   =>   'a',
			'/[ÁÀÂÃÄ]/u'    =>   'A',
			'/[ÍÌÎÏ]/u'     =>   'I',
			'/[íìîï]/u'     =>   'i',
			'/[éèêë]/u'     =>   'e',
			'/[ÉÈÊË]/u'     =>   'E',
			'/[óòôõºö]/u'   =>   'o',
			'/[ÓÒÔÕÖ]/u'    =>   'O',
			'/[úùûü]/u'     =>   'u',
			'/[ÚÙÛÜ]/u'     =>   'U',
			'/ç/'           =>   'c',
			'/Ç/'           =>   'C',
			'/ñ/'           =>   'n',
			'/Ñ/'           =>   'N',
			'/–/'           =>   '-', // UTF-8 hyphen to "normal" hyphen
			'/[’‘‹›‚]/u'    =>   ' ', // Literally a single quote
			'/[“”«»„]/u'    =>   ' ', // Double quote
			'/ /'           =>   ' ', // nonbreaking space (equiv. to 0x160)
		);
		return preg_replace(array_keys($utf8), array_values($utf8), $text);
	}

	public function save_sms_log($patient_id, $appointmentid, $sms_date, $serviceids){
		if(SurveySmsLog::where('appointment_id',$appointmentid)->exists()){
			SurveySmsLog::where('appointment_id',$appointmentid)->delete();
		}
		if(count($serviceids)){
			foreach($serviceids as $serviceid){
				$surveys = ServiceSurvey::where('service_id',$serviceid)->where('schedule_type','hours')->select('survey_id','schedule_type','scheduled_after')->get();
				if(count($surveys)){
					foreach($surveys as $survey){
						$new_sms_date = date('Y-m-d H:i:s', strtotime('+'.$survey->scheduled_after.' hours', strtotime($sms_date)));
						$surveySmsLog = new SurveySmsLog();
						$surveySmsLog->survey_id = $survey->survey_id;
						$surveySmsLog->patient_id = $patient_id;
						$surveySmsLog->appointment_id = $appointmentid;
						$surveySmsLog->sms_date = $new_sms_date;
						$surveySmsLog->send_status = 'pending';
						$surveySmsLog->email_send_status = 'pending';
						$surveySmsLog->schedule_type = 'hours';
						$surveySmsLog->save();
					}
				}
			}
		}
	}

	public function convertTimeByTZ($time, $fromTimezone, $toTimezone, $type='time'){
		//~ echo $fromTimezone."-------------------------".$toTimezone."----------------------".$time;
		//~ die;
		$toTimezone = trim($toTimezone);
		$fromTimezone = trim($fromTimezone);
		if($type == 'time'){
			$time 		= date('Y-m-d')." ".$time;
		}
		$date 		= new DateTime($time, new DateTimeZone($fromTimezone));
		//print_r($date);print $toTimezone;die;
		$date->setTimezone(new DateTimeZone($toTimezone));


		if($type == 'time'){
			return $date->format('H:i:s');
		}else{
			return $date->format('Y-m-d H:i:s');
		}
	}

	protected function syncGoogleCalanderEvent($providerID, $appointment, $patientID, $status){
		try{
			$providerCalenderDetails = userGooglecalSync::where('user_id',$providerID)->first();
			$clientId 		= getenv('GOOGLE_CLIENT_ID');
			$clientSecret 	= getenv('GOOGLE_CLIENT_SECRET');
			$patientData	= Patient::find($patientID);
			if($providerCalenderDetails && $providerCalenderDetails->sync_enabled){
				$refreshToken = $providerCalenderDetails->refresh_token;
				$refreshTokenData = GetRefreshedAccessToken($clientId, $refreshToken, $clientSecret);
				if($refreshTokenData['access_token']){
					$newAccessToken = $refreshTokenData['access_token'];
					$user_timezone   = GetUserCalendarTimezone($newAccessToken);
					$clinicTimeZone = $appointment['clinic']->timezone;
					$appointment_date		= date('Y-m-d', strtotime($appointment->appointment_datetime));
					$appointment_time		= date('H:i:s', strtotime($appointment->appointment_datetime));
					/***FROM TIME****/
					$utcAppFromDatetime     = $this->convertTimeByTZ($appointment->appointment_datetime, $clinicTimeZone,$user_timezone, "datetime");
					$fromDateTimeApp        = date('Y-m-dTH:i:s', strtotime($utcAppFromDatetime));

					if(strstr($fromDateTimeApp,'UTC')){
						$fromDateTimeApp        = str_replace('U', '', $fromDateTimeApp);
						$fromDateTimeApp        = str_replace('C', '', $fromDateTimeApp);

					}elseif(strstr($fromDateTimeApp,'ED')){
						$fromDateTimeApp        = str_replace('ED', '', $fromDateTimeApp);

					}else{
						$fromDateTimeApp        = str_replace('ES', '', $fromDateTimeApp);
					}

					/****TO TIME*****/
					$time    				= explode(':', $appointment_time);
					$minutes 				= ($time[0]*60) + ($time[1]) + ($time[2]/60);
					$duration				= $appointment->duration;
					$toTime                 = $minutes + $duration;
					$toTime                 = $this->convertToHoursMins($toTime, '%02d:%02d');
					$combinedToDT 		    = date('Y-m-d H:i:s', strtotime("$appointment_date $toTime"));
					$utcAppToDatetime       = $this->convertTimeByTZ($combinedToDT, $clinicTimeZone,$user_timezone, "datetime");
					$toDateTimeApp          = date('Y-m-dTH:i:s', strtotime($utcAppToDatetime));

					if(strstr($toDateTimeApp,'UTC')){
						$toDateTimeApp          = str_replace('U', '', $toDateTimeApp);
						$toDateTimeApp          = str_replace('C', '', $toDateTimeApp);

					}elseif(strstr($toDateTimeApp,'ED')){
						$toDateTimeApp          = str_replace('ED', '', $toDateTimeApp);

					}else{
						$toDateTimeApp          = str_replace('ES', '', $toDateTimeApp);
					}

					$clinic_location  = $appointment['clinic']->city;
					$description    = '';
					if(count($appointment['services']) > 0) {
						$description        = 'Services : ';

						foreach($appointment['services'] as $AppointmentService) {
							$description .=  $AppointmentService->name.', ';
						}
						$description .= '<br/>';
					}
					$description    .= 'Status : '.$status;
					$fullname       = @$patientData->firstname.' '.@$patientData->lastname;
					$event_id = $appointment->googlecal_event_id;
					if($event_id){
						UpdateCalendarEvent($event_id,'primary', $fullname, 0, array('start_time'=>$fromDateTimeApp, 'end_time'=>$toDateTimeApp, 'event_date'=>''), $user_timezone, $newAccessToken, $status, $clinic_location, $description);
					}else{
						$event_id = CreateCalendarEvent('primary', $fullname, 0, array('start_time'=>$fromDateTimeApp, 'end_time'=>$toDateTimeApp, 'event_date'=>''), $user_timezone, $newAccessToken, $status, $clinic_location, $description);

						if(!empty($event_id)) {
							Appointment::where('id',$appointment->id)->update(['googlecal_event_id'=>$event_id]);
						}
					}
				}
			}
		}catch( \Exception $e){
			$error = $e->getMessage();
			$this->saveUserNotificationOnSync($patientID, $providerID, $appointment->id, $error);
		}
	}

	public function convertToHoursMins($time, $format = '%02d:%02d') {
		if ($time < 1) {
			return;
		}
		$hours = floor($time / 60);
		$minutes = ($time % 60);
		return sprintf($format, $hours, $minutes);
	}

	private function saveUserNotificationOnSync($patientID, $providerID, $appointment_id, $error)
	{
		$session = Session::all();
		if(isset($session['account_detail'])){
			$db = $session['account_detail']->database_name;
			config(['database.connections.juvly_practice.database'=> $db ]);
			$userNotification = new UserNotification();
			$userNotification->user_id = $providerID;
			$userNotification->product_id = $appointment_id;
			$userNotification->notification_type = 'appointment_sync_failed';
			$userNotification->created =  date("Y-m-d H:i:s");
			$userNotification->save();
		}
	}

	protected function deleteGoogleEvent($providerID, $appointment, $patientID){
		try{
			$providerCalenderDetails = userGooglecalSync::where('user_id',$providerID)->first();
            $clientId = config('google.client_id');
            $clientSecret = config('google.client_secret');
			if($providerCalenderDetails && $providerCalenderDetails->sync_enabled){
				$refreshToken = $providerCalenderDetails->refresh_token;
				$refreshTokenData = GetRefreshedAccessToken($clientId, $refreshToken, $clientSecret);
				if($refreshTokenData['access_token']){
					$newAccessToken = $refreshTokenData['access_token'];
					$event_id = $appointment->googlecal_event_id;
					DeleteCalendarEvent($event_id, 'primary', $newAccessToken);
				}
			}
		}catch( \Exception $e){
			$error = $e->getMessage();
			$this->saveUserNotificationOnSync($patientID, $providerID, $appointment->id, $error);
		}
	}

    private function saveSmsEmailLog($type, $accountID)
    {
        $emailSMSLog = new SubscriptionEmailSmsLog();
        $emailSMSLog->account_id = $accountID;
        $emailSMSLog->type = $type;
        $emailSMSLog->created = date("Y-m-d H:i:s");
        $emailSMSLog->save();
    }

    public function phpDateFormat($account_id)
    {
        $account_prefrences = DB::table('account_prefrences')->where('account_id', $account_id)->first();
        $format = trim($account_prefrences->date_format);
        if (!empty($format)) {
            switch ($format) {
                case 'mm/dd/yyyy':
                    $phpFormat = 'm/d/Y';
                    break;
                case 'dd/mm/yyyy':
                    $phpFormat = 'd/m/Y';
                    break;
                case 'yyyy/mm/dd':
                    $phpFormat = 'Y/m/d';
                    break;
                default:
                    $phpFormat = 'm/d/Y';
            }
        } else {
            $phpFormat = 'm/d/Y';
        }
        return $phpFormat;
    }

    public function setSessionAppointmentSettingForPatient(Request $request){
        $httpHost		= $_SERVER['HTTP_HOST'];
        $subDomainArr	= explode('.', $httpHost);
        $subDomain		= $subDomainArr[0];
        $account = Account::where('pportal_subdomain', $subDomain)->where('status','!=','inactive')->first();
        if(!$account){
            return view('errors.404');
        }
        $accountPrefrences = AccountPrefrence::where('account_id',$account->id)->first();
        $allow_patients_to_manage_appt =  $accountPrefrences->allow_patients_to_manage_appt;
        $request->session()->put('allow_patients_to_manage_appt',$allow_patients_to_manage_appt);
    }

    protected function sendEmail($from_email, $patientEmail, $replyToEmail, $email_content, $subject, $attachments=false, $invoice_number='0'){

        return EmailHelper::sendEmail($from_email, $patientEmail, $replyToEmail, $email_content, $subject, $attachments, $invoice_number);
    }

    /* get date format */

    public function getDateFormat($date) {
        switch ($date) {
            case 'yyyy/mm/dd':
                $return = 'Y/m/d';
                break;
            case 'dd/mm/yyyy':
                $return = 'd/m/Y';
                break;
            case 'mm/dd/yyyy':
                $return = 'm/d/Y';
                break;
            default:
                $return = 'm/d/Y';
                break;
        }
        return $return;
    }

	public function isPosEnabled($account_id){
		$account =  DB::table('accounts')->where('id',$account_id)->first();
		$pos_enabled = false;
		config(['database.connections.juvly_practice.database'=> $account->database_name ]);
		$clinic_ids = [];
		$clinics = DB::connection('juvly_practice')->table('clinics')->where('status',0)->get();
		if(!empty($clinics)){
			foreach($clinics as $clinic){
				$clinic_ids[] = $clinic->id;
			}
		}
		$account_id = $account->id;
		if($account && $account->pos_enabled){
			$pos_gateway =  $account->pos_gateway;
			if($pos_gateway == 'clearent'){
				if($account->stripe_connection == 'clinic'){

					$stripe_config = DB::table('account_clearent_configs')->where('account_id',$account_id)->whereIn('clinic_id',$clinic_ids)->first();
					if($stripe_config){
						$pos_enabled = true;
					}
				}else{
					// GLOBAL where clinic id 0
					$stripe_config = DB::table('account_clearent_configs')->where('account_id',$account_id)->where('clinic_id',0)->first();
					if($stripe_config){
						$pos_enabled = true;
					}
				}
			}else{
				if($account->stripe_connection == 'clinic'){

					$stripe_config = DB::table('account_stripe_configs')->where('account_id',$account_id)->whereIn('clinic_id',$clinic_ids)->first();
					if($stripe_config){
						$pos_enabled = true;
					}
				}else{
					// GLOBAL where clinic id 0
					$stripe_config = DB::table('account_stripe_configs')->where('account_id',$account_id)->where('clinic_id',0)->first();
					if($stripe_config){
						$pos_enabled = true;
					}
				}
			}
		}
		return $pos_enabled;
	}
	public function getUserUsedClientName($account_id){
		$name = "client";
		$account_pref = AccountPrefrence::where('account_id',$account_id)->select('id','account_id','client_replacement_text')->first();
		if(!empty($account_pref->client_replacement_text) || !is_null($account_pref->client_replacement_text)){
			$name = strtolower($account_pref->client_replacement_text);
		}
		return $name;
	}

	public function patientIntegrationProcess($account_prefrence, $patient){

			$data = array();
			$id = $patient['id'];
			$data['id'] = $patient['id'];
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
			$account_id = $account_prefrence->account_id;
			$temp = array();
			$temp[0] = $data;

			$AccountZoho = AccountZoho::where('account_id',$account_id)->where('sync_new',1)->first();
			$access_token = '';

			if(!empty($AccountZoho)){
				try{
					$data = array();
					$data = json_encode($temp);
					$access_token = $AccountZoho->access_token;
					$refresh_token = Integration::createZohoAccessTokenFromRefreshToken($access_token);
					$res = Integration::createPatientOnZoho($data,$refresh_token);
					//$this->switchDatabase($database_name);
					$this->insert_patient_integrations($id,'zoho');
				}catch(\Exception $e){
					echo "<pre>"; print_r($e->getMessage().$e->getLine());
				}
			}


			// Hubspot Create Patient

			$AccountHubspot = AccountHubspot::where('account_id',$account_id)->where('sync_new',1)->first();
			$access_token = '';

			if(!empty($AccountHubspot)){
				try{
					$data = array();
					$data = $temp;
					$access_token = $AccountHubspot->access_token;
					$refreshToken = Integration::createHubspotAccessTokenFromRefreshToken($access_token);
					$access_token = $refreshToken->access_token;
					Integration::createPatientOnHubspot($data,$access_token);
				//	$this->switchDatabase($database_name);
					$this->insert_patient_integrations($id,'hubspot');
				}catch(\Exception $e){
					echo "<pre>"; print_r($e->getMessage().$e->getLine());
				}

			}


			// Intercom Create Patient

			//~ $AccountIntercom = AccountIntercom::where('account_id',$account_id)->where('sync_new',1)->first();
			//~ $access_token = '';

			//~ if(!empty($AccountIntercom)){
				//~ $data = array();
				//~ $data = json_encode($temp);
				//~ $access_token = $AccountIntercom->access_token;
				//~ Integration::intercomCreateContact($data,$access_token);
				//~ //$this->switchDatabase($database_name);
				//~ $this->insert_patient_integrations($id,'intercom');
			//~ }


			// mailchimp Create Patient

			$AccountMailchimp = AccountMailchimp::where('account_id',$account_id)->first();
			$access_token = '';

			if(!empty($AccountMailchimp)){
				try{
					$code = $AccountMailchimp->access_token;
					$result = Integration::getAccessTokenMailchimp($code);
					if(!empty($result->dc)){
						$location 	= $result->dc;
						$mailchimp_key = $code.'-'.$location;
						Integration::mailchimpCreateContact($account_id, $mailchimp_key, $location, $temp);
						//$this->switchDatabase($database_name);
						$this->insert_patient_integrations($id,'mailchimp');
					}
				}catch(\Exception $e){
					echo "<pre>"; print_r($e->getMessage().$e->getLine());
				}
			}

			//account active campain
			$AccountActiveCampaign = AccountActiveCampaign::where('account_id',$account_id)->where('sync_new',1)->first();
			$access_token = '';

			if(!empty($AccountActiveCampaign)){
				try{
					$code = $AccountActiveCampaign->access_token;
					$url = $AccountActiveCampaign->url;
					$result = Integration::create_active_content_user($url,$code,$temp);
					//$this->switchDatabase($database_name);
					$this->insert_patient_integrations($id,'active_campaign');
				}catch(\Exception $e){
					echo "<pre>"; print_r($e->getMessage().$e->getLine());
				}
			}

			//account constant contact
			$AccountConstantContact = AccountConstantContact::where('account_id',$account_id)->where('sync_new',1)->first();
			$access_token = '';

			if(!empty($AccountConstantContact)){
				try{
					$code = $AccountConstantContact->access_token;
					$result = Integration::create_constant_content_user($code,$temp);
					//$this->switchDatabase($database_name);
					$this->insert_patient_integrations($id,'constant_contact');
				}catch(\Exception $e){
					echo "<pre>"; print_r($e->getMessage().$e->getLine());
				}
			}

		return true;

	}

	private function insert_patient_integrations($patient_id,$type){

		$allData = PatientIntegration::where('patient_id',$patient_id)->where('integration',$type)->first();

		if(!empty($allData)){
			//
		} else {
			$data = array(
				'integration' => $type,
				'patient_id' => $patient_id,
				'created' => date('Y-m-d H:i:s')
				);

			PatientIntegration::insert($data);
		}
	}

	public function verifyTwilioNumberOld($number, $account)
	{
		$sid				= env("TWILIO_SID");
		$token				= env("TWILIO_TOKEN");
		$ch 				= curl_init();

		curl_setopt($ch, CURLOPT_URL, 'https://lookups.twilio.com/v1/PhoneNumbers/'.$number.'?Type=carrier');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
		curl_setopt($ch, CURLOPT_USERPWD, $sid . ':' . $token);

		$result 			= curl_exec($ch);

		curl_close($ch);

		$response			= json_decode($result);

		return $response;
	}

	public function verifyTwilioNumber($number, $account, $isRegister=false)
	{
        $sid = config('twilio.twilio.connections.twilio.sid');
        $token = config('twilio.twilio.connections.twilio.token');
		$serviceID			= $isRegister == 'true' ? 'VA10103d3408fc571eba82d143441c836a' : 'VA10103d3408fc571eba82d143441c836a';

		$number = str_replace('-','', $number);
		$number = str_replace('(','', $number);
		$number = str_replace(')','', $number);
		$ch 				= curl_init();
		$url 				= 'https://verify.twilio.com/v2/Services/'.$serviceID.'/Verifications';
		$arrdata 			= array("To" => $number, "Channel" => "sms" );
		$postdata 			= http_build_query($arrdata);

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERPWD, $sid . ':' . $token);

		$result 			= curl_exec($ch);

		curl_close($ch);

		$response			= json_decode($result);

		return $response;
	}

	public function verifyTwilioOTP($number, $otp, $isRegister=false)
	{
		$sid				= env("TWILIO_SID");
		$token				= env("TWILIO_TOKEN");

		$serviceID			= $isRegister == 'true' ? 'VA10103d3408fc571eba82d143441c836a' : 'VA10103d3408fc571eba82d143441c836a';

		$ch 				= curl_init();
		$url 				= 'https://verify.twilio.com/v2/Services/'.$serviceID.'/VerificationCheck';
		$number = str_replace('-','', $number);
		$number = str_replace('(','', $number);
		$number = str_replace(')','', $number);
		$arrdata 			= array("To" => $number, "Code" => $otp);
		$postdata 			= http_build_query($arrdata);

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_USERPWD, $sid . ':' . $token);

		$result 			= curl_exec($ch);

		curl_close($ch);

		$response			= json_decode($result);

		return $response;
	}

	public function sendOTPOnEmail(Request $request)
    {
		$input 				= $request->input();
		$account 			= $request->session()->get('account_detail');
		$otp				= trim($input['localKey']);
		$email				= trim($input['email']);

		$sender	 			= EmailHelper::getSenderEmail();

		config(['database.connections.juvly_practice.database'=> $account->database_name ]);

		$clinic 			= Clinic::where('status',0)->first();
		$noReply 			= config('mail.from.address');
		$subject 			= "Your OTP code to complete Patient Portal registration";
		$mail_body			= "<p>Your OTP code to complete your Patient Portal registration is $otp. You will need to enter this code into the box provided on the Patient Portal registration screen</p>";
		$email_content 		= EmailHelper::getEmailTemplate($mail_body, $account, $clinic, $subject);

		$response 			= EmailHelper::sendEmail($noReply, $email, $sender, $email_content, $subject, false);

		if ($response) {
			$json = array('status' => 200, 'error' => false, 'message' => 'OTP Sent');
		} else {
			$json = array("status" => 200, "error" => true, "message" => "We are unable to send OTP to your email ID");
		}

		return response()->json($json);
	}


	public function stripeMinimumAmount($stripe_currency)
	{
		$stripe_country = StripeCountry::where('currency_code', $stripe_currency)->first();
		$minimum_amount = 50;

		if ($stripe_country) {
			$minimum_amount =  $stripe_country->minimum_amount;
		}
		return $minimum_amount;
	}



    /**
     * Generate a token using the user identifier as the subject claim.
     *
     * @param mixed $user
     * @param int $myTTL 3 month by default
     * @return string
     */
	public function getJWTFromUser($user, $myTTL = 131400)
    {
        Tymon\JWTAuth\Facades\JWTFactory::factory('exp')->setTTL($myTTL);

        return Tymon\JWTAuth\Facades\JWTAuth::fromUser($user);
    }

	public function getNotInactiveAccountsDetails() {
		$httpHost					= $_SERVER['HTTP_HOST'];
		$subDomainArr				= explode('.', $httpHost);
		$subDomain					= $subDomainArr[0];

		$accountID		= 0;
		$account 		= Account::where('pportal_subdomain', $subDomain)->where('status','!=', 'inactive')->first();

		if ( $account ) {
			$accountID 		= $account->id;
		}

		return $accountID;
	}

    public function validateData($validator, Array $rule, Array $message = [])
    {
        $validator = Validator::make($validator, $rule, $message);
        if ($validator->fails()) {
            $errors = $validator->errors()->messages();
            if (!empty($errors)) {
                foreach ($errors as $key => $err) {
                    return $err[0];
                }
            }
        }
        return false;
    }

    public function sendResponse($status, $msg, $data = null, $header = [], $request_type = RequestTypesService::MOBILE_REQUEST_TYPE): \Illuminate\Http\JsonResponse
    {
        $resData = !is_null($data) ? $data : strtotime(date('Y-m-d H:i:s'));
        $resStatus = $status == Response::HTTP_OK ? 'success' : 'error';

        $msg = ucfirst(str_replace("_", " ", $msg));

        if ($request_type == RequestTypesService::WEB_REQUEST_TYPE) {
            $response = response()->json([
                'status' => $resStatus,
                'message' => $msg,
            ]);
        } else {
            $response = response()->json([
                'status' => $resStatus,
                'message' => $msg,
                'data' => $resData
            ], $status);
        }

        if (!empty($header))
            $response->withHeaders($header);
        return $response;
    }


    /* get currency symbol */
    public function getCurrencySymbol($currency_code) {
        $symbol = env("DEFAULT_CURRENCY_SYMBOL");
        $get_symbol = StripeCountry::where('currency_code',strtolower($currency_code))->select('currency_symbol')->first();
        if(!is_null($get_symbol)){
            $symbol = $get_symbol->currency_symbol;
        }
        return $symbol;
    }

    protected function refineDMYDateFormat($date_format, $date){
        if($date_format == 'd/m/Y' || $date_format == 'dd/mm/yyyy') {
            $date = str_replace('/', '-', $date);
        }
        return $date;
    }

    public function switchDatabase($user_db = null,$connection = 'juvly_practice') {
        $db = !is_null($user_db) ? $user_db : env("DB_DATABASE");
        config(['database.connections.' . $connection . '.database' => $db]);
    }

    /* get database date-time in selected datetime format */
    public function getDateTimeFormatValue($value,$date_format,$time_format, $doFormatting = false) {
        $return = "0000-00-00 00:00:00";
        $from_timezone =  env("DEFAULT_TIMEZONE");
        $to_timezone =  env("DEFAULT_TIMEZONE");

        if (!is_null($value) && !empty($value) && $value != '0000-00-00 00:00:00'){
            if(trim(strtolower($from_timezone)) == trim(strtolower($to_timezone))){
                $new_date = $value;
            }else{
                $new_date = ProcedureService::convertTZ($value, $from_timezone, $to_timezone, 'datetime');
            }
            $return	= $new_date;
            if($doFormatting){
                $format_time_format = ($time_format != "h:i A") ? "H:i" : "h:i A";
                //~ $new_date = $value;
                $datetime = $date_format." ".$format_time_format;
                $return = date($datetime,strtotime($new_date));
            }
        }
        return $return;
    }

    protected function validatorFails ($client_rules, $client_array) {
        $validator = Validator::make($client_array, $client_rules);

        if ($validator->fails()) {
            return $validator->getMessageBag();
        }

        return false;
    }

    public function checkS3UrlValid($full_url=null) {
        $file_headers = @get_headers($full_url);
        if($file_headers && strpos($file_headers[0], '200 OK')){
            return true;
        }else{
            return false;
        }
    }
}

