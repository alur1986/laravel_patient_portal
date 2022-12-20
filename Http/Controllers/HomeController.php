<?php

namespace App\Http\Controllers;

use App\Consultationlist;
use App\Http\Requests;
use App\Question;
use Carbon\Carbon;
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

class HomeController extends Controller
{
	use ConvertAttributesTrait;
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(Request $request)
    {
		$referer	= $request->headers->get('referer');
		if ( $referer ) {
			if (strpos($referer, 'password/reset')) {
				Session::put('success', 'Your password has been updated, please login to continue');
				Auth::logout();
			}
		}
        $this->middleware('auth');
        $user = Auth::user();

        if ($user) {
            $request->session()->put('user', $user);
        }
		$this->checkAccountStatus($request);
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        return redirect('/dashboard');
    }
    
    public function dashboard(Request $request)
    {
		$user = Auth::user();	
		$address = array();
		
		if(!empty($user->address))	{
			$address[] = $user->address;
		}
		if(!empty($user->city)) {
			$address[] = $user->city;
		}
		if(!empty($user->state)) {
			$address[] = $user->state;
		}
		if(!empty($user->country)) {

			$country =  Country :: where('country_code',$user->country)->pluck('country_name')->first();
			if($country) {
				$address[] = $country;
			}
		}
		if(!empty($user->pincode)) {
			$address[] = $user->pincode;
		}
		
		$complete_address = implode(', ',$address);
		//~ $patient_account =	PatientAccount::where('patient_user_id', $user->id)->first();
		//~ $account_detail  =	Account::where('id', $patient_account->account_id)->first();
		
		$account_detail 	= 	$request->session()->get('account_detail');
		$account_pref 		= AccountPrefrence::where('account_id',$account_detail->id)->first();
		$patient_account 	=	PatientAccount::where('patient_user_id', $user->id)->where('account_id',$account_detail->id)->first();

		if(!$account_detail || !$account_detail) {
			return Redirect::to('/dashboard');
		}
			
		$request->session()->put('database',$account_detail->database_name);
		$request->session()->put('patient_id',$patient_account->patient_id);
		
		config(['database.connections.juvly_practice.database'=> $account_detail->database_name]);
		$patent_detail = Patient::where('id',$patient_account->patient_id)->first();
		$request->session()->put('logged_in_patient',$patent_detail);
		if($request->input() != null && $request->input('filter_value') == 'date') {

			$filter_value 		= 	'date';
		} else {
			$filter_value 		= 	'status';

		}
		
		$appointments 		= 	Appointment :: with('users','clinic','appointment_booking','services','appointment_sevices.service')->where('patient_id',$patient_account->patient_id)->orderBy('appointment_datetime','DESC')->get();
	
		$services = array();
		$post_instruction_services = array();
		$action_count = 0;
		$patient_id 	= $request->session()->get('patient_id');
		if(count((array)$appointments)>0){
			$upcoming_array 		= array();
			$past_appointment_array = array();
			$cancel_array 			= array();
			$noshow_array 			= array();
			$order_by_date			= array();
			
			foreach($appointments as $row => $appointment) {
				$clinicTimeZone			= isset($appointment->clinic->timezone) ? $appointment->clinic->timezone : 'America/New_York';
				date_default_timezone_set($clinicTimeZone);
				$todayDateTime 			= new DateTime(date('Y-m-d H:i:s'));
				$todayTimeZone 			= new DateTimeZone($clinicTimeZone);
				$todayDateTime->setTimezone($todayTimeZone);
				$todayInClinicTZ		= $todayDateTime->format('Y-m-d H:i:s');
				
				$appDateTime 			= new DateTime($appointment->appointment_datetime);
				$appTimeZone 			= new DateTimeZone($clinicTimeZone);
				$appDateTime->setTimezone($appTimeZone);
				$appDateTimeInClinicTZ	= $appDateTime->format('Y-m-d H:i:s');
				/*next 2 hours of appointment datetime start*/
				$appDateTimeInClinicTZ2HoursNext	= $appDateTime->add(new \DateInterval('PT2H'));
				$newDate2HourNext	= $appDateTimeInClinicTZ2HoursNext->format('Y-m-d H:i:s');
				/*next 2 hours of appointment datetime end*/
				if(strtotime($appDateTimeInClinicTZ) > strtotime($todayInClinicTZ) && $appointment->status == 'booked' ){
					$status 		= "Upcoming";	
					$status_class 	= "";	
				} else if($appointment->status == 'canceled') {
					$status 		= "Canceled";
					$status_class 	= "gray";
				} else if($appointment->status == 'noshow'){
					$status 		= "No Show";
					$status_class 	= "sky-blue";
				} else {
					$status 		= "Past Appointment";
					$status_class 	= "green";
				}
				$appointments[$row]->appointment_status 		= $status;
				$appointments[$row]->appointment_status_class	= $status_class;
									
				//if(strtotime($appDateTimeInClinicTZ) > strtotime($todayInClinicTZ) && $appointment->status == 'booked' ) {
				if($appointment->status == 'booked' ) {

                    if($appointment->services) {
						foreach($appointment->services as $service) {
                            $service = clone $service;
							//~ if(strtotime($appDateTimeInClinicTZ) > strtotime($todayInClinicTZ)) {
							if(strtotime($newDate2HourNext) > strtotime($todayInClinicTZ)) {

								$service->appointment_id  		=   $appointment->id;
								$service->appointment_datetime  =   $appointment->appointment_datetime;
								$service_questionair 			= 	ServiceQuestionnaire :: with('questionnaires','procedureTemplate')->where('service_id',$service->id)->get();
								$service_treatment	 			= 	ServiceTreatmentInstruction :: with('pre_treatment_instructions')->where('service_id',$service->id)->get();
								$service_consents = ServiceConsent::with('consent')->where('service_id',$service->id)->get();

								if(count($service_treatment)>0) {

									$service->treatment_detail = $service_treatment;

									foreach($service->treatment_detail as $key => $val) {

										if($val->pre_treatment_instructions) {

											$val->pre_treatment_instructions->treatment_status   =   AppointmentTreatmentInstruction :: where('appointment_id', $appointment->id)->where('service_id',$service->id)->where('patient_id',$patient_id)->Where('treatment_instruction_id',$val->pre_treatment_instructions->id)->where('status',1)->count();
											$action_count = ($val->pre_treatment_instructions->treatment_status == 0) ? $action_count+1 : $action_count;
											//$action_count = 0;

										}
									}

								} else {
									$service->treatment_detail = array();
								}

								if(count($service_questionair) > 0) {

									$service->questionnair_detail = $service_questionair;

									foreach($service->questionnair_detail as $key => $val) {

										//if(count($val->questionnaires) > 0) {
											if($val->type == 'questionnaire' && $val->questionnaires){
                                                $questions = Question::where('consultation_id', $val->questionnaires->id)
                                                    ->where('status', 0)
                                                    ->get()
                                                    ->toArray();

                                                if (!$questions) {
                                                    unset($service->questionnair_detail[$key]);
                                                    continue;
                                                }

												$appointment_questionnair_records   =   AppointmentQuestionnair :: where('appointment_id', $appointment->id)->where('service_id',$service->id)->where('patient_id',$patient_id)->where('consultation_id',$val->questionnaires->id)->groupBy('consultation_id')->count();

												$appointment_questionnair_choice_records = AppointmentQuestionnairChoice :: where('consultation_id',$val->questionnaires->id)->where('appointment_id',$appointment->id)->where('service_id',$service->id)->where('patient_id',$patient_id)->groupBy('consultation_id')->count();

												$val->questionnaires->questionnair_status = $appointment_questionnair_records + $appointment_questionnair_choice_records;
												$action_count = ($val->questionnaires->questionnair_status == 0) ? $action_count+1 : $action_count;
											}
											elseif($val->type == 'template' && count($val->procedureTemplate) > 0)
											{
												//echo "<pre>"; print_r($val); die;
												$val->procedureTemplate->questionnair_status = AppointmentHealthtimelineQuestionnaire::where('procedure_template_id',$val->questionnaire_id)->where('appointment_id',$appointment->id)->count();
												$action_count = ($val->procedureTemplate->questionnair_status == 0) ? $action_count+1 : $action_count;
											}
										//}
									}


								} else {
									$service->questionnair_detail = array();
								}

								if(!empty($service->questionnair_detail)){
									$service_questionair_array = $service->questionnair_detail->toArray();

									usort($service_questionair_array, function ($a, $b) {
									  return strcmp($a['questionnaires']['order_by'], $b['questionnaires']['order_by']);
									});

									$service->questionnair_detail = $service_questionair_array;
								}
								/*this section is for pre treatment instruction and questionaries*/

								/*consent section*/
								//if($appointment->appointment_type == 'virtual'){
									if(count($service_consents) >0){
										$service->consents_details = $service_consents;
										//echo "<pre>"; print_r($service->consents_details); die;
										foreach($service->consents_details  as $val)
										{
											if($val->consent){
												$val->consent->consent_status = AppointmentHealthtimelineConsent::where('consent_id',$val->consent_id)->where('appointment_id',$appointment->id)->count();
												$action_count = ($val->consent->consent_status == 0) ? $action_count+1 : $action_count;
											}
										}
									}else{
										$service->consents_details = array();
									}
								//}
								/*End consent section*/
                                $services[] = $service;
							}
							//$postTeatmentInsId	= 0;
							//$sserviceIde		= $service->id;
							if(strtotime($appDateTimeInClinicTZ) < strtotime($todayInClinicTZ)) {
								$post_service_treatment	 		= 	ServicePostTreatmentInstruction :: with('post_treatment_instructions')->where('service_id',$service->id)->get();

									if(count((array)$post_service_treatment)>0) {
										$servicePost['post_treatment_detail'] = $post_service_treatment;
										$servicePost['service_name'] = $service->name;
										$servicePost['appointment_datetime'] = $appointment->appointment_datetime;
										$servicePost['appointment_id'] = $appointment->id;
										//$postTeatmentInsId				= $post_service_treatment[0]->post_treatment_instructions->id;
									} else {
										$servicePost['post_treatment_detail'] = array();
										$servicePost['service_name'] =null;
										$servicePost['appointment_datetime'] =null;
										$servicePost['appointment_id'] = null;
									}
									//$post_instruction_services[$postTeatmentInsId.'-'.$sserviceIde] = $servicePost;/* for unique instruction and service*/	
									$post_instruction_services[] = $servicePost;/* for unique instruction and service*/	
								/*this section is for post treatment instruction*/
							}
							
							$services[] = $service;	
						}
					}			
				}
				if($filter_value == 'status') {
					if(strtotime($appDateTimeInClinicTZ) > strtotime($todayInClinicTZ) && $appointment->status == 'booked' ){
						$upcoming_array[] 		= $appointment;
					} else if($appointment->status == 'canceled') {
						$cancel_array[] 			= $appointment;
					} else if($appointment->status == 'noshow'){
						$noshow_array[] 			= $appointment;
					} else {
						$past_appointment_array[] = $appointment;
					}
				} else {
					
					$order_by_date[] = $appointment;
				}
			}
			$appointments = array();
			if($filter_value == 'status') {
				
				$appointments = array_merge($upcoming_array,$past_appointment_array,$noshow_array,$cancel_array);
			} else {
				$appointments = $order_by_date;
				
			}
		}

		$patient_user = PatientAccount::with('Patient')->where('patient_id', $patient_id)->first();
		if( null != $patient_user['patient_id'] && !empty($patient_user['Patient']) ){
			
			$membership_tiers_count = MembershipTier::where('status',0)->where('active',0)->count();
			$patientMembershipQuery = PatientMembershipSubscription::where('patient_id',$patient_id);
			$patientMembershipData = $patientMembershipQuery->get();
			if($account_pref->membership_tier == 'multiple'){
				$patientMultiMembershipsCount = $patientMembershipQuery->where('membership_tier_id','!=',0)->count();
				if($patientMultiMembershipsCount == $membership_tiers_count){
					#it means you can not create further membership
					$request->session()->put('patient_is_monthly_membership',1);
				}else{
					$request->session()->put('patient_is_monthly_membership',0);
				}
			}else{
				$patientSingleMembership = $patientMembershipQuery->where('membership_tier_id','=',0)->count();
				if($patientSingleMembership){
					#it means you can not create further membership
					$request->session()->put('patient_is_monthly_membership',1);
				}else{
					$request->session()->put('patient_is_monthly_membership',0);
				}
			}
			
		}else{
			$request->session()->put('patient_is_monthly_membership',0);
			$patientMembershipData = [];
		}
		
		$this->setSessionAppointmentSettingForPatient($request);
		if($request->ajax()){
			return view('app.ajax_dashboard',compact('post_instruction_services','appointments','services','action_count','complete_address','filter_value','account_pref','patientMembershipData'))->render();
		} else {
		
		//	echo "<pre>"; print_r($appointments); die("hbjke");
			return view('app.dashboard',compact('post_instruction_services','appointments','services','action_count','complete_address','filter_value','account_pref','patientMembershipData'));
		}
				
	}
	
	
	public function editProfile(Request $request)
	{
		
		$user = Auth::user();
	
		if($user->id){
			
			$user_data	= User :: where('id',$user->id)->first();
			$account_detail 	= $request->session()->get('account_detail');
			$patient_id 		= $request->session()->get('patient_id');
			
			if($request->input()) {
				
				if($request->input('task') == 'update_profile') {
				
					$client_rules = array(
					'firstname' 		=> 'required',
					'lastname' 	 		=> 'required',
                    'email'  			=> 'required|email',
                    'full_number' 	 	=> ['phone:AUTO', 'regex:'. config('app.validations.phone_number')],
					);
					$client_array = array(
					'firstname' 		=> $request->input('firstname'),
					'lastname'  		=> $request->input('lastname'),
                    'email'  			=> $request->input('email'),
                    'full_number'  			=> $request->input('full_number')
					);

					$validator = Validator::make($client_array, $client_rules);
					
					if ( $validator->fails() ) {
					    $messages = [];
                        foreach ($validator->messages()->all() as $message)
                        {
                            array_push($messages, $message);
                        }
						$response_array = array(
						    'status'=>'error',
                            'messages'=> $messages
                        );

						return json_encode($response_array);
					}

					if(!empty($request->input('date_of_birth'))){
                        $dateOfBirth = Carbon::parse($request->input('date_of_birth'))->format('Y-m-d');
					}else{
						$dateOfBirth = '';
					}

					$user_data->firstname		= @$request->input('firstname');
					$user_data->lastname 		= @$request->input('lastname');
                    $user_data->email 			= @$request->input('email');
					$user_data->address  		= @$request->input('address');
					$user_data->city  			= @$request->input('city');
					$user_data->state  			= @$request->input('state');
					$user_data->pincode  		= @$request->input('pincode');
					$user_data->country  		= @$request->input('country');
					$user_data->phone	        = @$request->input('full_number');
					$user_data->gender	        = @$request->input('gender');
					$user_data->date_of_birth 	= @$dateOfBirth;
					$profile_image 				= @$request->input('hidden_user_image');
					
					if ( !empty($profile_image) ) {
						$user_data->profile_pic = $profile_image;
					}
					if ( $user_data->save() ) {

						config(['database.connections.juvly_practice.database'=> $account_detail->database_name]);
						
						$patient 					= Patient :: where('id',$patient_id)->first();
						if(count($patient)>0){

							$patient->firstname			= @$request->input('firstname');
							$patient->lastname 			= @$request->input('lastname');
                            $patient->email 			= @$request->input('email');
							$patient->phoneNumber  		= @$request->input('full_number');
							$patient->address_line_1  	= @$request->input('address');
							$patient->city  			= @$request->input('city');
							$patient->state  			= @$request->input('state');
							$patient->country  			= @$request->input('country');
							$patient->pincode  			= @$request->input('pincode');

							$patient->gender  			= @$request->input('gender');

							$patient->date_of_birth 	= !empty($request->input('date_of_birth')) ? date('Y-m-d',strtotime($request->input('date_of_birth')) ) : "";
							$patient->modified 			= date("Y-m-d");
							if($patient->save()){
								
								
								$response_array = array('status'=>'success','message'=>'Profile updated successfully');

							} else {
								$response_array = array('status'=>'error','message'=>'Error occur while completing the request. Try after some time');
							}
						}
					
					} else {
						$response_array = array('status'=>'error','message'=>'Error occur while completing the request. Try after some time');

					}
					
					$response_array = array('status'=>'success','message'=>'Profile updated successfully');
				
				} else {
					$response_array = $this->changePassword($request);
				}
					return json_encode($response_array);		
			}

            $account_pref = AccountPrefrence::where('account_id', $account_detail->id)->first();
            $date_format = $account_pref->date_format;
            $countries_object = Country:: all();
            $countries = $countries_object->toArray();
			config(['database.connections.juvly_practice.database'=> $account_detail->database_name]);
			$patient = Patient :: where('id',$patient_id)->first();
			$request->session()->put('logged_in_patient',$patient);
			return view('app.profile.ajax.edit_profile_popup',compact('user_data','countries','patient','date_format'))->render();
		}
	}
	
