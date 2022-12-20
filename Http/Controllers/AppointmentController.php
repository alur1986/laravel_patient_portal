<?php

namespace App\Http\Controllers;

use App\Helpers\EmailHelper;
use App\Http\Requests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Auth;
use App\Appointment;
use App\Procedure;
use App\Patient;
use App\Injection;
use App\Product;
use App\MedicalHistory;
use App\Question;
use App\PatientAccount;
use App\AppointmentQuestionnair;
use App\Consultationlist;
use App\PreTreatmentInstruction;
use App\AppointmentTreatmentInstruction;
use App\Clinic;
use App\QuestionChoice;
use App\AppointmentQuestionnairChoice;
use App\AppointmentService;
use App\Service;
use App\Account;
use App\UserLog;
use App\AppointmentCancellationTransaction;
use App\AccountPrefrence;
use App\PatientCardOnFile;
use App\PatientNote;
use App\ServicesTreatmentInstruction;
use App\ServiceTreatmentInstruction;
use App\ServicePostTreatmentInstruction;
use App\PostTreatmentInstruction;
use App\PrepostInstructionsLog;
use App\AppointmentReminderConfiguration;
use App\AppointmentReminderLog;
use App\AccountStripeConfig;
use App\Users;
use App\PosInvoice;
use App\AccountClearentConfig;

use Session;
use Config;
use Intervention\Image\ImageManagerStatic as Image;
use Twilio;
use DateTime;
use DateTimeZone;
use URL;
use App\Traits\Clearent;
use App\ClearentFailedTransaction;

