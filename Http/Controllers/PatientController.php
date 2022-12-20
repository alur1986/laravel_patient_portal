<?php

namespace App\Http\Controllers;

use App\Helpers\EmailHelper;
use App\Http\Requests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Auth;
use App\Account;
use App\PatientAccount;
use App\Appointment;
use App\Service;
use App\ServiceQuestionnaire;
use App\User;
use App\MedicalHistory;
use App\AppointmentQuestionnair;
use Validator;
use App\ServiceTreatmentInstruction;
use App\ServicePostTreatmentInstruction;
use App\AppointmentTreatmentInstruction;
use App\AppointmentQuestionnairChoice;
use Intervention\Image\ImageManagerStatic as Image;
use Session;
use DB;
use URL;
use Config;
use App\Patient;
use App\Country;
use View;
use DateTime;
use DateTimeZone;
use App\AccountPrefrence;
use App\PatientMembershipSubscription;
use App\MonthlyMembershipInvoice;
use App\Clinic;
use App\Users;
use App\ConvertAttributesTrait;
use App\MembershipTier;
use App\MembershipAgreement;
use App\AppointmentHealthtimelineQuestionnaire;
use App\AppointmentHealthtimelineAnswer;
use App\ProcedureTemplate;
use App\ProcedureTemplateQuestion;
use App\ProcedureTemplateQuestionOption;
use App\ProcedureTemplateLogic;
use App\AppointmentHealthtimelineConsent;
use App\ServiceConsent;
use App\Consent;
use App\ProcedureHealthtimelineConsent;
use App\Procedure;

class PatientController extends Controller
{
	use ConvertAttributesTrait;
    /**
     * Create a new controller instance.
     *
     * @return void
     */

    public function showRegistrationForm(Request $request)
    {
$user_data = $request->session()->get('logged_in_patient');
		$key = null;
        $hostarray = explode('.', $_SERVER['HTTP_HOST']);
        $subdomain = $hostarray[0];
        $account = Account:: where('pportal_subdomain', $subdomain)->first();
        if (count((array)$account) > 0) {

            $accountPrefre = AccountPrefrence::where('account_id', $account->id)->first();
            if (!$accountPrefre->patient_sign_up) {
                return Redirect::to('login');
            }

            $request->session()->put('account_detail', $account);
            $logourl = $this->setAccountLogo($account);
            $request->session()->put('logourl', $logourl);
            return view('auth.manual_register', compact('user_data', 'key'));

        } else {
            return view('errors.404');
        }
    }

	public function getAccountDetail($request,$key)
    {
        $account_id = $key['account_id'];
        $account_detail = Account::where('id', $account_id)->first();
        if (count((array)$account_detail) > 0) {

            $request->session()->put('account_detail', $account_detail);
            $logourl = $this->setAccountLogo($account_detail);

            $request->session()->put('logourl', $logourl);

            config(['database.connections.juvly_practice.database' => $account_detail->database_name]);

            $patient = Patient:: where('id', $patient_id)->where('status', 0)->first();

            return $patient;

        }
    }