	public function changePassword($request)
	{
		$user = Auth::user();
		
		if($user->id){
			
			if($request->input()) {
				
				$client_rules = array(
				'old_password' 		=> 'required',
				'new_password' 	 	=> 'required',
				'confirm_password'  => 'required'
				);
			
				$client_array = array(
					'old_password' 		    => $request->input('old_password'),
					'new_password'  		=> $request->input('new_password'),
					'confirm_password'  	=> $request->input('confirm_password')
				);

			$validator = Validator::make($client_array, $client_rules);
			
			if ( $validator->fails() ) {
					
				$response_array = array('status'=>'error','message'=>'Something went wrong');
				return $response_array;
			}
				
				$user_data	= User :: where('id',$user->id)->first();
				
				$current_pass	 = $request->input('old_password');
				$new_pass		 = $request->input('new_password');
				$confirm_pass	 = $request->input('confirm_password');
				
			
				if(Hash::check($current_pass, $user_data->password) &&  $new_pass ==  $confirm_pass){
					if($current_pass == $new_pass ) {
						
						$response_array = array('status'=>'error','message'=>'Password must differ from old password');	
					
					} else {
					
						$user_data->password = bcrypt($new_pass);
						if($user_data->save()) {
							$response_array = array('status'=>'success','message'=>'Password has been updated successfully');

						} else {
							$response_array = array('status'=>'error','message'=>'Something went wrong');

						}
					}
					
				} else {
					$response_array = array('status'=>'error','message'=>'Current password is not correct');
				
				}
				
				return $response_array;
				//return json_encode($response_array);
			
			} else {
				
				return view('app.profile.ajax.change_password_popup')->render();

			}
						
		}
	
	}
	