class AppointmentController extends Controller
{
    use Clearent;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(Request $request)
    {
        $this->middleware('auth');
        $user = Auth::user();
        $this->user = $user;

        if ($user) {
            $request->session()->put('user', $user);
            $this->checkAccountStatus($request);
        }
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */

    public function appointmentDetail(Request $request,$id = null)
    {
		if($id) {

			$database_name = $request->session()->get('database');

			config(['database.connections.juvly_practice.database'=> $database_name]);

			$appointment 	= 	Appointment :: with('users','clinic','appointment_booking','services','appointment_sevices.service')->where('id',$id)->first();

			if(count((array)$appointment)>0){
				$duration 				= $appointment->duration;
				$endTime 				= $appointment->appointment_datetime." +".$duration." minutes";
				$appointment->end_time  = $endTime;
			}

			$today =  date('Y-m-d H:i:s');

			$patient_id 	= $request->session()->get('patient_id');

			$past_appointments 		= 	Appointment :: with('users','clinic','appointment_booking','services','appointment_sevices.service')->where('patient_id',$patient_id)->orderBy('appointment_datetime','DESC')->get();

			if(count((array)$past_appointments)>0){

				foreach($past_appointments as $key => $past_appointment) {
					$clinicTimeZone			= isset($past_appointment->clinic->timezone) ? $past_appointment->clinic->timezone : 'America/New_York';
					date_default_timezone_set($clinicTimeZone);
					$todayDateTime 			= new DateTime(date('Y-m-d H:i:s'));
					$todayTimeZone 			= new DateTimeZone($clinicTimeZone);
					$todayDateTime->setTimezone($todayTimeZone);
					$todayInClinicTZ		= $todayDateTime->format('Y-m-d H:i:s');

					$appDateTime 			= new DateTime($past_appointment->appointment_datetime);
					$appTimeZone 			= new DateTimeZone($clinicTimeZone);
					$appDateTime->setTimezone($appTimeZone);
					$appDateTimeInClinicTZ	= $appDateTime->format('Y-m-d H:i:s');

					if(strtotime($appDateTimeInClinicTZ) > strtotime($todayInClinicTZ) && $past_appointment->status == 'booked' ){
						$status 		= "Upcoming";
						$status_class 	= "upcoming";
					} else if($past_appointment->status == 'canceled') {
						$status 		= "Canceled";
						$status_class 	= "canceled";
					} else if($past_appointment->status == 'noshow'){
						$status 		= "No Show";
						$status_class 	= 'noshow';
					} else {
						$status 		= "Past Appointment";
						$status_class 	= "past_apppointment";
					}
					$past_appointments[$key]->appointment_status = $status;
					$past_appointments[$key]->appointment_status_class = $status_class;

				}
			}

			$this->setSessionAppointmentSettingForPatient($request);

			return view('app.appointments.ajax.appointment_detail_popup',compact('appointment','past_appointments'))->render();

		}else{
			return view('errors.503');
		}

	}

	public function getQuestinnair(Request $request,$id = null,$appointment_id = null,$service_id = null)
	{
		if($id){

			$consultation_id = $id;

			$database_name 	= $request->session()->get('database');
			$patient_id 	= $request->session()->get('patient_id');
			$account_detail = $request->session()->get('account_detail');

			$storage_folder = $account_detail->storage_folder;

			config(['database.connections.juvly_practice.database'=> $database_name]);

			$questionnair_data 	= 	Consultationlist :: where('id',$id)->first();
			$questions 			= 	Question :: with('question_choices')->where('consultation_id',$id)->where('status',0)->orderBy('order_by','ASC')->get();

			if(count((array)$questions)>0){

				foreach($questions as $key => $val){
				//	echo "<pre>";print_r($val); echo "</pre>";
					if($val->question_type == 'yesno') {

						$answer = AppointmentQuestionnair :: where('consultation_id',$id)->where('appointment_id',$appointment_id)->where('service_id',$service_id)->where('patient_id',$patient_id)->where('question_id',$val->id)->first(['answer','comment']);

						if ($answer) {
							$val->your_answer = $answer->answer;
							$val->your_comment = $answer->comment;
						} else{
							$val->your_answer = null;
							$val->your_comment = null;
						}

					} else {

						if(count((array)$val->question_choices) > 0) {

							$val->multiple_choices 	= $val->question_choices[0]->multiple_selection;
						}

						$choice_answers = AppointmentQuestionnairChoice :: where('consultation_id',$id)->where('appointment_id',$appointment_id)->where('service_id',$service_id)->where('patient_id',$patient_id)->where('question_id',$val->id)->get(['choice_id']);

						$val->choice_answers 	= array();

						if(count((array)$choice_answers) > 0 ) {

						//	$val->choice_answers 	= $choice_answers;

							if(count((array)$val->question_choices) > 0){

								foreach( $val->question_choices as $choice){

									$choice->is_seleted = 0;

									foreach($choice_answers as $option) {

										if($option->choice_id == $choice->id) {

											$choice->is_seleted = 1;
										}
									}
								}
							}
						}
					}

				}
			}



		//	echo "<pre>"; print_r($questions); die("cfjh");
			return view('app.questionnair.ajax.questionnair_popup',compact('questions','appointment_id','service_id','consultation_id','questionnair_data','storage_folder'))->render();

		}else{
			return view('errors.503');
		}
	}

	public function cancelAppointment(Request $request,$id= null)
	{
		if($id) {

			$database_name = $request->session()->get('database');

			config(['database.connections.juvly_practice.database'=> $database_name]);

			$appointment 	= 	Appointment :: with('services')->with('clinic')->find($id);

			if(count((array)$appointment)>0){
				$canCharge 				= false;
				$account_data 			= $request->session()->get('account_detail');
				$accPrefs				= AccountPrefrence::where('account_id', $account_data->id)->first();
				$clinic					= Clinic :: where('id',$appointment['clinic_id'])->first();
				$gatewayType			= $account_data->pos_gateway;

				$apptDateTime 			= $appointment['appointment_datetime'];

				$apptTZ 				= $appointment['appointment_timezone'];

				$patID 					= $appointment['patient_id'];

				$clinicID				= $appointment['clinic_id'];

				if(count((array)$clinic)>0){
					$timezone	=	$clinic->timezone;
				} else {
					$timezone	=	'';
				}

				$clinicTimeZone			= isset($timezone) ? $timezone : 'America/New_York';
				//~ date_default_timezone_set($clinicTimeZone);
				//~ $todayDateTime 			= new DateTime(date('Y-m-d H:i:s'));
				//~ $todayTimeZone 			= new DateTimeZone($clinicTimeZone);
				//~ $todayDateTime->setTimezone($todayTimeZone);
				//~
				//~ $todayInClinicTZ		= $todayDateTime->format('Y-m-d H:i:s');
				$todayInClinicTZ		= $this->convertTZ('America/New_York');
				$curDateTimeInApptTZ	= $this->convertTZ($clinicTimeZone);

				$apptOldDateTime		= $appointment['appointment_datetime'];

				if ( count($accPrefs) ) {
					$daysForCharge 			= $accPrefs['cancelation_fee_charge_days'];

					$apptDateTimeForCharge	= date("Y-m-d H:i:s", strtotime("-".$daysForCharge." days", strtotime($apptDateTime)));

					if ( strtotime($curDateTimeInApptTZ) > strtotime($apptDateTimeForCharge) ) {
						$canCharge = true;
					}
				}

				$account 				=	Account :: find($account_data->id);
				$appointmentTransaction	=	AppointmentCancellationTransaction :: where('appointment_id',$id)->where('status','authorised')->first();

				if (count((array)$appointmentTransaction)>0 && $canCharge) {

					//$clinic			=	Clinic :: where('id',$appointment['clinic_id'])->first();

					if(count((array)$clinic)>0){
						$timezone	=	$clinic->timezone;
					} else {
						$timezone	=	'';
					}

					$account_data	= 	$account->toArray();

					if ( $gatewayType && $gatewayType == 'stripe' ) {
						$gatewayResponse =	$this->chargeCustomer($patID, $account_data, $id, $timezone, $appointmentTransaction, 'false');
					} elseif ( $gatewayType && $gatewayType == 'clearent' ) {
                        $gatewayResponse =	$this->chargeUsingClearent($request, $appointment, $patID, $account_data, $id, $timezone, $appointmentTransaction, 'false');
                    } else {
						$gatewayResponse =	$this->getAprivaAccountDetail($patID, $account_data,$id,$timezone, $appointmentTransaction, "false");
					}

				} else {
					$gatewayResponse = array('status'=>'success','msg' => '');

				}

				if(isset($gatewayResponse['status']) && $gatewayResponse['status'] == 'success') {

					$apptOldDateTime		= $appointment['appointment_datetime'];
					$appointment->status 	= 'canceled';

					if($appointment->save()){
						if(AppointmentReminderLog::where('appointment_id',$appointment->id)->exists()){
							AppointmentReminderLog::where('appointment_id',$appointment->id)->delete();
						}
						$user_log 							=	new UserLog ;
						$user_log->user_id 					=	0 ;
						$user_log->object 					=	'appointment' ;
						$user_log->object_id 				=	$id ;
						$user_log->action 					=	'cancel' ;
						$user_log->child 					=	'customer' ;
						$user_log->child_id 				=	0 ;
						$user_log->child_action 			=	null ;
						$user_log->created 					=	$todayInClinicTZ ;
						$user_log->appointment_datetime 	=	$apptOldDateTime;
						$user_log->save();

						if(count((array)$account)>0) {
							if($account->appointment_cancel_status == 1){

								$smsBody		= $account->appointment_canceled_sms;

								if($account->getKey() === config('app.juvly_account_id')) {
                                    $stop_sms_check = $this->stopSmsCheck($appointment->patient_id);
                                    if ($stop_sms_check == 0) {
                                        $smsBody = $smsBody . '

Reply "STOP" to stop receiving appointment SMS notifications';
                                        $this->sendAppointmentCancelPatientSMS($smsBody, $appointment, $request);
                                        $this->sendClinicBookingSMS($appointment, $request, $account, 'cancel');
                                    }
                                    $mailBody = $account->appointment_canceled_email;
                                    $this->sendAppointmentCancelPatientMail($mailBody, $account, $appointment, $request);
                                    $this->sendAppointmentCancelClinicMail($account, $appointment, $request);
                                } else {
                                    if ($this->checkSmsLimit()) {
                                        $this->sendAppointmentCancelPatientSMS($smsBody, $appointment, $request);
                                        $this->sendClinicBookingSMS($appointment, $request, $account, 'cancel');
                                    } elseif ($this->checkSmsAutofill() && $this->paidAccount() == 'paid') {
                                        $this->sendAppointmentCancelPatientSMS($smsBody, $appointment, $request);
                                        $this->sendClinicBookingSMS($appointment, $request, $account, 'cancel');
                                    }
                                    $mailBody = $account->appointment_canceled_email;
                                    if ($this->checkEmailLimit()) {
                                        $this->sendAppointmentCancelPatientMail($mailBody, $account, $appointment, $request);
                                    } elseif ($this->checkEmailAutofill() && $this->paidAccount() == 'paid') {
                                        $this->sendAppointmentCancelPatientMail($mailBody, $account, $appointment, $request);
                                    }
                                    if ($this->checkEmailLimit()) {
                                        $this->sendAppointmentCancelClinicMail($account, $appointment, $request);
                                    } elseif ($this->checkEmailAutofill() && $this->paidAccount() == 'paid') {
                                        $this->sendAppointmentCancelClinicMail($account, $appointment, $request);
                                    }
                                }
							}
						}
						$providerID = $appointment['user_id'];
						//$this->syncGoogleCalanderEvent($providerID, $appointment, $patID, 'Cancelled');
						$this->deleteGoogleEvent($providerID, $appointment, $patID);
						$response = array('status' => 'success','message'=>'The appointment has been canceled successfully', );
					} else {
						$response = array('status' => 'error','message'=>'Something went Wrong, Please Try Again' );
					}

				} else {

					$response = array('status' => $gatewayResponse['status'],'message'=> $gatewayResponse['msg'] );
				}
			}
			return json_encode($response);
		}

	}

	public function saveAppointmentQuestionnair(Request $request)
	{

		$database_name = $request->session()->get('database');

		config(['database.connections.juvly_practice.database'=> $database_name]);
		if($request->input()) {
		//	echo "<pre>"; print_r($request->input()); echo "</pre>"; //die;
			$consultation_id 	= $request->input('consultation_id');
			$service_id			= $request->input('service_id');
			$appointment_id		= $request->input('appointment_id');
			$patient_id 		= $request->session()->get('patient_id');
			$data 				= array();
			$choice_data	    = array();
			$newYorkTimeZone				= 'America/New_York';
			$now						= $this->convertTZ($newYorkTimeZone);
			if(count((array)$request->input('answer'))>0) {

				$comment = $request->input('comment');

				foreach($request->input('answer') as $key => $val) {

					$question_detail	= 	Question :: where('id',$key)->where('status',0)->first(['question_type']);

					if(count((array)$question_detail)>0) {

						if($question_detail->question_type == 'yesno') {

								if($val == 0) {
									$patient_answer = '';
								}else{
									$patient_answer = $comment[$key];
								}

								$data[] = array(
												'patient_id'	=> $patient_id,
												'appointment_id'=>$appointment_id,
												'service_id'	=> $service_id,
												'consultation_id' => $consultation_id,
												'question_id' => $key,
												'answer' => $val,
												'status' => 0,
												'comment' => trim($patient_answer),
												'created'=> $now
										);

						} else {

							if(is_array($val)) {

								foreach($val as $option) {

									if(!empty($option)) {

										$choice_data[] = array(
														'patient_id'		=> $patient_id,
														'appointment_id'	=> $appointment_id,
														'service_id'		=> $service_id,
														'consultation_id' 	=> $consultation_id,
														'question_id' 		=> $key,
														'choice_id' 		=> $option,
														'status' 			=> 0,
														'created'			=> $now
												);
										}
								}
							} else {

									$choice_data[] = array(
													'patient_id'		=> $patient_id,
													'appointment_id'	=>$appointment_id,
													'service_id'		=> $service_id,
													'consultation_id' 	=> $consultation_id,
													'question_id' 		=> $key,
													'choice_id' 		=> $val,
													'status' 			=> 0,
													'created'			=> $now
											);
							}
						}
					}
				}
			}

			AppointmentQuestionnair :: where('appointment_id',$appointment_id)->where('consultation_id',$consultation_id)->where('patient_id',$patient_id)->where('service_id',$service_id)->delete();

			AppointmentQuestionnairChoice :: where('appointment_id',$appointment_id)->where('consultation_id',$consultation_id)->where('patient_id',$patient_id)->where('service_id',$service_id)->delete();

			$affected_rows 		= AppointmentQuestionnair::insert($data);

			$affected_choice 	= AppointmentQuestionnairChoice::insert($choice_data);

			if(!empty($affected_rows) || !empty($affected_choice)){
				$response_array = array('status'=>'success','message'=>'Questions has been saved successfully');
			} else {
				$response_array = array('status'=>'error','message'=>'Something went wrong');
			}

		}
		return json_encode($response_array);
	}

	public function getTreatmentInstruction(Request $request,$id = null,$appointment_id = null,$service_id = null)
	{
		if($id){

			$database_name = $request->session()->get('database');
			$patient_id 	= $request->session()->get('patient_id');

			config(['database.connections.juvly_practice.database'=> $database_name]);

			$treatment_instruction 	= 	PreTreatmentInstruction :: where('id',$id)->first();
					//echo  "<pre>"; print_r($treatment_instruction); die("dcdskj");
			$appointment_treatment_instructions = AppointmentTreatmentInstruction :: where('appointment_id',$appointment_id)->where('service_id',$service_id)->where('patient_id',$patient_id)->where('treatment_instruction_id',$id)->first();

			if(!empty($appointment_treatment_instructions) && count((array)$appointment_treatment_instructions) > 0){
				$read = 1;
			}else{
				$read = 0;
			}
			return view('app.treatment_instructions.ajax.treatment_instruction_popup',compact('treatment_instruction','appointment_id','service_id','read'))->render();


		}else{
			return view('errors.503');
		}

	}

	public function saveTreatmentInstruction(Request $request)
	{
		$database_name = $request->session()->get('database');

		config(['database.connections.juvly_practice.database'=> $database_name]);

		if($request->input()) {

			$treatment_instruction_id			= $request->input('treatment_instruction_id');
			$service_id							= $request->input('service_id');
			$appointment_id						= $request->input('appointment_id');
			$patient_id 						= $request->session()->get('patient_id');

			$data = array(
							'patient_id' 	=> $patient_id,
							'appointment_id' => $appointment_id,
							'service_id'	 => $service_id,
							'treatment_instruction_id' 	=> $treatment_instruction_id,
							'status' => 1,
							'created' => date("Y-m-d H:i:s")

							);
				AppointmentTreatmentInstruction :: where('appointment_id',$appointment_id)->where('treatment_instruction_id',$treatment_instruction_id)->where('patient_id',$patient_id)->where('service_id',$service_id)->delete();
				$affected_rows = AppointmentTreatmentInstruction :: insert($data);
				if($affected_rows){
					$response_array = array('status'=>'success');
				} else {
					$response_array = array('status'=>'error');
				}
		}

	//	return Redirect :: to('/dashboard');
		return json_encode($response_array);
	}


	public function reScheduleAppointment(Request $request,$id= null)
	{
		if($id && !empty($request->input()))
		{

			$database_name = $request->session()->get('database');

			config(['database.connections.juvly_practice.database'=> $database_name]);

			$appointment 	= 	Appointment :: with('services')->with('clinic')->find($id);

			$services					= array();
			if ( isset($appointment->services) ) {
				if ( count((array)$appointment->services->toArray()) ) {
					foreach ($appointment->services->toArray() as $service) {
						$services[] = $service['id'];
					}
				}
			}


			$patientID					= $appointment->patient_id;
			$clinicID					= $appointment->clinic_id;
			$providerID					= $appointment->user_id;
			$selDate					= $request->input('appointment_date');
			$selTime					= $request->input('appointment_time');

			$getSession = Session::all();

			$account_data 			= $request->session()->get('account_detail');
			$accPrefs				= AccountPrefrence::where('account_id', $account_data->id)->first();
			Session::put('account_preference', $accPrefs);
			$format 	= trim($accPrefs->date_format);
			if ( $format == 'dd/mm/yyyy') {
				$date 		= DateTime::createFromFormat('d/m/Y', $request->input('appointment_date'));
				$selDate	= $date->format('Y-m-d');
			}

			$timezone					= $appointment->appointment_timezone;
			//~
			//~ $clinicTimeZone			= isset($timezone) ? $timezone : 'America/New_York';
			//~ date_default_timezone_set($clinicTimeZone);
			//~ $todayDateTime 			= new DateTime(date('Y-m-d H:i:s'));
			//~ $todayTimeZone 			= new DateTimeZone($clinicTimeZone);
			//~ $todayDateTime->setTimezone($todayTimeZone);
//~
			//~ $todayInClinicTZ		= $todayDateTime->format('Y-m-d H:i:s');

			$aptDateTime						= date('Y-m-d H:i:s', strtotime($selDate . " " . $selTime));
			$system_appointment_datetime = $this->convertTzToSystemTz($aptDateTime,$timezone);
			$appointment->system_appointment_datetime  = $system_appointment_datetime;

			$account_detail 			= $request->session()->get('account_detail');
			$accountID					= $account_detail->id;
			$gatewayType				= $account_detail->pos_gateway;
			$appointment_type 			= $appointment->appointment_type;
			$params						= array('patient_id' => $patientID, 'provider_id' => $providerID, 'appointment_id' => $id, 'appointment_date' => $selDate, 'appointment_time' => $selTime, 'clinic' => $clinicID, 'account_id' => $accountID, 'service' => $services, 'timezone' =>  $timezone, 'appointment_type' => $appointment_type );

			$params['package_id']		= $appointment->package_id;

			$canBeBooked 				= json_decode($this->checkIfApptCanBeBooked($params), true);

			if(count((array)$appointment)>0){
				$canCharge 				= false;

				$apptOldDateTime		= $appointment['appointment_datetime'];

				$clinic					=	Clinic :: where('id',$appointment['clinic_id'])->first();

				if(count((array)$clinic)>0){
					$timezone	=	$clinic->timezone;
				} else {
					$timezone	=	'';
				}

				$clinicTimeZone			= isset($timezone) ? $timezone : 'America/New_York';
				//~ date_default_timezone_set($clinicTimeZone);
				//~ $todayDateTime 			= new DateTime(date('Y-m-d H:i:s'));
				//~ $todayTimeZone 			= new DateTimeZone($clinicTimeZone);
				//~ $todayDateTime->setTimezone($todayTimeZone);
				//~
				//~ $todayInClinicTZ		= $todayDateTime->format('Y-m-d H:i:s');
				$todayInClinicTZ		= $this->convertTZ('America/New_York');

				if ( $canBeBooked['status'] == 'fail' ) {
					$response_array = array('status'=>'error','message'=>'Sorry, selected appointment time is already booked. Please select another appointment time.');
				} else {
					//~ $account_data 			= $request->session()->get('account_detail');
					//~ $accPrefs				= AccountPrefrence::where('account_id', $account_data->id)->first();
					$curDateTimeInApptTZ	= $this->convertTZ($clinicTimeZone);

					if ( count((array)$accPrefs) ) {
						$daysForCharge 			= $accPrefs['cancelation_fee_charge_days'];

						$apptDateTimeForCharge	= date("Y-m-d H:i:s", strtotime("-".$daysForCharge." days", strtotime($apptOldDateTime)));

						if ( strtotime($curDateTimeInApptTZ) > strtotime($apptDateTimeForCharge) ) {
							$canCharge = true;
						}
					}

					$account 				= Account::find($account_data->id);
					$appointmentTransaction	= AppointmentCancellationTransaction::where('appointment_id',$id)->where('status','authorised')->first();


					$canCharge = false; //Ganesh asked us to remove cancellation from reschedule even if it's falling in charge window.


					if (count((array)$appointmentTransaction)>0 && $canCharge) {
						$account_data	= 	$account->toArray();

						if ( $gatewayType && $gatewayType == 'stripe' ) {
							$gatewayResponse =	$this->chargeCustomer($patientID, $account_data,$id,$timezone, $appointmentTransaction, "true");
						} elseif ( $gatewayType && $gatewayType == 'clearent' ) {
                            $gatewayResponse =	$this->chargeUsingClearent($request, $appointment, $patientID, $account_data, $id, $timezone, $appointmentTransaction, 'true');
                        } else {
							$gatewayResponse =	$this->getAprivaAccountDetail($patientID, $account_data,$id,$timezone, $appointmentTransaction, "true");
						}
					} else {
						$gatewayResponse = array('status'=>'success','msg' => '');

					}

					$old_appointment_datetime = $appointment->appointment_datetime;
					$appointment->appointment_datetime = date("Y-m-d",strtotime($selDate))." ".date("H:i:s",strtotime($request->input('appointment_time')));

					if(isset($gatewayResponse['status']) && $gatewayResponse['status'] == 'success') {

						if($appointment->save()){
							if(AppointmentReminderLog::where('appointment_id',$appointment->id)->exists()){
								AppointmentReminderLog::where('appointment_id',$appointment->id)->delete();
							}
							$this->saveAppointmentReminderLogs($appointment->id , $system_appointment_datetime);

							$sms_date = date('Y-m-d H:i:s', strtotime('+'.$appointment->duration.' minutes', strtotime($system_appointment_datetime)));

							$this->save_sms_log($patientID, $appointment->id, $sms_date, $services);


							$user_log 							=	new UserLog ;
							$user_log->user_id 					=	0 ;
							$user_log->object 					=	'appointment' ;
							$user_log->object_id 				=	$id ;
							$user_log->action 					=	'reschedule' ;
							$user_log->child 					=	'customer' ;
							$user_log->child_id 				=	0 ;
							$user_log->child_action 			=	null ;
							$user_log->created 					=	$todayInClinicTZ ;
							$user_log->appointment_datetime 	=	$aptDateTime;
							$user_log->save();

							$account_data 	= $request->session()->get('account_detail');
							$account 		=	Account :: find($account_data->id);
							if(count((array)$account)>0) {
								if($account->appointment_reschedule_status == 1){

									$smsBody		= $account->appointment_reschedule_sms;

                                    if ($account->getKey() === config('app.juvly_account_id')) {
                                        $stop_sms_check = $this->stopSmsCheck($appointment->patient_id);
                                        if ($stop_sms_check == 0) {
                                            $smsBody = $smsBody . '

Reply "STOP" to stop receiving appointment SMS notifications';
                                            $this->sendAppointmentReschedulePatientSMS($old_appointment_datetime, $smsBody, $appointment, $request, $account);
                                            $this->sendClinicBookingSMS($appointment, $request, $account);
                                        }
                                        $mailBody = $account->appointment_reschedule_email;
                                        $this->sendAppointmentReschedulePatientMail($old_appointment_datetime, $mailBody, $account, $appointment, $request);
                                        $this->sendAppointmentRescheduleClinicMail($old_appointment_datetime, $account, $appointment, $request);
                                    } else {
                                        if ($this->checkSmsLimit()) {
                                            $this->sendAppointmentReschedulePatientSMS($old_appointment_datetime, $smsBody, $appointment, $request, $account);
                                            $this->sendClinicBookingSMS($appointment, $request, $account);
                                        } elseif ($this->checkSmsAutofill() && $this->paidAccount() == 'paid') {
                                            $this->sendAppointmentReschedulePatientSMS($old_appointment_datetime, $smsBody, $appointment, $request, $account);
                                            $this->sendClinicBookingSMS($appointment, $request, $account);
                                        }
                                        $mailBody = $account->appointment_reschedule_email;
                                        if ($this->checkEmailLimit()) {
                                            $this->sendAppointmentReschedulePatientMail($old_appointment_datetime, $mailBody, $account, $appointment, $request);
                                        } elseif ($this->checkEmailAutofill() && $this->paidAccount() == 'paid') {
                                            $this->sendAppointmentReschedulePatientMail($old_appointment_datetime, $mailBody, $account, $appointment, $request);
                                        }
                                        if ($this->checkEmailLimit()) {
                                            $this->sendAppointmentRescheduleClinicMail($old_appointment_datetime, $account, $appointment, $request);
                                        } elseif ($this->checkEmailAutofill() && $this->paidAccount() == 'paid') {
                                            $this->sendAppointmentRescheduleClinicMail($old_appointment_datetime, $account, $appointment, $request);
                                        }
                                    }
								}
							}
							$this->syncGoogleCalanderEvent($providerID, $appointment, $patientID, 'Rescheduled');
							$response_array = array('status'=>'success','message'=>'The appointment has been rescheduled successfully');

							} else {
								$response_array = array('status'=>'error','message'=>'Something went Wrong, Please Try Again');
							}

					}  else {
						$response_array = array('status' => $gatewayResponse['status'],'message'=> $gatewayResponse['msg'] );
					}
				}
			}
		}
		return json_encode($response_array);
	}


	public function sendAppointmentCancelClinicMail($account,$appointment,$request)
	{
		$database_name 					= $request->session()->get('database');

		config(['database.connections.juvly_practice.database'=> $database_name]);

		$clinic 						= Clinic :: findOrFail($appointment->clinic_id);
		$sender	 						= $this->getSenderEmail();
		$subject 						= "Appointment Canceled";
        $email_ids					    = explode(",", $clinic->appointment_notification_emails);

		$services					= array();
		if ( isset($appointment->services) ) {
			if ( count((array)$appointment->services->toArray()) ) {
				foreach ($appointment->services->toArray() as $service) {
					$services[] = ucfirst($service['name']);
				}
			}
		}

		if(!empty($clinic->address)){
			$location[] 		= $clinic->address;
		}

		if (!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
			$location[] 		= $clinic->clinic_city;
		} else if (!empty($clinic->city)){
			$location[] 		= $clinic->city;
		}

		if(count((array)$location)>0) {
			$clinic_location = implode(", ",$location);
		} else {
			$clinic_location = '';
		}

		$provider = Users :: where('id',@$appointment->user_id)->first();
		if(count((array)$provider) > 0) {

			if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

				$provider_name = ucfirst($provider->bio_name);
			} else {

				$provider_name = ucfirst($provider->firstname." ".$provider->lastname);
			}

		} else {
			$provider_name='';
		}

		$getSession = Session::all();
		$account_id 	= trim($getSession['account_preference']->account_id);
		$phpDateFormat = $this->phpDateFormat($account_id);

		$body_content					= Config::get('app.mail_body');
		$mail_body						= $body_content['CANCEL_APPOINTMENT_CLINIC_EMAIL'];
		$time							= date("H:i:s",strtotime($appointment->appointment_datetime));
		$date 							= date($phpDateFormat,strtotime($appointment->appointment_datetime));
		//$appointment_time 				= date('g:i a',strtotime($time));
		$appointment_time 				= changeFormatByPreference(@$time, null, true, true );
		$appointment_date_time 			= date('l',strtotime($date)).' '.$date.' @ '.$appointment_time;

		//~ $replace = array();
		//~ $replace['NAME'] 				= ucfirst($this->user->firstname ." ".$this->user->lastname);
		//~ $replace['CLINIC']			 	= ucfirst($clinic->clinic_name);
		//~ $replace['DATE_TIME']		 	= $appointment_date_time;
		//~
		//~ $tags = array();
		//~ $tags['NAME'] 					= "{{NAME}}";
		//~ $tags['CLINIC']			 		= "{{CLINIC}}";
		//~ $tags['DATE_TIME']		 		= "{{DATE_TIME}}";
		//~
		//~ foreach($tags as $key => $val){
			//~ if($val){
				//~
				 //~ $mail_body  =	 str_replace($val,$replace[$key], $mail_body);
			//~ }
		//~ }
		$client_name = $this->getUserUsedClientName($account->id);
		$mail_body						= "Appointment canceled by customer using ".ucfirst($client_name)." Portal" . "\n";
		$mail_body						.= ucfirst($client_name)." : " . ucfirst($this->user->firstname) . ' ' . ucfirst($this->user->lastname) . "\n";
		$mail_body						.= "Provider : " . $provider_name . "\n";
		$mail_body						.= "Clinic : " . ucfirst($clinic->clinic_name) . "\n";
		$mail_body						.= "Location : " . ucfirst($clinic_location) . "\n";
		$mail_body						.= "Appt Date Time Was : " . $appointment_date_time . "\n";
		$mail_body						.= "Services : " . implode(', ',$services) . "\n";

		$appointment_header['APPOINTMENTDATE'] 	= $date;
		$appointment_header['APPOINTMENTTIME'] 	= $appointment_time;
		$appointment_header['PROVIDERNAME'] 	= $provider_name;
		$appointment_header['BOOKEDSERVICES'] 	= implode(', ',$services);
		$appointment_header['CLINICNAME'] 	= $clinic->clinic_name;
		$bookObj = new BookController($request);
		$email_content = $bookObj->getAppointmentEmailTemplate($mail_body,$account,$clinic,$subject,$appointment_header);
		$noReply = config('mail.from.address');

        $response_data = EmailHelper::sendEmail($noReply, $email_ids, $sender, $email_content, $subject);

		if($response_data){
            if ($account->getKey() !== config('app.juvly_account_id')) {
                if (!$this->checkEmailLimit() && $this->checkEmailAutofill()) {
                    $this->updateUnbilledEmail();
                } else {
                    $this->saveEmailCount();
                }
            }
			return true;
		} else {
			return false;
		}

	}