    public function register(Request $request)
    {

		$input 				= $request->input();
		//echo "<pre>"; print_r($input); die;
		$account_detail = session()->get('account_detail');

		if ( null != $input ) {

			$client_rules = array(
									'firstname' 					=> 'required',
									'lastname' 	 					=> 'required',
									'email'  						=> 'required|email',
								//	'email'  						=> 'required|email',
									'password'  					=> 'required',
									'password_confirmation'  		=> 'required|same:password',
									'phone' 						=> 'required'
									);
			$client_array = array(
									'firstname' 					=> $input['first'],
									'lastname'  					=> $input['last'],
									'email'  						=> $input['username'],
									'password'  					=> $input['password'],
									'password_confirmation'  		=> $input['password_confirmation'],
									'phone' 						=> $input['phone']
									);

			$validator = Validator::make($client_array, $client_rules);

			if ( $validator->fails() ) {
				return Redirect::to('/patient/register')->withInput()->withErrors($validator);
			}

			config(['database.connections.juvly_practice.database'=> $account_detail->database_name]);

			$patient = Patient :: where('firstname',$input['first'])->where('email',$input['username'])->first();

            if (!$patient) {
                $patient = new Patient();
                $patient->user_id = 0;
                $patient->firstname = htmlentities(addslashes(trim(preg_replace('/\s+/', ' ', $input['first']))), ENT_QUOTES, 'utf-8');
                $patient->lastname = htmlentities(addslashes(trim(preg_replace('/\s+/', ' ', $input['last']))), ENT_QUOTES, 'utf-8');
                $patient->email = $input['username'];
                $patient->gender = 2;
                $patient->phoneNumber = $input['full_number'];
                $patient->status = 1;
                $patient->access_portal = 1;
                $patient->save();
                $patient_id = $patient->id;
            } else {
                $patient->access_portal = 1;
                $patient->save();
                $patient_id = $patient->id;

            }
            if ($patient->gender == null) {
                $gender = 0;
            } else {
                $gender = $patient->gender;
            }
            $patient_user = new User;
            $patient_user->firstname = $input['first'];
            $patient_user->lastname = $input['last'];
            $patient_user->email = $input['username'];
            $patient_user->password = bcrypt($input['password']);
            $patient_user->status = 1;
            $patient_user->phone = $input['full_number'];
            $patient_user->address = $patient->address_line_1;
            $patient_user->city = $patient->city;
            $patient_user->state = $patient->state;
            $patient_user->pincode = $patient->pincode;
            $patient_user->country = $patient->country;
            $patient_user->date_of_birth = $patient->date_of_birth;
            $patient_user->gender = $gender;

				$image = $this->getImage($patient,$account_detail,$patient_user->id);

				$patient_user->profile_pic 		= $image;

				if($patient_user->save()) {

				$patient_account 					= new PatientAccount ;
				$patient_account->patient_id 		= $patient_id;
				$patient_account->patient_user_id 	= $patient_user->id ;
				$patient_account->account_id        = $account_detail->id ;
				if($patient_account->save()){

					$email = $patient->email;
					//~ Auth::login($patient_user);
					//~ return redirect($this->redirectPath());
					$response = app('App\Http\Controllers\PatientController')->sendActivationLink($request, $patient_user->id, $email);
					session()->put('account_detail',$account_detail);
					$logourl 	= $this->setAccountLogo($account_detail);
					session()->put('logourl',$logourl);
					if(session()->has('patient_register_email')){
						session()->forget('patient_register_email');
					}
					return Redirect::to('/register/success');
				} else {
					session()->put('error', 'Something went Wrong,Please try Again');
					return Redirect::to('/patient/register');
				}
				} else {
					session()->put('error', 'Something went Wrong,Please try Again');
					return Redirect::to('/patient/register');
				}
		}

    }

    public function getImage($patient, $account_detail, $user_id)
    {
        	$url = rtrim(config('constants.urls.media.bucket') , '/'). "/{$account_detail->storage_folder }/patientpics/{ $patient->user_image}";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.1 Safari/537.11');
        $res = curl_exec($ch);
        $rescode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($rescode == 200) {
            $destinationPath = public_path('uploads/profiles');
            $imageArray = explode('.', $patient->user_image);
            $imageLastIndex = count($imageArray) - 1;
            $image_extension = $imageArray[$imageLastIndex];
            $imagename = $user_id . strtotime(date('Y-m-d H:i:s')) . '.' . strtolower($image_extension);
            $imagewithpath = $destinationPath . "/" . $imagename;
            file_put_contents($imagewithpath, $res);
            return $imagename;
        } else {
            return $imagename = '';
        }

    }

    public function sendActivationLink($request, $patient_user_id, $email)
    {
        $input = $request->input();
        $account = $request->session()->get('account_detail');
        //$email				= trim($input['email']);
        $business_name = $account->name;
        $sender = EmailHelper::getSenderEmail();
        $activation_key = $this->generateRandomString(30);
        DB::table('patient_users')->where('id', $patient_user_id)->update(['activation_key' => $activation_key]);
        config(['database.connections.juvly_practice.database' => $account->database_name]);
        $url = URL::to('/') . '/activate' . '/' . $activation_key;
        $clinic = Clinic::where('status', 0)->first();
        $noReply = getenv('MAIL_FROM_EMAIL');
        $subject = "You're almost there! Just confirm your email address.";
        $mail_body = "<p>Thank you for signing up for our Patient Portal. Please click below to verify your registration. <a href='" . $url . "'> <br><br> Activate Your Account</a> <br><br> Once you have done this, you will be able to login and complete any paperwork required before your appointment. See you soon! <br><br> If for any reason you are unable to click above, you can paste the link below in your browser: $url
		  </p>";
		$email_content 		= $this->getEmailTemplate($mail_body, $account, $clinic, $subject);

		$response 			= $this->sendEmail($noReply, $email, $sender, $email_content, $subject, false);

		if ($response) {
			$json = array("status" => 200, "error" => false, "message" => "OTP Sent");
		} else {
			$json = array("status" => 200, "error" => true, "message" => "We are unable to send OTP to your email ID");
		}

		return $json;
	}