	public function getMedicalHistory(Request $request)
	{
		
		$database_name 	= $request->session()->get('database');
		$patient_id 	= $request->session()->get('patient_id');
	
		config(['database.connections.juvly_practice.database'=> $database_name]);
		
		$medical_history =	MedicalHistory::where('patient_id', $patient_id)->first();
		
		if($request->input()) {
			
			if ( $medical_history ) {
				 $medical_history->patient_id 					= $patient_id;
				 $medical_history->major_events        			= $request->input('major_events');
				 $medical_history->smoking_status				= $request->input('smoking_status');
				 $medical_history->ongoing_medical_problems     = $request->input('ongoing_medical_problems');
				 $medical_history->social_history  				= $request->input('social_history');
				 $medical_history->family_health_history 		= $request->input('family_health_history');
				 $medical_history->nutrition_history 			= $request->input('nutrition_history');
				 $medical_history->preventive_care 				= $request->input('preventive_care');
				 $medical_history->development_history 	 		= $request->input('development_history');
				 $medical_history->drug_allergies				= $request->input('drug_allergies');
				 $medical_history->environmental_allergies		= $request->input('environmental_allergies');
				 $medical_history->food_allergies 			 	= $request->input('food_allergies');
				 if($medical_history->save()){
					
					$response_array = array('status'=>'success','message'=>'Medical history has been updated successfully');

				 }	else {
					$response_array = array('status'=>'error','message'=>'Something went wrong');
				 }
			} else {
				 $medical_history 								= new MedicalHistory();
				 $medical_history->patient_id 					= $patient_id;
				 $medical_history->major_events        			= $request->input('major_events');
				 $medical_history->smoking_status				= $request->input('smoking_status');
				 $medical_history->ongoing_medical_problems     = $request->input('ongoing_medical_problems');
				 $medical_history->social_history  				= $request->input('social_history');
				 $medical_history->family_health_history 		= $request->input('family_health_history');
				 $medical_history->nutrition_history 			= $request->input('nutrition_history');
				 $medical_history->preventive_care 				= $request->input('preventive_care');
				 $medical_history->development_history 	 		= $request->input('development_history');
				 $medical_history->drug_allergies				= $request->input('drug_allergies');
				 $medical_history->environmental_allergies		= $request->input('environmental_allergies');
				 $medical_history->food_allergies 			 	= $request->input('food_allergies');

				 if($medical_history->save()) { 
					$response_array = array('status'=>'success','message'=>'Medical history has been added successfully');
				} else {
					$response_array = array('status'=>'error','message'=>'Something went wrong');

				}
			}
				
			return json_encode($response_array);
			
		} else {
			return view('app.medical_history.ajax.medical_history_popup',compact('medical_history'))->render();
		}

	}
	
	
	public function imageUpload(Request $request, $internal =false)
    {
        $file 		= $request->file('file');
        $response 	= array('status'=>'error','data'=>'');
        $uploadImage = "";
        if ( !empty($file) && !empty($file->getClientOriginalName()) ) {
            $orignalName             = $file->getClientOriginalName();
            $imageRealPath  		 = $file->getRealPath();
            $imageArray              = explode('.', $orignalName);
            $uploadImage             = strtotime(date('Y-m-d H:i:s')).'_'.trim( str_replace(" ","_",$orignalName ));
            $destinationPath         = public_path('uploads/profiles');
            $image = Image::make($imageRealPath)->resize(300, null, function ($constraint) {
							$constraint->aspectRatio();
						})->orientate()->save($destinationPath. '/'. $uploadImage);
 
 
            $response['status'] = "success";
            $response['data'] = $uploadImage;
            if($internal){
				$response['destinationPath'] = $destinationPath. '/'. $uploadImage;
				return $response;
			}
        }
        return json_encode($response);
        exit;
    }