	public function sendAppointmentCancelPatientMail($mailBody,$account,$appointment,$request)
	{
		$database_name = $request->session()->get('database');
		config(['database.connections.juvly_practice.database'=> $database_name]);
		$clinic 						= Clinic :: findOrFail($appointment->clinic_id);
		$sender	 						= $this->getSenderEmail();
		$subject 						= "Appointment Canceled";

		$location 			= array();
		if(!empty($clinic->address)){
			$location[] 		= $clinic->address;
		}
		if (!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
			$location[] 		= $clinic->clinic_city;
		} else if (!empty($clinic->city)){
			$location[] 		= $clinic->city;
		}
		if(count((array)$location)>0) {
			$clinic_location = implode(",",$location);
		} else {
			$clinic_location = '';
		}
		if(!empty($clinic->email_special_instructions))	{

			$email_special_instructions  = $clinic->email_special_instructions;

		} else {
			$email_special_instructions = '';
		}

		$cancelation_fee_charge_days	= 0;
		$cancelation_fees				= 0;
		$accountPrefData				= AccountPrefrence::where('account_id', $account->id)->first();
		$business_name 					= @$account->name;
		if ( count((array)$accountPrefData) > 0 ) {
			$cancelation_fee_charge_days = $accountPrefData['cancelation_fee_charge_days'];
		}

		if ( $cancelation_fee_charge_days <= 1 ) {
			$cancelation_fee_charge_days = '24 Hrs';
		} else {
			$cancelation_fee_charge_days =  $cancelation_fee_charge_days . ' Days';
		}

		$cancelation_fees				= '$'.number_format(@$account->cancellation_fees, 2);

		$business_name 					= @$account->name;

		$services = array();
		$instruction_url = array();

		$serviceIds = AppointmentService :: where('appointment_id',$appointment->id)->pluck('service_id');

		if(count((array)$serviceIds)>0) {

            $serviceIds = $serviceIds->toArray();
            $allBookedServices = Service::with('services_treatment_instructions')->whereIn('id', $serviceIds)->get();
            $allBookedServices = $allBookedServices->toArray();
            if (count((array)$allBookedServices) > 0) {
                foreach ($allBookedServices as $key => $val) {
                    $services[] = ucfirst($val['name']);
                    if (count((array)$val['services_treatment_instructions']) > 0 && isset($val['services_treatment_instructions']['instruction_url']) && $val['services_treatment_instructions']['instruction_url'] != '') {
                        $instruction_url[] = "<a href='" . $val['services_treatment_instructions']['instruction_url'] . "' target='_blank'>" . $val['services_treatment_instructions']['instruction_url'] . " </a>";
                    }
                }
            }
		}

		$getSession = Session::all();
		$account_id 	= trim($getSession['account_preference']->account_id);
		$phpDateFormat = $this->phpDateFormat($account_id);

		$time							= date("H:i:s",strtotime($appointment->appointment_datetime));
		$date 							= date($phpDateFormat,strtotime($appointment->appointment_datetime));
		//$appointment_time 				= date('g:i a',strtotime($time));
		$appointment_time 				= changeFormatByPreference(@$time, null, true, true );
		$appointment_date_time 			= date('l',strtotime($date)).' '.$date.' @ '.$appointment_time;

		$provider = Users :: where('id',@$appointment->user_id)->first();

		if(count((array)$provider) > 0) {

			if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

				$provider_name = ucfirst($provider->bio_name);
			} else {

				$provider_name = ucfirst($provider->firstname." ".$provider->lastname);
			}

		} else {
			$provider_name='';
		}

		$appointment_header['APPOINTMENTDATE'] 	= $date;
		$appointment_header['APPOINTMENTTIME'] 	= $appointment_time;
		$appointment_header['PROVIDERNAME'] 	= $provider_name;
		$appointment_header['BOOKEDSERVICES'] 	= implode(', ',$services);
		$appointment_header['CLINICNAME'] 		= ucfirst($clinic->clinic_name);