	public function  generateRandomString($length = 9) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
			$randomString .= $characters[rand(0, $charactersLength - 1)];
		}
		return $randomString;
	}

	public function activatePatient(Request $request, $key)
    {
        $input = $request->input();
        $patient_user = User::where('activation_key', $key)->with('patientAccount')->first();

        if ($patient_user) {
            $patient_user_id = $patient_user->id;

            if ($patient_user->patientAccount) {
                $patient_id = $patient_user->patientAccount->patient_id;
                $account_id = $patient_user->patientAccount->account_id;

                $account = Account:: where('id', $account_id)->first();

                if ($account) {
                    config(['database.connections.juvly_practice.database' => $account->database_name]);

                    $patient = Patient::where('id', $patient_id)->update(array('status' => 0));

                    config(['database.connections.juvly_practice.database' => config('database.default_database_name')]);
                }

                $patient_user->status = 0;
                $patient_user->save();
                PatientAccount::where('patient_id', $patient_id)->where('account_id', $account_id)->where('patient_user_id', '!=', $patient_user_id)->delete();
            }
            Session::put('success', 'Your patient account has activated successfully, please login to continue.');
            return Redirect::to('/');
        } else {
            Session::put('error', 'Wrong activation link or it has been expired');
            return Redirect::to('/');
        }
    }

    public function registerSuccess(Request $request)
    {
        $email = Session::get('patient_register_email');
        return view('auth.thankyou')->with('email', $email);
    }

    public function sendPatientActivationLink($request, $patient_user_id, $email)
    {
        $input = $request->input();
        $account = $request->session()->get('account_detail');
        //$email				= trim($input['email']);
        $business_name = $account->name;

        $sender = EmailHelper::getSenderEmail();
        $activation_key = $this->generateRandomString(30);
        DB::table('patient_users')->where('id', $patient_user_id)->update(['activation_key' => $activation_key]);
        config(['database.connections.juvly_practice.database' => $account->database_name]);
        $url = URL::to('/') . '/activate' . '/' . $activation_key;
        $clinic = Clinic::where('status', 0)->first();
        $noReply = config('mail.from.address');
        $subject = "You're almost there! Just confirm your email address.";
        $mail_body = "<p>Thank you for signing up for our Patient Portal. Please click below to verify your registration. <a href='" . $url . "'> <br><br> Activate Your Account</a> <br><br> Once you have done this, you will be able to login and complete any paperwork required before your appointment. See you soon! <br><br> If for any reason you are unable to click above, you can paste the link below in your browser: $url
		  </p>";
        $email_content = EmailHelper::getEmailTemplate($mail_body, $account, $clinic, $subject);

        $response = EmailHelper::sendEmail($noReply, $email, $sender, $email_content, $subject, false);

		if ($response) {
			$json = array("status" => 200, "error" => false, "message" => "OTP Sent");
		} else {
			$json = array("status" => 200, "error" => true, "message" => "We are unable to send OTP to your email ID");
		}

		return $json;

		return true;
	}

	public function checkEmailAtNewSignup(Request $request)
	{
		$input = $request->input();
		$email = $input['email'];
		if($this->checkJuvlyDomainName()) {
            $account_id = $this->getAccountsDetails();
        } else {
            $account_id = $this->getNotInactiveAccountsDetails();
        }
        $user = User :: where('email',$email)->whereHas('patientAccount',function($q) use($account_id){
            $q->where('account_id', $account_id);
        })->first();

        echo json_encode(!$user);
    }

    public function verifyNumber(Request $request)
    {
        $input = $request->input();
        $number = $input['number'];
        $response = $this->verifyTwilioNumber($number, array(), 'true');

        if ($response && isset($response->sid) && $response->status == "pending") {
            $json = array("status" => 200, "canShowOTPSection" => true, "message" => "Open OTP section");
        } else if (is_object($response) && ($response->status != "pending")) {
            $json = array("status" => 200, "canShowOTPSection" => false, "message" => 'Error : ' . $response->message);
        } else {
            $json = array("status" => 200, "canShowOTPSection" => false, "message" => 'Unable to send SMS at this time, please try again later');
        }

        return response()->json($json);
    }

    public function verifyOTP(Request $request)
    {
        $input = $request->input();
        $number = $input['number'];
        $otp = $input['otp'];

        $response = $this->verifyTwilioOTP($number, $otp, 'true');

        if ($response && isset($response->status) && $response->status == "approved") {
            $json = array("status" => 200, "error" => false, "message" => "The OTP entered is correct");
        } else {
            $json = array("status" => 200, "error" => true, "message" => "The OTP entered is incorrect");
        }

        return response()->json($json);
    }

    public function resendOTP(Request $request)
    {
        $input = $request->input();
        $number = $input['number'];

        $response = $this->verifyTwilioNumber($number, array(), 'true');

        if ($response && isset($response->sid) && $response->status == "pending") {
            $json = array("status" => 200, "error" => false, "message" => "OTP sent again");
        } else if (is_object($response) && ($response->status != "pending")) {
            $json = array("status" => 200, "canShowOTPSection" => false, "message" => 'Error : ' . $response->message);
        } else {
            $json = array("status" => 200, "canShowOTPSection" => false, "message" => 'Unable to send SMS at this time, please try again later');
        }

        return response()->json($json);
    }
}