    public function getDateFormat($format_input){
		switch ($format_input){
			case 'mm/dd/yyyy':
				$format = 'm/d/Y';
				break;
			case 'dd/mm/yyyy':
				$format = 'd/m/Y';
				break;
			case 'yyyy/mm/dd':
				$format = 'Y/m/d';
				break;
			default:
				$format = 'm/d/Y';
		}
		return $format;
	}
	
	public function notFoundPage(){
		return view('errors.404_custom');
	}

    public function showMembershipDetails(Request $request)
    {
		$database_name	= $request->session()->get('database');
		$patient_id 	= $request->session()->get('patient_id');
		$account_detail = $request->session()->get('account_detail');
		config(['database.connections.juvly_practice.database'=> $database_name]);
		$account_pref = AccountPrefrence::where('account_id',$account_detail->id)->first();
		#patient memberships STARTED
		$membershipSubscription = PatientMembershipSubscription::where('patient_id', $patient_id)->with(['membershipTier','invoice'])->get();
		//echo "<pre>"; print_r($membershipSubscription); die;
		$patient_details = Patient::where('id', $patient_id)->first();
		$membership_benefits = $patient_details->membership_benefits_this_year;

		//Convert MemberShip Start Time In Clinic TZ
		$clinic = Clinic::where('status',0)->first();
		$userId = $request->session()->get('account_detail')->admin_id;
		if( !empty($userId) ){
			$clinicId = Users::find($userId)->clinic_id;
			if( !empty($clinicId) ){
				$clinic = Clinic::where('status',0)->where('id',$clinicId)->first();
			}
		}
		foreach($membershipSubscription as $key=> $memberSubscription){
			$date = new DateTime($memberSubscription->subscription_started_at, new DateTimeZone("America/New_York"));
			$date->setTimezone(new DateTimeZone($clinic->timezone));
			
			$membershipSubscription[$key]->subscription_start_inTZ	= self::convertDateFormat($date->format('Y-m-d'));
			//$membershipSubscription->terms_accepted_on_inTZ 	= self::convertDateTimeFormat($date->format('Y-m-d H:i:s'));
			$agreement_signed_date = $memberSubscription->agreement_signed_date;
			$membershipSubscription[$key]->terms_accepted_on_inTZ 	= self::convertDateFormat($agreement_signed_date,$date->format('Y-m-d H:i:s'));
			
			//~ $applied_coupon = null;
			//~ $total_discount = 0;
			//~ $mothly_membership_fees = $membershipSubscription->mothly_membership_fees;
			//~ if($membershipSubscription->coupon_code && $membershipSubscription->discount_value > 0 ){
				//~ $get_discounted_amount 	= $this->getDiscountedAmount($membershipSubscription);
				//~ $applied_coupon =  $membershipSubscription->coupon_code;
				//~ $total_discount =  $get_discounted_amount['total_discount'];
				//~ //$mothly_membership_fees = getFormattedCurrency($get_discounted_amount['mothly_membership_fees']);
			//~ }
			//~ $membership_tier = null;
			//~ if($membershipSubscription->membership_tier_id > 0){
				//~ $membership_tier = MembershipTier::where('id',$membershipSubscription->membership_tier_id)->first();
			//~ }
			#patient memberships END
		}
		# membership invoices started
		$membershipInvoics = MonthlyMembershipInvoice::where('patient_id', $patient_id)
								->whereNotIn('payment_status', ['pending'])
									->orderBy('id','desc')
										->get()
											->map(function ($items) {
												if($items->payment_status == 'past_due'){
													$items->payment_status = 'Pending';
												} else {
													$items->payment_status = ucfirst($items->payment_status);
												}
												return $items;
											});
		# membership invoices end
		if($membershipSubscription->count() < 1){
			return view('errors.404');
		}
		//echo "<pre>"; print_r($membershipSubscription); die;
		//return response($membershipSubscription->toJson())->header('Content-Type', 'application/json');
		
		//~ return view('app.membership_details.membership_detail_popup', compact('membershipSubscription','membershipInvoics','membership_benefits','applied_coupon','total_discount','mothly_membership_fees','membership_tier'))->render();
		
		return view('app.membership_details.membership_detail_popup', compact('membershipSubscription','membershipInvoics','membership_benefits','account_pref'))->render();
	}
	