		$replace						= array();
		//$replace['PATIENTNAME'] 		= ucfirst($this->user->firstname ." ". $this->user->lastname);
		$replace['PATIENTNAME'] 		= ucfirst($this->user->firstname);
		$replace['CLINICNAME']			= ucfirst($clinic->clinic_name);
		$replace['CLINICLOCATION']		= $clinic_location;
		$replace['APPOINTMENTDATETIME']	= $appointment_date_time;
		$replace['PROVIDERNAME']		= $provider_name;
		$replace['CLINICINSTRUCTIONS']	= $email_special_instructions;
		$replace['BOOKEDSERVICES']		= implode(', ',$services);
		$replace['CANFEECHARGEDAYS']	= $cancelation_fee_charge_days;
		$replace['CANCELATIONFEES']		= $cancelation_fees;
		$replace['BUSINESSNAME']		= $business_name;
		$replace['CLIENTPATIENTURL']		= URL::to('/');
		$replace['SERVICEINSTRURL']		= implode(', ',$instruction_url);

		$tags							= array();
		$tags['PATIENTNAME'] 			= "{{PATIENTNAME}}";
		$tags['CLINICNAME']				= "{{CLINICNAME}}";
		$tags['APPOINTMENTDATETIME']	= "{{APPOINTMENTDATETIME}}";
		$tags['CLINICLOCATION']			= "{{CLINICLOCATION}}";
		$tags['CLINICINSTRUCTIONS']		= "{{CLINICINSTRUCTIONS}}";
		$tags['PROVIDERNAME']			= "{{PROVIDERNAME}}";
		$tags['BOOKEDSERVICES']			= "{{BOOKEDSERVICES}}";
		$tags['CANFEECHARGEDAYS']		= "{{CANFEECHARGEDAYS}}";
		$tags['CANCELATIONFEES']		= "{{CANCELATIONFEES}}";
		$tags['BUSINESSNAME']			= "{{BUSINESSNAME}}";
		$tags['CLIENTPATIENTURL']		= "{{CLIENTPATIENTURL}}";
		$tags['SERVICEINSTRURL']		= "{{SERVICEINSTRURL}}";

		foreach($tags as $key => $val){
			if($val){

				 $mailBody  =	 str_replace($val,$replace[$key], $mailBody);
			}
		}

		$bookObj = new BookController($request);
		$email_content = $bookObj->getAppointmentEmailTemplate($mailBody,$account,$clinic,$subject,$appointment_header);

		$noReply = config('mail.from.address');

        $response_data =  EmailHelper::sendEmail($noReply,  $this->user->email, $sender, $email_content, $subject);