	private function getDiscountedAmount($patient_membership_data){
		$mothly_membership_fees = $patient_membership_data->mothly_membership_fees;
		$one_time_setup_fees 	= $patient_membership_data->one_time_membership_setup;
		if($patient_membership_data->discount_type == 'percentage'){
			 $discount_percent = $patient_membership_data->discount_value;
			 $total_discount   = ( $mothly_membership_fees * $discount_percent ) / 100;
				
		}elseif($patient_membership_data->discount_type == 'dollar') {
			 $total_discount = $patient_membership_data->discount_value;
			  if( $total_discount >= $mothly_membership_fees ){
				$total_discount = $mothly_membership_fees; /*if total_discount is greater than mothly_membership_fees then giving the full discount = mothly_membership_fees */ 
			  }
			
		}else {
		  /*this section run when user entered trial coupon*/
			 $response['mothly_membership_fees'] = 0;
			 $response['total_discount'] = 0;
			 return $response;	
		}
		$response['mothly_membership_fees'] = $mothly_membership_fees - $total_discount;
		$response['total_discount'] = $total_discount;
		return $response;
	}
	
	public function downloadAgreementOld(Request $request, $agreement_id = 0 )
    {
		$database_name	= $request->session()->get('database');
		$patient_id 	= $request->session()->get('patient_id');
		config(['database.connections.juvly_practice.database'=> $database_name]);
		
		$membsership_agreement = MembershipAgreement::where('id',$agreement_id)->where('status',0)->first();
		if($membsership_agreement){
			$agreement_text = $membsership_agreement['agreement_text'];
			ob_start();
			$file = fopen('php://output', 'wb');
			fputs($file, $agreement_text);
			fclose($file);
			if($this->checkJuvlyDomainName()) {
			    $filename = 'Juvly_Membership_Terms_and_Conditions';
            } else {
                $filename = 'Membership_Terms_and_Conditions';
            }
			header("Content-Type: application/octet-stream");
			header("Content-Disposition: attachment; filename=\"{$filename}.docx\"");
			exit();
		}
	}
	public function downloadAgreement(Request $request, $membership_id = 0 )
    {
		$input 				= $request->all();
		$database_name 			= $request->session()->get('database');
		$account_detail 	= $request->session()->get('account_detail');
		
		$patient_id 		= $request->session()->get('patient_id');
		$account_id			= $account_detail->id;
		$storage_folder		= $account_detail->storage_folder;
		$data				= null;
		$template			= 'patient_signature';
		$type 				= 'agreement_';
		#connect db
		config(['database.connections.juvly_practice.database'=> $database_name]);
		$membership = PatientMembershipSubscription::with(['patient','clinic'])->find($membership_id);
		if(!$membership){
			return view('errors.404');
		}
		$to_timezone	=  'America/New_York';
		$data['agreement_title'] 	= $membership->agreement_title;
		$data['agreement_text'] 	= $membership->agreement_text;
		$patient_signature  		= $membership->patient_signature;
		$signed_on 					= $membership->signed_on;
		$from_timezone				=  'America/New_York';
		
		if(!empty($membership->clinic->timezone)){
			$to_timezone = $membership->clinic->timezone;
		}
		$signed_on		= $this->convertTimeByTZ($signed_on, $from_timezone, $to_timezone, 'datetime');
		$date_time = explode(' ',$signed_on);
		$aws_s3_storage_url = config("constants.default.aws_s3_storage_url");
		$dateFormat = $this->phpDateFormat($account_id);
		$dateSigned = $this->getDateFormatWithOutTimeZone($date_time[0],$dateFormat,true);
		$signed_on = $dateSigned.' '.date('g:ia',strtotime($date_time[1]));
		$ar_media_path = env('MEDIA_URL');
		if(isset($account_detail->logo) && $account_detail->logo != '') {
			$logo_img_src 		= $ar_media_path.$storage_folder.'/admin/'.$account_detail->logo;
		} else {
			$logo_img_src 		= env('NO_LOGO_FOR_PDF');
		}
		$data['signed_on'] = $signed_on;
		$data['patient_signature'] 	= $patient_signature;
		$data['siganture_url'] 	= $ar_media_path.$storage_folder.'/patient_signatures/'.$patient_signature;
		$data['patient_name']  	= @$membership->patient->firstname.' '.$membership->patient->lastname;
		$data['account_id'] = $account_id;
		$data['account_logo'] = $logo_img_src;
		$media_path = public_path();
		$media_url = url('/');
		$pdf = \PDF::loadView('patient_signature', ['data' => $data]); 
		$pdf_title 		= rand(10,100).$account_id.$patient_id.rand(10,100).date('ymdhis');
		$dir 			= $media_path.'/stripeinvoices/';
		$filename 			= $dir.$pdf_title.".pdf";
		$pdf->save($filename,'F');
		$attachments 	= $media_url.'/stripeinvoices/'.$pdf_title.'.pdf';
		return redirect($attachments);
	}
	
	public function getHealthQuestinnair(Request $request,$id = null,$appointment_id = 0,$service_id = null)
	{
		if($id){
			
			$consultation_id = $id;
			
			$database_name 	= $request->session()->get('database');
			$patient_id 	= $request->session()->get('patient_id');
			$account_detail = $request->session()->get('account_detail');
			
			$storage_folder = $account_detail->storage_folder;
	
			config(['database.connections.juvly_practice.database'=> $database_name]);
			
			$data = $this->getProcedureTemplateQuesion($id, $appointment_id);
			if(!empty($data['procedureTemplateQuestion'])){
				$total_questions = count($data['procedureTemplateQuestion']);
			}
			$current_index       =  1;
			$media_path = env('MEDIA_URL').$storage_folder.'/procedureImages/';
			return view('app.health_questionnair.ajax.questionnair_popup',compact('data','total_questions','current_index','appointment_id','media_path'))->render();
		}else{
			return view('errors.503');
		}
	}
	
	private function getProcedureTemplateQuesion($template_id, $appointment_id)
	{
		$template_data 	= ProcedureTemplate::with(['procedureTemplateQuestion' => function($quest) {
			$quest->with(['procedureTemplateQuestionOption'
		,'procedureTemplatesLogic'])
		->where('status',0)->orderby('order_by', 'ASC');
		}])
		->where('id',$template_id)
		->where('status',0)->first();
		$filledQuestionnair =  AppointmentHealthtimelineQuestionnaire::where('procedure_template_id',$template_id)
		->where('appointment_id',$appointment_id)->with('healthTimelineAnswer')->first();
		$filledData = [];
		$filled_questionnaire_id = 0;
		if(!empty($filledQuestionnair['healthTimelineAnswer'])){
			foreach($filledQuestionnair['healthTimelineAnswer'] as $filled){
				$filledData[$filled->question_id]['question_id'] 	= $filled->question_id;
				$filledData[$filled->question_id]['question_type'] = $filled->question_type;
				$filledData[$filled->question_id]['comment'] = $filled->comment;
				if($filled->question_type == 'Opinion Scale'){
					$filledData[$filled->question_id]['answer'] 		= $filled->score;
				}else{
					$filledData[$filled->question_id]['answer'] 		= $filled->answer;
				}
			}
			$filled_questionnaire_id = $filledQuestionnair->id;
		}
		if(!empty($template_data['procedureTemplateQuestion'])){
			foreach($template_data['procedureTemplateQuestion'] as $question){
				$question->answer = null;
				$question->comment = null;
				if(!empty($filledData) && $question->id == @$filledData[$question->id]['question_id'] ){
					$question->answer = $filledData[$question->id]['answer'];
					$question->comment = $filledData[$question->id]['comment'];
				}
				if(!empty($question['procedureTemplateQuestionOption'])){
					foreach($question['procedureTemplateQuestionOption'] as $options){
						$logicArray = [];
						if(!empty($question['procedureTemplatesLogic'])){
							foreach($question['procedureTemplatesLogic'] as $logic){
								
								$logicArray[$logic->procedure_question_option_id]['option_id'] = $logic->procedure_question_option_id; 
								$logicArray[$logic->procedure_question_option_id]['jump_to_question'] = $logic->jump_to_question;
							}
						}
						//if($question->question_type == 'Opinion Scale'){
							$options->jump_to = 0;
							if(array_key_exists($options->id,$logicArray)){
								//echo "</br>if"; echo $options->id;
								$options->jump_to = $logicArray[$options->id]['jump_to_question'];
							}else{
								//echo "</br>else"; echo $options->id;
								if(array_key_exists(0,$logicArray)){
									$options->jump_to = $logicArray[0]['jump_to_question'];
								}
							}
							
						//}
					}
				}
				//~ if($question->question_type == 'Opinion Scale'){
					//~ die;
				//~ }
			}
		}
		
		$template_data['filled_questionnaire_id'] = $filled_questionnaire_id;
		//echo "<pre>"; print_r($template_data->toArray()); die;
		return $template_data;
	}
	