		if($response_data){
            if($account->getKey() !== config('app.juvly_account_id')) {
                if( !$this->checkEmailLimit() && $this->checkEmailAutofill()){
                    $this->updateUnbilledEmail();
                }else{
                    $this->saveEmailCount();
                }
            }
			return true;
		} else {
			return false;

		}

	}



	public function sendAppointmentReschedulePatientMail($old_appointment_datetime,$mailBody,$account,$appointment,$request)
	{

		$database_name = $request->session()->get('database');

		$cancelation_fee_charge_days	= 0;
		$cancelation_fees				= 0;
		$accountPrefData				= AccountPrefrence::where('account_id', $account->id)->first();

		if ( count((array)$accountPrefData) > 0 ) {
			$cancelation_fee_charge_days = $accountPrefData['cancelation_fee_charge_days'];
		}

		if ( $cancelation_fee_charge_days <= 1 ) {
			$cancelation_fee_charge_days = '24 Hrs';
		} else {
			$cancelation_fee_charge_days =  $cancelation_fee_charge_days . ' Days';
		}

		$cancelation_fees				= '$'.number_format(@$account->cancellation_fees, 2);

		config(['database.connections.juvly_practice.database'=> $database_name]);

		$clinic 						= Clinic :: findOrFail($appointment->clinic_id);
		$sender	 						= $this->getSenderEmail();
		$subject 						= "Appointment Rescheduled";

		$location 			= array();
		if(!empty($clinic->address)){
			$location[] 		= $clinic->address;
		}

		if (!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
			$location[] 		= $clinic->clinic_city;
		} else if(!empty($clinic->city)){
			$location[] 		= $clinic->city;
		}

		if(count((array)$location)>0) {
			$clinic_location = implode(",",$location);
		} else {
			$clinic_location = '';
		}

		if(!empty($clinic->email_special_instructions))	{

			$email_special_instructions  = $clinic->email_special_instructions;

		} else {
			$email_special_instructions = '';
		}

		$business_name 					= @$account->name;
		$services		 = array();
		$instruction_url = array();

		$serviceIds = AppointmentService :: where('appointment_id',$appointment->id)->pluck('service_id');

		if(count((array)$serviceIds)>0) {

            $serviceIds = $serviceIds->toArray();
            $allBookedServices = Service:: with('services_treatment_instructions')->whereIn('id', $serviceIds)->get();
            $allBookedServices = $allBookedServices->toArray();
            if (count((array)$allBookedServices) > 0) {
                foreach ($allBookedServices as $key => $val) {
                    $services[] = ucfirst($val['name']);
                    if (count((array)$val['services_treatment_instructions']) > 0 && isset($val['services_treatment_instructions']['instruction_url']) && $val['services_treatment_instructions']['instruction_url'] != '') {
                        $instruction_url[] = "<a href='" . $val['services_treatment_instructions']['instruction_url'] . "' target='_blank'>" . $val['services_treatment_instructions']['instruction_url'] . " </a>";
                    }
                }
            }
		}

		$getSession = Session::all();
		$account_id 	= trim($getSession['account_preference']->account_id);
		$phpDateFormat = $this->phpDateFormat($account_id);

		$time							= date("H:i:s",strtotime($appointment->appointment_datetime));
		$date 							= date($phpDateFormat,strtotime($appointment->appointment_datetime));
		//$appointment_time 				= date('g:i a',strtotime($time));
		$appointment_time 				= changeFormatByPreference(@$time, null, true, true );
		$appointment_date_time 			= date('l',strtotime($date)).' '.$date.' @ '.$appointment_time;

		$provider = Users :: where('id',@$appointment->user_id)->first();

		if(count((array)$provider) > 0) {

			if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

				$provider_name = ucfirst($provider->bio_name);
			} else {

				$provider_name = ucfirst($provider->firstname." ".$provider->lastname);
			}

		} else {
			$provider_name='';
		}

		$appointment_header['APPOINTMENTDATE'] 	= $date;
		$appointment_header['APPOINTMENTTIME'] 	= $appointment_time;
		$appointment_header['PROVIDERNAME'] 	= $provider_name;
		$appointment_header['BOOKEDSERVICES'] 	= implode(', ',$services);
		$appointment_header['CLINICNAME'] 		= ucfirst($clinic->clinic_name);

		$replace						= array();
		//$replace['PATIENTNAME'] 		= ucfirst($this->user->firstname ." ". $this->user->lastname);
		$replace['PATIENTNAME'] 		= ucfirst($this->user->firstname);
		$replace['CLINICNAME']			= ucfirst($clinic->clinic_name);
		$replace['CLINICLOCATION']		= $clinic_location;
		$replace['APPOINTMENTDATETIME']	= $appointment_date_time;
		$replace['PROVIDERNAME']		= $provider_name;
		$replace['CLINICINSTRUCTIONS']	= $email_special_instructions;
		$replace['BOOKEDSERVICES']		= implode(', ',$services);
		$replace['SERVICEINSTRURL']		= implode(', ',$instruction_url);
		$replace['CANFEECHARGEDAYS']	= $cancelation_fee_charge_days;
		$replace['CANCELATIONFEES']		= $cancelation_fees;
		$replace['BUSINESSNAME']		= $business_name;
		$replace['CLIENTPATIENTURL']		= URL::to('/');

		$tags							=  array();
		$tags['PATIENTNAME'] 			= "{{PATIENTNAME}}";
		$tags['CLINICNAME']				= "{{CLINICNAME}}";
		$tags['APPOINTMENTDATETIME']	= "{{APPOINTMENTDATETIME}}";
		$tags['CLINICLOCATION']			= "{{CLINICLOCATION}}";
		$tags['CLINICINSTRUCTIONS']		= "{{CLINICINSTRUCTIONS}}";
		$tags['PROVIDERNAME']			= "{{PROVIDERNAME}}";
		$tags['BOOKEDSERVICES']			= "{{BOOKEDSERVICES}}";
		$tags['SERVICEINSTRURL']		= "{{SERVICEINSTRURL}}";
		$tags['CANFEECHARGEDAYS']		= "{{CANFEECHARGEDAYS}}";
		$tags['CANCELATIONFEES']		= "{{CANCELATIONFEES}}";
		$tags['BUSINESSNAME']			= "{{BUSINESSNAME}}";
		$tags['CLIENTPATIENTURL']			= "{{CLIENTPATIENTURL}}";

		$encoded_account_id = $this->encryptKey($account->id);
		$meeting_id 		=  $appointment->meeting_id;
		$appointment_type 	= $appointment->appointment_type;
		if($appointment_type == "virtual") {
			//~ $mailBody .= "<br><br>"."Your Appointment Meeting Link is https://meeting.aestheticrecord.com/?m=".$meeting_id."&u=3accd955bde83ab8af6ec833742ac748&a=".$encoded_account_id;
			$meeting_link = config('constants.urls.top_box');
			//~ $replace['MEETINGLINK'] = "<h5><b>Join Your Virtual Meeting<b><h5><br>
			//~ Please click the link below on the day & time of your appointment to enter our Virtual Clinic. For the best experience, we recommend joining your appointment from a quiet place with a strong wifi or cellular connection. Ensure your camera and audio are both enabled as you enter the portal. While settings vary by browser, but you should be prompted once you login.
			//~ <br><br>"."Appointment Link: $meeting_link/client/".$meeting_id;
			$replace['MEETINGLINK'] = $meeting_link."/client/".$meeting_id;

		}else{
			$replace['MEETINGLINK'] = "";
		}
		$tags['MEETINGLINK']			= "{{MEETINGLINK}}";

		foreach($tags as $key => $val){
			if($val){

				 $mailBody  =	 str_replace($val,$replace[$key], $mailBody);
			}
		}
		$bookObj = new BookController($request);
		$email_content = $bookObj->getAppointmentEmailTemplate($mailBody,$account,$clinic,$subject,$appointment_header);
		$noReply = config('mail.from.address');

        $response_data = EmailHelper::sendEmail($noReply, $this->user->email, $sender, $email_content, $subject);
		if($response_data){
            if ($account->getKey() !== config('app.juvly_account_id')) {
                if (!$this->checkEmailLimit() && $this->checkEmailAutofill()) {
                    $this->updateUnbilledEmail();
                } else {
                    $this->saveEmailCount();
                }
            }
			return true;
		} else {
			return false;

		}


	}

	public function sendAppointmentRescheduleClinicMail($old_appointment_datetime,$account,$appointment,$request)
	{
		$database_name = $request->session()->get('database');

		config(['database.connections.juvly_practice.database'=> $database_name]);

		$clinic 					= Clinic :: findOrFail($appointment->clinic_id);
        $email_ids 				    = explode(",", $clinic->appointment_notification_emails);

		$services					= array();
		if ( isset($appointment->services) ) {
			if ( count((array)$appointment->services->toArray()) ) {
				foreach ($appointment->services->toArray() as $service) {
					$services[] = ucfirst($service['name']);
				}
			}
		}

		if(!empty($clinic->address)){
			$location[] 		= $clinic->address;
		}

		if (!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
			$location[] 		= $clinic->clinic_city;
		} else if(!empty($clinic->city)){
			$location[] 		= $clinic->city;
		}
		if(count((array)$location)>0) {
			$clinic_location = implode(",",$location);
		} else {
			$clinic_location = '';
		}

		$provider = Users :: where('id',@$appointment->user_id)->first();
		if(count((array)$provider) > 0) {

			if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

				$provider_name = ucfirst($provider->bio_name);
			} else {

				$provider_name = ucfirst($provider->firstname." ".$provider->lastname);
			}

		} else {
			$provider_name='';
		}

		$getSession = Session::all();
		$account_id 	= trim($getSession['account_preference']->account_id);
		$phpDateFormat = $this->phpDateFormat($account_id);

		$sender	 					= 	$this->getSenderEmail();
		$subject 					= 	"Appointment Rescheduled";
		$body_content				= 	Config::get('app.mail_body');
		$mail_body					=  	$body_content['RESCHEDULE_APPOINTMENT_CLINIC_EMAIL'];
		$time						= 	date("H:i:s",strtotime($appointment->appointment_datetime));
		$date						= 	date("m/d/Y",strtotime($appointment->appointment_datetime));
		//$appointment_time 			= 	date('g:i a',strtotime($time));
		$appointment_time 				= changeFormatByPreference(@$time, null, true, true );
		$appointment_date_time 		= 	date('l',strtotime($date)).' '.$date.' @ '.$appointment_time;
		$old_time					= 	date("H:i:s",strtotime($old_appointment_datetime));
		$old_date 					= date($phpDateFormat,strtotime($old_appointment_datetime));
		//$old_appointment_time 		= 	date('g:i a',strtotime($old_time));
		$old_appointment_time 				= changeFormatByPreference(@$old_time, null, true, true );
		$old_appointment_date_time 	= 	date('l',strtotime($old_date)).' '.$old_date.' @ '.$old_appointment_time;

		//~ $replace = array();
		//~ $replace['NAME'] 			= ucfirst($this->user->firstname ." ".$this->user->lastname);
		//~ $replace['CLINIC']			= ucfirst($clinic->clinic_name);
		//~ $replace['DATE_TIME']		= $appointment_date_time;
		//~ $replace['OLD_DATE_TIME']	= $old_appointment_date_time;
			//~
		//~ $tags = array();
		//~ $tags['NAME'] 			 = "{{NAME}}";
		//~ $tags['CLINIC']			 = "{{CLINIC}}";
		//~ $tags['DATE_TIME']		 = "{{DATE_TIME}}";
		//~ $tags['OLD_DATE_TIME']	 = "{{OLD_DATE_TIME}}";
		//~
		//~ foreach($tags as $key => $val){
			//~ if($val){
				//~
				 //~ $mail_body  =	 str_replace($val,$replace[$key], $mail_body);
			//~ }
		//~ }

		$client_name = $this->getUserUsedClientName($account->id);
		$mail_body						= "Appointment Rescheduled by customer using ".ucfirst($client_name)." Portal" . "\n";
		$mail_body						.= ucfirst($client_name)." : " . ucfirst($this->user->firstname) . ' ' . ucfirst($this->user->lastname) . "\n";
		$mail_body						.= "Provider : " . $provider_name . "\n";
		$mail_body						.= "Clinic : " . ucfirst($clinic->clinic_name) . "\n";
		$mail_body						.= "Location : " . ucfirst($clinic_location) . "\n";
		$mail_body						.= "Old Appt Date Time : " . $old_appointment_date_time . "\n";
		$mail_body						.= "New Appt Date Time : " . $appointment_date_time . "\n";
		$mail_body						.= "Services : " . implode(', ',$services) . "\n";

		$appointment_header['APPOINTMENTDATE'] 	= $date;
		$appointment_header['APPOINTMENTTIME'] 	= $appointment_time;
		$appointment_header['PROVIDERNAME'] 	= $provider_name;
		$appointment_header['BOOKEDSERVICES'] 	= implode(', ',$services);
		$appointment_header['CLINICNAME'] 	= $clinic->clinic_name;
		$bookObj = new BookController($request);
		$email_content = $bookObj->getAppointmentEmailTemplate($mail_body,$account,$clinic,$subject,$appointment_header);
		$noReply = config('mail.from.address');

        $response_data =  EmailHelper::sendEmail($noReply, $email_ids, $sender, $email_content, $subject);

		if($response_data){
            if($account->getKey() !== config('app.juvly_account_id')) {
                if( !$this->checkEmailLimit() && $this->checkEmailAutofill()){
                    $this->updateUnbilledEmail();
                }else{
                    $this->saveEmailCount();
                }
            }
			return true;
		} else {
			return false;
		}
	}

	public function getProviderAvailabilityDays(Request $request,$provider_id,$appointment_id)
	{

		$database_name = $request->session()->get('database');

		config(['database.connections.juvly_practice.database'=> $database_name]);

		$appointment 	= 	Appointment :: with('clinic','services')->where('id',$appointment_id)->first();

		$service_ids = array();

		if(count((array)$appointment)>0 && count((array)$appointment->services)>0) {

			foreach($appointment->services as $service ){

				$service_ids[] = $service->id;
			}
		}
		//echo "<pre>";print_r($service_ids); die("ca");

		$params = array();
		$account_detail = $request->session()->get('account_detail');
		$params['provider_id']				= $provider_id;
		$params['account_id']				= $account_detail->id;
		$params['clinic_id']				= $appointment->clinic->id;
		$params['appointment_service']		= $service_ids;
		$params['package_id']				= $appointment->package_id;
		$params['appointment_type']			= $appointment->appointment_type;
		$response = $this->getProviderAvailability($params);
		return $response;
	}


	public function getProviderAvailabilityTime(Request $request)
	{
		if($request->input()){
			$database_name = $request->session()->get('database');

			config(['database.connections.juvly_practice.database'=> $database_name]);
			$input = $request->input();
			$appointment 	= 	Appointment :: with('clinic','services')->where('id',$input['appointment_id'])->first();

		//	echo "<pre>"; print_r($input);die("nkj");
			$patient_id 					= $request->session()->get('patient_id');
			$account_detail 				= $request->session()->get('account_detail');
			$params = array();
			$params['provider_id'] 			=	$input['provider_id'];
			$params['appointment_id'] 		=	$input['appointment_id'];
			$params['date'] 				=	$input['date'];
			$params['clinic_id'] 			=	$input['clinic_id'];
			$params['account_id'] 			=	$account_detail->id;
			$params['appointment_service'] 	=	$input['appointment_service'];
			$params['timezone'] 			=	$input['timezone'];
			$params['package_id']			=   $input['package_id'];
			$params['patient_id']			=   $patient_id;
			$params['appointment_type']		= 	$appointment->appointment_type;
			$response						=	$this->getProviderTime($params);
			return $response;
		}

	}

	public function sendAppointmentReschedulePatientSMS($old_appointment_datetime,$smsBody,$appointment,$request, $account)
	{
		if(!empty($smsBody)) {
			$twilio_response 	= array();
			$database_name 		= $request->session()->get('database');
			config(['database.connections.juvly_practice.database'=> $database_name]);
			$clinic 				= Clinic :: findOrFail($appointment->clinic_id);
			$location 			= array();

			$services = array();

			$cancelation_fee_charge_days	= 0;
			$cancelation_fees				= 0;
			$accountPrefData				= AccountPrefrence::where('account_id', $account->id)->first();

			if ( count((array)$accountPrefData) > 0 ) {
				$cancelation_fee_charge_days = $accountPrefData['cancelation_fee_charge_days'];
			}

			if ( $cancelation_fee_charge_days <= 1 ) {
				$cancelation_fee_charge_days = '24 Hrs';
			} else {
				$cancelation_fee_charge_days =  $cancelation_fee_charge_days . ' Days';
			}

			$cancelation_fees				= '$'.number_format(@$account->cancellation_fees, 2);

			if(count((array)$appointment->services)>0) {
				foreach ( $appointment->services as $appServices ) {
					$services[] = ucfirst($appServices->name);
				}
			}

			if(!empty($clinic->address)){
				$location[] 		= $clinic->address;
			}

			if (!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
				$location[] 		= $clinic->clinic_city;
			} else if(!empty($clinic->city)){
				$location[] 		= $clinic->city;
			}
			if(count((array)$location)>0) {
				$clinic_location = implode(",",$location);
			} else {
				$clinic_location = '';
			}

			if(!empty($clinic->email_special_instructions))	{

				$email_special_instructions  = $clinic->email_special_instructions;

			} else {
				$email_special_instructions = '';
			}

			$getSession = Session::all();
			$account_id 	= trim($getSession['account_preference']->account_id);
			$phpDateFormat = $this->phpDateFormat($account_id);

			$time							= date("H:i:s",strtotime($appointment->appointment_datetime));
			$date 							= date($phpDateFormat,strtotime($appointment->appointment_datetime));
			//$appointment_time 				= date('g:i a',strtotime($time));
			$appointment_time 				= changeFormatByPreference(@$time, null, true, true );
			$appointment_date_time 			= date('l',strtotime($date)).' '.$date.' @ '.$appointment_time;

			$replace 						= array();
			$replace['PROVIDERNAME'] 		= '';
			$replace['CLINICNAME']			= ucfirst($clinic->clinic_name);
			$replace['APPOINTMENTDATETIME']	= $appointment_date_time;
		//	$replace['PATIENTNAME'] 		= ucfirst($this->user->firstname ." ". $this->user->lastname);
			$replace['PATIENTNAME'] 		= ucfirst($this->user->firstname);
			$replace['CLINICLOCATION']		= $clinic_location;
			$replace['CLINICINSTRUCTIONS']	= $email_special_instructions;
			$replace['BOOKEDSERVICES']		= implode(', ',$services);
			$replace['SERVICEINSTRURL']		= '';
			$replace['CANFEECHARGEDAYS']	= $cancelation_fee_charge_days;
			$replace['CANCELATIONFEES']		= $cancelation_fees;

			$tags							=  array();
			$tags['PATIENTNAME'] 			= "{{PATIENTNAME}}";
			$tags['CLINICNAME']				= "{{CLINICNAME}}";
			$tags['APPOINTMENTDATETIME']	= "{{APPOINTMENTDATETIME}}";
			$tags['CLINICLOCATION']			= "{{CLINICLOCATION}}";
			$tags['PROVIDERNAME']			= "{{PROVIDERNAME}}";
			$tags['CLINICINSTRUCTIONS']		= "{{CLINICINSTRUCTIONS}}";
			$tags['BOOKEDSERVICES']			= "{{BOOKEDSERVICES}}";
			$tags['SERVICEINSTRURL']		= "{{SERVICEINSTRURL}}";
			$tags['CANFEECHARGEDAYS']			= "{{CANFEECHARGEDAYS}}";
			$tags['CANCELATIONFEES']			= "{{CANCELATIONFEES}}";

			$encoded_account_id = $this->encryptKey($account->id);
			$meeting_id 		=  $appointment->meeting_id;
			$appointment_type 	= $appointment->appointment_type;
			if($appointment_type == "virtual") {
				//~ $smsBody .= "\n"."Your Appointment Meeting Link is https://meeting.aestheticrecord.com/?m=".$meeting_id."&u=3accd955bde83ab8af6ec833742ac748&a=".$encoded_account_id;
				$meeting_link = config('constants.urls.top_box');
				$replace['MEETINGLINK'] = "\n"."Your Appointment Meeting Link is $meeting_link/client/".$meeting_id;

			}else{
				$replace['MEETINGLINK'] = "";
			}
			$tags['MEETINGLINK']			= "{{MEETINGLINK}}";

			foreach( $tags as $key => $val ) {
				if ( $val ) {
					 $smsBody  = str_replace($val,$replace[$key], $smsBody);
				}
			}

			$accountData	 = $request->session()->get('account_detail');
			$logged_in_patient = $request->session()->get('logged_in_patient');
			$logged_in_patient_phone = $logged_in_patient->phoneNumber;
			$to = $logged_in_patient_phone;

            if ($account->getKey() === config('app.juvly_account_id')) {
                $this->sendSMS($logged_in_patient_phone, $smsBody, $accountData);
            } else {
                if (!empty($to)) {
                    $sms_response = $this->sendSMS($to, $smsBody, $account);
                    if ($sms_response) {
                        if (!$this->checkSmsLimit() && $this->checkSmsAutofill()) {
                            $this->updateUnbilledSms();
                        } else {
                            $this->saveSmsCount();
                        }
                        return true;
                    } else {
                        return true;
                    }
                } else {
                    return true;
                }
            }

			//~ try {
				//~ $twilio_response = Twilio::message($to, $smsBody);
            //~ } catch (\Exception $e) {
				//~ if($e->getCode() == 21211) {
					//~ $message = $e->getMessage();
				//~ }
            //~ }
			//~
			//~ if(count($twilio_response)>0){
				//~ //echo "<pre>"; print_r($twilio_response);
				//~ if($twilio_response->media->client->last_response->error_code != ''){
					//~ return false;
				//~ } else {
					//~ return true;
				//~ }
			//~ }
		}
	}

	public function sendAppointmentCancelPatientSMS($smsBody,$appointment,$request)
	{
		if(!empty($smsBody)) {
			$twilio_response 	= array();
			$database_name 		= $request->session()->get('database');
			config(['database.connections.juvly_practice.database'=> $database_name]);
			$clinic 				= Clinic :: findOrFail($appointment->clinic_id);

			$services = array();

			if(count((array)$appointment->services)>0) {
				foreach ( $appointment->services as $appServices ) {
					$services[] = ucfirst($appServices->name);
				}
			}

			$location 			= array();
			if(!empty($clinic->address)){
				$location[] 		= $clinic->address;
			}

			if (!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
				$location[] 		= $clinic->clinic_city;
			} else if(!empty($clinic->city)){
				$location[] 		= $clinic->city;
			}
			if(count((array)$location)>0) {
				$clinic_location = implode(",",$location);
			} else {
				$clinic_location = '';
			}
			if(!empty($clinic->email_special_instructions))	{

				$email_special_instructions  = $clinic->email_special_instructions;

			} else {
				$email_special_instructions = '';
			}

			$getSession 	= Session::all();
			$account_id 	= trim($getSession['account_preference']->account_id);
			$phpDateFormat 	= $this->phpDateFormat($account_id);

			$time							= date("H:i:s",strtotime($appointment->appointment_datetime));
			$date 							= date($phpDateFormat,strtotime($appointment->appointment_datetime));
			//$appointment_time 				= date('g:i a',strtotime($time));
			$appointment_time 				= changeFormatByPreference(@$time, null, true, true );
			$appointment_date_time 			= date('l',strtotime($date)).' '.$date.' @ '.$appointment_time;

			$replace 						= array();
			$replace['PROVIDERNAME'] 		= '';
			$replace['CLINICNAME']			= ucfirst($clinic->clinic_name);
			$replace['APPOINTMENTDATETIME']	= $appointment_date_time;
			//$replace['PATIENTNAME'] 		= ucfirst($this->user->firstname ." ". $this->user->lastname);
			$replace['PATIENTNAME'] 		= ucfirst($this->user->firstname);
			$replace['CLINICLOCATION']		= $clinic_location;
			$replace['CLINICINSTRUCTIONS']	= $email_special_instructions;
			$replace['BOOKEDSERVICES']		= implode(', ',$services);
			$replace['SERVICEINSTRURL']		= '';

			$tags							= array();
			$tags['PATIENTNAME'] 			= "{{PATIENTNAME}}";
			$tags['CLINICNAME']				= "{{CLINICNAME}}";
			$tags['APPOINTMENTDATETIME']	= "{{APPOINTMENTDATETIME}}";
			$tags['CLINICLOCATION']			= "{{CLINICLOCATION}}";
			$tags['PROVIDERNAME']			= "{{PROVIDERNAME}}";
			$tags['CLINICINSTRUCTIONS']		= "{{CLINICINSTRUCTIONS}}";
			$tags['BOOKEDSERVICES']			= "{{BOOKEDSERVICES}}";
			$tags['SERVICEINSTRURL']		= "{{SERVICEINSTRURL}}";


			foreach( $tags as $key => $val ) {
				if ( $val ) {
					 $smsBody  = str_replace($val,$replace[$key], $smsBody);
				}
			}

			$account		= $request->session()->get('account_detail');

			$logged_in_patient = $request->session()->get('logged_in_patient');
			$logged_in_patient_phone = $logged_in_patient->phoneNumber;
            $to = $logged_in_patient_phone;
            if ($account->getKey() === config('app.juvly_account_id')) {
                $this->sendSMS($logged_in_patient_phone, $smsBody, $account);
            } else {
                if (!empty($to)) {
                    $sms_response = $this->sendSMS($to, $smsBody, $account);
                    if ($sms_response) {
                        if (!$this->checkSmsLimit() && $this->checkSmsAutofill()) {
                            $this->updateUnbilledSms();
                        } else {
                            $this->saveSmsCount();
                        }
                        return true;
                    } else {
                        return true;
                    }
                } else {
                    return true;
                }
            }

			//~ try {
				//~ $twilio_response = Twilio::message($to, $smsBody);
            //~ } catch (\Exception $e) {
				//~ if($e->getCode() == 21211) {
					//~ $message = $e->getMessage();
				//~ }
            //~ }
			//~
			//~ if(count($twilio_response)>0){
				//~ //echo "<pre>"; print_r($twilio_response);
				//~ if($twilio_response->media->client->last_response->error_code != ''){
					//~ return false;
				//~ } else {
					//~ return true;
				//~ }
			//~ }
		}

	}

	public function getAppointmentEndTime(Request $request)
	{
		if($request->input() != null) {
			$duration 				= $request->input('duration');
			$date 					= date("Y-m-d", strtotime($request->input('date')));
			$time 					= $request->input('time');
			$date_time_set			= $date." ".$time;
			$date_time_set			= date("Y-m-d H:i:s", strtotime($date_time_set));
			$endTimeString 			= $date_time_set." +".$duration." minutes";
			$endTime				= date("h:i A",strtotime($endTimeString));
			$response_array 		= array('endTime' => $endTime, 'status'=> 'success');
		} else {
			$response_array 		= array('endTime' => '', 'status'=> 'error');

		}
		return  json_encode($response_array);

	}

	private function chargeCustomer($patientID, $account = array(), $appointment_id = null,$timezone = null, $transaction, $rescheduled="false")
	{
		$response  = array();

		if ( count((array)$account) > 0 ) {
			$accountID				= $account['id'];
			$dbname					= $account['database_name'];
			$cardsOnfilesData 		= PatientCardOnFile::where('patient_id', $patientID)->first();
			$accountStripeConfig	= AccountStripeConfig::where('account_id', $accountID)->first();

			if ( count((array)$accountStripeConfig) > 0 ) {
				if ( count((array)$cardsOnfilesData) ) {
					$customerTokenID		= $cardsOnfilesData["card_on_file"];
					$host_transaction_id 	= $transaction->authorize_transaction_id;
					$cancelation_fee		= $transaction->cancellation_fee;
					$platformFee			= $accountStripeConfig->platform_fee;
					//~ $stripeUserID			= $accountStripeConfig->stripe_user_id;
					$stripeUserID			= $transaction->stripe_user_id;
					$platformFee			= ($cancelation_fee * $platformFee ) / 100;

					$accountName			= isset($account['name']) && !empty($account['name']) ? $account['name'] : 'Aesthetic Record';
					$accountName			= substr(strtoupper(str_replace(' ', '', $accountName)), 0, 20);
					$accountName			= $this->cleanString($accountName);
					$accountName			= preg_replace('/[^A-Za-z0-9\-]/', '', $accountName);

					$accountCurrency		= $account['stripe_currency'];

					//~ $chargeArr	= array(
						//~ "amount" 	  			=> round($cancelation_fee, 2) * 100,
						//~ "customer" 	  			=> $customerTokenID,
						//~ "currency"	  			=> $accountCurrency,
						//~ "statement_descriptor"	=> strtoupper($accountName),
						//~ "description" 			=> 'Patient with id - ' . $patientID . ' charged with '.$accountCurrency.' ' . $cancelation_fee,
						//~ "application_fee" 		=> round($platformFee, 2) * 100,
						//~ "destination" 			=> array(
							//~ "account" 	=> $stripeUserID,
						//~ )
					//~ );

					$chargeArr	= array(
						"amount" 	  					=> round($cancelation_fee, 2) * 100,
						"customer" 	  					=> $customerTokenID,
						"currency"	  					=> $accountCurrency,
						//"statement_descriptor"			=> strtoupper($accountName),
						"statement_descriptor_suffix"	=> strtoupper($accountName),
						"description" 					=> 'Patient with id - ' . $patientID . ' charged with USD' . $cancelation_fee,
						"application_fee_amount" 		=> round($platformFee, 2) * 100,
						"on_behalf_of" 					=> $stripeUserID,
						"transfer_data"					=> array(
							"destination" => $stripeUserID
						)
					);

					$chargeCustomerResponse 	= callStripe('charges', $chargeArr);

					if ( count((array)$chargeCustomerResponse) && isset($chargeCustomerResponse->id) )
					{
						$hostTransactionID 						= $chargeCustomerResponse->id;
						$currentTime	= date('Y-m-d H:i:s');
						$currentTime	= $this->getCurrentTimeNewYork($currentTime);
						$clinicTimeZone			= isset($timezone) ? $timezone : 'America/New_York';
						date_default_timezone_set($clinicTimeZone);
						$todayDateTime 			= new DateTime(date('Y-m-d H:i:s'));
						$todayTimeZone 			= new DateTimeZone($clinicTimeZone);
						$todayDateTime->setTimezone($todayTimeZone);
						$todayInClinicTZ		= $todayDateTime->format('Y-m-d H:i:s');

						$transaction->charge_transaction_id 	= $hostTransactionID;
						$transaction->status 					= 'charged';
						$transaction->modified 					= $todayInClinicTZ;
						$cancellationTransID = $transaction->id;
						$transaction->save();

						if ( $rescheduled && $rescheduled == "true" ) {
							$transaction							= new AppointmentCancellationTransaction();
							$transaction->appointment_id			= $appointment_id;
							$transaction->status					= 'authorised';
							$transaction->authorize_transaction_id	= '1111111111';
							$transaction->cancellation_fee			= $cancelation_fee;
							$transaction->created					= $currentTime;
							$transaction->modified					= $currentTime;
							$transaction->stripe_user_id			= $stripeUserID;
							$saved 									= $transaction->save();
						}
						if($hostTransactionID){
							$this->createCancellationInvoice($chargeCustomerResponse, $account, $patientID, $appointment_id, $cancellationTransID);
						}
						$response = array('status'=>'success','msg' => 'Transaction successfully completed');
					} else {
						$this->savePatientNoteIfThereIsError($patientID, '2');
						$response = array('status'=>'success','msg' => 'Transaction successfully completed');
					}
				} else {
					$this->savePatientNoteIfThereIsError($patientID, '1');
					$response = array('status'=>'success','msg' => 'Transaction successfully completed');
				}
			} else {
				$response = array('status'=>'error','msg' => 'Invalid account configuration');
			}
		} else {
			$response = array('status'=>'error','msg' => 'Invalid account detail');
		}
		return $response;
	}

	protected  function savePatientNoteIfThereIsError($patientID, $errorType)
	{
		$note	= "Unable to charge Cancellation Fee as Error occurred while charging from Processor";
		if ( $errorType == '1' ) {
			$note	= "Unable to charge Cancellation Fee as No card found in Customer profile";
		}
		$addedBY						= ucfirst(trim($this->user["firstname"])).' '.ucfirst(trim($this->user["lastname"]));

		$patientNote					= new PatientNote();
		$patientNote->user_id			= $patientID;
		$patientNote->patient_id		= $patientID;
		$patientNote->notes				= $note;
		$patientNote->added_by			= $addedBY;
		$patientNote->created			= date("Y-m-d H:i:s");

		$saved 							= $patientNote->save();
	}

	public function getPostTreatmentInstruction(Request $request,$id = null,$appointment_id = null,$service_id = null)
	{
		if($id){

			$database_name = $request->session()->get('database');
			$patient_id 	= $request->session()->get('patient_id');
			config(['database.connections.juvly_practice.database'=> $database_name]);
			$treatment_instruction 	= 	PostTreatmentInstruction :: where('id',$id)->first();
			return view('app.treatment_instructions.ajax.post_treatment_instruction_popup',compact('treatment_instruction','appointment_id','service_id'))->render();


		}else{
			return view('errors.503');
		}

	}

	private function getAprivaAccountDetail($patientID, $account = array(),$appointment_id = null,$timezone = null, $transaction, $rescheduled="false")
	{
		$response  = array();

		if ( count((array)$account) > 0 ) {

			$dbname				= $account['database_name'];
			$storagefolder 		= $account['storage_folder'];
			$aprivaProductId 	= $account['pos_product_id'];
			$aprivaClientId		= $account['pos_client_id'];
			$aprivaClientSecret	= $account['pos_secret'];
			$aprivaPlatformKey	= $account['pos_platform_key'];
			$aprivaPosEnabled	= $account['pos_enabled'];
			$uniqueIdentifier	= $this->generateRandomString(6)."-".$this->generateRandomString(5)."-".$this->generateRandomString(4);

			$cardsOnfilesData 	=	PatientCardOnFile::where('patient_id', $patientID)->first();

			if ( count((array)$cardsOnfilesData) ) {
				$cardToken 				= $cardsOnfilesData["card_on_file"];
				$access_token 			= connect($aprivaProductId, $aprivaClientId, $aprivaClientSecret, $aprivaPlatformKey);

				if ( $access_token ) {
					$host_transaction_id 	=	$transaction->authorize_transaction_id;
					$cancelation_fee		=	$transaction->cancellation_fee;

					$postdata 			= array(
						'TransactionData' => array(
							'UniqueIdentifier' => $uniqueIdentifier,
							'TimeStamp' => date('c'),
							'TotalAmount' => $cancelation_fee
						),
						'AmountsReq' => array(
							'SubTotalAmount' => $cancelation_fee,
							'TaxAmount' => 0,
							'TipAmount' => 0
						),
						'PaymentData' => array(
							'EntryMethod' => 'CardOnFile',
							'CardData' => array(
								'CardOnFileData' => array(
									'Token' => $cardToken
								)
							)
						)
					);


					//$authorize_response		= 	credit_post_authorization($access_token, $aprivaPlatformKey,$uniqueIdentifier,$host_transaction_id,$cancelation_fee);

					$authorize_response		= 	sale_credit_card_on_file($access_token, $aprivaPlatformKey,$postdata);



					if(isset($authorize_response->Result->ResponseCode) && $authorize_response->Result->ResponseCode == 0)
					{
						$hostTransactionID 						= $authorize_response->TransactionResultData->HostTransactionID;

						$clinicTimeZone			= isset($timezone) ? $timezone : 'America/New_York';
						date_default_timezone_set($clinicTimeZone);
						$todayDateTime 			= new DateTime(date('Y-m-d H:i:s'));
						$todayTimeZone 			= new DateTimeZone($clinicTimeZone);
						$todayDateTime->setTimezone($todayTimeZone);
						$todayInClinicTZ		= $todayDateTime->format('Y-m-d H:i:s');

						$transaction->charge_transaction_id 	= $hostTransactionID;
						$transaction->status 					= 'charged';
						$transaction->modified 					= $todayInClinicTZ;

						$transaction->save();

						if ( $rescheduled && $rescheduled == "true" ) {
							$transaction							= new AppointmentCancellationTransaction();
							$transaction->appointment_id			= $appointment_id;
							$transaction->status					= 'authorised';
							$transaction->authorize_transaction_id	= '1111111111';
							$transaction->cancellation_fee			= $cancelation_fee;
							$transaction->created					= date('Y-m-d H:i:s');
							$transaction->modified					= date('Y-m-d H:i:s');
							$saved 									= $transaction->save();
						}

						$response = array('status'=>'success','msg' => 'Transaction successfully completed');
					} else {
						$this->savePatientNoteIfThereIsError($patientID, '2');
						$response = array('status'=>'success','msg' => 'Transaction successfully completed');
					}
					disconnect($access_token);
				} else {
					$response = array('status'=>'error','msg' => 'Unable to connect with payment gateway, please try again');
				}
			} else {
				$this->savePatientNoteIfThereIsError($patientID, '1');
				$response = array('status'=>'success','msg' => 'Transaction successfully completed');
			}
		} else {
			$response = array('status'=>'error','msg' => 'Invalid account detail');
		}
		return $response;
	}

	private function getAccountStripeConfig($accountID, $clinicID)
	{
		$stripeUserID = '';

		if ( count((array)$account) > 0 ) {
			$stripeConnectionType	= $account->stripe_connection;

			if ( $stripeConnectionType == 'global' ) {
				$clinic 			= 0;
			} else {
				$clinic 			= $clinicID;
			}

			$accountStripeConfig	= AccountStripeConfig::where('account_id', $accountID)->where('clinic_id', $clinicID)->first();

			if ( count((array)$accountStripeConfig) > 0 ) {
				$stripeUserID		= $accountStripeConfig->stripe_user_id;
			}
		}

		return $stripeUserID;
	}

	protected function savePreAndPostLog($appointmentID, $serviceID)
	{
		$apptData 				= Appointment::where('id', $appointmentID)->first();
		$patientID				= $apptData['patient_id'];
		$appointmentDateTime	= $apptData['appointment_datetime'];
		$allPreInstructions  	= ServiceTreatmentInstruction::where('service_id', $serviceID)->get();
		$allPostInstructions  	= ServicePostTreatmentInstruction::where('service_id', $serviceID)->get();


		if ( count((array)$allPreInstructions) ) {
			$preInstructions	= array();
			foreach ( $allPreInstructions as $eachPreInstruction ) {
				$preInstructionID 	= $eachPreInstruction['pre_treatment_instruction_id'];
				$preInstructions	= PreTreatmentInstruction::where('id', $preInstructionID)->first();

				if ( count((array)$preInstructions) ) {
					$daysBefore		= $preInstructions['days_before'];
					$dateBefore		= date("Y-m-d", strtotime("-".$daysBefore." day", strtotime($appointmentDateTime)));

					$this->savePrePostLog($dateBefore, $preInstructionID, $patientID, $appointmentID, $serviceID, 'pre');
				}
			}
		}

		if ( count((array)$allPostInstructions) ) {
			$postInstructions	= array();
			foreach ( $allPostInstructions as $eachPostInstruction ) {
				$postInstructionID 	= $eachPostInstruction['post_treatment_instruction_id'];
				$postInstructions	= PostTreatmentInstruction::where('id', $postInstructionID)->first();;

				if ( count((array)$postInstructions) ) {
					$daysAfter		= $postInstructions['days_after'];
					$dateAfter		= date("Y-m-d", strtotime("+".$daysAfter." day", strtotime($appointmentDateTime)));

					$this->savePrePostLog($dateAfter, $postInstructionID, $patientID, $appointmentID, $serviceID, 'post');
				}
			}
		}
	}

	protected function savePrePostLog($sendOn, $typeID, $patientID, $appointmentID, $serviceID, $type)
	{
		$today						= date("Y-m-d H:i:s");
		$prePostLog					= new PrepostInstructionsLog();
		$prePostLog->appointment_id	= $appointmentID;
		$prePostLog->service_id		= $serviceID;
		$prePostLog->patient_id		= $patientID;
		$prePostLog->type			= $type;
		$prePostLog->type_id		= $typeID;
		$prePostLog->send_on		= $sendOn;
		$prePostLog->status			= "pending";
		$prePostLog->created		= $today;
		$prePostLog->modified		= $today;
		$saved 						= $prePostLog->save();

	}

	protected function sendClinicBookingSMS($appointmentData, $request, $account, $smsType="reschedule")
	{
		$database_name 		= $request->session()->get('database');
		$provider_name		= '';
		$clinic_name 		= '';
		$date				= '';
		$time				= '';
		$twilio_response 	= array();

		$cancelation_fee_charge_days	= 0;
		$cancelation_fees				= 0;
		$accountPrefData				= AccountPrefrence::where('account_id', $account->id)->first();
		$cancelation_fees				= '$'.number_format(@$account->cancellation_fees, 2);

		config(['database.connections.juvly_practice.database'=> $database_name]);


		$services					= array();
		if ( isset($appointmentData->services) ) {
			if ( count((array)$appointmentData->services->toArray()) ) {
				foreach ($appointmentData->services->toArray() as $service) {
					$services[] = ucfirst($service['name']);
				}
			}
		}
		$provider = Users :: where('id',@$appointmentData->user_id)->first();
		if(count((array)$provider) > 0) {

			if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

				$provider_name = ucfirst($provider->bio_name);
			} else {

				$provider_name = ucfirst($provider->firstname." ".$provider->lastname);
			}

		} else {
			$provider_name='';
		}



		$clinic 		= Clinic::findOrFail(@$appointmentData->clinic_id);
		if(count((array)$clinic) > 0) {

			$clinic_name = ucfirst($clinic->clinic_name);

		}

		$location 			= array();
		if(!empty($clinic->address)){
			$location[] 		= $clinic->address;
		}

		if (!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
			$location[] 		= $clinic->clinic_city;
		} else if(!empty($clinic->city)){
			$location[] 		= $clinic->city;
		}
		if(count((array)$location)>0) {
			$clinic_location = implode(",",$location);
		} else {
			$clinic_location = '';
		}

		$getSession = Session::all();
		$account_id 	= trim($getSession['account_preference']->account_id);
		$phpDateFormat = $this->phpDateFormat($account_id);

		$dateOfAppt						= explode(' ', $appointmentData->appointment_datetime);
		$apptDate 						= date($phpDateFormat, strtotime($dateOfAppt[0]));
		$client_name = $this->getUserUsedClientName($account->id);
		if ( $smsType != "reschedule" ) {
			$headingText 	= "Appointment canceled by customer using ".ucfirst($client_name)." Portal";
			$dateTimeText	= "Appt Date Time Was";
		} else {
			$headingText	 = "Appointment rescheduled by customer using ".ucfirst($client_name)." Portal";
			$dateTimeText	= "Appt Date Time";
		}

		//$appointment_time 				= date('g:i a',strtotime(@$appointmentData->appointment_datetime));
		$appointment_time 				= changeFormatByPreference(@$appointmentData->appointment_datetime, null, true, true );
		$appointment_date_time			= date('l',strtotime(@$appointmentData->appointment_datetime)).' '.$apptDate.' @ '.$appointment_time;
		$smsBody						= $headingText . "\n";
		$smsBody						.= ucfirst($client_name)." : " .  ucfirst($this->user->firstname) . "\n";
		$smsBody						.= "Provider : " . $provider_name . "\n";
		$smsBody						.= "Clinic : " . $clinic_name . "\n";
		$smsBody						.= "Location : " . $clinic_location . "\n";
		$smsBody						.= $dateTimeText  .  " : "  . $appointment_date_time . "\n";
		$smsBody						.= "Services : " . implode(', ',$services) . "\n";

		$to 							= $clinic->sms_notifications_phone;

		if ( !empty($to) ) {
            $sms_response = $this->sendSMS($to, $smsBody, $account);
            if ($account->getKey() !== config('app.juvly_account_id')) {
                if ($sms_response) {
                    if (!$this->checkSmsLimit() && $this->checkSmsAutofill()) {
                        $this->updateUnbilledSms();
                    } else {
                        $this->saveSmsCount();
                    }
                    return true;
                } else {
                    return true;
                }
            }
		} else {
			return true;
		}
	}

	public function encryptKey($account_id){
		#encrypt
		$plaintext 			= $account_id;
		$ivlen 				= openssl_cipher_iv_length($cipher="AES-128-CBC");
		$key				= 'rozer';
		$iv 				= openssl_random_pseudo_bytes($ivlen);
		$ciphertext_raw 	= openssl_encrypt($plaintext, $cipher, $key, $options=OPENSSL_RAW_DATA, $iv);
		$hmac 				= hash_hmac('sha256', $ciphertext_raw, $key, $as_binary=true);
		$ciphertext 		= base64_encode( $iv.$hmac.$ciphertext_raw );
		$ciphertext			= str_replace("/", "[]", $ciphertext);
		return $ciphertext;
	}

	private function createCancellationInvoice($charge_data, $account, $patient_id, $appointment_id, $cancellationTransID, $pos_gateway = null){
		$account_id = $account['id'];
		$db = $account['database_name'];
		$appointmentDetail = Appointment::where('id',$appointment_id)->with('appointment_sevices')->first();
		$patient = Patient::where('id',$patient_id)->first();
		$clinicID = $appointmentDetail->clinic_id;
		//~ $service_ids = [];
		//~ if(!empty($appointmentDetail['appointment_sevices'])){
			//~ foreach($appointmentDetail['appointment_sevices'] as $appointment_sevices){
				//~ $service_ids[] = $appointment_sevices->service_id;
			//~ }
		//~ }
		$invoiceNumber 		= 'AR00'.$account_id.'0'.$patient_id.'0'.time();
		$customerCardBrand = '';
		$customerCardLast4 = '';
        $apriva_transaction_data = null;
        if (!empty($pos_gateway) && $pos_gateway == "clearent") {
            if (!empty($charge_data["payload"]["tokenResponse"]) && isset($charge_data["payload"]["tokenResponse"])) {
                $customerCardBrand = $charge_data["payload"]["tokenResponse"]["card-type"];
            } else {
                $customerCardBrand = $charge_data["payload"]["transaction"]["card-type"] ?? '';
            }
            $customerCardLast4 = $charge_data["payload"]["transaction"]["last-four"];
            $total_amount = $charge_data["payload"]["transaction"]["amount"];
            $apriva_transaction_data = json_encode($charge_data["payload"]);
        } else {
            if (isset($charge_data->source->brand)) {
                $customerCardBrand = $charge_data->source->brand;
            }
            if (isset($charge_data->source->last4)) {
                $customerCardLast4 = $charge_data->source->last4;
            }

            $total_amount = $charge_data->amount / 100;
        }
		$currentTime	= date('Y-m-d H:i:s');

		$currentTime	= $this->getCurrentTimeNewYork($currentTime);
		//~ $services = Service::whereIn('id',$service_ids)->get();
		//~ $service_names = [];
		//~ if(!empty($services)){
			//~ foreach($services as $service){
				//~ $service_names[] = $service->name;
			//~ }
		//~ }
		//~ $service_name_string = '';
		//~ if(!empty($service_names)){
			//~ $service_name_string = implode(',', $service_names);
		//~ }
        $platformFee = $charge_data['platformFee'];
		$posInvoiceData	= array(
			'invoice_number' 					=> $invoiceNumber,
			'customerCardBrand' 				=> $customerCardBrand,
			'customerCardLast4' 				=> $customerCardLast4,
			'patient_id' 						=> $patient_id,
			'clinic_id' 						=> $clinicID,
			'sub_total' 						=> $total_amount,
			'total_tax' 						=> 0,
			'total_amount' 						=> $total_amount,
			'treatment_invoice_id' 				=> 0,
			'patient_email' 					=> $patient->email,
			'status'							=> "paid",
			'created'							=> $currentTime,
			'paid_on'							=> $currentTime,
			'product_type'						=> 'custom',
			'monthly_amount'					=> 0,
			'one_time_amount'					=> 0,
			'total_discount'					=> 0,
			'title'								=> 'cancellation_fee',
			'appointment_cacellation_transaction_id'					=> $cancellationTransID,
			'product_units'						=> 1,
            'platformFee'						=> $platformFee,
            'apriva_transaction_data'			=> $apriva_transaction_data
		);
		$posInvoiceData['custom_product_name'] = 'Appointment Cancellation/Rescheduling Charges';
        if (!empty($pos_gateway) && $pos_gateway == "clearent") {
            $posInvoiceData['host_transaction_id'] = $charge_data["payload"]["transaction"]["id"];
        } else {
            $posInvoiceData['host_transaction_id'] = $charge_data->id;
        }
		$invoice_id  = (new SubcriptionController)->createPosInvoice($posInvoiceData, 'custom', $appointmentDetail->user_id,$pos_gateway);
		$posInvoiceData['invoice_id'] = $invoice_id;
		$posInvoiceData['account_id'] = $account_id;
		$posInvoiceData['admin_id'] = $account['admin_id'];
		return $this->sendCancellationInvoiceEmail($posInvoiceData);

	}

	private function sendCancellationInvoiceEmail($posInvoiceData)
	{
		$patientID 		= 	$posInvoiceData['patient_id'];
		$amount 		=	$posInvoiceData['total_amount'];
		$invoice_id		=	$posInvoiceData['invoice_id'];
		$account_id		=	$posInvoiceData['account_id'];
		$admin_id		=	$posInvoiceData['admin_id'];

		$user 				= Users::find($admin_id);
		$accountData 		= Account::with('accountPrefrence')->find($account_id);
		$from_email         = config('mail.from.address');
		$replyToEmail      	= config('mail.from.address');
		if($accountData['accountPrefrence']->from_email){
			$replyToEmail  	= $accountData['accountPrefrence']->from_email;
		}
		$patientData 		= Patient::find($patientID);

		$Invoices 			= PosInvoice::with('posInvoiceItems')->find($invoice_id);
		$business_name = "";
		$clinic = Clinic::where('id',$Invoices->clinic_id)->first();
		$clinic_name = $clinic->clinic_name;
		$patientEmail		= $patientData->email;
		$patientName		= $patientData->firstname.' '.$patientData->lastname;
		if(!empty($patientEmail)) {
			$storagefolder			= '';
			$storagefolder 			= $accountData->storage_folder;
			$logo_img_src 			= '';
			$media_path = public_path();
			//$media_url = url('/');
			$media_url = public_path();
			$ar_media_path = rtrim(config('constants.urls.media.bucket'), '/');
            if (isset($accountData->logo) && $accountData->logo != '') {
                $logo_img_src = "{$ar_media_path}/{$storagefolder}/admin/{$accountData->logo}";
            } else {
                if($account_id === config('app.juvly_account_id')) {
                    $logo_img_src = config('constants.juvly.no_logo_pdf');
                } else {
                    $logo_img_src = config('constants.ar.no_logo_pdf');
                }
            }
			$filename		= '';
			$attachments	= null;
			$email_content 	= '';
			$subject 		= "Appointment Cancellation/Rescheduling Charges";
			$data = [];
			$data['invoice_amount'] = $amount;
			$data['invoice_data'] = $Invoices;
			$data['logo_img_src'] = $logo_img_src;
			$data['name'] = config('constants.ar.name');
			$data['address'] = config('constants.ar.address');
			$data['patient_data'] = $patientData;
			$data['account_data'] = $accountData;
			$data['clinic_name'] = $clinic_name;
			$data['total_amount'] = $posInvoiceData['total_amount'];
			$data['date_format'] = $accountData['accountPrefrence']->date_format;
			$data['custom_product_label'] = '';
			$data['customerCardLast4'] = $posInvoiceData['customerCardLast4'];
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
			$site_url			= config('constants.urls.site');


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
			$view 	= \View::make('appointments.cancel_charge_invoice_template', ['data' => $data,'clinic_location' => $clinic_location,'account_logo' => $account_logo, 'site_url' => $site_url,'storage_folder' => $storage_folder,'appointment_status' => $appointment_status, 'clinic_address'=>$clinic_address]);


			$mail_body 	= "Dear $patientName,<br><br>

We noticed you canceled your upcoming appointment. Please see your attached receipt for this one time charge per our cancellation policy. If you would like to reschedule, please contact our practice or rebook online. <br><br>

Sincerely,<br>
$account_name";
			$email_content 		= $this->getEmailTemplate($mail_body, $accountData, $clinic, $subject);
			if($amount > 0 ){
				$pdf = \PDF::loadView('appointments.cancel_charge_invoice_template', ['data' => $data]);
				$invoive_title 		= rand(10,100).$account_id.$patientID.$invoice_id.rand(10,100).date('ymdhis');
				$dir 			= $media_path.'/stripeinvoices/';
				$filename 			= $dir.$invoive_title.".pdf";
				$pdf->save($filename,'F');
				$attachments 	= $media_url.'/stripeinvoices/'.$invoive_title.'.pdf';
			}
			return $this->sendEmail($from_email, $patientEmail, $replyToEmail, $email_content, $subject, $attachments, $posInvoiceData['invoice_number']);
		}
	}

    public function chargeUsingClearent($request, $appointment, $patientID, $account = array(), $appointment_id = null, $timezone = null, $transaction, $rescheduled = "false")
    {
        $ip = null;
        if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }

        if (count((array)$account) > 0) {
            $cancellation_fees = 0;
            $dbname = $account['database_name'];
            $cancelation_fee = $account['cancellation_fees'];
            $clinicID = $appointment->clinic_id;
            $stripeConnectionType = $account['stripe_connection'];
            if ($stripeConnectionType == 'global') {
                $clinicID = 0;
            } else {
                $clinicID = $clinicID;
            }
            $stipeCon = [
                ['account_id', $account['id']],
                ['clinic_id', $clinicID]
            ];
            $this->switchDatabase(config('database.default_database_name'));
            $accountClearentConfig = AccountClearentConfig::where($stipeCon)->first();
            if ($accountClearentConfig) {
                $accountClearentConfig = $accountClearentConfig->toArray();
            } else {
                $accountClearentConfig = array();
            }

            if (empty($accountClearentConfig)) {
                $responseArray = array("status" => "error", "data" => array(), "message" => "Unable to process: Clearent connection not found");
            }
            $this->switchDatabase($dbname);

            $cardsOnfilesData = PatientCardOnFile::where('patient_id', $patientID)->first();
            $patient = Patient::where('id', $patientID)->first();
            $stripeUserID = $accountClearentConfig['merchant_id'];
            $platformFee = $accountClearentConfig['platform_fee'];
            $bookAppointMent = Session::get('bookAppointMent');

            if (count((array)$cardsOnfilesData)) {

                $customerTokenID = $cardsOnfilesData["card_on_file"];
                $cardExpiryDate = $cardsOnfilesData["card_expiry_date"];
                $cancelation_fee = $transaction->cancellation_fee;

                $accountName = !empty($account['name']) ? $account['name'] : 'Aesthetic Record';
                $accountName = substr(strtoupper(str_replace(' ', '', $accountName)), 0, 20);
                $accountName = $this->cleanString($accountName);
                $accountName = preg_replace('/[^A-Za-z0-9\-]/', '', $accountName);

                $accountCurrency = $account['stripe_currency'];
                //~ $currencyMinAmnt		= $this->stripeMinimumAmount($accountCurrency);

                $headers = [
                    'content-type: application/json',
                    'accept: application/json',
                    'api-key: ' . $accountClearentConfig['apikey']
                ];
                $endPoint = rtrim(config('clearent.payment_url'), '/') . '/transactions/sale';
                $invoice_number = 'AR00' . $account['id'] . '0' . $patientID . '0' . time();
                $postData = array(
                    "type" => 'SALE',
                    "exp-date" => $cardExpiryDate,
                    "amount" => number_format((float)$cancelation_fee, 2, '.', ''),
                    "card" => $customerTokenID,
                    "description" => 'Patient with id - ' . $patientID . ' charged with USD for cancellation fee',
                    "order-id" => $appointment->id,
                    //~ "invoice"  => $invoice_number ?? '',
                    "email-address" => $patient->email ?? '',
                    "customer-id" => (new BookController($request))->getClearentCustomerData($patientID) ?? '',
                    'software-type' => config('clearent.software.type'),
                    'software-type-version' => config('clearent.software.version'),
                    "client-ip" => isset($ip) ?? null,
                    "billing" => ["zip" => $cardsOnfilesData['billing_zip'] ?? ''],
                );

                $response_data = [];
                $response_data = Clearent::curlRequest($endPoint, $headers, $postData, 'POST');
                if (isset($response_data["result"]) && !empty($response_data["result"])) {
                    $clearent_array = json_decode(json_encode($response_data["result"]), true);
                    if ($clearent_array['code'] == 200) {
                        $clearent_array['platformFee'] = $platformFee;
                        $hostTransactionID = $clearent_array["payload"]["transaction"]["id"];
                        $currentTime = date('Y-m-d H:i:s');
                        $currentTime = $this->getCurrentTimeNewYork($currentTime);
                        $clinicTimeZone = isset($timezone) ? $timezone : 'America/New_York';
                        date_default_timezone_set($clinicTimeZone);
                        $todayDateTime = new DateTime(date('Y-m-d H:i:s'));
                        $todayTimeZone = new DateTimeZone($clinicTimeZone);
                        $todayDateTime->setTimezone($todayTimeZone);
                        $todayInClinicTZ = $todayDateTime->format('Y-m-d H:i:s');

                        $transaction->charge_transaction_id = $hostTransactionID;
                        $transaction->status = 'charged';
                        $transaction->modified = $todayInClinicTZ;
                        $cancellationTransID = $transaction->id;
                        $transaction->save();

                        if ($rescheduled && $rescheduled == "true") {
                            $transaction = new AppointmentCancellationTransaction();
                            $transaction->appointment_id = $appointment_id;
                            $transaction->status = 'authorised';
                            $transaction->authorize_transaction_id = '1111111111';
                            $transaction->cancellation_fee = $cancelation_fee;
                            $transaction->created = $currentTime;
                            $transaction->modified = $currentTime;
                            $transaction->stripe_user_id = $stripeUserID;
                            $saved = $transaction->save();

                        }
                        if ($hostTransactionID) {
                            $gatewayType = 'clearent';
                            $this->createCancellationInvoice($clearent_array, $account, $patientID, $appointment_id, $cancellationTransID, $gatewayType);
                        }
                        $responseArray = array('status' => 'success', 'msg' => 'Transaction successfully completed');
                    } else {
                        $this->clearentFailedTransactions('', $clearent_array);
                        $this->savePatientNoteIfThereIsError($patientID, '2');
                        $responseArray = array('status' => 'success', 'msg' => 'Transaction successfully completed');
                    }
                } else {
                    $this->savePatientNoteIfThereIsError($patientID, '2');
                    $responseArray = array('status' => 'success', 'msg' => 'Transaction successfully completed');
                }
            } else {
                $this->savePatientNoteIfThereIsError($patientID, '1');
                $responseArray = array('status' => 'success', 'msg' => 'Transaction successfully completed');
            }
        } else {
            $responseArray["message"] = "An error occured - " . $result_set["message"];
        }
        return $responseArray;
    }

    private function clearentFailedTransactions($invoice_id, $response)
    {
        ClearentFailedTransaction::create([
            'invoice_id' => $invoice_id ?? 0,
            'clearent_response' => json_encode($response),
            'created' => date('Y-m-d H:i:s')
        ]);
    }
}