	public function uploadQuestionnarieFile(Request $request)
	{	
		$file 		= $request->file('file');
		
		$database_name 	= $request->session()->get('database');
		$patient_id 	= $request->session()->get('patient_id');
		$account_detail = $request->session()->get('account_detail');
		$storage_folder = $account_detail->storage_folder;
		
        if ( !empty($file) ) {
            $postdata = [];	
            
            $tmp_path 		= $file->getRealPath();
            $ext 			= $file->getClientOriginalExtension();
            $file_size 		= $file->getSize();
            $file_name 		= $file->getClientOriginalName();
			
			if (function_exists('curl_file_create')) { // php 5.5+
			  $cFile 		= curl_file_create($tmp_path,$ext,$file_name);
			} else { 
			  $cFile 		= '@' . realpath($tmp_path);
			}
            
			$postdata['upload_type'] 				= 'procedure_image';
			$postdata['file'] 						= $cFile;
			$postdata['ext'] 						= $ext;
			$postdata['file_size'] 					= $file_size;
			$postdata['file_name'] 					= $file_name;
			$postdata['storage_folder'] 			= $account_detail->storage_folder;
			$postdata['user_id'] 					= $account_detail->admin_id;
			$postdata['api_name'] 					= 'upload_file';
			
			
			$curl_response = $this->uploadExternalFile($postdata);
			if($curl_response->status == 200){
				$file_name = $curl_response->data->file_name;
				$response['status'] = "success";
				$response['data'] = $file_name;
				$response['doc_url'] = env('MEDIA_URL').$storage_folder.'/procedureImages/'.$file_name;
			}else{
				$response['status'] = "error";
			}
			
		}else{
			$response['status'] = "something went wrong";
		}
		return $response;
	}
	
	public function saveHealthQuestionnarie(Request $request, $id, $appointment_id, $filledQuestionnaireId)
	{
		$input = $request->all();
		//echo "<pre>"; print_r($input); die;	
		$consultation_id = $id;
		
		$database_name 	= $request->session()->get('database');
		$patient_id 	= $request->session()->get('patient_id');
		$account_detail = $request->session()->get('account_detail');
		//echo $database_name; die;
		$storage_folder = $account_detail->storage_folder;

		config(['database.connections.juvly_practice.database'=> $database_name]);
		$questions				= $request->input('questions');
		$insert_data 			= array();
		$total_score 			= 0;
		if( count($questions) >0 ) {
			$newYorkTimeZone				= 'America/New_York';
			$now						= $this->convertTZ($newYorkTimeZone);
			$healthQuestinnaire =  new AppointmentHealthtimelineQuestionnaire;
			if($filledQuestionnaireId > 0){
				$healthQuestinnaire = $healthQuestinnaire->find($filledQuestionnaireId);
				AppointmentHealthtimelineAnswer::where('appointment_healthtimeline_questionnaire_id',$filledQuestionnaireId)->delete();
			}
			
			$healthQuestinnaire->appointment_id = $appointment_id;
			$healthQuestinnaire->procedure_template_id = $id;
			$healthQuestinnaire->user_id = 0;
			$healthQuestinnaire->save();
			
			$question_count =  count((array)$questions);
			foreach ($questions as $key => $val) 
			{
				$answer 	= null;
				$score 		= 0;
				$comment 	= '';
				if($val['quest_type'] == 'Multiple Choice')
				{
					$answer  = isset($val['answers'][0]) ? implode(',', $val['answers']) : null;	
				}
				else if($val['quest_type'] == 'Opinion Scale' || $val['quest_type'] == 'scale')
				{
					$val['quest_type'] ='Opinion Scale';
					$score 		= $val['answers'][0];
					$comment 	= $val['answers'][1];
				}
				else {
					$answer = isset($val['answers'][0]) ? $val['answers'][0] : null;	
				}
				
				$insert_data[] = array(
						'appointment_healthtimeline_questionnaire_id' => $healthQuestinnaire->id,
						'question_id' 		=>  $val['question_id'],
						'question_type' 	=>  $val['quest_type'],
						'score' 			=>  $score,
						'answer'			=>  $answer,
						
						'comment'			=>  $comment,
						//'question_text'		=>  trim($val['quest_text']),
						'status'			=>  0,
						'created'			=>  $now
				);					
			}
			
			$affected_rows = AppointmentHealthtimelineAnswer::insert($insert_data);
			if($affected_rows){
				$response_array = array('status'=>'success','message'=>'Questionnaire saved successfully');
			} else {
				$response_array = array('status'=>'error','message'=>'Something went wrong');	
			}
		}else {
			$response_array = array('status'=>'error','message'=>'Something went wrong');	
		}
		return json_encode($response_array);	
	}
	
	public function getHealthConsent(Request $request,$id = null,$appointment_id = 0,$service_id = null)
	{
		if($id){
			
			$consultation_id = $id;
			
			$database_name 	= $request->session()->get('database');
			$patient_id 	= $request->session()->get('patient_id');
			$account_detail = $request->session()->get('account_detail');
			
			$storage_folder = $account_detail->storage_folder;
	
			config(['database.connections.juvly_practice.database'=> $database_name]);
			$savedhealthConsent = AppointmentHealthtimelineConsent::where('appointment_id',$appointment_id)->where('consent_id',$id)->first();
			if($savedhealthConsent){
				$savedhealthConsent->signature_url = env('MEDIA_URL').$storage_folder.'/patient_signatures/'.$savedhealthConsent->signature_image;
			}
			$consent = ServiceConsent::where('consent_id',$id)->where('service_id',$service_id)->with('consent')->first(); 
			return view('app.health_questionnair.ajax.consent',compact('consent','appointment_id','service_id','savedhealthConsent'))->render();
		}else{
			return view('errors.503');
		}
	}
	
	public function saveConsent(Request $request)
	{
		$input = $request->all();
		//echo "<pre>"; print_r($input); die;	
		//$consultation_id = $id;
		
		$database_name 	= $request->session()->get('database');
		$patient_id 	= $request->session()->get('patient_id');
		$account_detail = $request->session()->get('account_detail');
		if(empty($input['image_data'])){
			$response_array = array('status'=>'error','message'=>'Signature is empty');
			return json_encode($response_array);
		}
		
		$postdata['upload_type'] 				= 'patient_signatures';
		$postdata['account']['storage_folder'] 	= $account_detail->storage_folder;
		$postdata['user_data']['id'] 			= $account_detail->admin_id;
		$postdata['image_data'] 				= $input['image_data'];
		$postdata['api_name'] 					= 'upload_patient_signature';
		
		$response = $this->uploadExternalData($postdata);
		if( $response->status !=200 || empty($response->data->file_name)){
			$response_array = array('status'=>'error','message'=>'Something went wrong');
			return json_encode($response_array);	
		}
		
		config(['database.connections.juvly_practice.database'=> $database_name]);
		
		$clinicTimeZone				= 'America/New_York';
		$now						= $this->convertTZ($clinicTimeZone);
		$signature_image = $response->data->file_name;
		$data['appointment_id'] = $input['appointment_id'];
		$data['consent_id'] 	= $input['consent_id'];
		$data['user_id'] 		= 0;
		$data['signature_image'] = $signature_image;
		$data['signed_on'] = $now;
		$data['is_signed'] = 1;
		$data['created'] = $now;
		if($input['saved_consent_id'] > 0){
			$saved_consent = AppointmentHealthtimelineConsent::find($input['saved_consent_id']);
			$saved_consent->signature_image = $signature_image; 
			$saved_consent->modified = $now; 
			$saved_consent->save();
			$affected_rows = 1;
		}else{
			$affected_rows = AppointmentHealthtimelineConsent::insert($data);
		}
		
		$procedure = Procedure::where('appointment_id',$input['appointment_id'])
		->select('id','appointment_id')->first();
		if($procedure){
			$procedureConsent = ProcedureHealthtimelineConsent::where('procedure_id',$procedure->id)
			->where('consent_id',$input['consent_id'])->first();
			if($procedureConsent){
				$procedureConsent->signature_image 	= $signature_image;
				$procedureConsent->is_signed 		= 1;
				$procedureConsent->save();
			}
			
		}
		
		if($affected_rows){		
			$response_array = array('status'=>'success','message'=>'Consent signed successfully'); 
		}else {
			$response_array = array('status'=>'error','message'=>'Something went wrong');	
		}
	
		return $response_array;
		
	}
	
	public function uploadExternalData($postdata){
		
		$url = rtrim(config('constants.urls.ar_backend'), '/')."/{$postdata['api_name']}";

		$curl = curl_init();
		$field_string = http_build_query($postdata);

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
			return json_decode($response);

		}
	}

	public function uploadExternalFile($arrdata){
		//echo "<pre>"; print_r($arrdata); die;
		$url = rtrim(config('constants.urls.ar_backend'), '/')."/{$arrdata['api_name']}";

		$headers = array("Content-Type:multipart/form-data"); // cURL headers for file uploading
		$ch = curl_init();
		$options = array(
			CURLOPT_URL => $url,
			CURLOPT_HEADER => false,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false,
			CURLOPT_POST => 1,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_POSTFIELDS => $arrdata,
			CURLOPT_RETURNTRANSFER => true,
		); // cURL options
		curl_setopt_array($ch, $options);
		$response = curl_exec($ch);
		$err = curl_error($ch);

		curl_close($ch);
		if ($err) {
			return json_encode(array());
		} else {
			return json_decode($response);

		}
	}

	public function getDateFormatWithOutTimeZone($value,$date_format, $doFormatting = false) {
		$return = "0000-00-00";
        if (!is_null($value) && !empty($value && $value != '0000-00-00')){
			if($doFormatting){
				//~ $new_date = $value;
				$new_date = date($date_format,strtotime($value));
			}
			$return = $new_date;
		}
		return $return;
    }
}
