<?php

namespace App\Http\Controllers;

use App\Account;
use App\AccountClearentConfig;
use App\AccountCommunication;
use App\AccountPrefrence;
use App\AccountStripeConfig;
use App\Appointment;
use App\AppointmentBooking;
use App\AppointmentCancellationTransaction;
use App\AppointmentReminderConfiguration;
use App\AppointmentReminderLog;
use App\AppointmentService;
use App\BookingPayment;
use App\ClearentFailedTransaction;
use App\Client;
use App\ClientsAccount;
use App\ClientsLogin;
use App\Clinic;
use App\GoogleAdwordsRoi;
use App\Helpers\BookingHelper;
use App\Helpers\EmailHelper;
use App\Helpers\TelehealthHelper;
use App\Package;
use App\Patient;
use App\PatientCardOnFile;
use App\PatientWallet;
use App\PostTreatmentInstruction;
use App\PrepostInstructionsLog;
use App\PreTreatmentInstruction;
use App\Provider;
use App\Service;
use App\ServiceCategory;
use App\ServiceCategoryAssoc;
use App\ServiceClinic;
use App\ServiceNotClubbable;
use App\ServicePackage;
use App\ServicePostTreatmentInstruction;
use App\ServiceTreatmentInstruction;
use App\SurveySmsLog;
use App\TimeZone;
use App\Traits\Clearent;
use App\Traits\TouchMd;
use App\User;
use App\UserLog;
use App\Users;
use Auth;
use Carbon\Carbon;
use Cartalyst\Stripe\Stripe;
use Config;
use Crypt;
use DateTime;
use DateTimeZone;
use DB;
use Exception;
use Hashids\Hashids;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Session;
use Twilio;
use URL;
use View;


class BookController extends Controller
{
	use TouchMd;
    use Clearent;
    /**
     * Create a new controller instance.
     *
     * @return void
     */

    public $userDB		= "";
    public $hashids;
    public $logourl		= "";

    public function __construct(Request $request)
    {
        $httpHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null;

        if ($httpHost) {
            $subDomainArr = explode('.', $httpHost);
            $subDomain = $subDomainArr[0];
            $accountID = $this->getAccountID($subDomain);
            $account = Account::where('id', $accountID)->where('status', '!=', 'inactive')->first();
            $this->logourl = $this->setAccountLogo($account);
            $request->session()->put('logourl', $this->logourl);
        }
    }

    public function appointments(Request $request, $clinicID=0, $step='clinics', $ajax='false', $edit='false')
    {
		$hashids 		= new Hashids('juvly_aesthetics', 30);
		$data 			= array();
		$authUser 		= array();
		$authUser 		= Auth::user();
		$params			= $request->route('step');
		$isAjax			= $ajax;
		$userEditing	= $edit;
		$input 			= $request->input();
		$funcName		= $step;
        $verticalID     = 0;
        $cc_auth_text = "";
        $cancellation_policy = 'As a courtesy to our customers at Aesthetic Record EMR, please provide a minimum of 24 hours notice should you need to cancel or reschedule an appointment. You will be charged a certain cancellation fee, which will be decided by individual Aesthetic Record customers as per their company policies if an appointment is cancelled less than 24 hours in advance or if there is a no-show. Because appointments fill up quickly, we suggest you schedule your next appointment before you leave.';

        $httpHost		= $_SERVER['HTTP_HOST'];

		$subDomainArr	= explode('.', $httpHost);
		$subDomain		= $subDomainArr[0];

		$this->getDatabase($subDomain);

		$accountID		= 0;
		$accountID 		= $this->getAccountID($subDomain);
		$accountPrefre = AccountPrefrence::where('account_id',$accountID)->first();

		if($accountID === config('app.juvly_account_id')) {
            if(!$accountPrefre->allow_patients_to_manage_appt){
                return Redirect::to('login');
            }
        }
		$hashedClinic	= $clinicID;
		$clinicHasArr	= $hashids->decode($clinicID);

		if ( count((array)$authUser) ) {
			$hasAuth	= 'true';
		} else {
			$hasAuth	= 'false';
		}

		$hashedClinicName 	= '';
		$clinicTimeZone 	= 'America/New_York';

		$isCaptchaEnabled = !empty($accountPrefre) ? $accountPrefre->is_captcha_enabled : true;

		$canShowFirstAvail= '0';

		$accountComData		= AccountCommunication::where('account_id', $accountID)->first();

		if (count((array)$accountComData)) {
			$canShowFirstAvail = $accountComData['show_first_available_btn'];
		}


		if ( $this->userDB != "" ) {
			if ( count((array)$clinicHasArr) ) {
				$clinicID	= $clinicHasArr[0];
                $verticalID = !empty($clinicHasArr[1]) ? $clinicHasArr[1] : 0;

				if ( $clinicID > 0 ) {
					if ( $this->checkIfClinicExists($clinicID) == false ) {
						$clinicID 		= 0;
						$hashedClinic	= $hashids->encode($clinicID);
					} else {
						$clinicData 	= Clinic::where('status', 0)->where('id', $clinicID)->first();

						if ($clinicData) {
							$hashedClinicName 	= $clinicData->clinic_name;
							$clinicTimeZone 	= $clinicData->timezone;
						}
					}
				}
			} else {
                if($step === 'verticals') {
                    $hashidsDecode = new Hashids('juvly_aesthetics_verticals', 30);
                    $verticalHashArr = $hashidsDecode->decode($clinicID);
                    if(count((array)$verticalHashArr)) {
                        $verticalID = $verticalHashArr[0];
                    } else {
                        $verticalID = 0;
                    }
                    $clinicID = 0;
                    $hashedClinic = $hashids->encode($clinicID, $verticalID);
                } else {
                    $clinicID = 0;
                    $hashedClinic = $hashids->encode($clinicID);
                }
			}
            $input['vertical_id'] = $verticalID;

            $merchant_id = '';
            if (!empty($accountData->pos_gateway) && $accountData->pos_gateway == "clearent") {
                $stipeCon = [
                    ['account_id', $accountID],
                    ['clinic_id', $clinicID]
                ];
                $accountClearentConfig = AccountClearentConfig::whereHas('clearent_setup', function ($q) {
                    $q->where('status', 'completed');
                })->with(['clearent_setup' => function ($q) {
                    $q->where('status', 'completed');
                }])->where($stipeCon)->first();
                if (!empty($accountClearentConfig) && isset($accountClearentConfig)) {
                    $merchant_id = $accountClearentConfig->merchant_id;
                }
            }

			if ( $request->isMethod('get') ) {
				$step = 'clinics';
			}

			if ( method_exists($this, $step)) {
				if ( $step == 'contact' ) {
					if ( $accountID ) {
						$accountData 		= Account::where('id', $accountID)->first();
						$accountPrefData	= AccountPrefrence::where('account_id', $accountID)->first();

						if ( count((array)$accountData) ) {
							if ( strlen(trim($accountData['cancellation_policy']))) {
								$def_cancellation_fee	= 0; /// Change here if you want to change default cancellation fees
								$def_charge_days		= "24 hrs"; /// Change here if you want to change default charge days

								$cancellation_fees		= $accountData['cancellation_fees'];
								$cancellation_policy	= $accountData['cancellation_policy'];

								if ( count((array)$accountPrefData) && strlen(trim($accountPrefData['cc_auth_text'])) ) {
									$cc_auth_text		= trim($accountPrefData['cc_auth_text']);
								}

								if ( $cancellation_fees > 0 ) {
									$cancellation_policy	= str_replace("{{CANCELATIONFEES}}", "$".$cancellation_fees, $cancellation_policy);
									$cc_auth_text			= str_replace("{{CANCELATIONFEES}}", "$".$cancellation_fees, $cc_auth_text);
								} else {
									$cancellation_policy	= str_replace("{{CANCELATIONFEES}}", $def_cancellation_fee, $cancellation_policy);
									$cc_auth_text			= str_replace("{{CANCELATIONFEES}}", $def_cancellation_fee, $cc_auth_text);
								}

								if ( count((array)$accountPrefData) ) {
									$can_charge_days		= $accountPrefData['cancelation_fee_charge_days'];

									if ( $can_charge_days > 1 ) {
										$cancellation_policy	= str_replace("{{CANFEECHARGEDAYS}}", $can_charge_days." days", $cancellation_policy);

										$cc_auth_text			= str_replace("{{CANFEECHARGEDAYS}}", $can_charge_days." days", $cc_auth_text);
									} else {
										$cancellation_policy 	= str_replace("{{CANFEECHARGEDAYS}}", $def_charge_days, $cancellation_policy);

										$cc_auth_text			= str_replace("{{CANFEECHARGEDAYS}}", $def_charge_days, $cc_auth_text);
									}
								} else  {
									$cancellation_policy 		= str_replace("{{CANFEECHARGEDAYS}}", $def_charge_days, $cancellation_policy);

									$cc_auth_text				= str_replace("{{CANFEECHARGEDAYS}}", $def_charge_days, $cc_auth_text);
								}
							}
						}
					}
				}

				$data		= $this->$step($input);
			} else {
				$data		= $this->clinics($input);
			}

			if ( $ajax == 'false' ) {
				Session::put('bookAppointMent', array());
				if ( count((array)$authUser) ) {
					$patientID			= Session::get('patient_id');
                    $patienCardOnFile 	= $this->getPatientCardOnFileData($this->userDB, $patientID, $merchant_id);

					if ( count((array)$patienCardOnFile) ) {
						$cardNumbr		= $patienCardOnFile['card_number'];
					} else {
						$cardNumbr		= "";
					}

					$bookAppointMent 	= array('formData' => array('firstname' => $authUser->firstname, 'lastname' => $authUser->lastname, 'email' => $authUser->email, 'phone' => $authUser->phone, 'card_number' => $cardNumbr ));
					Session::put('bookAppointMent', $bookAppointMent);
				}
				$bookAppointMent 					= Session::get('bookAppointMent');
				$bookAppointMent['selServiceType'] 	= 'service';
				Session::put('bookAppointMent', $bookAppointMent);
				return view('appointments.' . $step, compact('data', 'isAjax', 'userEditing', 'params', 'clinicID', 'hashedClinic', 'hashids', 'hasAuth', 'authUser', 'cancellation_policy', 'cc_auth_text', 'isCaptchaEnabled', 'hashedClinicName', 'clinicTimeZone', 'canShowFirstAvail', 'verticalID'))->render();
			} else {
				if ( $step && $data ) {
					if ( $step == 'book' ) {
						if ( $data['status'] == 'success' ) {
							$msg 				= "Appointment booked successfully";
						} else {
							if ( $data['cause'] == "userisfired" ) {
								$msg 			= "Sorry, we are unable to book your appointment. Please contact office regarding same";
							} else if ( $data['cause'] == "timeused" ) {
								$msg 			= "Sorry, selected appointment time is already booked. Please select another appointment time.";
							} else if ( $data['cause'] == "providernotavailable" ) {
								$msg 			= "Sorry, selected provider is now not available for booking. Please try again with another provider.";
							} else if ( $data['cause'] == "clinicnotavailable" ) {
								$msg 			= "Sorry, selected clinic is now not available for booking. Please try again with another clinic.";
							} else if ( $data['cause'] == "servorpacknotavailable" ) {
								//$msg 			= "Sorry, one or more selected service(s) or package(s) is not available for booking. Please refresh the page.";
								$msg 			= "Sorry, one or more selected service(s) is not available for booking. Please refresh the page.";
							} else if ( $data['cause'] == "servicesnotclubbable" ) {
								$msg 			= "Sorry, one or more selected service(s) can not be booked at the same time. Please try booking these separately or Call us for more information.";
							} else if ( $data['cause'] == "paymentgatewayerror" || $data['cause'] == "transaction" ) {
								$msg 			= $data['message'];
							} else {
								$msg 			= "Error occured while booking your appointment, please try again in some time";
							}
						}
						$json 					= array('data' => $msg, 'status' => $data['status'], "cause" => $data['cause'], "appointment_id" => @$data["appointment_id"] );
					} else {
						if ( $step == 'clinics' && $isAjax == 'true' && $userEditing == 'true' ) {
							$step = $step . '_ajax';
						}

						$view 					= View::make('appointments.'. $step, compact('data', 'isAjax', 'userEditing', 'params', 'clinicID', 'hashedClinic', 'hashids', 'hasAuth', 'authUser', 'cancellation_policy', 'cc_auth_text', 'isCaptchaEnabled', 'hashedClinicName', 'clinicTimeZone', 'canShowFirstAvail', 'verticalID'));
						$contents 				= $view->render();
						$json 					= array('data' => $contents, 'status' => 'success' );
					}
				} else {
					if ( $funcName == 'clinics' ) {
						$dataStr 	= 'No clinic found';
						$cause		= "noclinic";
					} else if ( $funcName == 'services' ) {
						//$dataStr = 'No service(s)/package(s) found for selected clinic';
						$dataStr 	= 'No service(s) found for selected clinic';
						$cause		= "noservice";
					} else if ( $funcName == 'providers' ) {
						$dataStr 	= 'No provider is available, opening service-error popup';
						$cause		= "noprovider";
					} else if ( $funcName == 'date' ) {
						$dataStr 	= 'No dates are available for selected provider';
						$cause		= "nodate";
					} else if ( $funcName == 'book' ) {
						$dataStr 	= 'Unable to save booking data, please try again';
						$cause		= "booking";
					} else {
						$dataStr 	= 'No record found';
						$cause		= "unknown";
					}
                    if ($cause && $cause == 'noprovider') {
                        $clininc = Clinic::find(Session::get('bookAppointMent')['selClinic']);
                        $phoneNumber = '';
                        if ($clininc) {
                            $phoneNumber = $clininc->contact_no;
                        }

                        $json = array('data' => $dataStr, 'status' => 'error', 'phoneNumber' => $phoneNumber);
                    } else {
                        $json = array('data' => $dataStr, 'status' => 'error');
                    }
				}

				return response()->json($json);
			}
		} else {
			$json 								= array('data' => "Either the account is inactive or database does not exists", 'status' => 'error', "cause" => "accountnotfound" );
			//return response()->json($json);
			return view('appointments.error_page');
		}
	}

	protected function saveBookingsPayment($db, $appointmentID)
	{
		$this->switchDatabase($db);
		$bookingpayment_array	= array(
			'appointment_id'			=> $appointmentID,
			'payment_status'			=> 'pending',
			'payment_transaction_id'	=> 0,
			'created'					=> date('Y-m-d'),
			'modified'					=> date('Y-m-d'),
		);

		$bookingpayment 						= new BookingPayment();
		$bookingpayment->appointment_id			= $appointmentID;
		$bookingpayment->payment_status			= 'pending';
		$bookingpayment->payment_transaction_id	= 0;
		$bookingpayment->created				= date('Y-m-d');
		$bookingpayment->modified				= date('Y-m-d');
		$saved 									= $bookingpayment->save();

		if ( $saved ) {
			return $bookingpayment->id;
		} else {
			return 0;
		}
	}

	protected function saveAppointmentServices($db, $appointmentID)
	{
		$status									= false;
		$selSerives								= array();
		$data									= Session::get('bookAppointMent');
		$app_type								= $data['selServiceType'];
		$this->switchDatabase($db);

		AppointmentService::where('appointment_id', $appointmentID)->delete();

		if ( $app_type == 'package' ) {
			$package_id							= $data['selService'][0];
			$servicePackages					= ServicePackage::where('package_id', $package_id)->get();

			if ( count((array)$servicePackages) ) {
				$servicePackages				= $servicePackages->toArray();

				foreach( $servicePackages as $key => $value ) {
					$packageServiceArr[] 		= $value['service_id'];
				}

			}
			$selSerives							= $packageServiceArr;
		} else {
			$selSerives							= $data['selService'];
		}

		if ( count((array)$selSerives) ) {
			$serviceData = array();
			foreach ( $selSerives as $service ) {
				$this->savePreAndPostLog($appointmentID, $service);

				$serviceData 					= Service::where('id', $service)->first();
				$duration 						= $serviceData->duration;

				$appservice 					= new AppointmentService();
				$appservice->appointment_id		= $appointmentID;
				$appservice->service_id			= $service;
				$appservice->duration			= $duration;
				$appservice->created			= date('Y-m-d');
				$appservice->modified			= date('Y-m-d');
				$saved 							= $appservice->save();

				if ( $saved ) {
					$status = true;
				}
			}
		}
		return $status;
	}

	protected function updateBookingInAppointments($db, $appointmentID, $appointmentBookingID)
	{
		$update_arr	= array(
			'booking_id'	=> $appointmentBookingID
		);

		$status    = Appointment::where('id', $appointmentID)->update($update_arr);

		if ( $status ) {
			return $appointmentID;
		} else {
			return 0;
		}
	}

	protected function createNewAppointmentBooking($db, $appointmentID)
	{
		$formData									= array();
		$data										= Session::get('bookAppointMent');

		$aptDateTimeZone							= isset($data['selTimeZone']) ? $data['selTimeZone'] : 'America/New_York';
		//~ date_default_timezone_set($aptDateTimeZone);
		//~
		//~ $todayDateTime 								= new DateTime(date('Y-m-d H:i:s'));
		//~ $todayTimeZone 								= new DateTimeZone($aptDateTimeZone);
		//~ $todayDateTime->setTimezone($todayTimeZone);
		//~ $todayInAptTimeZone							= $todayDateTime->format('Y-m-d H:i:s');
		$todayInAptTimeZone							= $this->convertTZ($aptDateTimeZone);

		$bookedBy									= 'patient';
		$bookedByUser								= 0;

		if ( count((array)$data) ) {
			if ( array_key_exists('appUserID', $data)) {
				$bookedBy								= 'staff';
				$bookedByUser							= $data['appUserID'];
			}
		}

		$formData									= $data['formData'];

		$this->switchDatabase($db);

		$appointmentBooking							= new AppointmentBooking();
		$appointmentBooking->appointment_id			= $appointmentID;
		$appointmentBooking->booked_by				= $bookedBy;
		$appointmentBooking->booked_by_user			= $bookedByUser;
		$appointmentBooking->booking_datetime		= $todayInAptTimeZone;
		$appointmentBooking->booking_payment_id		= 0;
		$appointmentBooking->firstname				= $formData['firstname'];
		$appointmentBooking->lastname				= $formData['lastname'];
		$appointmentBooking->email					= $formData['email'];
		$appointmentBooking->phone					= $formData['phone'];
		$appointmentBooking->appointment_notes		= htmlentities(addslashes(trim(preg_replace('/\s+/', ' ', @$formData['appointment_notes']))), ENT_QUOTES, 'utf-8');
		$appointmentBooking->created				= date('Y-m-d');
		$appointmentBooking->modified				= date('Y-m-d');
		$saved 										= $appointmentBooking->save();

		if ( $saved ) {
			return $appointmentBooking->id;
		} else {
			return 0;
		}
	}

	protected function createNewAppointment($db, $patientID)
	{
		$data								= Session::get('bookAppointMent');
		$appType							= $data['selServiceType'];

		$this->switchDatabase($db);

		$serviceIDs							= implode(',', $data['selService']);

		if ( $appType == 'package' ) {
			$duration 						= DB::select("SELECT SUM(duration) AS duration FROM $db.`packages` where id in ($serviceIDs)");
		} else {
			$duration 						= DB::select("SELECT SUM(duration) AS duration FROM $db.`services` where id in ($serviceIDs)");
		}

		$aptDateTime						= date('Y-m-d H:i:s', strtotime(@$data['selDate'] . " " . @$data['selTime']));
		$aptDateTimeZone					= isset($data['selTimeZone']) ? $data['selTimeZone'] : 'America/New_York';
		$clinicID							= $data['selClinic'];
		$appointment_type							= $data['appointment_type'];

		$clinicInfo 						= Clinic::where('id', $clinicID)->first();
		if ( count((array)$clinicInfo) ) {
			$clinic 						= $clinicInfo->toArray();
			if ( count((array)$clinic) ) {
				$aptDateTimeZone			= '';
				$aptDateTimeZone			= $clinic['timezone'];
			}
		}

		$providerID							= $data['selDoc'];
		$MeetingId =0;
		$MeetingId = BookingHelper::getVirtualMeetingId();

		$appointment						= new Appointment();
		$appointment->patient_id			= $patientID;
		$appointment->duration				= @$duration[0]->duration;
		$appointment->appointment_datetime	= $aptDateTime;
		$appointment->appointment_timezone	= $aptDateTimeZone;
		$appointment->clinic_id				= $clinicID;
		$appointment->user_id				= $providerID;
		$appointment->status				= 'booked';
		$appointment->user_agent			= $_SERVER ['HTTP_USER_AGENT'];
		$appointment->created				= date('Y-m-d');
		$appointment->modified				= date('Y-m-d');
		$appointment->appointment_type		= $appointment_type;
		$appointment->meeting_id 			= $MeetingId;
		$appointment->meeting_type 			= 'tokbox';
		$appointment->appointment_source 	= 'pportal';

		$system_appointment_datetime = $this->convertTzToSystemTz($aptDateTime,$aptDateTimeZone);

		$appointment->system_appointment_datetime  = $system_appointment_datetime;

		if ( $appType == 'package' ) {
			$appointment->package_id		= $data['selService'][0];
		}

		$saved 								= $appointment->save();

		$this->saveAppointmentReminderLogs($appointment->id , $system_appointment_datetime);

		$sms_date = date('Y-m-d H:i:s', strtotime('+'.$appointment->duration.' minutes', strtotime($system_appointment_datetime)));
		$this->save_sms_log($patientID, $appointment->id, $sms_date, $data['selService']);

		if ( $saved ) {
		    if($this->checkJuvlyDomainName()) {
                $this->saveApptIdToGoogleROI($appointment->id);
            }
			return $appointment->id;
		} else {
			return 0;
		}

	}

	protected function createNewPatient($db)
	{
		$formData					= array();
		$data						= Session::get('bookAppointMent');
		$formData					= $data['formData'];

		$this->switchDatabase($db);
		$patient 					= new Patient();
		$patient->user_id			= 0;
		$patient->firstname			= htmlentities(addslashes(trim(preg_replace('/\s+/', ' ', $formData['firstname']))), ENT_QUOTES, 'utf-8');
		$patient->lastname			= htmlentities(addslashes(trim(preg_replace('/\s+/', ' ', $formData['lastname']))), ENT_QUOTES, 'utf-8');
		$patient->email				= $formData['email'];
		$patient->gender			= 2;
	//	$patient->phoneNumber		= $formData['phone'];
		$patient->phoneNumber		= $formData['full_number'];
		$saved 						= $patient->save();

		$httpHost					= $_SERVER['HTTP_HOST'];
		$subDomainArr				= explode('.', $httpHost);
		$subDomain					= $subDomainArr[0];
		$accountID					= $this->getAccountID($subDomain);

		$account_preference =   AccountPrefrence::where('account_id',$accountID)->first();
		if ( $saved ) {
            if ($accountID === config('app.juvly_account_id')) {
                $this->enablePatientPortalAccessAndSendMail($data, $patient->id);
            } else {
                if ($account_preference->allow_patients_to_manage_appt && $account_preference->patient_portal_activation_link) {
                    $this->enablePatientPortalAccessAndSendMail($data, $patient->id);
                }
            }

			$this->touchMD($account_preference, $patient);

			$this->patientIntegrationProcess($account_preference, $patient);

			return $patient->id;
		} else {
			return 0;
		}
	}

	protected function checkIfPatientExists($db, $bookAppointMent)
	{
		$formData	= array();
		$patients	= array();
		$formData	= $bookAppointMent['formData'];

		$this->switchDatabase($db);


		$patients = Patient::where('firstname', trim($formData['firstname']))->where('email', trim($formData['email']))->where('status', 0)->first();

		if ( count((array)$patients) ) {
			$patients = $patients->toArray();
		}

		if ( count((array)$patients) ) {
			return $patients['id'];
		} else {
			return 0;
		}
	}

	protected function updateTimeZoneOfUser()
	{
		if ( count((array)Auth::user()) ) {
			$userID				= Auth::user()->id;

			$bookAppointMent 	= Session::get('bookAppointMent');

			$update_arr			= array(
				'timezone'	=> $bookAppointMent['selTimeZone']
			);

			$status    			= User::where('id', $userID)->update($update_arr);

			if ( $status ) {
				return true;
			} else {
				return false;
			}
		} else {
			return true;
		}
	}

	protected function updateZipOfPatient($db, $patientID, $bookAppointMent)
	{
		if ( isset($bookAppointMent['formData']['pincode']) && !empty($bookAppointMent['formData']['pincode']) ) {
			$update_arr	= array(
				'pincode'	=> $bookAppointMent['formData']['pincode']
			);

			$status    = Patient::where('id', $patientID)->update($update_arr);

			if ( $status ) {
				return $patientID;
			} else {
				return 0;
			}
		} else {
			return $patientID;
		}
	}

	protected function updatePhoneOfPatient($db, $patientID, $bookAppointMent)
	{
		if ( isset($bookAppointMent['formData']['full_number']) && !empty($bookAppointMent['formData']['full_number']) ) {
			$update_arr	= array(
				'phoneNumber'	=> $bookAppointMent['formData']['full_number']
			);

			$status    = Patient::where('id', $patientID)->update($update_arr);

			if ( $status ) {
				return $patientID;
			} else {
				return 0;
			}
		} else {
			return $patientID;
		}
	}

	public function book($input)
	{
		$data 				= array();
		$db 				= $this->userDB;
		$ifPatientIsFired 	= 0;

		$httpHost			= $_SERVER['HTTP_HOST'];
		$subDomainArr		= explode('.', $httpHost);
		$subDomain			= $subDomainArr[0];
		$accountID			= $this->getAccountID($subDomain);
		$globalUniqueID		= 0;

		$this->switchDatabase($db);

		if ( $input ) {
			$params 		  	= array();
			$creditData			= array();
			$finalParams		= array();
			parse_str($input['formData'], $params);
			parse_str($input['creditData'], $creditData);

			if ( count((array)$creditData) ) {
				$finalParams	= array_merge($params, $creditData);
			}

			$bookAppointMent 				= Session::get('bookAppointMent');
			$appointmentType				= $bookAppointMent['appointment_type'];
			$globalClientID					= @$bookAppointMent['formData']['global_client_id'];
			$finalParams['global_client_id']= $globalClientID;
			$bookAppointMent['formData'] 	= $finalParams;
			Session::put('bookAppointMent', $bookAppointMent);
		}

		$bookAppointMent 	= Session::get('bookAppointMent');
		$authPatID		 	= Session::get('patient_id');
		$appType			= Session::get('bookAppointMent')['selServiceType'];
		$ifPatientExist  	= $this->checkIfPatientExists($db, $bookAppointMent);

		if ( $authPatID > '0' ) {
			$patientID = $authPatID;

			$ifPatientIsFired  	= $this->checkIfPatientIsFired($db, $patientID);
			if ( $ifPatientIsFired > 0 ) {
				$data = array("status" => "error", "cause" => "userisfired", "message" => "");
				return $data;
				exit;
			}

		} else {
			if ( $ifPatientExist != '0' ) { //exists
				$patientID 			= $ifPatientExist;
				$ifPatientIsFired  	= $this->checkIfPatientIsFired($db, $patientID);

				if ( $ifPatientIsFired > 0 ) {
					$data = array("status" => "error", "cause" => "userisfired", "message" => "");
					return $data;
					exit;
				}

			} else {
				$patientID 			= $this->createNewPatient($db, $bookAppointMent);
				$this->createPatientWallet($db, $patientID);
			}
		}

		$globalUniqueID = $this->checkClientAccount($patientID, $accountID);

		$this->switchDatabase($db);

		if ($globalUniqueID) {
			$update_arr		= array(
				'unique_id'	=> $globalUniqueID
			);

			Patient::where('id', $patientID)->update($update_arr);
		}

		$con = DB::connection('juvly_practice');

		$con->beginTransaction();

		try {
			if ( $patientID == 0 ) {
				throw new Exception();
			}

			if ( $patientID ) {
				$clinicID					= $bookAppointMent['selClinic'];
				$providerID					= $bookAppointMent['selDoc'];
				$selDate					= $bookAppointMent['selDate'];
				$selTime					= $bookAppointMent['selTime'];
				$services					= $bookAppointMent['selService'];
				$timezone					= isset($bookAppointMent['selTimeZone']) ? $bookAppointMent['selTimeZone'] : 'America/New_York';
				$appointment_type			= $bookAppointMent['appointment_type'];
				$params						= array('patient_id' => $patientID, 'provider_id' => $providerID, 'appointment_id' => 0, 'appointment_date' => $selDate, 'appointment_time' => $selTime, 'clinic' => $clinicID, 'account_id' => $accountID, 'service' => $services, 'timezone' =>  $timezone, 'appointment_type' => $appointment_type );

				if ( $appType == 'package' ) {
					$params['package_id']	= $services[0];
				} else {
					$params['package_id']	= 0;
				}

				$canBeBooked 				= json_decode($this->checkIfApptCanBeBooked($params), true);
				$ifClinicCanBeUsed			= $this->checkIfClinicCanBeUsed($db, $bookAppointMent);
				$IfServOrPackCanBeUsed		= $this->checkIfServOrPackCanBeUsed($db, $bookAppointMent);
				$ifProviderCanBeUsed		= $this->checkIfProviderCanBeUsed($bookAppointMent);
				$isServicePaid 				= $this->checkServicePaid($db, $bookAppointMent, $appointmentType);
				if ( count((array)$canBeBooked) && $canBeBooked['status'] == 'fail' ) {
					$data = array("status" => "error", "cause" => "timeused", "message" => "");
					return $data;
					exit;
				} else if ( $ifProviderCanBeUsed == '0' ) {
					$data = array("status" => "error", "cause" => "providernotavailable", "message" => "");
					return $data;
					exit;
				} else if ( $ifClinicCanBeUsed == '0' ) {
					$data = array("status" => "error", "cause" => "clinicnotavailable", "message" => "");
					return $data;
					exit;
				} else if ( $IfServOrPackCanBeUsed == '0' ) {
					$data = array("status" => "error", "cause" => "servorpacknotavailable", "message" => "");
					return $data;
					exit;
				} else {
					$this->updateZipOfPatient($db, $patientID, $bookAppointMent);
					$this->updatePhoneOfPatient($db, $patientID, $bookAppointMent);
					$bookAppointMent['patient_id'] = $patientID;
					Session::put('bookAppointMent', $bookAppointMent);


					$account  			= Session::get('account');
					$card_save_on_file 	= false;
					$authorizeCardData	= array();
					$needToSaveOnFile	= false;
					$accCancellationFee	= $account->cancellation_fees;
					$gatewayType		= $account->pos_gateway;

					$upfrontConsultationFees	= 0;
					$accountPrefData			= AccountPrefrence::where('account_id', $account->id)->first();

					if ( count((array)$accountPrefData) > 0 ) {
						$upfrontConsultationFees	= $accountPrefData['upfront_consultation_fees'];
						$cancellationFeeStatus		= $accountPrefData['cancelation_policy_status'];
					} else {
						$cancellationFeeStatus		= 0;
					}

					$appointmentID = $this->createNewAppointment($db, $patientID);

					if ( $appointmentID == 0 ) {
						throw new Exception();
					}

					$bookAppointMent['appointment_id'] = $appointmentID;
					Session::put('bookAppointMent', $bookAppointMent);

					if ( isset($bookAppointMent['formData']) ) {
						$canTakePayment = $this->canUseStripe();

						if ( (count((array)$account) > 0) && ($account->pos_enabled == 1) && ($cancellationFeeStatus == 1) && $canTakePayment && $isServicePaid > 0  && $appointmentType != 'virtual') {
							if ( isset($bookAppointMent['formData']['isAddClicked']) && $bookAppointMent['formData']['isAddClicked'] != '' ) {
								if ( $bookAppointMent['formData']['isAddClicked'] == 'false' ) {
                                    if ($gatewayType && $gatewayType == 'stripe') {
                                        $stripeUserID = $this->getAccountStripeConfig($account, $bookAppointMent);
                                        if (strlen($stripeUserID) == 0) {
                                            return array("status" => "error", "cause" => "transaction", "message" => 'Unable to process: Stripe connection not found');
                                        }
                                    } elseif ($gatewayType == 'clearent') {
                                        $stripeUserID = $this->getAccountClearenteConfig($account, $bookAppointMent);
                                        if (strlen($stripeUserID) == 0) {
                                            return array("status" => "error", "cause" => "transaction", "message" => 'Unable to process: Clearent connection not found');
                                        }
                                    } else {
                                        $stripeUserID = "";
                                    }

                                    $transID = $this->createNewPatientAppointmentTransaction($db, $appointmentID, array(), $account, "saved", $stripeUserID);
                                } else {
									if ( !empty($bookAppointMent['formData']['card_number']) && $bookAppointMent['formData']['expiry_month'] != "" && $bookAppointMent['formData']['expiry_year'] != "" && !empty($bookAppointMent['formData']['cvv']) ) {

                                        if ($gatewayType && $gatewayType == 'stripe') {
                                            $authorizeCardData = $this->authorizeCardUsingStripe($account, $bookAppointMent, $patientID, "new", 'in_person', 'false');
                                        } elseif ($isServicePaid > 0 && $gatewayType && $gatewayType == 'clearent') {
                                            $authorizeCardData = $this->authorizeCardUsingClearent($account, $bookAppointMent, $patientID, "new", 'in_person', 'false');
                                        } else {
                                            $authorizeCardData = $this->authorizeCardUsingApriva($account, $bookAppointMent['formData'], $patientID, "new");
                                        }

										if ( $authorizeCardData["status"] == "error" ) {
                                            if (!empty($gatewayType) && $gatewayType == 'clearent') {
                                                $this->rollbackAppointment($appointmentID);
                                            }

											$data = array("status" => $authorizeCardData["status"], "cause" => "paymentgatewayerror", "message" => $authorizeCardData["message"]);
											return $data;
											exit;
										} else {
											$authorizeCardData		= $authorizeCardData["data"];
											if ( count((array)$authorizeCardData) ) {
												$needToSaveOnFile 	= true;
                                                if ($gatewayType == 'clearent') {
                                                    $stripeUserID = $this->getAccountClearenteConfig($account, $bookAppointMent);
                                                } else {
                                                    $stripeUserID = $this->getAccountStripeConfig($account, $bookAppointMent);
                                                }
												if ( strlen($stripeUserID) == 0 ) {
													return array("status" => "error", "cause" => "transaction", "message" => 'Unable to process: Stripe connection not found');
												}

												if ( $bookAppointMent['formData']['isAddClicked'] == 'true' ) {
													$card_save_on_file 	= $this->savePatientCard($account, $authorizeCardData, $patientID, $stripeUserID, $gatewayType);
												} else {
													$card_save_on_file 	= true;
												}
											}
										}
									}
								}
							}
						} else if (count((array)$account) > 0 && $account->pos_enabled == 1 && $canTakePayment && $appointmentType == 'virtual') {
							//if ( !empty($bookAppointMent['formData']['card_number']) && $bookAppointMent['formData']['expiry_month'] != "" && $bookAppointMent['formData']['expiry_year'] != "" && !empty($bookAppointMent['formData']['cvv']) ) {

								$serviceData = Service::where('id', $bookAppointMent['selService'])->first();

								if ($serviceData && $serviceData->free_consultation > 0) {
									//~ if ( $gatewayType && $gatewayType == 'stripe' ) {
										//~ $authorizeCardData = $this->authorizeCardUsingStripe($account, $bookAppointMent, $patientID, "new", 'virtual', 'true');
									//~ }

									//~ if ( $authorizeCardData["status"] == "error" ) {
										//~ $data = array("status" => $authorizeCardData["status"], "cause" => "paymentgatewayerror", "message" => $authorizeCardData["message"]);
										//~ return $data;
										//~ exit;
									//~ } else {
										//~ $authorizeCardData		= $authorizeCardData["data"];
										//~ if ( count($authorizeCardData) ) {
											//~ $stripeUserID		= $this->getAccountStripeConfig($account, $bookAppointMent);

											//~ if ( strlen($stripeUserID) == 0 ) {
												//~ return array("status" => "error", "cause" => "transaction", "message" => 'Unable to process: Stripe connection not found');
											//~ }
											//~ $this->createNewPatientAppointmentTransaction($db, $appointmentID, $authorizeCardData, $account, "new", $stripeUserID); //earlier this was done at later stages
											//~ $needToSaveOnFile 	= true;
										//~ }
									//~ }

									$needToSaveOnFile 	= false;
								} else if ($serviceData && $serviceData->service_charge_type == 'booking' && $serviceData->price > 0.00 && $serviceData->free_consultation == 0) {

                                    if ($isServicePaid > 0 && $gatewayType && $gatewayType == 'stripe') {
                                        $authorizeCardData = $this->authorizeCardUsingStripe($account, $bookAppointMent, $patientID, "new", 'virtual', 'false');
                                        $this->savePosData($authorizeCardData, $bookAppointMent, $account, $gatewayType);
                                    } elseif ($isServicePaid > 0 && $gatewayType && $gatewayType == 'clearent') {
                                        $authorizeCardData = $this->authorizeCardUsingClearent($account, $bookAppointMent, $patientID, "new", 'virtual', 'false');
                                        if (!empty($authorizeCardData["status"]) && $authorizeCardData["status"] == "error") {
                                            $this->rollbackAppointment($appointmentID);
                                            $data = array("status" => $authorizeCardData["status"], "cause" => "paymentgatewayerror", "message" => $authorizeCardData["message"]);
                                            return $data;
                                            exit;
                                        }
                                        $this->savePosData($authorizeCardData, $bookAppointMent, $account, $gatewayType);
                                    }

									if ( $authorizeCardData["status"] == "error" ) {
										$data = array("status" => $authorizeCardData["status"], "cause" => "paymentgatewayerror", "message" => $authorizeCardData["message"]);
										return $data;
										exit;
									} else {
										$authorizeCardData		= $authorizeCardData["data"];
										if ( count((array)$authorizeCardData) ) {
											$needToSaveOnFile 	= true;

                                            if ($gatewayType == 'clearent') {
                                                $stripeUserID = $this->getAccountClearenteConfig($account, $bookAppointMent);
                                            } else {
                                                $stripeUserID = $this->getAccountStripeConfig($account, $bookAppointMent);
                                            }
											if ( strlen($stripeUserID) == 0 ) {
												return array("status" => "error", "cause" => "transaction", "message" => 'Unable to process: Stripe connection not found');
											}

											//~ $transID			= $this->createNewPatientAppointmentTransaction($db, $appointmentID, $authorizeCardData, $account, "new", $stripeUserID);

											if ( isset($bookAppointMent['formData']['isAddClicked']) && $bookAppointMent['formData']['isAddClicked'] != '' ) {
												if ( $bookAppointMent['formData']['isAddClicked'] == 'true' ) {
													$card_save_on_file 	= $this->savePatientCard($account, $authorizeCardData, $patientID, $stripeUserID, $gatewayType);
												} else {
													$card_save_on_file 	= true;
												}
											}
										}
									}
								}
							//}
						}
					}

					if ( $appointmentID ) {
						$appointmentBookingID 	= $this->createNewAppointmentBooking($db, $appointmentID);
						$checkIfCanTake 		= $this->canUseStripe();

						if ( $appointmentBookingID ) {
							$updatedAppointMentID = $this->updateBookingInAppointments($db, $appointmentID, $appointmentBookingID);

							if ( $updatedAppointMentID == 0 ) {
								throw new Exception();
							}
						} else {
							throw new Exception();
						}

						if ( $appType == 'service' ) {
							//$appointmentServices	= $this->saveAppointmentServices($db, $appointmentID);
						} else {
							//$appointmentServices	= true;
						}

						$appointmentServices	= $this->saveAppointmentServices($db, $appointmentID);

						$bookingPaymentID		= $this->saveBookingsPayment($db, $appointmentID);

						if ( !$appointmentServices || $bookingPaymentID == 0 ) {
							throw new Exception();
						}

						if ( $gatewayType && $gatewayType == 'stripe' ) {
							if ( $needToSaveOnFile && null != $accCancellationFee && $accCancellationFee > 0 && ($cancellationFeeStatus == 1) && count((array)$authorizeCardData) && $isServicePaid > 0 && $appointmentType != 'virtual' ) {
								$stripeUserID		= $this->getAccountStripeConfig($account, $bookAppointMent);

								if ( strlen($stripeUserID) == 0 ) {
									return array("status" => "error", "cause" => "transaction", "message" => 'Unable to process: Stripe connection not found');
								}

								$transID			= $this->createNewPatientAppointmentTransaction($db, $appointmentID, $authorizeCardData, $account, "new", $stripeUserID);

								//~ if ( isset($bookAppointMent['formData']['isAddClicked']) && $bookAppointMent['formData']['isAddClicked'] != '' ) {
									//~ if ( $bookAppointMent['formData']['isAddClicked'] == 'true' ) {
										//~ $card_save_on_file 	= $this->savePatientCard($account, $authorizeCardData, $patientID, $stripeUserID);
									//~ } else {
										//~ $card_save_on_file 	= true;
									//~ }
								//~ }

								$card_save_on_file 	= true;
							} else if ($needToSaveOnFile && count((array)$account) > 0 && $account->pos_enabled == 1 && $checkIfCanTake && $appointmentType == 'virtual') {
								//~ $stripeUserID		= $this->getAccountStripeConfig($account, $bookAppointMent);

								//~ if ( strlen($stripeUserID) == 0 ) {
									//~ return array("status" => "error", "cause" => "transaction", "message" => 'Unable to process: Stripe connection not found');
								//~ }

								//~ if ( $bookAppointMent['formData']['isAddClicked'] == 'true' ) {
									//~ $card_save_on_file 	= $this->savePatientCard($account, $authorizeCardData, $patientID, $stripeUserID);
								//~ } else {
									//~ $card_save_on_file 	= true;
								//~ }
								$card_save_on_file 	= true;
							} else {
								$card_save_on_file 	= true;
							}
                        } elseif ($gatewayType && $gatewayType == 'clearent') {
                            if ($needToSaveOnFile && null != $accCancellationFee && $accCancellationFee > 0 && ($cancellationFeeStatus == 1) && count((array)$authorizeCardData) && $isServicePaid > 0 && $appointmentType != 'virtual') {
                                $stripeUserID = $this->getAccountClearenteConfig($account, $bookAppointMent);
                                if (strlen($stripeUserID) == 0) {
                                    return array("status" => "error", "cause" => "transaction", "message" => 'Unable to process: Clearent connection not found');
                                }
                                $transID = $this->createNewPatientAppointmentTransaction($db, $appointmentID, $authorizeCardData, $account, "new", $stripeUserID);
                                $card_save_on_file = true;
                            } else if ($needToSaveOnFile && count((array)$account) > 0 && $account->pos_enabled == 1 && $checkIfCanTake && $appointmentType == 'virtual') {
                                $card_save_on_file = true;
                            } else {
                                $card_save_on_file = true;
                            }
                        } else {



							if ( $needToSaveOnFile && null != $accCancellationFee && $accCancellationFee > 0 && ($cancellationFeeStatus == 1) && $isServicePaid > 0 && $appointmentType != 'virtual' ) {
								$transID			= $this->createNewPatientAppointmentTransaction($db, $appointmentID, $authorizeCardData, $account, "new", "");

								if ( isset($bookAppointMent['formData']['isAddClicked']) && $bookAppointMent['formData']['isAddClicked'] != '' ) {
									if ( $bookAppointMent['formData']['isAddClicked'] == 'true' ) {
										$card_save_on_file 	= $this->getAccountSettings($account, $bookAppointMent['formData'], $patientID);
									} else {
										$card_save_on_file 	= true;
									}
								}
							} else {
								$card_save_on_file 	= true;
							}
						}

						$this->saveUserLog($db, $appointmentID, $bookAppointMent);

						if ( $bookingPaymentID > 0 && $appointmentServices === true && $card_save_on_file === true ) {
							$con->commit();
							$data = array("status" => "success", "cause" => "", "message" => "Appointment booked successfully", "appointment_id" => $appointmentID);

							if ( count((array)Auth::user()) ) {
								$this->updateTimeZoneOfUser();
							}

						} else {
							throw new Exception();
						}
					}
				}
			}
		} catch (\Exception $e) {
			$con->rollBack();
			$data = array("status" => "error", "cause" => "transaction", "message" => $e->getMessage());
		}

		return $data;
	}

	private function checkIfClinicExists($clinicID)
	{
		$status		= false;
		$allClinics	= array();
		$db 		= $this->userDB;

		$this->switchDatabase($db);

		$allClinics = Clinic::where('status', 0)->where('id', $clinicID)->where('is_available_online', 1)->first();

		if ( count((array)$allClinics) ) {
			$status = true;
		}

		return $status;
	}

	private function clinics($input)
	{
		$allClinics	= array();
		$db 		= $this->userDB;

		$this->switchDatabase($db);
		if(isset($input['appointment_type'])) {
			$temp_sess = Session::get('bookAppointMent');
			$temp_sess['appointment_type'] = $input['appointment_type'];
			Session::put('bookAppointMent', $temp_sess);
		}
        if(!empty($input['vertical_id']) && $input['vertical_id'] !== 0) {
            $allClinics = Clinic::query()
                ->join('vertical_locations', 'vertical_locations.location_id', '=', 'clinics.id')
                ->where('vertical_locations.vertical_id', '=', $input['vertical_id'])
                ->where('status', '=', 0)
                ->where('is_available_online', '=', 1)
                ->select(['clinics.id', 'clinics.clinic_name', 'clinics.timezone'])
                ->get();
        } else {
            $allClinics = Clinic::where('status', 0)->where('is_available_online', 1)->get();
        }

		if ( count((array)$allClinics) ) {
			$allClinics = $allClinics->toArray();
		}

        if ($this->checkJuvlyDomainName()) {
            if (is_array($allClinics)) {
                $this->moveElement($allClinics, count((array)$allClinics) - 1, 3);
            }
        }

		return $allClinics;
	}

	public function services($input)
	{
		$allPackages 				= array();
		$allServices 				= array();
		$services['services'] 		= array();
		$services['packages'] 		= array();
		$data 						= array();
		$db 						= $this->userDB;
        $verticalID                 = empty($input['selVertical']) ? 0 : $input['selVertical'];

		$this->switchDatabase($db);

		if (isset($input['appointment_type'])) {
			$temp_sess = Session::get('bookAppointMent');
			$temp_sess['appointment_type'] = $input['appointment_type'];
			Session::put('bookAppointMent', $temp_sess);
		}

		Session::put('selectedDB', '');
		Session::put('selectedDB', $db);

		if ( $input ) {
		//	$allServices 					= ServiceClinic::with('services')->where('clinic_id', $input['selClinic'])->get();
            $allService_ids = ServiceClinic::query()
                ->where('service_clinics.clinic_id', $input['selClinic']);
            if($verticalID > 0) {
                $allService_ids
                    ->join('vertical_services', 'vertical_services.service_id', '=', 'service_clinics.service_id')
                    ->where('vertical_services.vertical_id', '=', $verticalID);
            }
            $allService_ids = $allService_ids
                ->pluck('service_clinics.service_id');

			if ( count((array)$allService_ids) ) {
                $allService_ids = $allService_ids->toArray();
                $allServiceAssoc = ServiceCategoryAssoc::whereIn('service_id', $allService_ids)->get();
                $allServiceAssoc = $allServiceAssoc->toArray();

				if(count((array)$allServiceAssoc) > 0)	 {
					$category = array();

                    foreach ($allServiceAssoc as $key => $val) {
                        if (count((array)$category) > 0) {
                            if (!in_array($val['category_id'], $category)) {
                                $category[] = $val['category_id'];
                            }
                        } else {
                            $category[] = $val['category_id'];
                        }
                    }

					if( count((array)$category) > 0 ){

						$category_data 	=	 ServiceCategory::whereIn('id', $category)->where('is_active',1)->orderBy('order_by','ASC')->get();

						if( count((array)$category_data) > 0 ) {
							$category_data 	=	 $category_data->toArray();

							foreach ( $category_data as $index => $cat ) {

                                $services_assoc_data = ServiceCategoryAssoc::with('service', 'not_clubbable', 'not_clubbable_related')
                                    ->where('category_id', $cat['id'])
                                    ->whereIn('service_id', $allService_ids)
                                    ->orderBy('service_order','ASC')
                                    ->get();

								$category_data[$index]['service_assoc'] = array();

								if ( count((array)$services_assoc_data )>0 ) {
									$category_data[$index]['is_service_online'] = 0;

									foreach ( $services_assoc_data  as $key => $record) {
                                        if (count($record->service) > 0) {
                                            $category_data[$index]['is_service_online'] = 1;
                                            $services_assoc_data[$key]->service_type = $record->service[0]->service_type;
                                            $services_assoc_data[$key]->service_name = strtolower($record->service[0]->name);
                                            if ($record->service[0]->service_type == 'virtual') {
                                                unset($services_assoc_data[$key]);
                                            }
                                        } else {
                                            $services_assoc_data[$key]->service_name = '';
                                        }
									}
									$service_assoc_data = $services_assoc_data->toArray();

									usort($service_assoc_data, array($this, "sortServiceNameOrder"));
									//echo "<pre>"; print_r($service_assoc_data);
									$category_data[$index]['service_assoc'] = $service_assoc_data;
								}

								if ( isset($category_data[$index]['is_service_online']) &&  $category_data[$index]['is_service_online'] == 0 ) {
									unset($category_data[$index]);

								}
							}
							$category_data = array_values($category_data);
						}
					}
			//echo "<pre>"; print_r($category_data); die;
					if(count((array)$category_data) > 0 ){
						$services['services'] = $category_data;
					}

				}
			}

			$httpHost						= $_SERVER['HTTP_HOST'];
			$subDomainArr					= explode('.', $httpHost);
			$subDomain						= $subDomainArr[0];
			$accountID						= $this->getAccountID($subDomain);
			$params							= array( 'clinic_id' => $input['selClinic'], 'account_id' => $accountID );
			$allPackages					= array(); //json_decode($this->getPackageByClinic($params), true);
			/*
			 * REMEMBER TO UNCOMMENT THE RESPONSE
			 */

			if ( count((array)$allPackages) ) {
				foreach ( $allPackages as $allPackage ) {
					if ( count((array)$allPackage['Package']) ) {
						$services['packages'][] = $allPackage['Package'];
					}
				}
			}

			$bookAppointMent 				= Session::get('bookAppointMent');
			$bookAppointMent['selClinic'] 	= $input['selClinic'];

			if ( count((array)$bookAppointMent) ) {
				if ( !array_key_exists('selServiceType', $bookAppointMent) ) {
					$bookAppointMent['selServiceType'] 	= 'service';
				}
			}
			Session::put('bookAppointMent', $bookAppointMent);

			if($bookAppointMent['appointment_type'] == 'virtual'){
				$virtual_services = Service::whereIn('id',$allService_ids)->where('is_available_online',1)->where('status',0)->where('service_type','virtual')->orderBy('name','ASC')->get();
				$services['services'] = array();
				$services['services'] = $virtual_services;
			}

			$data							= $services;
		}

		//~ if ( (count($data['services']) == 0) && (count($data['packages']) == 0 ) ) {
			//~ $data							= array();
		//~ }
		/*
			 * REMEMBER TO UNCOMMENT
			 */
		if ( isset($data['services']) && (count((array)$data['services']) == 0) ) {
			$data							= array();
		}
		return $data;
	}

	public function providers($input)
	{
		$providers 			= array();
		$allProviders 		= array();
		$packageServiceArr	= array();
		$accountData		= array();

		$db 			= $this->userDB;

		$this->switchDatabase($db);

		if ( $input ) {
			$bookAppointMent 				= Session::get('bookAppointMent');
			$bookAppointMent['selService'] 	= $input['selService'];
			Session::put('bookAppointMent', $bookAppointMent);

			$httpHost					= $_SERVER['HTTP_HOST'];
			$subDomainArr				= explode('.', $httpHost);
			$subDomain					= $subDomainArr[0];
			$accountID					= 0;
			$accountID					= $this->getAccountID($subDomain);

			if ( $accountID ) {
				$accountData 			= Account::where('id', $accountID)->first();
				if ( count((array)$accountData) ) {
					$storageFolder		= $accountData['storage_folder'];
                    if ($accountID === config('app.juvly_account_id')) {
                        $mediaFolder = $accountData['media_folder_path'];
                    }
				}
			}

			$params						= array( 'clinic_id' => $bookAppointMent['selClinic'], 'serviceArr' => $input['selService'], 'account_id' => $accountID );

			$app_type					= $bookAppointMent['selServiceType'];

			if ( $app_type == 'package' ) {
				$params['package_id']	= $input['selService'][0];
				$servicePackages		= ServicePackage::where('package_id', $input['selService'][0])->get();

				if ( count((array)
                $servicePackages) ) {
					$servicePackages	= $servicePackages->toArray();

					foreach( $servicePackages as $key => $value ) {
						$packageServiceArr[] = $value['service_id'];
					}

				}
				$params['serviceArr']	= $packageServiceArr;
			} else {
				$params['package_id']	= 0;
			}

			$allProviders				= json_decode($this->getProviderByClinic($params));
		}

		if ( count((array)$allProviders) ) {
			$i = 0;
			foreach( $allProviders as $allProvider ) {
				$providers[] = (array) $allProvider->User;
                $providers[$i]['storage_folder'] = $storageFolder;
                if ($accountID === config('app.juvly_account_id')) {
                    $providers[$i]['mediaFolder'] = $mediaFolder;
                }
				$i++;
			}
		}
		usort($providers, array($this, "sortByDisplayOrder"));
		return $providers;
	}

	public function date($input)
	{
		$data 				= array();
		$availableDates 	= array();
		$allAvailableDates 	= array();

		if ( $input ) {
			$bookAppointMent 			= Session::get('bookAppointMent');
			$bookAppointMent['selDoc'] 	= $input['selDoc'];
			Session::put('bookAppointMent', $bookAppointMent);

			if ( isset($input['selTZVal']) && $input['selTZVal'] != '' ) {
				$bookAppointMent 				= Session::get('bookAppointMent');
				$bookAppointMent['selTimeZone'] = $input['selTZVal'];
				Session::put('bookAppointMent', $bookAppointMent);
			} else if ( count((array)Auth::user()) && $input['selTZVal'] == '' ) {
				$bookAppointMent 				= Session::get('bookAppointMent');
				$bookAppointMent['selTimeZone'] = Auth::user()->timezone;
				Session::put('bookAppointMent', $bookAppointMent);
			}

			$httpHost					= $_SERVER['HTTP_HOST'];
			$subDomainArr				= explode('.', $httpHost);
			$subDomain					= $subDomainArr[0];
			$accountID					= $this->getAccountID($subDomain);

			$clinicID					= $bookAppointMent['selClinic'];
			$services					= $bookAppointMent['selService'];
			$appointment_type			= $bookAppointMent['appointment_type'];

			$params						= array( 'is_provider_availability' => true, 'provider_id' => $input['selDoc'], 'account_id' => $accountID, 'clinic_id' => $clinicID, 'appointment_service' => $services, 'appointment_type' => $appointment_type );

			$app_type					= $bookAppointMent['selServiceType'];

			if ( $app_type == 'package' ) {
				$params['package_id']	= $services[0];
			} else {
				$params['package_id']	= 0;
			}
			
			$startMonth = Carbon::now()->startOfMonth()->format('Y-m-d');
            $params['month_start'] = empty($input['month_start']) ? $startMonth : $input['month_start'];
            $params['month_end'] = Carbon::parse($params['month_start'])->endOfMonth()->format('Y-m-d');

//            $availableDates							= json_decode($this->getProviderAvailability($params));
            $availableDates							= json_decode($this->getNewProviderAvailability($params));
			$allAvailableDates['available_days'] 	= array();
			$allAvailableDates['vacation_dates'] 	= array();
            $allAvailableDates['month_start']       = $params['month_start'];
            $allAvailableDates['month_end']         = $params['month_end'];
			
			if ( isset($availableDates->schedules) ) {
				if ( count((array)$availableDates->schedules) ) {
					foreach( $availableDates->schedules as $key => $value ) {
						$allAvailableDates['available_days'][] = $value;
					}
				}
			}

			if ( isset($availableDates->vacation_array) ) {
				if ( count((array)$availableDates->vacation_array) ) {
					foreach( $availableDates->vacation_array as $key => $value ) {
						$allAvailableDates['vacation_dates'][] = $value;
					}
				}
			}

			$data = $allAvailableDates;
		}

		return $data;
	}

	public function contact($input)
	{
        $clearent_clinic_public_data = '';
        $isClearentGateway = false;
        $data = array();
        $httpHost = $_SERVER['HTTP_HOST'];
        $subDomainArr = explode('.', $httpHost);
        $subDomain = $subDomainArr[0];
        $accountID = $this->getAccountID($subDomain);
        $account = Account::where('id', $accountID)->first();
        $pos_gateway = $account->pos_gateway;

		if ( $input ) {
            if ($pos_gateway == 'clearent') {
                if ($account->stripe_connection == 'clinic') {
                    $condition = ['clinic_id' => $clinic_id, 'account_id' => $accountID];
                } else {
                    $condition = ['clinic_id' => 0, 'account_id' => $accountID];
                }
                $isClearentGateway = true;
                $bookAppointMent = Session::get('bookAppointMent');
                $clinic_id = $bookAppointMent['selClinic'];
                $clearent_clinic_public_data = AccountClearentConfig::whereHas('clearent_setup', function ($q) {
                    $q->where('status', 'completed');
                })->with(['clearent_setup' => function ($q) {
                    $q->where('status', 'completed');
                }])->where($condition)->first();
            }


            if ($pos_gateway == 'clearent') {
                $bookAppointMent = Session::get('bookAppointMent');
                $clinic_id = $bookAppointMent['selClinic'];
                if ($account->stripe_connection == 'clinic') {
                    $condition = ['clinic_id' => $clinic_id, 'account_id' => $accountID];
                } else {
                    $condition = ['clinic_id' => 0, 'account_id' => $accountID];
                }
                $isClearentGateway = true;
                $clearent_clinic_public_data = AccountClearentConfig::whereHas('clearent_setup', function ($q) {
                    $q->where('status', 'completed');
                })->with(['clearent_setup' => function ($q) {
                    $q->where('status', 'completed');
                }])->where($condition)->first();
            }

			/*if($pos_gateway == 'clearent'){
					$isClearentGateway = true;
					$bookAppointMent 			= Session::get('bookAppointMent');
					$clinic_id = $bookAppointMent['selClinic'];
					$clearent_clinic_public_data = AccountClearentConfig::whereHas('clearent_setup', function($q){
					$q->where('status','completed');
				})->with(['clearent_setup'=> function($q){
					$q->where('status','completed');
				}])->where(['clinic_id'=> $clinic_id, 'account_id' => $accountID])->first();
			}*/
			$bookAppointMent 			= Session::get('bookAppointMent');
			$bookAppointMent['selDate'] = $input['selDate'];
			$bookAppointMent['selTime'] = $input['selTime'];
            $bookAppointMent['isClearentGateway'] = $isClearentGateway;
            if (!empty($clearent_clinic_public_data) && isset($clearent_clinic_public_data)) {
                $bookAppointMent['clearent_public_key'] = $clearent_clinic_public_data->publickey ?? '';
            } else {
                $bookAppointMent['clearent_public_key'] = '';
            }
			Session::put('bookAppointMent', $bookAppointMent);
			$data = $bookAppointMent;
		}

		return $data;
	}

	public function saveFormData(Request $request)
	{
		$params 		= array();
		$creditData 	= array();
		$input 			= $request->input();
		parse_str($input['formData'], $params);
		parse_str($input['creditData'], $creditData);

		if ( count((array)$creditData) ) {
			$finalParams	= array_merge($params, $creditData);
		}

		if ( $input ) {
            $bookAppointMent = Session::get('bookAppointMent');
			if ($bookAppointMent && isset($bookAppointMent['formData']) && count((array)$bookAppointMent['formData'])) {

				if (isset($bookAppointMent['formData']['global_client_id']) && null != $bookAppointMent['formData']['global_client_id']) {
					$globalClientID = $bookAppointMent['formData']['global_client_id'];
				}
			}
			$bookAppointMent['formData'] 						= $finalParams;
			$bookAppointMent['formData']['full_number'] 		= @$input["full_number"];
			$bookAppointMent['formData']['global_client_id'] 	= @$globalClientID;
			Session::put('bookAppointMent', $bookAppointMent);
		}
		$json 								= array('data' => 'saved', 'status' => 'success' );
		return response()->json($json);
	}


	public function openPopup(Request $request)
	{
		$data 			= array();
		$input 			= $request->input();

		if ( $input ) {
			$defTimezone				= $input['timeZone'];
			$myTimezoneVal				= (string)$input['myTimeZone'];

			$juvlyTimeZone				= 'America/New_York';
			$juvlyDate 					= new \DateTime("now", new \DateTimeZone($juvlyTimeZone) );
			$data['juvlyTime'] 			= $juvlyDate->format('h:i A');
			$data['juvlyTimeZone'] 		= 'EDT';
			$data['juvlyTimeZoneVal'] 	= $juvlyTimeZone;

			if ( $myTimezoneVal == 'null' || $myTimezoneVal == '' || empty($myTimezoneVal) ) {
			//	$tzData 					= $this->getMyTimeZone();
				//$myTimeZone					= @json_decode($tzData)->timezone;
                $myTimeZone					= 'America/New_York';
			} else {
				$myTimeZone					= $myTimezoneVal;
			}

			$myDate						= new \DateTime("now", new \DateTimeZone(@$myTimeZone) );
			$data['myTime'] 			= $myDate->format('h:i A');
			$data['myTimeZone'] 		= null != ($this->getHumanReadableValueOfTimezone(@$myTimeZone)) ? $this->getHumanReadableValueOfTimezone(@$myTimeZone) : @$myTimeZone;
			$data['myTimeZoneVal'] 		= @$myTimeZone;
		}

		$json = array('data' => $data, 'status' => 'success' );

		return response()->json($json);
	}

	public function getAvailablity(Request $request)
	{
		$availableTime		= array();
		$morningTimes 		= array();
		$noonTimes 			= array();
		$eveningTimes 		= array();
		$input 				= $request->input();

		if ( $input ) {
			$providerID 	= $input['selProvider'];
			$selDate 		= $input['selDate'];
			$defTimezone	= $input['timeZone'];
			$selTime		= $input['selTime'];
			$dateToShow 	= date('D, M d, Y', strtotime($selDate));

			if ( $defTimezone ) {
				$selectedTimezone 		= null != ($this->getHumanReadableValueOfTimezone($defTimezone)) ? $this->getHumanReadableValueOfTimezone($defTimezone) : $defTimezone;
				$selectedTimezoneValue	= $defTimezone;
			} else {
				$selectedTimezone 		= 'Eastern Time (US &amp; Canada)';
				$selectedTimezoneValue	= 'America/New_York';

				$bookAppointMent 				= Session::get('bookAppointMent');
				$bookAppointMent['selTimeZone'] = $selectedTimezoneValue;
				Session::put('bookAppointMent', $bookAppointMent);
			}

			$bookAppointMent 			= Session::get('bookAppointMent');
			$clinicID					= $bookAppointMent['selClinic'];
			$services					= $bookAppointMent['selService'];

			$httpHost					= $_SERVER['HTTP_HOST'];
			$subDomainArr				= explode('.', $httpHost);
			$subDomain					= $subDomainArr[0];
			$accountID					= $this->getAccountID($subDomain);
			$appointment_type			= $bookAppointMent['appointment_type'];
			$params						= array('provider_id' => $providerID, 'appointment_id' => 0, 'date' => $selDate, 'clinic_id' => $clinicID, 'account_id' => $accountID, 'appointment_service' => $services, 'timezone' =>  (isset($defTimezone) && $defTimezone != '') ? $defTimezone : $selectedTimezoneValue, 'appointment_type' => $appointment_type );

			$app_type					= $bookAppointMent['selServiceType'];

			if ( $app_type == 'package' ) {
				$params['package_id']	= $services[0];
			} else {
				$params['package_id']	= 0;
			}



			$authPatID		 	= Session::get('patient_id');

			if ( $authPatID > '0' ) {
				$params['patient_id']	= $authPatID;
			} else {
				$params['patient_id']	= 0;
			}

			$availableTime				= json_decode($this->getProviderTime($params));

			if ( count((array)$availableTime) ) {

				foreach( $availableTime as $key => $value ) {
					if ( $key < '12:00:00') {
						$morningTimes[$key] = $value;
					}
					if ( $key >= '12:00:00' && $key < '17:00:00' ) {
						$noonTimes[$key] = $value;
					}
					if ( $key >= '17:00:00' && $key < '24:00:00' ) {
						$eveningTimes[$key] = $value;
					}
				}
			}

			$view 						= View::make('appointments.timezone', compact('providerID', 'selDate', 'selTime', 'selectedTimezoneValue', 'selectedTimezone', 'dateToShow', 'availableTime', 'morningTimes', 'noonTimes', 'eveningTimes'));
			$contents 					= $view->render();
			$json 						= array('data' => $contents, 'status' => 'success' );

			return response()->json($json);
		}
	}

	protected function getMyTimeZone()
	{
		$data 	= array();
		$ip 	= $this->getUserIP();

		$data 	= $this->get_http_response($ip);
		//$data 	= $this->get_http_response('122.173.21.208');

		return $data;
	}

	protected function getUserIP()
    {
        $client  = @$_SERVER['HTTP_CLIENT_IP'];
        $forward = @$_SERVER['HTTP_X_FORWARDED_FOR'];
        $remote  = $_SERVER['REMOTE_ADDR'];

        if ( filter_var($client, FILTER_VALIDATE_IP) ) {
            $ip = $client;
        } elseif ( filter_var($forward, FILTER_VALIDATE_IP) ) {
            $ip = $forward;
        } else {
            $ip = $remote;
        }

        return $ip;
    }

    protected function get_http_response( $ipaddress )
    {
        $data   = array();
        $ch     = curl_init();
        $url    = "http://ip-api.com/json/" . $ipaddress;

        curl_setopt_array($ch, array(
		  CURLOPT_URL => $url,
		  CURLOPT_RETURNTRANSFER => true,
		  CURLOPT_ENCODING => "",
		  CURLOPT_MAXREDIRS => 10,
		  CURLOPT_TIMEOUT => 30,
		  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		  CURLOPT_CUSTOMREQUEST => "POST",
		  CURLOPT_HTTPHEADER => array(
			"accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
			"accept-encoding: gzip, deflate",
			"accept-language: en-US,en;q=0.5",
			"cache-control: no-cache",
			"connection: keep-alive",
			"content-type: application/x-www-form-urlencoded",
			"host: ip-api.com",
			"postman-token: 39f480bf-0585-ba7a-73dc-8c3812d9452b",
			"upgrade-insecure-requests: 1",
			"user-agent: " . $_SERVER ['HTTP_USER_AGENT']
		  ),
		));


        $data = curl_exec($ch);

        if ( curl_exec($ch) === false ) {
            return array();
        } else {
            return $data;
        }
        return $data;
    }

	public function getHumanReadableValueOfTimezone($givenTimeZone)
	{
		$availableTimeZone = array("America/Chicago" => "Central Time (US &amp; Canada)", "America/Denver" => "Mountain Time (US &amp; Canada)", "America/Indiana/Indianapolis" => "Indiana (East)", "America/Juneau" => "Alaska", "America/Los_Angeles" => "Pacific Time (US &amp; Canada)", "America/New_York" => "Eastern Time (US &amp; Canada)", "America/Phoenix" => "Arizona", "Pacific/Honolulu" => "Hawaii" );

		if ( array_key_exists($givenTimeZone, $availableTimeZone) ) {
			return $availableTimeZone[$givenTimeZone];
		}
	}

	private function getDatabase($subDomain)
	{
		$db				= "";
		$account 		= Account::where('pportal_subdomain', $subDomain)->where('status','!=', 'inactive')->first();
		//$account 		= Account::where('pportal_subdomain', "customers")->where('status', 'active')->first();

		if ( $account ) {
			$db 		= $account->database_name;
			Session::put('account', $account);
		}

		$this->userDB 		= $db;
	}

	private function getAccountID($subDomain)
	{
		$accountID		= 0;
		$account 		= Account::where('pportal_subdomain', $subDomain)->where('status','!=', 'inactive')->first();
		//$account 		= Account::where('pportal_subdomain', "customers")->where('status', 'active')->first();

		if ( $account ) {
			$accountID 		= $account->id;
		}

		return $accountID;
	}

	public function showThankYou(Request $request)
	{
		$db					= "";
		$appointmentData 	= array();
		$selectedClinic 	= array();
		$selectedServices	= array();
		$selectedDoctor		= array();
		$appointmentData 	= Session::get('bookAppointMent');
		//$db 				= Session::get('selectedDB');

		$httpHost		= $_SERVER['HTTP_HOST'];
		$subDomainArr	= explode('.', $httpHost);
		$subDomain		= $subDomainArr[0];
		$this->getDatabase($subDomain);
		$db					= $this->userDB;
		$accountID			= "";
		if ( count((array)$appointmentData) > 1 ) {
			if ( $db != "" ) {
				$this->switchDatabase($db);
				$selServiceType		= $appointmentData['selServiceType'];
				$selectedClinic 	= Clinic::where('id', $appointmentData['selClinic'])->first();
				if ( $selServiceType == 'service' ) {
					$selectedServices	= Service::whereIn('id', $appointmentData['selService'])->get();
				} else {
					$selectedServices	= Package::whereIn('id', $appointmentData['selService'])->get();
				}
				$selectedDoctor		= Provider::where('id', $appointmentData['selDoc'])->first();
			}
		//	$this->sendAppointmentBookingClinicMail($request,$appointmentData);
		//	$this->sendAppointmentBookingPatientMail($request,$appointmentData);
			$httpHost					= $_SERVER['HTTP_HOST'];
			$subDomainArr				= explode('.', $httpHost);
			$subDomain					= $subDomainArr[0];
			$accountID					= $this->getAccountID($subDomain);
			$account 					= Account::where('id', $accountID)->where('status','!=', 'inactive')->with('accountCommunication')->first();
			$account_pre 				= AccountPrefrence::where('account_id', $accountID)->first();
			if(count((array)$account)>0) {
				//send covid email
				$covidMailBody			= $account_pre->covid_email_body;

				$aptDateTime			= date('Y-m-d', strtotime(@$appointmentData['selDate'] . " " . @$appointmentData['selTime']));
				//~ $aptDateTimeZone		= isset($appointmentData['selTimeZone']) ? $appointmentData['selTimeZone'] : 'America/New_York';

				//~ $clinicInfo 						= Clinic::where('id', $appointmentData['selClinic'])->first();
				//~ if ( count($clinicInfo) ) {
					//~ $clinic 						= $clinicInfo->toArray();
					//~ if ( count($clinic) ) {
						//~ $aptDateTimeZone			= '';
						//~ $aptDateTimeZone			= $clinic['timezone'];
					//~ }
				//~ }

				//~ $systemDate 						= $this->convertTzToSystemTz($aptDateTime, $aptDateTimeZone);
				$today								= date('Y-m-d');
				$nextDay							= date('Y-m-d', strtotime("+1 day"));

				//~ $appointment_datetime_with_timezone = new DateTime($aptDateTime, new DateTimeZone($aptDateTimeZone));
				//~ $system_appointment_datetime 		= $appointment_datetime_with_timezone->setTimezone(new DateTimeZone('America/New_York'));
				//~ $systemDate							= $system_appointment_datetime->format('Y-m-d');

				if ((strtotime($today) == strtotime($aptDateTime) || strtotime($nextDay) == strtotime($aptDateTime)) && $account_pre->covid_email_status == '1') {
				//~ if ($account_pre->covid_email_status == '1') {
                    if ($accountID === config('app.juvly_account_id')) {
                        $this->sendCovidMail($appointmentData, $covidMailBody, $account);
                    } else {
                        if ($this->checkEmailLimit()) {
                            $this->sendCovidMail($appointmentData, $covidMailBody, $account);
                        } elseif ($this->checkEmailAutofill() && $this->paidAccount() == 'paid') {
                            $this->sendCovidMail($appointmentData, $covidMailBody, $account);
                        }
                    }
				}

				//send covid email

				/* in person */
				if($account->appointment_booking_status == 1 && isset($appointmentData['selService']) && $appointmentData['appointment_type'] == "in_person" ){
                    $smsBody = $account->appointment_booking_sms;
                    /*sms part started*/
                    if ($accountID === config('app.juvly_account_id')) {
                        $stop_sms_check = $this->stopSmsCheck($appointmentData['patient_id']);
                        if ($stop_sms_check == 0) {
                            $smsBody = $smsBody . '

Reply STOP to stop receiving appointment SMS notifications';
                            $this->sendAppointmentBookingSMS($appointmentData, $smsBody, $account);
                        }
                    } else {
                        if ($this->checkSmsLimit()) {
                            $this->sendAppointmentBookingSMS($appointmentData, $smsBody, $account);
                        } elseif ($this->checkSmsAutofill() && $this->paidAccount() == 'paid') {
                            $this->sendAppointmentBookingSMS($appointmentData, $smsBody, $account);
                        }
                        if ($this->checkSmsLimit()) {
                            $this->sendClinicBookingSMS($appointmentData, $account);
                        } elseif ($this->checkSmsAutofill() && $this->paidAccount() == 'paid') {
                            $this->sendClinicBookingSMS($appointmentData, $account);
                        }
                    }
                    /*sms part end*/

                    /*email part started*/
                    $mailBody = $account->appointment_booking_email;

                    if($accountID === config('app.juvly_account_id')) {
                        //~ $this->enablePatientPortalAccessAndSendMail($appointmentData, Session::get('bookAppointMent')['patient_id'], $account);
                        $this->sendClinicBookingSMS($appointmentData, $account);
                        $this->sendAppointmentBookingPatientMail($appointmentData,$mailBody,$account);
                        $this->sendAppointmentBookingClinicMail($appointmentData,$account);
                        $pre_treatment_body		= $account_pre->pre_treatment_body;
                        $pre_tret_subject		= $account_pre->pre_treatment_subject;
                        $this->sendPreInstrucionMail($appointmentData,$pre_treatment_body,$pre_tret_subject,$account);
                    } else {
                        if($this->checkEmailLimit()) {
                            $this->sendAppointmentBookingPatientMail($appointmentData,$mailBody,$account);
                        }elseif($this->checkEmailAutofill() && $this->paidAccount() == 'paid' ) {
                            $this->sendAppointmentBookingPatientMail($appointmentData,$mailBody,$account);
                        }
                        if($this->checkEmailLimit()) {
                            $this->sendAppointmentBookingClinicMail($appointmentData,$account);
                        }elseif($this->checkEmailAutofill() && $this->paidAccount() == 'paid' ) {
                            $this->sendAppointmentBookingClinicMail($appointmentData,$account);
                        }
                        $pre_treatment_body		= $account_pre->pre_treatment_body;
                        $pre_tret_subject		= $account_pre->pre_treatment_subject;
                        if($this->checkEmailLimit()) {
                            $this->sendPreInstrucionMail($appointmentData,$pre_treatment_body,$pre_tret_subject,$account);
                        }elseif($this->checkEmailAutofill() && $this->paidAccount() == 'paid' ) {
                            $this->sendPreInstrucionMail($appointmentData,$pre_treatment_body,$pre_tret_subject,$account);
                        }
                    }
				}
				/* virtual type appointment */
				if($account['accountCommunication']->appointment_virtual_booking_status == 1 && isset($appointmentData['selService']) && $appointmentData['appointment_type'] == "virtual" ){
                    $smsBody = $account['accountCommunication']->appointment_virtual_booking_sms;
                    /*sms part started*/
                    if ($accountID === config('app.juvly_account_id')) {
                        $stop_sms_check = $this->stopSmsCheck($appointmentData['patient_id']);
                        if ($stop_sms_check == 0) {
                            $smsBody = $smsBody . '

Reply STOP to stop receiving appointment SMS notifications';
                            $this->sendAppointmentBookingSMS($appointmentData, $smsBody, $account);
                        }
                    } else {
                        if ($this->checkSmsLimit()) {
                            $this->sendAppointmentBookingSMS($appointmentData, $smsBody, $account);
                        } elseif ($this->checkSmsAutofill() && $this->paidAccount() == 'paid') {
                            $this->sendAppointmentBookingSMS($appointmentData, $smsBody, $account);
                        }
                        if ($this->checkSmsLimit()) {
                            $this->sendClinicBookingSMS($appointmentData, $account);
                        } elseif ($this->checkSmsAutofill() && $this->paidAccount() == 'paid') {
                            $this->sendClinicBookingSMS($appointmentData, $account);
                        }
                    }
                    /*sms part end*/

                    /*email part started*/
                    $mailBody = $account['accountCommunication']->appointment_virtual_booking_email;

                    if ($accountID === config('app.juvly_account_id')) {
                        //~ $this->enablePatientPortalAccessAndSendMail($appointmentData, Session::get('bookAppointMent')['patient_id'], $account);
                        $this->sendClinicBookingSMS($appointmentData, $account);
                        $this->sendAppointmentBookingPatientMail($appointmentData, $mailBody, $account);
                        $this->sendAppointmentBookingClinicMail($appointmentData, $account);
                        $pre_treatment_body = $account_pre->pre_treatment_body;
                        $pre_tret_subject = $account_pre->pre_treatment_subject;
                        $this->sendPreInstrucionMail($appointmentData, $pre_treatment_body, $pre_tret_subject, $account);
                    } else {
                        if ($this->checkEmailLimit()) {
                            $this->sendAppointmentBookingPatientMail($appointmentData, $mailBody, $account);
                        } elseif ($this->checkEmailAutofill() && $this->paidAccount() == 'paid') {
                            $this->sendAppointmentBookingPatientMail($appointmentData, $mailBody, $account);
                        }
                        if ($this->checkEmailLimit()) {
                            $this->sendAppointmentBookingClinicMail($appointmentData, $account);
                        } elseif ($this->checkEmailAutofill() && $this->paidAccount() == 'paid') {
                            $this->sendAppointmentBookingClinicMail($appointmentData, $account);
                        }
                        $pre_treatment_body = $account_pre->pre_treatment_body;
                        $pre_tret_subject = $account_pre->pre_treatment_subject;
                        if ($this->checkEmailLimit()) {
                            $this->sendPreInstrucionMail($appointmentData, $pre_treatment_body, $pre_tret_subject, $account);
                        } elseif ($this->checkEmailAutofill() && $this->paidAccount() == 'paid') {
                            $this->sendPreInstrucionMail($appointmentData, $pre_treatment_body, $pre_tret_subject, $account);
                        }
                    }
				}

				$appointmentWithSeriveAndClinic 	= 	Appointment :: with('services')->with('clinic')->find($appointmentData['appointment_id']);
				$providerID = $appointmentWithSeriveAndClinic->user_id;
				$patientID = $appointmentWithSeriveAndClinic->patient_id;
				$this->syncGoogleCalanderEvent($providerID, $appointmentWithSeriveAndClinic, $patientID, 'Booked');
			}
			$appUserID = 0;
			if ($appointmentData['appointment_type'] == "virtual") {
				TelehealthHelper::addMeetingSessions($accountID, $appointmentData);
			}
			if ( count((array)$appointmentData) ) {
				if ( array_key_exists('appUserID', $appointmentData)) {
					$appUserID 		= $appointmentData['appUserID'];
					$hashids   		= new Hashids('juvly_aesthetics', 30);
					$appUserID		= $hashids->encode($appUserID);
				}
			}

		} else {
			return Redirect::to('/book/appointments');
		}
		Session::put('database_tmp',$db);
		Session::put('bookAppointMentForCalender',$appointmentData);

		$bookinArray = Session::get('bookAppointMent');
		Session::put('patienttttt_id',$bookinArray['patient_id']);

		$patient_id = $bookinArray['patient_id'];

		unset($bookinArray['patient_id']);
		unset($bookinArray['selClinic']);
		unset($bookinArray['appointment_type']);
		unset($bookinArray['selService']);
		unset($bookinArray['selDoc']);
		unset($bookinArray['selTimeZone']);
		unset($bookinArray['selDate']);
		unset($bookinArray['selTime']);
		unset($bookinArray['formData']);
		unset($bookinArray['selServiceType']);
        unset($bookinArray['isClearentGateway']);
        unset($bookinArray['appointment_id']);

		if ( count((array)$bookinArray)) {
			if ( array_key_exists('appUserID', $bookinArray)) {
				unset($bookinArray['appUserID']);
			}
		}

        if ($accountID === config('app.juvly_account_id')) {
            if (count((array)$bookinArray)) {
                if (array_key_exists('gClickID', $bookinArray)) {
                    unset($bookinArray['gClickID']);
                }
            }
        }
		Session::put('bookAppointMent', $bookinArray);
		$bookAppointMentForCalender = Session::get('bookAppointMentForCalender');
		$database_tmp = Session::get('database_tmp');
		$patienttttt_id = Session::get('patienttttt_id');
		$url = URL::to('/');
		$new_book_session = array('bookAppointMentForCalender'=>$bookAppointMentForCalender,'database_tmp'=>$database_tmp,'patienttttt_id'=> $patienttttt_id,'url'=>$url);
		$all_session = Crypt::encrypt($new_book_session);
		//$appointmentData 	= Session::get('bookAppointMent');
		$this->switchDatabase($this->userDB);
		$patent_detail = Patient::where('id',$patient_id)->first();
		$request->session()->put('logged_in_patient',$patent_detail);

		Session::put('selectedDB', '');
		return view('appointments.thankyou', compact('appointmentData', 'selectedClinic', 'selectedServices', 'selectedDoctor', 'selServiceType', 'appUserID', 'accountID','all_session'))->render();
	}

	public function sendAppointmentBookingPatientMail($appointmentData, $mailBody,$account)
	{
		$appointment = Appointment::find($appointmentData['appointment_id']);
		$database_name 	= Session::get('selectedDB');

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

		$this->switchDatabase($database_name);

		$clinic 						= Clinic::findOrFail(@$appointmentData['selClinic']);
		$sender	 						= $this->getSenderEmail();
		$subject 						= "Appointment Booked";

		$location 			= array();
		if(!empty($clinic->address)){
			$location[] 		= $clinic->address;
		}
		if(!empty($clinic->city)){
			$location[] 		= !empty($clinic->clinic_city) ? $clinic->clinic_city : $clinic->city;
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
		$selDate 						= changeFormatByPreference($appointmentData['selDate']);
		//$appointment_time 				= date('g:i a',strtotime($appointmentData['selTime']));
		$appointment_time 				= changeFormatByPreference(@$appointmentData['selTime'], null, true, true );
		$appointment_date_time 			= date('l',strtotime($appointmentData['selDate'])).' '.$selDate.' @ '.$appointment_time;

		$provider = Users :: where('id',@$appointmentData['selDoc'])->first();

		if(count((array)$provider) > 0) {

			if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

				$provider_name = ucfirst($provider->bio_name);
			} else {

				$provider_name = ucfirst($provider->firstname." ".$provider->lastname);
			}

		} else {
			$provider_name='';
		}

		$services 			= array();
		$instruction_url 	= array();

		if(count((array)$appointmentData['selService'])>0) {

            $allBookedServices = Service:: with('services_treatment_instructions')->whereIn('id', $appointmentData['selService'])->get();
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
		$appointment_header['APPOINTMENTDATE'] 	= $selDate;
		$appointment_header['APPOINTMENTTIME'] 	= $appointment_time;
		$appointment_header['PROVIDERNAME'] 	= $provider_name;
		$appointment_header['BOOKEDSERVICES'] 	= implode(', ',$services);
		$appointment_header['CLINICNAME'] 	= $clinic->clinic_name;

		$replace 							= array();
	//	$replace['PATIENTNAME'] 			= ucfirst($appointmentData['formData']['firstname'] ." ". $appointmentData['formData']['lastname']);
		$replace['PATIENTNAME'] 			= ucfirst($appointmentData['formData']['firstname']);
		$replace['CLINICNAME']				= ucfirst($clinic->clinic_name);
		$replace['CLINICLOCATION']			= $clinic_location;
		$replace['CLINICINSTRUCTIONS']		= $email_special_instructions;
		$replace['APPOINTMENTDATETIME']		= $appointment_date_time;
		$replace['PROVIDERNAME']			= $provider_name;
		$replace['BUSINESSNAME']			= $business_name;
		$replace['BOOKEDSERVICES']			= implode(', ',$services);
		$replace['SERVICEINSTRURL']			= implode(', ',$instruction_url);

		$replace['CANFEECHARGEDAYS']		= $cancelation_fee_charge_days;
		$replace['CANCELATIONFEES']			= $cancelation_fees;
		$replace['CLIENTPATIENTURL']		= URL::to('/');

		$tags								= array();
		$tags['PATIENTNAME'] 				= "{{PATIENTNAME}}";
		$tags['CLINICNAME']					= "{{CLINICNAME}}";
		$tags['APPOINTMENTDATETIME']		= "{{APPOINTMENTDATETIME}}";
		$tags['CLINICLOCATION']				= "{{CLINICLOCATION}}";
		$tags['CLINICINSTRUCTIONS']			= "{{CLINICINSTRUCTIONS}}";
		$tags['PROVIDERNAME']				= "{{PROVIDERNAME}}";
		$tags['BOOKEDSERVICES']				= "{{BOOKEDSERVICES}}";
		$tags['SERVICEINSTRURL']			= "{{SERVICEINSTRURL}}";
		$tags['CANFEECHARGEDAYS']			= "{{CANFEECHARGEDAYS}}";
		$tags['CANCELATIONFEES']			= "{{CANCELATIONFEES}}";
		$tags['CLIENTPATIENTURL']			= "{{CLIENTPATIENTURL}}";
		$tags['BUSINESSNAME']				= "{{BUSINESSNAME}}";
		$tags['MEETINGLINK']			= "{{MEETINGLINK}}";

		$encoded_account_id = $this->encryptKey($account->id);
		$meeting_id =  $appointment['meeting_id'];
		$appointment_type = $appointment['appointment_type'];
		if($appointment_type == "virtual" && $meeting_id) {

			$meeting_link = config('constants.urls.top_box');
			//~ $replace['MEETINGLINK'] = "<a href=".$meeting_link."/client/".$meeting_id."><h5><b>Join Your Virtual Meeting<b></h5></a><br>
			//~ Please click the link below on the day & time of your appointment to enter our Virtual Clinic. For the best experience, we recommend joining your appointment from a quiet place with a strong wifi or cellular connection. Ensure your camera and audio are both enabled as you enter the portal. While settings vary by browser, but you should be prompted once you login.
			//~ <br><br>"."Appointment Link: $meeting_link/client/".$meeting_id;

			//~ $replace['MEETINGLINK'] = "<a target='_blank' href=".$meeting_link."/client/".$meeting_id.">".$meeting_link."/client/".$meeting_id."</a>";
			$replace['MEETINGLINK'] = $meeting_link."/client/".$meeting_id;

		}else{
			$replace['MEETINGLINK'] = "";
		}


		foreach ( $tags as $key => $val ) {
			if ( $val ) {
				 $mailBody  = str_replace($val, $replace[$key], $mailBody);
			}
		}


		$email_content = $this->getAppointmentEmailTemplate($mailBody,$account,$clinic,$subject,$appointment_header, "false");
		$noReply = config('mail.from.address');

        $response_data =  EmailHelper::sendEmail($noReply, trim($appointmentData['formData']['email']), $sender, $email_content, $subject);

		//echo "<pre>"; print_r($response_data); echo "</pre>";
		if ($response_data) {
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

	public function sendAppointmentBookingClinicMail($appointmentData,$account)
	{
		$database_name 	= Session::get('selectedDB');
		$this->switchDatabase($database_name);

		$clinic 						= Clinic::findOrFail(@$appointmentData['selClinic']);
		$sender	 						= $this->getSenderEmail();
		$subject 						= "Appointment Booked";
        $email_ids					    = explode(",", @$clinic->appointment_notification_emails);

        $selDate 				= changeFormatByPreference($appointmentData['selDate']);
		$body_content			= Config::get('app.mail_body');
		$mail_body				= $body_content['BOOKING_APPOINTMENT_CLINIC_EMAIL'];
		//$appointment_time 		= date('g:i a',strtotime($appointmentData['selTime']));
		$appointment_time 				= changeFormatByPreference(@$appointmentData['selTime'], null, true, true );
		$appointment_date_time = date('l',strtotime($appointmentData['selDate'])).' '.$selDate.' @ '.$appointment_time;

		$location 			= array();
		$services = array();

		$provider = Users :: where('id',@$appointmentData['selDoc'])->first();
		if(count((array)$provider) > 0) {

			if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

				$provider_name = ucfirst($provider->bio_name);
			} else {

				$provider_name = ucfirst($provider->firstname." ".$provider->lastname);
			}

		} else {
			$provider_name='';
		}

		if(count((array)$appointmentData['selService'])>0) {

			$allBookedServices 	= Service :: whereIn('id',$appointmentData['selService'])->pluck('name');

			if(count((array)$allBookedServices)>0){
				$allBookedServices = $allBookedServices->toArray();
				foreach($allBookedServices as $key => $val){
					$services[] = ucfirst($val);
				}
			}

		}

		if(!empty($clinic->address)){
			$location[] 		= $clinic->address;
		}
		if(!empty($clinic->city)){
			$location[] 		= !empty($clinic->clinic_city) ? $clinic->clinic_city : $clinic->city;
		}
		if(count((array)$location)>0) {
			$clinic_location = implode(",",$location);
		} else {
			$clinic_location = '';
		}
		$client_name = $this->getUserUsedClientName($account->id);
		$mail_body						= "New appointment booked by customer using ".ucfirst($client_name)." Portal" . "\n";
		$mail_body						.= ucfirst($client_name)." : " . ucfirst($appointmentData['formData']['firstname']) . ' ' . ucfirst($appointmentData['formData']['lastname']) . "\n";
		$mail_body						.= "Provider : " . $provider_name . "\n";
		$mail_body						.= "Clinic : " . ucfirst($clinic->clinic_name) . "\n";
		$mail_body						.= "Location : " . ucfirst($clinic_location) . "\n";
		$mail_body						.= "Appt Date Time : " . $appointment_date_time . "\n";
		$mail_body						.= "Services : " . implode(', ',$services) . "\n";
		$appointment_header['APPOINTMENTDATE'] 	= $selDate;
		$appointment_header['APPOINTMENTTIME'] 	= $appointment_time;
		$appointment_header['PROVIDERNAME'] 	= $provider_name;
		$appointment_header['BOOKEDSERVICES'] 	= implode(', ',$services);
		$appointment_header['CLINICNAME'] 	= $clinic->clinic_name;

		$email_content = $this->getAppointmentEmailTemplate($mail_body,$account,$clinic,$subject,$appointment_header, "false");
		$noReply = config('mail.from.address');
		$mail_data  	  = array('content' => array(0 => (object) array ('type' => 'text/html','value' => $email_content)),
						   'from' => (object) array ('email' => $noReply),
						   'reply_to' => (object) array ('email' => $sender),
						  'personalizations' => array(0 => (object) array ('to' =>  $email_ids)),
						  'subject' => $subject
		);

        $response_data =  EmailHelper::sendEmail($noReply, $email_ids, $sender, $email_content, $subject);

		if ($response_data) {
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

	function getAvailableProvider(Request $request)
	{
		$input 						= $request->input();

		if ($input) {
			$providerData				= array();
			$httpHost					= $_SERVER['HTTP_HOST'];
			$subDomainArr				= explode('.', $httpHost);
			$subDomain					= $subDomainArr[0];
			$accountID					= $this->getAccountID($subDomain);
			$bookAppointMent 			= Session::get('bookAppointMent');

			$selDate 					= $input['date'];
			$selTime					= date('H:i:s', strtotime($input['time']));
			$clinicID					= $bookAppointMent['selClinic'];
			$services					= $bookAppointMent['selService'];
			$timezone					= isset($bookAppointMent['selTimeZone']) ? $bookAppointMent['selTimeZone'] : 'America/New_York';

			$params						= array( 'appointment_id' => 0, 'date' => $selDate, 'time' => $selTime, 'clinic_id' => $clinicID, 'account_id' => $accountID, 'appointment_service' => $services, 'timezone' =>  $timezone );

			$app_type					= $bookAppointMent['selServiceType'];

			if ( $app_type == 'package' ) {
				$params['package_id']	= $services[0];
			} else {
				$params['package_id']	= 0;
			}

			$authPatID		 	= Session::get('patient_id');

			if ( $authPatID > '0' ) {
				$params['patient_id']	= $authPatID;
			} else {
				$params['patient_id']	= 0;
			}

			$params['appointment_type']	= $bookAppointMent['appointment_type'];

			$providerData				= json_decode($this->getRandomProvider($params), true);

			if ( count((array)$providerData) ) {
				$json 					= array('data' => $providerData, 'status' => 'success' );

				$bookAppointMent['selDoc'] 	= $providerData['id'];
				Session::put('bookAppointMent', $bookAppointMent);
			} else {
				$json 					= array('data' => array(), 'status' => 'error' );
			}

			return response()->json($json);
		}
	}

	function changeServiceType(Request $request)
	{
		$input 		= $request->input();

		if ($input) {
			$bookAppointMent 					= Session::get('bookAppointMent');
			$bookAppointMent['selServiceType'] 	= $input['type'];
			Session::put('bookAppointMent', $bookAppointMent);

			$json 								= array('data' => 'saved', 'status' => 'success' );
		} else {
			$json 								= array('data' => '', 'status' => 'error' );
		}

		return response()->json($json);
	}

	protected function savePatientCard($account, $input, $patient_id, $stripeUserID="", $gatewayType = null)
	{
		if ( count((array)$account) > 0 && count((array)$input) > 0 ) {
			$dbname	= $account->database_name;

			$this->switchDatabase($dbname);
			$patient_on_file 	= array();
			$patient_on_file 	= PatientCardOnFile::where('patient_id', $patient_id)->where('stripe_user_id', $stripeUserID)->first();

            $billing_zip = '';
            if (!empty($gatewayType) && $gatewayType == "clearent") {
                if (!empty($input["payload"]["tokenResponse"]) && isset($input["payload"]["tokenResponse"])) {
                    $card_number = $input["payload"]["tokenResponse"]["card-type"] . ' ending ' . $input["payload"]["tokenResponse"]["last-four-digits"];
                    $card_on_file = $input["payload"]["tokenResponse"]["token-id"];
                    $card_expiry_date = $input["payload"]["tokenResponse"]["exp-date"];
                    $billing_zip = $input["payload"]["tokenResponse"]['avs-zip'] ?? '';
                } else if (!empty($input["payload"]["transaction"]) && isset($input["payload"]["transaction"])) {
                    $card_number = $input["payload"]["transaction"]["card-type"] . ' ending ' . $input["payload"]["transaction"]["last-four"];
                    // $card_on_file		= $input["payload"]["transaction"]["id"];
                    $card_on_file = $this->getClearentLinksData($input["links"]);
                    $card_expiry_date = $input["payload"]["transaction"]["exp-date"];
                    $billing_zip = $input["payload"]["transaction"]["billing"]["zip"] ?? '';
                }
            } else {
                $card_number = $input->source->brand . ' ending ' . $input->source->last4;
                $card_on_file = $input->customer;
                $card_expiry_date = null;
            }

			if (count((array)$patient_on_file) == 0) {
				$PatientCardOnFile 					= new PatientCardOnFile;
				$PatientCardOnFile->patient_id  	= $patient_id;
                $PatientCardOnFile->card_on_file    = $card_on_file;
				$PatientCardOnFile->status    		= 0;
				$PatientCardOnFile->card_number    	= $card_number;
				$PatientCardOnFile->stripe_user_id  = $stripeUserID;
				$PatientCardOnFile->card_expiry_date  = $card_expiry_date;
                if (!empty($billing_zip) && isset($billing_zip)) {
                    $PatientCardOnFile->billing_zip = $billing_zip;
                }
				if ($PatientCardOnFile->save()) {
					return true;
				} else {
					return false;
				}

			} else {
				$update_arr	= array(
					'card_on_file'	=> $card_on_file,
					'card_number'	=> $card_number,
					'stripe_user_id'=> $stripeUserID,
                    'card_expiry_date'  => $card_expiry_date ?? null
				);

				$status    = PatientCardOnFile::where('id', $patient_on_file->id)->update($update_arr);

				if ( $status ) {
					return true;
				} else {
					return false;
				}

			}
		} else {
			return false;
		}
	}

	protected function checkIfPatientIsFired($db, $patientID)
	{
		$patient	= array();

		$this->switchDatabase($db);

		$patient_info = Patient::where('id', $patientID)->where('status', 0)->first();

		if ( count((array)$patient_info) ) {
			$patient = $patient_info->toArray();
		}

		if ( count((array)$patient) && isset($patient['is_fired']) && ($patient['is_fired'] == 1) ) {
			return $patient['id'];
		} else {
			return 0;
		}
	}

	protected function checkIfProviderCanBeUsed($appointmentData)
	{
		$providerCanBeBooked 	= '0';
		$providerInfo			= array();
		$provider				= array();

		if ( count((array)$appointmentData) ) {
			if ( isset($appointmentData['selDoc']) && $appointmentData['selDoc'] > 0 ) {
				$providerInfo = Users::where('id', $appointmentData['selDoc'])->first();
				if ( count((array)$providerInfo) ) {
					$provider = $providerInfo->toArray();
				}
				if ( count((array)$provider) ) {
					if ( strtolower($provider['status']) == 'active' && $provider['is_available_online'] == 1 ) {
						$providerCanBeBooked = '1';
					}
				}
			}
		}
		return $providerCanBeBooked;
	}

	protected function checkIfClinicCanBeUsed($db, $appointmentData)
	{
		$clinicCanBeBooked 	= '0';
		$clinicInfo			= array();
		$clinic				= array();

		$this->switchDatabase($db);

		if ( count((array)$appointmentData) ) {
			if ( isset($appointmentData['selClinic']) && $appointmentData['selClinic'] > 0 ) {
				$clinicInfo = Clinic::where('id', $appointmentData['selClinic'])->where('is_available_online', 1)->first();
				if ( count((array)$clinicInfo) ) {
					$clinic = $clinicInfo->toArray();
				}
				if ( count((array)$clinic) ) {
					if ( strtolower($clinic['status']) == 0 ) {
						$clinicCanBeBooked = '1';
					}
				}
			}
		}
		return $clinicCanBeBooked;
	}

	protected function checkIfServOrPackCanBeUsed($db, $appointmentData)
	{
		$serviceInfo 			= array();
		$packageInfo 			= array();
		$services 				= array();
		$packages 				= array();
		$servOrPackCanBeUsed	= '0';
		$inactiveServices 		= 0;
		$inactivePackages 		= 0;

		$this->switchDatabase($db);

		if ( count((array)$appointmentData) ) {
			$app_type		= $appointmentData['selServiceType'];

			if ( $app_type == 'service' ) {
				$serviceInfo = Service::whereIn('id', $appointmentData['selService'])->get();
				if ( count((array)$serviceInfo) ) {
					$services = $serviceInfo->toArray();
				}
				if ( count((array)$services) ) {
					foreach ( $services as $service ) {
						if ( $service['status'] == 1 ) {
							$inactiveServices  = $inactiveServices + 1;
						}
					}
				}
			} else {
				$packageID		= $appointmentData['selService'];
				$packageInfo 	= Package::whereIn('id', $packageID)->get();
				if ( count((array)$packageInfo) ) {
					$packages = $packageInfo->toArray();
				}
				if ( count((array)$packages) ) {
					foreach ( $packages as $package ) {
						if ( $package['status'] == 1 || $package['is_active'] == 0 ) {
							$inactivePackages  = $inactivePackages + 1;
						}
					}
				}
			}

			if ( $inactiveServices + $inactivePackages == 0 ) {
				$servOrPackCanBeUsed = '1';
			}
		}
		return $servOrPackCanBeUsed;
	}

	protected function sendAppointmentBookingSMS($appointmentData,$smsBody,$account)
	{
		$appointment = Appointment::find($appointmentData['appointment_id']);
		if(!empty($smsBody)) {
			$provider_name		= '';
			$clinic_name 		= '';
			$date				= '';
			$time				= '';
			$twilio_response 	= array();

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

			if(count((array)$appointmentData['selService'])>0) {

				$allBookedServices 	= Service :: whereIn('id',$appointmentData['selService'])->pluck('name');

				if(count((array)$allBookedServices)>0){
					$allBookedServices = $allBookedServices->toArray();
					foreach($allBookedServices as $key => $val){
						$services[] = ucfirst($val);
					}
				}

			}

			$provider = Users :: where('id',@$appointmentData['selDoc'])->first();
			if(count((array)$provider) > 0) {

				if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

					$provider_name = ucfirst($provider->bio_name);
				} else {

					$provider_name = ucfirst($provider->firstname." ".$provider->lastname);
				}

			} else {
				$provider_name='';
			}

			$db 			= Session::get('selectedDB');
			$this->switchDatabase($db);

			$clinic 		= Clinic::findOrFail(@$appointmentData['selClinic']);
			if(count((array)$clinic) > 0) {

				$clinic_name = ucfirst($clinic->clinic_name);

			}

			$location 			= array();
			if(!empty($clinic->address)){
				$location[] 		= $clinic->address;
			}
			if(!empty($clinic->city)){
				$location[] 		= !empty($clinic->clinic_city) ? $clinic->clinic_city : $clinic->city;
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

			$selDate 				= changeFormatByPreference($appointmentData['selDate']);
			//$appointment_time 		= date('g:i a',strtotime(@$appointmentData['selTime']));
			$appointment_time 				= changeFormatByPreference(@$appointmentData['selTime'], null, true, true );
			$appointment_date_time 	= date('l',strtotime(@$appointmentData['selDate'])).' '.$selDate.' @ '.$appointment_time;

			$replace 						= array();
			$replace['PROVIDERNAME'] 		= $provider_name;
			$replace['CLINICNAME']			= $clinic_name;
			$replace['APPOINTMENTDATETIME']	= $appointment_date_time;
		//	$replace['PATIENTNAME'] 		= ucfirst($appointmentData['formData']['firstname'] ." ". $appointmentData['formData']['lastname']);
			$replace['PATIENTNAME'] 		= ucfirst($appointmentData['formData']['firstname']);
			$replace['CLINICLOCATION']		= $clinic_location;
			$replace['CLINICINSTRUCTIONS']	= $email_special_instructions;
			$replace['BOOKEDSERVICES']		= implode(', ',$services);
			$replace['CANFEECHARGEDAYS']	= $cancelation_fee_charge_days;
			$replace['CANCELATIONFEES']		= $cancelation_fees;


			$tags								= array();
			$tags['PATIENTNAME'] 				= "{{PATIENTNAME}}";
			$tags['CLINICNAME']					= "{{CLINICNAME}}";
			$tags['APPOINTMENTDATETIME']		= "{{APPOINTMENTDATETIME}}";
			$tags['CLINICLOCATION']				= "{{CLINICLOCATION}}";
			$tags['PROVIDERNAME']				= "{{PROVIDERNAME}}";
			$tags['CLINICINSTRUCTIONS']			= "{{CLINICINSTRUCTIONS}}";
			$tags['BOOKEDSERVICES']				= "{{BOOKEDSERVICES}}";
			$tags['CANFEECHARGEDAYS']			= "{{CANFEECHARGEDAYS}}";
			$tags['CANCELATIONFEES']			= "{{CANCELATIONFEES}}";

			$encoded_account_id = $this->encryptKey($account->id);
			$meeting_id =  $appointment['meeting_id'];
			$appointment_type = $appointment['appointment_type'];
			if($appointment_type == "virtual" && $meeting_id) {
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
			if(!empty($appointmentData['formData']['full_number'])){
				$to = $appointmentData['formData']['full_number'];
			}else{
				$to = $this->getPatientPhoneNumber($account, $appointmentData['patient_id']);
			}

            if (!empty($to)) {
                $sms_response = $this->sendSMS($to, $smsBody, $account);
                if ($account->getKey() !== config('app.juvly_account_id') && $sms_response) {
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

			//~ try {
				//~ $twilio_response = Twilio::message($to, $smsBody);
            //~ } catch (\Exception $e) {
				//~ if($e->getCode() == 21211) {
					//~ $message = $e->getMessage();
				//~ }
            //~ }
			//~
			//~ if (count($twilio_response) > 0 ) {
				//~ if ( $twilio_response->media->client->last_response->error_code != '' ) {
					//~ return false;
				//~ } else {
					//~ return true;
				//~ }
			//~ }
		}
	}

	function saveHashedUser(Request $request)
	{
		$input 		= $request->input();

		if ( isset($input['hashedUserID']) && !empty($input['hashedUserID']) ) {
			$hashids 							= new Hashids('juvly_aesthetics', 30);
			$hashedUserID						= $input['hashedUserID'];
			$userID								= $hashids->decode($hashedUserID);
			$bookAppointMent 					= Session::get('bookAppointMent');
			if ( count((array)$userID) ) {
				$bookAppointMent['appUserID'] 		= $userID[0];
				Session::put('bookAppointMent', $bookAppointMent);
				$json 								= array('data' => 'saved', 'status' => 'success' );
			} else {
				$json 								= array('data' => 'notsaved', 'status' => 'error' );
			}
		} else {
			$json 									= array('data' => 'notsaved', 'status' => 'error' );
		}

		return response()->json($json);
	}

	protected function saveUserLog($db, $appointmentID, $appointmentData)
	{
		//if ( array_key_exists('appUserID', $appointmentData)) {
			$this->switchDatabase($db);

			if ( count((array)$appointmentData) ) {
				$dateTime						= $appointmentData['selDate'] . " " . $appointmentData['selTime'];
				$appointment_datetime			= date('Y-m-d H:i:s', strtotime($dateTime));

				$clinic					=	Clinic :: where('id',$appointmentData['selClinic'])->first();

				if(count((array)$clinic)>0){
					$timezone	=	$clinic->timezone;
				} else {
					$timezone	=	'';
				}

				//~ $clinicTimeZone			= isset($timezone) ? $timezone : 'America/New_York';
				$clinicTimeZone			=  'America/New_York';
				//~ date_default_timezone_set($clinicTimeZone);
				//~ $todayDateTime 			= new DateTime(date('Y-m-d H:i:s'));
				//~ $todayTimeZone 			= new DateTimeZone($clinicTimeZone);
				//~ $todayDateTime->setTimezone($todayTimeZone);
				//~
				//~ $todayInClinicTZ		= $todayDateTime->format('Y-m-d H:i:s');
				$todayInClinicTZ			= $this->convertTZ($clinicTimeZone);

				$userLog						= new UserLog();

				if ( array_key_exists('appUserID', $appointmentData)) {
					$userLog->user_id			= $appointmentData['appUserID'];
				} else {
					$userLog->user_id			= 0;
					$userLog->child				= 'customer';
				}

				$userLog->object				= 'appointment';
				$userLog->object_id				= $appointmentID;
				$userLog->action				= 'booked';

				$userLog->created				= $todayInClinicTZ;
				$userLog->appointment_datetime	= $appointment_datetime;

				$saved 							= $userLog->save();

				if ( $saved ) {
					return $userLog->id;
				} else {
					return 0;
				}
			}
		//}
	}

	public function clearSessionData(Request $request)
	{
		$input 				= $request->input();
		$bookAppointMent	= Session::get('bookAppointMent');
		if ( isset($input) && !empty($input['step']) ) {
			$step 	= $input['step'];
			if ( $step && !empty($step) ) {
				if ( $step == 'clinics' ) {
					if ( array_key_exists('selService', $bookAppointMent)) {
						unset($bookAppointMent['selService']);
					}
					if ( array_key_exists('selDoc', $bookAppointMent)) {
						unset($bookAppointMent['selDoc']);
					}
					if ( array_key_exists('selTimeZone', $bookAppointMent)) {
						unset($bookAppointMent['selTimeZone']);
					}
					if ( array_key_exists('selDate', $bookAppointMent)) {
						unset($bookAppointMent['selDate']);
					}
					if ( array_key_exists('selTime', $bookAppointMent)) {
						unset($bookAppointMent['selTime']);
					}
				} else if ( $step == 'services' ) {
					if ( array_key_exists('selDoc', $bookAppointMent)) {
						unset($bookAppointMent['selDoc']);
					}
					if ( array_key_exists('selTimeZone', $bookAppointMent)) {
						unset($bookAppointMent['selTimeZone']);
					}
					if ( array_key_exists('selDate', $bookAppointMent)) {
						unset($bookAppointMent['selDate']);
					}
					if ( array_key_exists('selTime', $bookAppointMent)) {
						unset($bookAppointMent['selTime']);
					}
				} else if ( $step == 'provider' ) {
					if ( array_key_exists('selTimeZone', $bookAppointMent)) {
						unset($bookAppointMent['selTimeZone']);
					}
					if ( array_key_exists('selDate', $bookAppointMent)) {
						unset($bookAppointMent['selDate']);
					}
					if ( array_key_exists('selTime', $bookAppointMent)) {
						unset($bookAppointMent['selTime']);
					}
				}
				Session::put('bookAppointMent', $bookAppointMent);
			}
		}
		return response()->json(array("status" => "success"));
	}

    protected function checkIfServiceAreNotClubbable($db, $appointmentData)
    {
        $servicesCanBeBooked = '1';
        $serviceClubInfo = array();
        $servicesToCheck = array();
        $servicesCanNotBeBooked = array();
        $this->switchDatabase($db);

        if (count((array)$appointmentData)) {
            if (isset($appointmentData['selService']) && $appointmentData['selService'] > 0) {
                $serviceClubInfo = ServiceNotClubbable::whereIn('service_id', $appointmentData['selService'])->get();
                if (count((array)$serviceClubInfo)) {
                    $serviceClubInfo = $serviceClubInfo->toArray();
                    foreach ($serviceClubInfo as $notClubbableService) {
                        $tempArray = array();
                        $tempArray = $notClubbableService['not_clubbed_service'];
                        array_push($servicesToCheck, $tempArray);
                    }
                }
                $servicesCanNotBeBooked = array_intersect($appointmentData['selService'], $servicesToCheck);

                if (count((array)$servicesCanNotBeBooked)) {
                    $servicesCanBeBooked = '0';
                }
            }
        }
        return $servicesCanBeBooked;
    }

	protected function getPatientCardOnFileData($db, $patientID, $stripeUserID="")
	{
		$patCardInfo = array();
		$cardInfo 	 = array();

		$this->switchDatabase($db);

		$account		= Session::get('account');
		$gatewayType	= $account->pos_gateway;

		if ( $patientID ) {
            if ($gatewayType && $gatewayType == 'stripe') {
                $cardInfo = PatientCardOnFile::where('patient_id', $patientID)->where('stripe_user_id', $stripeUserID)->first();
            } else if ($gatewayType && $gatewayType == 'clearent') {
                $cardInfo = PatientCardOnFile::where('patient_id', $patientID)->where('stripe_user_id', $stripeUserID)->first();
            } else {
                $cardInfo = PatientCardOnFile::where('patient_id', $patientID)->first();
            }
			if ( count((array)$cardInfo) ) {
				$patCardInfo = $cardInfo->toArray();
			}
		}
		return $patCardInfo;
	}

	public function checkIfCreditDetailsCanBeShown(Request $request)
	{
		$input 			= $request->input();

		if ( count((array)$input) ) {
			$httpHost		= $_SERVER['HTTP_HOST'];
			$subDomainArr	= explode('.', $httpHost);
			$subDomain		= $subDomainArr[0];

			$this->getDatabase($subDomain);

			$db				= $this->userDB;
			$params 		= array();
			parse_str($input['formData'], $params['formData']);

			$account		= Session::get('account');
			$gatewayType	= $account->pos_gateway;
			$stripeUserID	= "";
			$bookAppointMent= Session::get('bookAppointMent');

            if ($gatewayType && $gatewayType == 'stripe') {
                $stripeUserID = $this->getAccountStripeConfig($account, $bookAppointMent);

                if (strlen($stripeUserID) == 0) {
                    return response()->json(array("status" => "error", "data" => "'Unable to process: Stripe connection not found"));
                }
            } elseif ($gatewayType && $gatewayType == 'clearent') {
                $stripeUserID = $this->getAccountClearenteConfig($account, $bookAppointMent);

                if (strlen($stripeUserID) == 0) {
                    return response()->json(array("status" => "error", "data" => "'Unable to process: Stripe connection not found"));
                }
            }

			if ( count((array)$params['formData']) && !empty($db) ) {
				if ( !empty($params['formData']['firstname']) && !empty($params['formData']['lastname']) ) {
					$cardData	= array();

					$authPatID		 	= Session::get('patient_id');

					if ( $authPatID > '0' ) {
						$patientID 	= $authPatID;
					} else {
						$patientID 	= $this->checkIfPatientExists($db, $params);
					}

					if ( $patientID > 0 ) {
                        if ($gatewayType && $gatewayType == 'stripe') {
                            $cardData = $this->getPatientCardOnFileData($db, $patientID, $stripeUserID);
                        } elseif ($gatewayType && $gatewayType == 'clearent') {
                            $cardData = $this->getPatientCardOnFileData($db, $patientID, $stripeUserID);
                        } else {
                            $cardData = $this->getPatientCardOnFileData($db, $patientID, "");
                        }
					}

					return response()->json(array("status" => "success", "data" => $cardData, "patient" => $patientID ));
				}
			} else {
				return response()->json(array("status" => "error", "data" => "Some error occured, please try again"));
			}
		} else {
			return response()->json(array("status" => "error", "data" => "No request data"));
		}
	}

	public function checkIfPOSAndFeesEnabled(Request $request)
	{
        $cancellationFees = 0;
        $isPOSEnabled = 0;
        $cancellationFeeStatus = 0;
        $isEnabled = false;
        $account = session('account');
        $isServicePaid = 1;
        $input = $request->input();
        $apptType = $input['appointmentType'];
        $serviceAmount = 0;
        $currency = '$';
        $chargeType	= 'booking';

		if ( count((array)$account) ) {
			$gatewayType		= $account->pos_gateway;
			$connectionType		= $account->stripe_connection;
			$bookAppointMent	= session('bookAppointMent');
			$clinicID			= @$bookAppointMent['selClinic'];
			$accountID			= $account->id;
			$db					= $account->database_name;

			if ( $gatewayType && $gatewayType == 'stripe' ) {
				if ( $connectionType && $connectionType == 'clinic' ) {
					if ( $clinicID ) {
						$accountStripeConfig	= AccountStripeConfig::where('account_id', $accountID)->where('clinic_id', $clinicID)->first();

						if ( count((array)$accountStripeConfig) ) {
							$isEnabled = true;
						}
					}
				} else {
					$accountStripeConfig	= AccountStripeConfig::where('account_id', $accountID)->where('clinic_id', 0)->first();

					if ( count((array)$accountStripeConfig) ) {
						$isEnabled = true;
					}
				}
            } else if ($gatewayType && $gatewayType == 'clearent') {
                if ($connectionType && $connectionType == 'clinic') {
                    if ($clinicID) {
                        $accountStripeConfig = AccountClearentConfig::whereHas('clearent_setup', function ($q) {
                            $q->where('status', 'completed');
                        })->with(['clearent_setup' => function ($q) {
                            $q->where('status', 'completed');
                        }])->where('account_id', $accountID)->where('clinic_id', $clinicID)->first();

                        if (count((array)$accountStripeConfig)) {
                            $isEnabled = true;
                        }
                    }
                } else {
                    $accountStripeConfig = AccountClearentConfig::whereHas('clearent_setup', function ($q) {
                        $q->where('status', 'completed');
                    })->with(['clearent_setup' => function ($q) {
                        $q->where('status', 'completed');
                    }])->where('account_id', $accountID)->where('clinic_id', 0)->first();

                    if (count((array)$accountStripeConfig)) {
                        $isEnabled = true;
                    }
                }
            } else {
				$isEnabled = true;
			}

			if ( $isEnabled ) {
				$accountPrefData	= AccountPrefrence::where('account_id', $account->id)->first();

				if ( count((array)$accountPrefData) > 0 ) {
					$cancellationFeeStatus	= $accountPrefData['cancelation_policy_status'];
					$cancellationFees		= $account->cancellation_fees;
					$isPOSEnabled			= $account->pos_enabled;
				}
			}
			$country = DB::table('stripe_countries')->where('currency_code', $account->stripe_currency)->first();
			if($country && $country->currency_symbol){
				$currency = $country->currency_symbol;
			}
			$isServicePaid = $this->checkServicePaid($db, $bookAppointMent, $apptType);

			if ($apptType && $apptType == 'virtual') {
				$serviceAmount = $this->getServiceAmount($db, $bookAppointMent);

				$serviceAmount = $serviceAmount->price;
			}

			$serviceChargeType 	= $this->getServiceChargeType($db, $bookAppointMent);

			if ($apptType && $apptType == 'virtual') {
				$chargeType = $serviceChargeType->service_charge_type;
			}
		}

		return response()->json(array("status" => "success", "isPOSEnabled" => $isPOSEnabled, "cancellationFees" => $cancellationFees, 'cancellationFeeStatus' => $cancellationFeeStatus, 'isServicePaid' =>$isServicePaid, 'serviceAmount' => $serviceAmount, 'currency' => $currency, 'serviceChargeType' => $chargeType ));
	}

	function authorizeCardUsingStripe($account, $input, $patientID, $type="new", $appointmentType, $isFreeVirtualService)
	{
		$responseArray 			= array("status" => "error", "data" => array(), "message" => "Unable to connect with payment gateway, please try again");
		$hostTransactionData 	= array();

		if ( count((array)$account) > 0 ) {
			$cancellation_fees		= 0;
			$dbname					= $account->database_name;
			$cancelation_fee 		= $account->cancellation_fees;
			$stripeUserID			= $this->getAccountStripeConfig($account, $input);
			$input					= $input['formData'];
			$bookAppointMent		= Session::get('bookAppointMent');

			if ($appointmentType && $appointmentType == 'virtual' && $isFreeVirtualService == "false") {
				$serviceAmount 		= $this->getServiceAmount($dbname, $bookAppointMent);

				$serviceAmount 		= $serviceAmount->price;
			} else {
				$serviceAmount 		= 0;
			}

			if ( strlen($stripeUserID) > 0 ) {
				if ( !empty($input['card_number']) && !empty($input['expiry_month']) && !empty($input['expiry_year']) && !empty($input['cvv']) && !empty($input['stripeToken']) && $type == "new" ) {

					$createCustomerArr	= array(
					  "email" 			=> $input['email'],
					  "source" 			=> $input['stripeToken']
					);

					$createStripeCustomerResponse = callStripe('customers', $createCustomerArr);

					if ( count((array)$createStripeCustomerResponse) ) {
						if ( isset($createStripeCustomerResponse->id) && !empty($createStripeCustomerResponse->id) ) {
							$customerTokenID	= $createStripeCustomerResponse->id;
							$accountName			= !empty($account->name) ? $account->name : 'Aesthetic Record';
							$accountName			= substr(strtoupper(str_replace(' ', '', $accountName)), 0, 20);
							$accountName			= $this->cleanString($accountName);
							$accountName			= preg_replace('/[^A-Za-z0-9\-]/', '', $accountName);

							$accountCurrency		= $account->stripe_currency;
							$currencyMinAmnt		= $this->stripeMinimumAmount($accountCurrency);

							if ($appointmentType && $appointmentType == 'in_person') {
								//~ $chargeArr	= array(
									//~ "amount" 	  			=> $currencyMinAmnt,
									//~ "capture"				=> 'false',
									//~ "customer" 	  			=> $customerTokenID,
									//~ "currency"	  			=> $accountCurrency,
									//~ "statement_descriptor"	=> strtoupper($accountName),
									//~ "description" 			=> $input['email'] . ' booked an appointment',
									//~ "destination" 			=> array(
										//~ "account" => $stripeUserID,
									//~ )
								//~ );

								$chargeArr	= array(
									"amount" 	  					=> $currencyMinAmnt,
									"capture"						=> 'false',
									"customer" 	  					=> $customerTokenID,
									"currency"	  					=> $accountCurrency,
									//"statement_descriptor"			=> strtoupper($accountName),
									"statement_descriptor_suffix"	=> strtoupper($accountName),
									"description" 					=> $input['email'] . ' booked an appointment',
									//"application_fee_amount" 		=> round($platformFee, 2) * 100,
									"on_behalf_of" 					=> $stripeUserID,
									"transfer_data"					=> array(
										"destination" => $stripeUserID
									)
								);
							} else if ($appointmentType && $appointmentType == 'virtual' && $isFreeVirtualService == "true") {
								//~ $chargeArr	= array(
									//~ "amount" 	  			=> $currencyMinAmnt,
									//~ "capture"				=> 'false',
									//~ "customer" 	  			=> $customerTokenID,
									//~ "currency"	  			=> $accountCurrency,
									//~ "statement_descriptor"	=> strtoupper($accountName),
									//~ "description" 			=> $input['email'] . ' booked a free virtual appointment',
									//~ "destination" 			=> array(
										//~ "account" => $stripeUserID,
									//~ )
								//~ );

								$chargeArr	= array(
									"amount" 	  					=> $currencyMinAmnt,
									"capture"						=> 'false',
									"customer" 	  					=> $customerTokenID,
									"currency"	  					=> $accountCurrency,
									//"statement_descriptor"			=> strtoupper($accountName),
									"statement_descriptor_suffix"	=> strtoupper($accountName),
									"description" 					=> $input['email'] . ' booked a free virtual appointment',
									//"application_fee_amount" 		=> round($platformFee, 2) * 100,
									"on_behalf_of" 					=> $stripeUserID,
									"transfer_data"					=> array(
										"destination" => $stripeUserID
									)
								);
							} else if ($appointmentType && $appointmentType == 'virtual' && $isFreeVirtualService == "false") {
								$amnt						= (float) $serviceAmount;
								$finalAmnt					= $amnt * 100;

								//~ $chargeArr	= array(
									//~ "amount" 	  			=> $finalAmnt,
									//~ "capture"				=> 'true',
									//~ "customer" 	  			=> $customerTokenID,
									//~ "currency"	  			=> $accountCurrency,
									//~ "statement_descriptor"	=> strtoupper($accountName),
									//~ "description" 			=> $input['email'] . ' booked a virtual appointment',
									//~ "destination" 			=> array(
										//~ "account" => $stripeUserID,
									//~ )
								//~ );

								$chargeArr	= array(
									"amount" 	  					=> $finalAmnt,
									"capture"						=> 'true',
									"customer" 	  					=> $customerTokenID,
									"currency"	  					=> $accountCurrency,
									//"statement_descriptor"			=> strtoupper($accountName),
									"statement_descriptor_suffix"	=> strtoupper($accountName),
									"description" 					=> $input['email'] . ' booked a virtual appointment',
									//"application_fee_amount" 		=> round($platformFee, 2) * 100,
									"on_behalf_of" 					=> $stripeUserID,
									"transfer_data"					=> array(
										"destination" => $stripeUserID
									)
								);
							}

							$chargeCustomerResponse 	= callStripe('charges', $chargeArr);

							if ( count((array)$chargeCustomerResponse) && is_object($chargeCustomerResponse) ) {

								$responseArray["status"] 	= "success";
								$responseArray["data"] 		= $chargeCustomerResponse;
								$responseArray["message"] 	= "Card authorized successfully";
							} else {
								$responseArray["message"] 	= "An error occured - " . $chargeCustomerResponse;
							}
						} else {
							$responseArray["message"] 	= "An error occured - " . $createStripeCustomerResponse;
						}
					} else {
						$responseArray["message"] 		= "We are unable to authorize your card, please try again";
					}
				} else {
					if ( $type == "saved" ) {
						if ( $patientID ) {
							$patient_on_file 		= array();
							$patient_on_file 		= PatientCardOnFile::where('patient_id',$patientID)->first();

							if ( count((array)$patient_on_file) ) {
								/// TO DO IN FUTURE
							}
						}
					}
				}
			} else {
				$responseArray 			= array("status" => "error", "data" => array(), "message" => "Unable to process: Stripe connection not found");
			}
		}
		return $responseArray;
	}

	protected function createNewPatientAppointmentTransaction($db, $appointmentID, $authorizeCardData, $account, $cardType, $stripeUserID="")
	{
		$cancellation_fees 		= 0;
		$currentTime	= date('Y-m-d H:i:s');
		$currentTime	= $this->getCurrentTimeNewYork($currentTime);
		if ( count((array)$account) ) {
			$cancellation_fees 	= $account->cancellation_fees;
			$gatewayType		= $account->pos_gateway;

			$this->switchDatabase($db);

			$transaction							= new AppointmentCancellationTransaction();
			$transaction->appointment_id			= $appointmentID;
			$transaction->status					= 'authorised';
			if ( $cardType == "new" ) {
                if ($gatewayType && $gatewayType == 'stripe') {
                    $transaction->authorize_transaction_id = $authorizeCardData->id;
                    $transaction->stripe_user_id = $stripeUserID;
                } elseif ($gatewayType && $gatewayType == 'clearent') {
                    if (!empty($authorizeCardData["payload"]["tokenResponse"]) && isset($authorizeCardData["payload"]["tokenResponse"])) {
                        $transaction->authorize_transaction_id = $authorizeCardData["payload"]["tokenResponse"]["token-id"];
                    } else if (!empty($authorizeCardData["payload"]["transaction"]) && isset($authorizeCardData["payload"]["transaction"])) {
                        $transaction->authorize_transaction_id = $authorizeCardData["payload"]["transaction"]["id"];
                    }
                    $transaction->stripe_user_id = $stripeUserID;
                } else {
                    $transaction->authorize_transaction_id = $authorizeCardData->TransactionResultData->HostTransactionID;
                }
			} else {
				$transaction->authorize_transaction_id	= '1111111111';

                if ($gatewayType && $gatewayType == 'stripe') {
                    $transaction->stripe_user_id = $stripeUserID;
                } elseif ($gatewayType && $gatewayType == 'clearent') {
                    $transaction->stripe_user_id = $stripeUserID;
                }
			}
			$transaction->cancellation_fee			= $cancellation_fees;
			$transaction->created					= $currentTime;
			$transaction->modified					= $currentTime;
			$saved 									= $transaction->save();

			if ( $saved ) {
				return $transaction->id;
			} else {
				return 0;
			}
		} else {
			return 0;
		}
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

	public function checkCancelationPolicyStatus(Request $request)
	{
		$account  				= Session::get('account');
		$accountPrefData		= AccountPrefrence::where('account_id', $account->id)->first();
		$cancellationFeeStatus	= 0;
		$cancellationFees		= 0;
		$posStatus				= 0;
		$staus					= "error";

		if ( count((array)$account) > 0 && count((array)$accountPrefData) > 0 ) {
			$cancellationFees	= $account->cancellation_fees;
			$posStatus			= $account->pos_enabled;
			$cancellationFeeStatus	= $accountPrefData['cancelation_policy_status'];

			if ( $cancellationFeeStatus == '1' && $cancellationFees > 0 && $posStatus > 0 ) {
				$staus				= "success";
			}
		}

		return response()->json(array("status" => $staus, "cancellationFeeStatus" => $cancellationFeeStatus ));
	}

	public function saveAppointmentReminderLogs($appointment_id =null, $combinedDT=null) {
		$appointment_reminders_config = AppointmentReminderConfiguration::get();
		if($combinedDT){
			if(count((array)$appointment_reminders_config) > 0) {
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

	public function getPatientPhoneNumber($account, $patient_id) {
		config(['database.connections.juvly_practice.database'=> $account->database_name]);
		if(Patient::where('id',$patient_id)->exists() ){
			$patient = Patient::find($patient_id);
			return $patient->phoneNumber;
		}else{
			return 0;
		}
	}

    public function getClinicTimeZone(Request $request, $clinic = null)
    {
        $hashids = new Hashids('juvly_aesthetics', 30);
        $clinic_id = $hashids->decode($clinic);

        $httpHost = $_SERVER['HTTP_HOST'];
        $subDomainArr = explode('.', $httpHost);
        $subDomain = $subDomainArr[0];

        $this->getDatabase($subDomain);
        $db = $this->userDB;
        $this->switchDatabase($db);
        if (Clinic::where('id', $clinic_id[0])->exists()) {
            $clinic = Clinic::find($clinic_id[0]);
            return array('timezone' => $clinic->timezone);
        }
        return array('timezone' => '');
    }

    public function pickTimeZone(Request $request)
    {
        $timezone = $request->input('timezone_id');
        $response['status'] = 0;
        $response['timezone'] = '';

        if (TimeZone::where('php_timezone', $timezone)->exists()) {
            $timezone_data = TimeZone::where('php_timezone', $timezone)->first();
            if (!empty($timezone_data->timezone)) {
                $response['status'] = 1;
                $response['timezone'] = $timezone_data->timezone;
            }
        }
        return $response;
    }

	public function sendPreInstrucionMail($appointmentData, $mailBody,$mailSubject,$account)
	{
		$database_name 	= Session::get('selectedDB');

		$accountPrefData				= AccountPrefrence::where('account_id', $account->id)->first();

		$this->switchDatabase($database_name);
		$clinic 						= Clinic::findOrFail(@$appointmentData['selClinic']);
		$sender	 						= $this->getSenderEmail();
		//$subject 						= "Pre Instructions Email";

		$location 			= array();
		if(!empty($clinic->address)){
			$location[] 		= $clinic->address;
		}
		if(!empty($clinic->city)){
			$location[] 		= !empty($clinic->clinic_city) ? $clinic->clinic_city : $clinic->city;
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

		$selDate 						= changeFormatByPreference($appointmentData['selDate']);
		//$appointment_time 				= date('g:i a',strtotime($appointmentData['selTime']));
		$appointment_time 				= changeFormatByPreference(@$appointmentData['selTime'], null, true, true );
		$appointment_date_time 			= date('l',strtotime($selDate)).' '.$selDate.' @ '.$appointment_time;

		$provider = Users :: where('id',@$appointmentData['selDoc'])->first();

		if(count((array)$provider) > 0) {

			if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

				$provider_name = ucfirst($provider->bio_name);
			} else {

				$provider_name = ucfirst($provider->firstname)." ".ucfirst($provider->lastname);
			}

		} else {
			$provider_name='';
		}

		$services = array();

		if(count((array)$appointmentData['selService'])>0) {

			$allBookedServices 	= Service :: whereIn('id',$appointmentData['selService'])->pluck('name');
			$allBookedServices = $allBookedServices->toArray();
			if(count((array)$allBookedServices)>0){
				foreach($allBookedServices as $key => $val){
					$services[] = ucfirst($val);
				}
			}

		}
		$appointment_header['APPOINTMENTDATE'] 	= $selDate;
		$appointment_header['APPOINTMENTTIME'] 	= $appointment_time;
		$appointment_header['PROVIDERNAME'] 	= $provider_name;
		$appointment_header['BOOKEDSERVICES'] 	= implode(', ',$services);
		$appointment_header['CLINICNAME'] 	= $clinic->clinic_name;

			$pre_instruction_logs = PrepostInstructionsLog::
			with(array('Service'=>function($service){
				$service->select('id','name');
			}))
			->with('servicesTreatmentInstructions.pre_treatment_instructions')
			->where('appointment_id',$appointmentData['appointment_id'])->where('type','pre')->get();
		$client_name = $this->getUserUsedClientName($account->id);
		if(!empty($pre_instruction_logs)){
			$pre_instruction_logs = $pre_instruction_logs->toArray();

			foreach($pre_instruction_logs as $pre_instruction){
				$replace 							= array();
				$replace['PATIENTNAME'] 			= ucfirst($appointmentData['formData']['firstname']);
				$replace['CLINICNAME']				= ucfirst($clinic->clinic_name);
				$replace['CLINICLOCATION']			= $clinic_location;
				$replace['CLINICINSTRUCTIONS']		= $email_special_instructions;
				$replace['APPOINTMENTDATETIME']		= $appointment_date_time;
				$replace['PROVIDERNAME']			= $provider_name;
				$replace['INSTRUCTIONSSERVICE']		= ucfirst($pre_instruction['service']['name']);
				$replace['INSTRUCTIONSTITLE']		= $pre_instruction['services_treatment_instructions']['pre_treatment_instructions']['title'];
				$replace['INSTRUCTIONSDESCRIPTION']	= $pre_instruction['services_treatment_instructions']['pre_treatment_instructions']['description'];

				$tags								= array();
				$tags['PATIENTNAME'] 				= "{{PATIENTNAME}}";
				$tags['CLINICNAME']					= "{{CLINICNAME}}";
				$tags['APPOINTMENTDATETIME']		= "{{APPOINTMENTDATETIME}}";
				$tags['CLINICLOCATION']				= "{{CLINICLOCATION}}";
				$tags['CLINICINSTRUCTIONS']			= "{{CLINICINSTRUCTIONS}}";
				$tags['PROVIDERNAME']				= "{{PROVIDERNAME}}";
				$tags['INSTRUCTIONSSERVICE']		= "{{INSTRUCTIONSSERVICE}}";
				$tags['INSTRUCTIONSTITLE']			= "{{INSTRUCTIONSTITLE}}";
				$tags['INSTRUCTIONSDESCRIPTION']	= "{{INSTRUCTIONSDESCRIPTION}}";

				$body  		= $mailBody;
				$subject  	= $mailSubject;
				foreach ( $tags as $key => $val ) {
					if ( $val ) {
						 $body  = str_replace($val, $replace[$key], $body);
						 $subject  = str_replace($val, $replace[$key], $subject);
					}
				}
				$label_text_small 	= strtolower($body);
				if(strpos($label_text_small, 'client') !== false || strpos($label_text_small, 'patient') !== false){
					$body = str_ireplace('client', ucfirst($client_name), $body);
					$body = str_ireplace('patient', ucfirst($client_name), $body);
				}
				$email_content = $this->getAppointmentEmailTemplate($body,$account,$clinic,$subject,$appointment_header, "false");
				$noReply = config('mail.from.address');

                $response_data =  EmailHelper::sendEmail($noReply, trim($appointmentData['formData']['email']), $sender, $email_content, $subject);

                if ($account->getKey() !== config('app.juvly_account_id')) {
                    if ($response_data) {
                        if (!$this->checkEmailLimit() && $this->checkEmailAutofill()) {
                            $this->updateUnbilledEmail();
                        } else {
                            $this->saveEmailCount();
                        }
                    }
                }
				//~ if ( $response_data->statusCode() == 202 ) {
					//~ return true;
				//~ } else {
					//~ return false;
				//~ }

			}


		}
	}

	private function createPatientWallet($db, $pateint_id){
		$this->switchDatabase($db);
		$patient_wallet = new PatientWallet;
		$patient_wallet->patient_id = $pateint_id;
		$patient_wallet->balance = 0;
		$patient_wallet->membership_fee = 0;
		$patient_wallet->save();
	}

	function authorizeCardUsingApriva($account, $input, $patientID, $type="new")
	{
		$responseArray 			= array("status" => "error", "data" => array(), "message" => "Unable to connect with payment gateway, please try again");
		$hostTransactionData 	= array();

		if ( count((array)$account) > 0 ) {
			$cancellation_fees	= 0;

			$dbname				= $account->database_name;
			$storagefolder 		= $account->storage_folder;
			$aprivaProductId 	= $account->pos_product_id;
			$aprivaClientId		= $account->pos_client_id;
			$aprivaClientSecret	= $account->pos_secret;
			$aprivaPlatformKey	= $account->pos_platform_key;
			$aprivaPosEnabled	= $account->pos_enabled;
			$access_token 		= connect($aprivaProductId, $aprivaClientId, $aprivaClientSecret, $aprivaPlatformKey);
			$uniqueIdentifier   = $this->generateRandomString(6)."-".$this->generateRandomString(5)."-".$this->generateRandomString(4);
			$cancelation_fee 	= $account->cancellation_fees;

			//~ $accountPrefData	= AccountPrefrence::where('account_id', $account->id)->first();
			//~
			//~ if ( count($accountPrefData) > 0 ) {
				//~ $upfront_consultation_fees	= $accountPrefData['upfront_consultation_fees'];
			//~ }

			//~ if ( $cancelation_fee > 0 && $upfront_consultation_fees > 0 ) {
				//~ $cancellation_fees 	= $upfront_consultation_fees;
			//~ } else if ( $cancelation_fee > 0 && $upfront_consultation_fees == 0 ) {
				//~ $cancellation_fees 	= $cancelation_fee;
			//~ } else if ( $cancelation_fee == 0 && $upfront_consultation_fees > 0 ) {
				//~ $cancellation_fees 	= $upfront_consultation_fees;
			//~ } else {
				//~ $cancellation_fees	= 0.01;
			//~ }

			if ( $cancelation_fee > 0 ) {
				$cancellation_fees 	= $cancelation_fee;
			} else {
				$cancellation_fees	= 0.01;
			}


			if ( !empty($access_token) ) {
				if ( !empty($input['card_number']) && !empty($input['expiry_month']) && !empty($input['expiry_year']) && !empty($input['cvv']) && $type == "new" ) {
					$hostTransactionData = credit_authorization($access_token, $aprivaPlatformKey, $uniqueIdentifier, $input, '0.01');

					if ( count((array)$hostTransactionData) ) {
						if ( isset($hostTransactionData->Result->ResponseCode) &&  $hostTransactionData->Result->ResponseCode == 0 ) {
							$responseArray["status"] 	= "success";
							$responseArray["data"] 		= $hostTransactionData;
							$responseArray["message"] 	= "Card authorized successfully";
						} else {
							$responseArray["message"] 	= "An error occured - ".$hostTransactionData->Result->ResponseText;
						}
					} else {
						$responseArray["message"] 		= "We are unable to authorize your card, please try again";
					}
				} else {
					if ( $type == "saved" ) {
						if ( $patientID ) {
							$patient_on_file 		= array();
							$patient_on_file 		= PatientCardOnFile::where('patient_id',$patientID)->first();

							if ( count((array)$patient_on_file) ) {
								$cardToken				= $patient_on_file->card_on_file;
								$hostTransactionData 	= credit_authorization_saved_card($access_token, $aprivaPlatformKey, $uniqueIdentifier, $cardToken, '0.01');

								if ( count((array)$hostTransactionData) ) {
									if ( isset($hostTransactionData->Result->ResponseCode) &&  $hostTransactionData->Result->ResponseCode == 0 ) {
										$responseArray["status"] 	= "success";
										$responseArray["data"] 		= $hostTransactionData;
										$responseArray["message"] 	= "Card authorized successfully";
									} else {
										$responseArray["message"] 	= "An error occured - ".$hostTransactionData->Result->ResponseText;
									}
								} else {
									$responseArray["message"] 		= "We are unable to authorize your card, please try again";
								}
							}
						}
					}
				}
				disconnect($access_token);
			}
		}
		return $responseArray;
	}

	protected function getAccountSettings($account, $input, $patient_id)
	{
		if ( count((array)$account) > 0 ) {
			$dbname				= $account->database_name;
			$storagefolder 		= $account->storage_folder;
			$aprivaProductId 	= $account->pos_product_id;
			$aprivaClientId		= $account->pos_client_id;
			$aprivaClientSecret	= $account->pos_secret;
			$aprivaPlatformKey	= $account->pos_platform_key;
			$aprivaPosEnabled	= $account->pos_enabled;
			$access_token 		= connect($aprivaProductId, $aprivaClientId, $aprivaClientSecret, $aprivaPlatformKey);

			if ( !empty($access_token) ) {
				if ( !empty($input['card_number']) ) {
					$response = add_card_on_file($access_token, $aprivaPlatformKey, $input);

					if ( $response ) {
						$this->switchDatabase($dbname);
						$patient_on_file = array();
						$patient_on_file = 	PatientCardOnFile::where('patient_id',$patient_id)->first();
						$card_number = substr(trim($input['card_number']), 0, 4)."*****".substr(trim($input['card_number']), -4);

						if (count((array)$patient_on_file) == 0) {
							$PatientCardOnFile = new PatientCardOnFile;
							$PatientCardOnFile->patient_id  	= $patient_id;
							$PatientCardOnFile->card_on_file    = $response->CardOnFileToken;
							$PatientCardOnFile->status    		= 0;
							$PatientCardOnFile->card_number    	= $card_number;
							if ($PatientCardOnFile->save()) {
								return true;
							} else {
								return false;
							}

						} else {
							if ( $response->CardOnFileToken != $patient_on_file->card_on_file ) {
								$deletedFromApriva = delete_card_on_file($access_token, $patient_on_file->card_on_file, $aprivaPlatformKey);

								if ( $deletedFromApriva ) {
									$update_arr	= array(
										'card_on_file'	=> $response->CardOnFileToken,
										'card_number'	=> $card_number
									);

									$status    = PatientCardOnFile::where('id', $patient_on_file->id)->update($update_arr);

									if ( $status ) {
										return true;
									} else {
										return false;
									}
								} else {
									return false;
								}
							} else {
								return true;
							}
						}

					} else {
						return false;
					}
					disconnect($access_token);
				} else {
					return false;
				}
			} else {
				return false;
			}
		} else {
			return false;
		}
	}

	private function sortByDisplayOrder($a, $b) {
		$a = $a['display_order'];
		$b = $b['display_order'];

		if ($a == $b) return 0;
		return ($a < $b) ? -1 : 1;
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

	private function getAccountStripeConfig($account, $input)
	{
		$stripeUserID = '';

		if ( count((array)$account) > 0 ) {
			$stripeConnectionType	= $account->stripe_connection;

			if ( $stripeConnectionType == 'global' ) {
				$clinicID 			= 0;
			} else {
				$clinicID 			= $input['selClinic'];
			}

			$accountStripeConfig	= AccountStripeConfig::where('account_id', $account->id)->where('clinic_id', $clinicID)->first();

			if ( count((array)$accountStripeConfig) > 0 ) {
				$stripeUserID		= $accountStripeConfig->stripe_user_id;
			}
		}

		return $stripeUserID;
	}

	protected function canUseStripe()
	{
		$account				= array();
		$return 				= 0;
		$isEnabled				= false;
		$account				= Session::get('account');

		if ( count((array)$account) ) {
			$gatewayType		= $account->pos_gateway;
			$connectionType		= $account->stripe_connection;
			$bookAppointMent	= Session::get('bookAppointMent');
			$clinicID			= @$bookAppointMent['selClinic'];
			$accountID			= $account->id;

			if ( $gatewayType && $gatewayType == 'stripe' ) {
				if ( $connectionType && $connectionType == 'clinic' ) {
					if ( $clinicID ) {
						$accountStripeConfig	= AccountStripeConfig::where('account_id', $accountID)->where('clinic_id', $clinicID)->first();

						if ( count((array)$accountStripeConfig) ) {
							$isEnabled = true;
						}
					}
				} else {
					$accountStripeConfig	= AccountStripeConfig::where('account_id', $accountID)->where('clinic_id', 0)->first();

					if ( count((array)$accountStripeConfig) ) {
						$isEnabled = true;
					}
				}
            } elseif ($gatewayType && $gatewayType == 'clearent') {
                if ($connectionType && $connectionType == 'clinic') {
                    if ($clinicID) {
                        $accountClearentConfig = AccountClearentConfig::where('account_id', $accountID)->where('clinic_id', $clinicID)->first();

                        if (count((array)$accountClearentConfig)) {
                            $isEnabled = true;
                        }
                    }
                } else {
                    $accountClearentConfig = AccountClearentConfig::where('account_id', $accountID)->where('clinic_id', 0)->first();

                    if (count((array)$accountClearentConfig)) {
                        $isEnabled = true;
                    }
                }
            } else {
				$isEnabled = true;
			}

			if ( $isEnabled ) {
				$return = 1;
			}
		}

		return $return;
	}

	public function enablePatientPortalAccessAndSendMail($appointmentData, $patientID)
	{
		if ($patientID) {
			$httpHost						= $_SERVER['HTTP_HOST'];
			$subDomainArr					= explode('.', $httpHost);
			$subDomain						= $subDomainArr[0];
			$accountID						= $this->getAccountID($subDomain);
			$accountPrefData				= AccountPrefrence::where('account_id', $accountID)->first();
			$account 						= Account::where('id', $accountID)->where('status','!=', 'inactive')->first();
			$database_name 					= $account->database_name;
			$buisnessName 					= !empty($account->name) ? ucfirst(trim($account->name)) : "Aesthetic Record";

			$dbSubject						= trim($accountPrefData->clinic_patient_portal_access_subject);
			$dbBody							= trim($accountPrefData->clinic_patient_portal_access_body);
			$template_used 					= false;

			$this->switchDatabase($database_name);

			$update_arr	= ['access_portal' => 1];

			$status    = Patient::where('id', $patientID)->update($update_arr);

			$clinic 						= Clinic::findOrFail(@$appointmentData['selClinic']);
			$sender	 						= $this->getSenderEmail();
			$subject 						= !empty($dbSubject) ? $dbSubject : "Welcome to {{BUSINESSNAME}}";
			$subject						= str_replace("{{BUSINESSNAME}}", $buisnessName, $subject);

			$body_content					= Config::get('app.mail_body');
			$mail_body						= !empty($dbBody) ? $dbBody : $body_content['SEND_PATIENT_REGISTER_LINK'];
			$mail_body						= str_replace("{{BUSINESSNAME}}", $buisnessName, $mail_body);
			$mail_body						= str_replace("{{NAME}}", ucfirst($appointmentData['formData']['firstname']), $mail_body);

			$secret_key 					= "juvly12345";
			$hashids         				= new Hashids($secret_key, 30);

			$pportalSubdomain 				= $account->pportal_subdomain;

			$p_encoded    					= $hashids->encode($patientID);
			$u_encoded    					= $hashids->encode($account->id);
			$encoded 						= $u_encoded.':'.$p_encoded;


            $hostName = config('constants.domain.hostname');
            $domainName = config('constants.domain.domain');

			if (strstr($mail_body,'href="{{CLIENTPATIENTURL}}"')) {
				$template_used = true;
			}

			$link 							= $hostName.@$pportalSubdomain.'.'.$domainName.'.com/register?key='.$encoded;
			$mail_body						= str_replace("{{CLIENTPATIENTURL}}", $link, $mail_body);

			$email_content 	= $this->getWelcomeEmailTemplate($mail_body,$account,$clinic,$subject, $template_used);
			$noReply = config('mail.from.address');

            EmailHelper::sendEmail($noReply, trim($appointmentData['formData']['email']), $sender, $email_content, $subject);
		}

		return true;
	}

	private function checkServicePaid($db, $bookAppointMent, $apptType = 'in_person'){
		$this->switchDatabase($db);
		$selectedServices = Service::whereIn('id',$bookAppointMent['selService'])->get();
		$servicePaid = 0;
		if(count((array)$selectedServices)){
			foreach($selectedServices as $service){
				if($apptType == 'in_person'){
					if($service->is_service_free == 0){
						/* is_service_free == 0 means service is paid */
						$servicePaid = 1;
					}
				}else{
					if($service->free_consultation == 0 && $service->price >= '0.5'){
						/* free_consultation == 0 means service is paid */
						$servicePaid = 1;
					}
				}
			}
		}
		return $servicePaid;
	}

	protected function sendClinicBookingSMS($appointmentData,$account)
	{
		$provider_name		= '';
		$clinic_name 		= '';
		$date				= '';
		$time				= '';
		$twilio_response 	= array();
		$services 			= array();

		$cancelation_fee_charge_days	= 0;
		$cancelation_fees				= 0;

		$accountPrefData				= AccountPrefrence::where('account_id', $account->id)->first();
		$cancelation_fees				= '$'.number_format(@$account->cancellation_fees, 2);

		$db 			= Session::get('selectedDB');
		$this->switchDatabase($db);

		if(count((array)$appointmentData['selService'])>0) {

			$allBookedServices 	= Service :: whereIn('id',$appointmentData['selService'])->pluck('name');

			if(count((array)$allBookedServices)>0){
				$allBookedServices = $allBookedServices->toArray();
				foreach($allBookedServices as $key => $val){
					$services[] = ucfirst($val);
				}
			}

		}

		$provider = Users :: where('id',@$appointmentData['selDoc'])->first();
		if(count((array)$provider) > 0) {

			if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

				$provider_name = ucfirst($provider->bio_name);
			} else {

				$provider_name = ucfirst($provider->firstname." ".$provider->lastname);
			}

		} else {
			$provider_name='';
		}

		$clinic 		= Clinic::findOrFail(@$appointmentData['selClinic']);
		if(count((array)$clinic) > 0) {

			$clinic_name = ucfirst($clinic->clinic_name);

		}

		$location 			= array();
		if(!empty($clinic->address)){
			$location[] 		= $clinic->address;
		}
		if(!empty($clinic->city)){
			$location[] 		= !empty($clinic->clinic_city) ? $clinic->clinic_city : $clinic->city;
		}
		if(count((array)$location)>0) {
			$clinic_location = implode(",",$location);
		} else {
			$clinic_location = '';
		}
		$client_name = $this->getUserUsedClientName($account->id);
		$selDate 						= changeFormatByPreference($appointmentData['selDate']);
		//$appointment_time 				= date('g:i a',strtotime(@$appointmentData['selTime']));
		$appointment_time 				= changeFormatByPreference(@$appointmentData['selTime'], null, true, true );
		$appointment_date_time 			= date('l',strtotime(@$appointmentData['selDate'])).' '.$selDate.' @ '.$appointment_time;
		$smsBody						= "New appointment booked by customer using ".ucfirst($client_name)." Portal" . "\n";
		$smsBody						.= ucfirst($client_name)." : " . ucfirst($appointmentData['formData']['firstname']) . "\n";
		$smsBody						.= "Provider : " . $provider_name . "\n";
		$smsBody						.= "Clinic : " . $clinic_name . "\n";
		$smsBody						.= "Location : " . $clinic_location . "\n";
		$smsBody						.= "Appt Date Time : " . $appointment_date_time . "\n";
		$smsBody						.= "Services : " . implode(', ',$services) . "\n";

		$to 							= $clinic->sms_notifications_phone;

        if ( !empty($to) ) {
            $sms_response = $this->sendSMS($to, $smsBody, $account);
            if($account->getKey() !== config('app.juvly_account_id') && $sms_response){
                if( !$this->checkSmsLimit() && $this->checkSmsAutofill()){
                    $this->updateUnbilledSms();
                }else{
                    $this->saveSmsCount();
                }
                return true;
            }else{
                return true;
            }
        } else {
            return true;
        }
	}

	public function saveGClick(Request $request)
	{
		//~ $httpHost					= $_SERVER['HTTP_HOST'];
		//~ $subDomainArr				= explode('.', $httpHost);
		//~ $subDomain					= $subDomainArr[0];

		//~ $accountID					= $this->getAccountID($subDomain);

		$input	= $request->input();

		if ( !empty($input['uri']) && !empty($input['client_ip']) ) {
			$uri 			= @$input['uri'];
			$ip 			= @$input['client_ip'];
			$campaign		= @$_POST['utm_campaign'];
			$source			= @$_POST['utm_source'];
			$clickID		= @$_POST['gclid'];
			$accountID		= @$_POST['account_id'];
			$db				= $this->userDB;

			if ( empty($campaign) ) {
				$source 	= "direct";

				if ( $accountID && $accountID == 1650 ) {
					$campaign 	= "juvly.com";
				} else {
					$campaign 	= "contourclinic.com";
				}
			}

			$this->switchDatabase('juvly_idoctorz_juvly');

			$clickData						= GoogleAdwordsRoi::where('click_id', $clickID)->first();

			if ( empty($clickData) ) {
				$adwords 					= new GoogleAdwordsRoi();
				$adwords->account_id		= $accountID;
				$adwords->uri				= $uri;
				$adwords->campaign			= $campaign;
				$adwords->source			= $source;
				$adwords->click_id			= $clickID;
				$adwords->client_ip			= $ip;
				$adwords->appointment_id	= 0;
				$adwords->invoice_id		= 0;
				$adwords->total_amount		= 0;
				$saved 						= $adwords->save();

				if ( $saved ) {
					echo "saved";
				} else {
					echo "not saved";
				}
			} else {
				echo "not saved - already exists";
			}

			$this->switchDatabase($db);
		}
	}

	public function saveClickID(Request $request)
	{
		$input 		= $request->input();

		if ( isset($input['clickID']) && !empty($input['clickID']) ) {
			$clickID						= $input['clickID'];
			$bookAppointMent 				= Session::get('bookAppointMent');
			if ( strlen($clickID) > 0 ) {
				$bookAppointMent['gClickID'] = $clickID;
				Session::put('bookAppointMent', $bookAppointMent);
				$json 						= array('data' => 'click_saved', 'status' => 'success' );
			} else {
				$json 						= array('data' => 'no_click', 'status' => 'error' );
			}
		} else {
			$json 							= array('data' => 'no_click', 'status' => 'error' );
		}

		return response()->json($json);
	}

	protected function saveApptIdToGoogleROI($appointmentID)
	{
		$httpHost					= $_SERVER['HTTP_HOST'];
		$subDomainArr				= explode('.', $httpHost);
		$subDomain					= $subDomainArr[0];
		$accountID					= $this->getAccountID($subDomain);
		$bookinArray 				= Session::get('bookAppointMent');

		//~ $this->switchDatabase('juvly_idoctorz_juvly');

		if ( count((array)$bookinArray)) {
			if ( array_key_exists('gClickID', $bookinArray)) {
				$gClickID	= $bookinArray['gClickID'];

				//~ $updateArr	= array(
					//~ 'appointment_id' 	=> $appointmentID,
					//~ 'account_id'		=> $accountID
				//~ );

				//~ GoogleAdwordsRoi::where('click_id', $gClickID)->where('appointment_id', 0)->update($updateArr);

				$url 	= "https://juvly.aestheticrecord.com/save_adwords_appt.php";
				$curl 	= curl_init();

				$fields = array(
					'appointment_id'			=> $appointmentID,
					'click_id' 					=> $gClickID
				);

				$field_string = http_build_query($fields);

				curl_setopt_array($curl, array(
				  CURLOPT_URL => $url,
				  CURLOPT_RETURNTRANSFER => true,
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
			}
		}
		return true;
	}

	public function googleAuthSyncEvent(Request $request){
		//echo "<pre>"; print_r($request->all()); die;
		$input = $request->all();
		$decryptData =  Crypt::decrypt($input['state']);
		//echo "<pre>"; print_r($decryptData); die;
		//$database_tmp = Session::get('database_tmp');
		$database_tmp = $decryptData['database_tmp'];
		$url = $decryptData['url'];
		$this->switchDatabase($database_tmp);
		//$appointMentForCalender = Session::get('bookAppointMentForCalender');
		$appointMentForCalender = $decryptData['bookAppointMentForCalender'];
		$appointmentWithSeriveAndClinic 	= 	Appointment :: with('services')->with('clinic')->find($appointMentForCalender['appointment_id']);
		//$patient_id = Session::get('patienttttt_id');
		$patient_id = $decryptData['patienttttt_id'];
		$patent_tmp_details = Patient::where('id',$patient_id)->first();
		$requestData = $request->all();
		if(!empty($requestData['code'])){
			$returnStatus = $this->syncWithPatientGoogleCalender($request, $patent_tmp_details, $appointMentForCalender, $appointmentWithSeriveAndClinic);
			Session::put('url',$url);
			return Redirect::to('thankyou_sync');
			//return view('thankyou_sync')->with('url',$url);
			//unset($requestData['code']);
		}
	}

	private function syncWithPatientGoogleCalender($request, $patent_detail, $appointmentData, $appointmentWithSeriveAndClinic){
		$requestData = $request->all();
		if(!empty($requestData['code'])){

            $redirect_uri = config('google.redirect_url');
            $client_id = config('google.client_id');
            $client_secret = config('google.client_secret');
			$calCredentials  = GetAccessToken($client_id, $redirect_uri, $client_secret, $requestData['code']);
			$access_token    = $calCredentials['access_token'];
			//$fullname = @$patent_detail->firstname.' '.@$patent_detail->lastname;

			$user = Users::select('id','firstname','lastname')->find($appointmentWithSeriveAndClinic->user_id);

			$firstName =  $user['firstname'];
			$lastName  =  $user['lastname'];
			$fullname  =  'Appointment with '.$firstName.' '.$lastName;

			$user_timezone   = GetUserCalendarTimezone($access_token);
			$clinicTimeZone  = $appointmentWithSeriveAndClinic['clinic']->timezone;
			$clinic_location = $appointmentWithSeriveAndClinic['clinic']->city;
			$appointment_date		= date('Y-m-d', strtotime($appointmentWithSeriveAndClinic->appointment_datetime));
			$appointment_time		= date('H:i:s', strtotime($appointmentWithSeriveAndClinic->appointment_datetime));
			/***FROM TIME****/
			//$utcAppFromDatetime     = $this->convertTimeByTZ($appointmentWithSeriveAndClinic->appointment_datetime, $clinicTimeZone,$user_timezone, "datetime");

			$utcAppFromDatetime     = $this->convertTimeByTZ($appointmentWithSeriveAndClinic->appointment_datetime, $user_timezone,$clinicTimeZone, "datetime");

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
			$duration				= $appointmentWithSeriveAndClinic->duration;
			$toTime                 = $minutes + $duration;
			$toTime                 = $this->convertToHoursMins($toTime, '%02d:%02d');
			$combinedToDT 		    = date('Y-m-d H:i:s', strtotime("$appointment_date $toTime"));
			//$utcAppToDatetime       = $this->convertTimeByTZ($combinedToDT, $clinicTimeZone,$user_timezone, "datetime");
			$utcAppToDatetime       = $this->convertTimeByTZ($combinedToDT, $user_timezone,$clinicTimeZone, "datetime");
			$toDateTimeApp          = date('Y-m-dTH:i:s', strtotime($utcAppToDatetime));

			if(strstr($toDateTimeApp,'UTC')){
				$toDateTimeApp          = str_replace('U', '', $toDateTimeApp);
				$toDateTimeApp          = str_replace('C', '', $toDateTimeApp);

			}elseif(strstr($toDateTimeApp,'ED')){
				$toDateTimeApp          = str_replace('ED', '', $toDateTimeApp);

			}else{
				$toDateTimeApp          = str_replace('ES', '', $toDateTimeApp);
			}
			$status = '';
			$description  = '';
			if(count((array)$appointmentWithSeriveAndClinic['services']) > 0) {
				$description        = 'Services : ';

				foreach($appointmentWithSeriveAndClinic['services'] as $AppointmentService) {
					$description .=  $AppointmentService->name.', ';
				}
				$description .= '<br/>';
			}
			$description    .= 'Status : '.'Booked';

			$event_time = array('start_time'=>$fromDateTimeApp, 'end_time'=>$toDateTimeApp, 'event_date'=>'');
			try{
				$data = CreateCalendarEvent('primary', $fullname, 0, $event_time, $user_timezone, $access_token, $status, $clinic_location, $description);
				//echo $data; die;
				return true;
			}catch(\Exception $e){
				//echo $e->getMessage(); die;
				return false;
			}

		}
	}

	public function yahooCalendarPatient(Request $request){
		$userTimezone = "America/New_York";
		try{
			$curIP = $_SERVER['REMOTE_ADDR'];
			$data  = getCurTimeZone($curIP);
			$data  = json_decode($data);
			$currentTZ = $data->data->geo->timezone;
			//echo $currentTZ; die();
			//return true;
		}catch(\Exception $e){
			//echo $e->getMessage(); die;
			//return false;
		}
		$userTimezone = $currentTZ;
		//$userTimezone = $timeZoneFirstKey.'/'.$timeZoneSecondKey;
		$database_tmp = Session::get('database_tmp');
		$this->switchDatabase($database_tmp);
		$appointMentForCalender = Session::get('bookAppointMentForCalender');
		$appointmentWithSeriveAndClinic 	= 	Appointment :: with('services')->with('clinic')->find($appointMentForCalender['appointment_id']);
		$patient_id = Session::get('patienttttt_id');
		$patent_tmp_details = Patient::where('id',$patient_id)->first();

		$requestData = $request->all();
		$data = $this->sendPatientYahooCalender($request, $patent_tmp_details, $appointMentForCalender, $appointmentWithSeriveAndClinic, $userTimezone);
		return Redirect::to('thankyou_sync');
	}

	private function sendPatientYahooCalender($request, $patent_detail, $appointmentData, $appointmentWithSeriveAndClinic, $user_timezone){
		//echo "<pre>"; print_r($appointmentWithSeriveAndClinic); die;
		$requestData = $request->all();

			//~ $redirect_uri	 = config('google.redirect_url');
			//~ $client_id		 = config('google.client_id');
			//~ $client_secret	 = config('google.client_secret');
			//~ $calCredentials  = GetAccessToken($client_id, $redirect_uri, $client_secret, $requestData['code']);
			//~ $access_token    = $calCredentials['access_token'];
			//$fullname = @$patent_detail->firstname.' '.@$patent_detail->lastname;

			$user = Users::select('id','firstname','lastname')->find($appointmentWithSeriveAndClinic->user_id);

			$firstName =  $user['firstname'];
			$lastName  =  $user['lastname'];
			$fullname  =  'Appointment with '.$firstName.' '.$lastName;

			//$user_timezone   = GetUserCalendarTimezone($access_token);
			$clinicTimeZone  = $appointmentWithSeriveAndClinic['clinic']->timezone;
			$clinic_location = $appointmentWithSeriveAndClinic['clinic']->city;
			$appointment_date		= date('Y-m-d', strtotime($appointmentWithSeriveAndClinic->appointment_datetime));
			$appointment_time		= date('H:i:s', strtotime($appointmentWithSeriveAndClinic->appointment_datetime));
			/***FROM TIME****/


			//$utcAppFromDatetime     = $this->convertTimeByTZ($appointmentWithSeriveAndClinic->appointment_datetime, $clinicTimeZone, $user_timezone, "datetime");

			$utcAppFromDatetime     = $this->convertTimeByTZ($appointmentWithSeriveAndClinic->appointment_datetime, $user_timezone, $clinicTimeZone, "datetime");

			$fromDateTimeApp        = date('YmdTHis', strtotime($utcAppFromDatetime));

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
			$duration				= $appointmentWithSeriveAndClinic->duration;
			$toTime                 = $minutes + $duration;
			$toTime                 = $this->convertToHoursMins($toTime, '%02d:%02d');
			$combinedToDT 		    = date('Y-m-d H:i:s', strtotime("$appointment_date $toTime"));
			//$utcAppToDatetime       = $this->convertTimeByTZ($combinedToDT, $clinicTimeZone,$user_timezone, "datetime");
			$utcAppToDatetime       = $this->convertTimeByTZ($combinedToDT, $user_timezone,$clinicTimeZone, "datetime");
			$toDateTimeApp          = date('YmdTHis', strtotime($utcAppToDatetime));

			if(strstr($toDateTimeApp,'UTC')){
				$toDateTimeApp          = str_replace('U', '', $toDateTimeApp);
				$toDateTimeApp          = str_replace('C', '', $toDateTimeApp);

			}elseif(strstr($toDateTimeApp,'ED')){
				$toDateTimeApp          = str_replace('ED', '', $toDateTimeApp);

			}else{
				$toDateTimeApp          = str_replace('ES', '', $toDateTimeApp);
			}
			$status = '';
			$description  = '';
			if(count((array)$appointmentWithSeriveAndClinic['services']) > 0) {
				$description        = 'Services : ';

				foreach($appointmentWithSeriveAndClinic['services'] as $AppointmentService) {
					$description .=  $AppointmentService->name.', ';
				}
				$description .= '<br/>';
			}
			$description    .= 'Status : '.'Booked';

			$event_time = array('start_time'=>$fromDateTimeApp, 'end_time'=>$toDateTimeApp, 'event_date'=>'');
			try{
				$data = createYahooCalendarEvent($event_time, $fullname, $clinic_location, $description);
				$data;
			}catch(\Exception $e){
				//echo $e->getMessage(); die;
				return $e->getMessage();
			}

	}

	public function syncIcanlendar(Request $request){
		$userTimezone = "America/New_York";
		try{
			$curIP = $_SERVER['REMOTE_ADDR'];
			$data  = getCurTimeZone($curIP);
			$data  = json_decode($data);
			$currentTZ = $data->data->geo->timezone;
			//echo $currentTZ; die();
			//return true;
		}catch(\Exception $e){
			//echo $e->getMessage(); die;
			//return false;
		}
		$userTimezone = $currentTZ;
		//$userTimezone = $timeZoneFirstKey.'/'.$timeZoneSecondKey;
		$database_tmp = Session::get('database_tmp');
		$this->switchDatabase($database_tmp);
		$appointMentForCalender = Session::get('bookAppointMentForCalender');
		$appointmentWithSeriveAndClinic 	= 	Appointment :: with('services')->with('clinic')->find($appointMentForCalender['appointment_id']);
		$patient_id = Session::get('patienttttt_id');
		$patent_tmp_details = Patient::where('id',$patient_id)->first();

		$requestData = $request->all();
		$data = $this->downloadIcalendarEvent($patent_tmp_details, $appointMentForCalender, $appointmentWithSeriveAndClinic, $userTimezone);
		return Redirect::to('thankyou_sync');
	}

	private function downloadIcalendarEvent($patient, $event, $appointmentWithClinic, $user_timezone){
		$appointment_date = date('Y-m-d', strtotime($appointmentWithClinic->appointment_datetime));
		$appointment_time = date('H:i:s', strtotime($appointmentWithClinic->appointment_datetime));
		$clinicTimeZone = $appointmentWithClinic['clinic']->timezone;
		//echo "<pre>"; print_r($appointmentWithClinic); die('here');

		$user = Users::select('id','firstname','lastname')->find($appointmentWithClinic['user_id']);

		$firstName =  $user['firstname'];
		$lastName  =  $user['lastname'];
		$fullName  =  $firstName.' '.$lastName;

		$description  = '';
		if(count((array)$appointmentWithClinic['services']) > 0) {
			$description        = 'Services : ';

			foreach($appointmentWithClinic['services'] as $AppointmentService) {
				$description .=  $AppointmentService->name.', ';
			}
			$description .= "\n";
		}
		$description .= 'Status : '.'Booked';

		/***FROM TIME****/
		//$utcAppFromDatetime     = $this->convertTimeByTZ($appointmentWithClinic->appointment_datetime, $clinicTimeZone, $user_timezone, "datetime");

		$utcAppFromDatetime     = $this->convertTimeByTZ($appointmentWithClinic->appointment_datetime, $user_timezone, $clinicTimeZone, "datetime");

		$fromDateTimeApp        = date('YmdTHis', strtotime($utcAppFromDatetime));

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
		$duration				= $appointmentWithClinic->duration;
		$toTime                 = $minutes + $duration;
		$toTime                 = $this->convertToHoursMins($toTime, '%02d:%02d');
		$combinedToDT 		    = date('Y-m-d H:i:s', strtotime("$appointment_date $toTime"));
		//$utcAppToDatetime       = $this->convertTimeByTZ($combinedToDT, $clinicTimeZone,$user_timezone, "datetime");
		$utcAppToDatetime       = $this->convertTimeByTZ($combinedToDT, $user_timezone,$clinicTimeZone, "datetime");
		$toDateTimeApp          = date('YmdTHis', strtotime($utcAppToDatetime));
		if(strstr($toDateTimeApp,'UTC')){
			$toDateTimeApp          = str_replace('U', '', $toDateTimeApp);
			$toDateTimeApp          = str_replace('C', '', $toDateTimeApp);

		}elseif(strstr($toDateTimeApp,'ED')){
			$toDateTimeApp          = str_replace('ED', '', $toDateTimeApp);

		}else{
			$toDateTimeApp          = str_replace('ES', '', $toDateTimeApp);
		}
		//echo $toDateTimeApp; die;
		// Build the ics file
		$ical = 'BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//hacksw/handcal//NONSGML v1.0//EN
CALSCALE:GREGORIAN
BEGIN:VEVENT
UID:' . md5(time()) . '
DTSTAMP:' . time() . '
LOCATION:' . addslashes($appointmentWithClinic['clinic']->city) . '
DESCRIPTION:' . addslashes($description) . '
URL;VALUE=URI: http://mydomain.com/events/' . $appointmentWithClinic['id'] . '
SUMMARY:' . addslashes('Appointment with '.@$fullName) . '
DTSTART:' . $fromDateTimeApp . '
DTEND:' . $toDateTimeApp . '
END:VEVENT
END:VCALENDAR';
		//set correct content-type-header
		header('Content-type: text/calendar; charset=utf-8');
		header('Content-Disposition: attachment; filename=iCalendar-event.ics');
		echo $ical;
		exit;
	}

	public function successSync(){
		return view('thankyou_sync');
	}

	function moveElement(&$array, $a, $b) {
		$out = array_splice($array, $a, 1);
		array_splice($array, $b, 0, $out);
	}

	private function touchMD($account_prefrence, $patient){
		/***********************SAVE TOUCH MD USER STARTS********************************/

		if($account_prefrence->touch_md_sync_enabled == 1 && !empty($account_prefrence->touch_md_api_key) && $account_prefrence->touch_md_sync_new == 1){
			$account_id = $account_prefrence->account_id;
			$patient_id = $patient->id;
			$gender = 'U';
			if($patient->gender == 0){
				$gender = 'M';
			}
			if($patient->gender == 1){
				$gender = 'F';
			}
			$has_birth_date = true;
			if((!empty($patient->date_of_birth)) && ($patient->date_of_birth)){
				$dt = new DateTime($patient->date_of_birth);
				$birth_date = $dt->format('Y-m-d\TH:i:s.u\Z');
			}else{
				$birth_date = '1970-01-01T00:00:00Z';
				$has_birth_date = false;
			}
			$touchmd_insert = [
				// $patient['firstname'].rand(1,99).str_random(10).time().str_random(10)
				"SourceId" => (string) $patient_id.'_AR',
				"FirstName"=> (string) !empty($patient->firstname) ? $patient->firstname : '',
				"LastName" => (string) !empty($patient->lastname) ? $patient->lastname : $patient->firstname,
				"Email" => (string) !empty($patient->email) ? $patient->email : null,
				"Gender" => (string) $gender,
				"PhoneNumber" => (string) !empty($patient->phoneNumber) ? $patient->phoneNumber : null,
				"DateOfBirth" => (string) $birth_date
			];
			try{
				if($has_birth_date) {
					TouchMd::createMdPatient($account_id,$account_prefrence, $touchmd_insert,$patient_id);
				}
			}catch(Exception $e){

			}
		}
		/***************************SAVE TOUCH MD USER ENDS*******************************/
	}

	private function sortServiceNameOrder($a, $b){
		if(isset($a['service_name']) && isset($b['service_name'])){
			return strcmp($a["service_name"], $b["service_name"]);
		}
	}

	public function verifyNumber(Request $request)
	{
		$account  	= Session::get('account');
		$data		= Session::get('bookAppointMent');
		$formData	= array();

		$input 		= $request->input();
		$formData	= $data['formData'];
		//~ $number		= $formData['full_number'];
		$number		= $input['number'];

		$response	= $this->verifyTwilioNumber($number, $account);

		if ($response && isset($response->sid) && $response->status == "pending") {

			$this->switchDatabase('ar-global');
			$checkClient = Client::where('phone', $number)->where('status', '0')->first();

			if (count((array)$checkClient)) {
				$gloalClientID 	= $checkClient->id;
			} else {
				$dateTime					= date('Y-m-d H:i:s');
				$client 					= new Client();
				$client->firstname			= $formData['firstname'];
				$client->lastname			= $formData['lastname'];
				$client->email				= $formData['email'];
				$client->phone				= $number;
				$client->created			= $dateTime;
				$client->modified			= $dateTime;
				$client->about_me			= 'pportal';
				$clientSaved				= $client->save();
				$gloalClientID 				= $client->id;

			}

			$data 									= Session::get('bookAppointMent');
			$data['formData']['global_client_id'] 	= @$gloalClientID;
			Session::put('bookAppointMent', $data);

			$json = array("status" => 200, "canShowOTPSection" => true, "message" => "Open OTP section");
		} else if (is_object($response) && ($response->status != "pending")) {
			$json = array("status" => 200, "canShowOTPSection" => false, "message" => 'Error : ' . $response->message);
		} else {
			$json = array("status" => 200, "canShowOTPSection" => false, "message" => 'Unable to send SMS at this time, please try again later');
		}

		$httpHost		= $_SERVER['HTTP_HOST'];
		$subDomainArr	= explode('.', $httpHost);
		$subDomain		= $subDomainArr[0];

		$this->getDatabase($subDomain);

		$db				= $this->userDB;

		$this->switchDatabase($db);

		return response()->json($json);
	}


	// deprecated : Do not use
	private function sendOTP($gloalClientID, $account, $number)
	{
		$this->switchDatabase('ar-global');

		$uniqueID		= 10000 + $gloalClientID;

		$update_arr		= array(
			'unique_id'	=> $uniqueID
		);

		$status    		= Client::where('id', $gloalClientID)->update($update_arr);
		$returnStatus	= false;

		if ($status) {
			$otp							 	= mt_rand(000001, 999999);

			$smsBody						 	= "";
			$smsBody							.= "OTP to book your Appointment";

			if ($account && strlen($account->name) > 0) {
				$smsBody						.= " at " . ucfirst($account->name) . " is " . $otp;
			} else {
				$smsBody						.= " is " . $otp;
			}

            if ($account->getKey() === config('app.juvly_account_id')) {
                $smsSent = $this->sendSMS($number, $smsBody, $account, 'true');
            } else {
                $smsSent = $this->sendSMS($number, $smsBody, $account);
            }

			if ($smsSent) {
				$otpExpiration					= date('Y-m-d H:i:s', strtotime("+30 minutes"));
				$dateTime						= date('Y-m-d H:i:s');

				ClientsLogin::where('client_id', $gloalClientID)->delete();

				$clientsLogin 					= new ClientsLogin();
				$clientsLogin->client_id		= $gloalClientID;
				$clientsLogin->sms_otp			= $otp;
				$clientsLogin->otp_expiration	= $otpExpiration;
				$clientsLogin->created			= $dateTime;
				$clientsLogin->modified			= $dateTime;
				$saved 							= $clientsLogin->save();

				if ($saved) {
					$returnStatus				= true;
				}
			}
		}

		return $returnStatus;
	}

	public function verifyOTP(Request $request)
	{
		$account  	= Session::get('account');
		$input 		= $request->input();
		$number		= $input['number'];
		$otp		= $input['otp'];

		$response	= $this->verifyTwilioOTP($number, $otp);

		if ($response && isset($response->status) && $response->status == "approved") {
			$json = array("status" => 200, "error" => false, "message" => "The OTP entered is correct");
		} else {
			$json = array("status" => 200, "error" => true, "message" => "The OTP entered is incorrect");
		}

		return response()->json($json);
	}

	private function checkClientAccount($patientID, $accountID)
	{
		$data			= Session::get('bookAppointMent');
		$formData		= array();
		$formData		= $data['formData'];
		$returnStatus	= false;
		$gloalClientID	= $data['formData']['global_client_id'];

		config(['database.connections.juvly_practice.database'=> 'ar-global' ]);
		DB::purge('juvly_practice');

		if (count((array)$formData) && isset($formData['full_number'])) {

			$number		 = $formData['full_number'];
			$checkClient = Client::where('id', $gloalClientID)->where('status', '0')->first();

			if (count((array)$checkClient)) {
				$uniqueID		= $checkClient->unique_id;

				if ($gloalClientID && $patientID) {
					$chkAcc								= ClientsAccount::where('client_id', $gloalClientID)->where('patient_id', $patientID)->where('account_id', $accountID)->first();

					if (count((array)$chkAcc)) {
						$returnStatus 					= $uniqueID;
					} else {
						$dateTime						= date("Y-m-d H:i:s");

						$clientsAccount 				= new ClientsAccount();
						$clientsAccount->client_id		= $gloalClientID;
						$clientsAccount->patient_id		= $patientID;
						$clientsAccount->account_id		= $accountID;
						$clientsAccount->created		= $dateTime;
						$clientsAccount->modified		= $dateTime;
						$caSaved 						= $clientsAccount->save();

						if ($caSaved) {
							$returnStatus 				= $uniqueID;
						}
					}
				}
			}
		}

		$httpHost		= $_SERVER['HTTP_HOST'];
		$subDomainArr	= explode('.', $httpHost);
		$subDomain		= $subDomainArr[0];

		$this->getDatabase($subDomain);

		$db				= $this->userDB;

		config(['database.connections.juvly_practice.database'=> $db]);
		DB::purge('juvly_practice');

		return $returnStatus;
	}

	public function resendOTP(Request $request)
	{
		$account  	= Session::get('account');
		$input 		= $request->input();
		$formData 	= Session::get('bookAppointMent');
		$gcID		= $formData['formData']['global_client_id'];
		//~ $number		= $formData['formData']['full_number'];
		$number		= $input['number'];

		$response	= $this->verifyTwilioNumber($number, $account);

		if ($response && isset($response->sid) && $response->status == "pending") {
			$json = array("status" => 200, "error" => false, "message" => "OTP sent again");
		} else if (is_object($response) && ($response->status != "pending")) {
			$json = array("status" => 200, "canShowOTPSection" => false, "message" => 'Error : ' . $response->message);
		} else {
			$json = array("status" => 200, "canShowOTPSection" => false, "message" => 'Unable to send SMS at this time, please try again later');
		}

		return response()->json($json);
	}

	public function getAWSMeeting(Request $request){
		$MeetingId =0;
		try{
		  $s3 = \App::make('aws')->createClient('Chime');
		  $request_token = "AR" . rand(100000,9999999) . "meeting";
		  $request_token = (string) $request_token;

		  $response = $s3->CreateMeeting(array(
			'ClientRequestToken'     => $request_token,
			'MediaRegion'        => 'us-west-2',
			'MeetingHostId' => 'xzw477',
			'NotificationsConfiguration' =>[]
		  ));
		  $MeetingId = $response['Meeting']['MeetingId'];
		}catch(Exception $e){
		  //echo $e; die();
		  /* meeting not created */
		}
    echo $MeetingId; die;
    }

	public function getAWSMeetingID() {
		$response = '';
		try {
			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, 'http://ivy1.estheticrecord.com/book/meeting' );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );

			$response			 = curl_exec( $ch );
			$info				 = curl_getinfo( $ch );
			curl_close ( $ch );
		} catch (Exception $e) {
			$response = '';
		}
		return $response;
	}

	private function getServiceAmount($db, $bookAppointMent){
		$this->switchDatabase($db);
		$selectedServices = Service::whereIn('id',$bookAppointMent['selService'])->first();
		return $selectedServices;
	}

	private function savePosData($charge_data, $bookAppointMent, $account, $gatewayType=null){

		$input = $bookAppointMent['formData'];
		$account_id = $account->id;
		$db = $account->database_name;
		$patient_id = $bookAppointMent['patient_id'];
		$clinicID = $bookAppointMent['selClinic'];

		$customerCardBrand = '';
		$customerCardLast4 = '';
        $apriva_transaction_data = null;
        if (!empty($gatewayType) && $gatewayType == "clearent") {
            $charge_data = $charge_data['data'];
            if (!empty($charge_data["payload"]["transaction"]) && isset($charge_data["payload"]["transaction"])) {
                $host_transaction_id = $charge_data["payload"]["transaction"]["id"];
                if (!empty($charge_data["payload"]["tokenResponse"]) && isset($charge_data["payload"]["tokenResponse"])) {
                    $customerCardBrand = $charge_data["payload"]["tokenResponse"]["card-type"];
                }
                $customerCardLast4 = $charge_data["payload"]["transaction"]["last-four"];
                $apriva_transaction_data = json_encode($charge_data["payload"]);
            } else if (!empty($charge_data["payload"]["tokenResponse"]) && isset($charge_data["payload"]["tokenResponse"])) {
                $host_transaction_id = $charge_data["payload"]["tokenResponse"]["token-id"];
                $customerCardBrand = $charge_data["payload"]["tokenResponse"]["card-type"];
                $customerCardLast4 = $charge_data["payload"]["tokenResponse"]["last-four-digits"];
                $apriva_transaction_data = json_encode($charge_data["payload"]);
            }
            $invoiceNumber = $charge_data["invoice_number"];
            $platformFee = $charge_data["platformFee"];
            $total_amount = $charge_data['payload']['transaction']['amount'];

        } else {
            if (isset($charge_data['data']->source->brand)) {
                $customerCardBrand = $charge_data['data']->source->brand;
            }
            if (isset($charge_data['data']->source->last4)) {
                $customerCardLast4 = $charge_data['data']->source->last4;
            }
            $total_amount = 0;
            if (isset($charge_data['data']->amount)) {
                $total_amount = $charge_data['data']->amount / 100;
            }
            $host_transaction_id = null;
            if (isset($charge_data['data']->id)) {
                $host_transaction_id = $charge_data['data']->id;
            }
            $invoiceNumber = 'AR00' . $account_id . '0' . $patient_id . '0' . time();
            $platformFee = null;
        }
		$currentTime	= date('Y-m-d H:i:s');

		$currentTime	= $this->getCurrentTimeNewYork($currentTime);

		$service = $this->getServiceAmount($db, $bookAppointMent);
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
			'patient_email' 					=> $input['email'],
			'status'							=> "paid",
			'created'							=> $currentTime,
			'paid_on'							=> $currentTime,
			'product_type'						=> 'custom',
			'monthly_amount'					=> 0,
			'one_time_amount'					=> 0,
			'total_discount'					=> 0,
			'title'								=> 'Virtual appointment',
            'platformFee'						=> $platformFee,
            'apriva_transaction_data'			=> $apriva_transaction_data
		);
		$posInvoiceData['custom_product_name'] = @$service->name;

		$posInvoiceData['host_transaction_id'] = $host_transaction_id;
		$invoice_id  = (new SubcriptionController)->createPosInvoice($posInvoiceData, 'custom', $bookAppointMent['selDoc'],'virtual', $gatewayType);
		Appointment::where('id',$bookAppointMent['appointment_id'])->update(['invoice_id' =>$invoice_id]);
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

	public function generateRandomString($length = 20) {
		$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()_+=][{}\/?><|Z';
		$charactersLength = strlen($characters);
		$randomString = '';
		for ($i = 0; $i < $length; $i++) {
				$randomString .= $characters[rand(0, $charactersLength - 1)];
		}
		return $randomString.time();
	}

	private function getServiceChargeType($db, $bookAppointMent){
		$this->switchDatabase($db);
		$selectedServices = Service::whereIn('id',$bookAppointMent['selService'])->first();
		return $selectedServices;
	}

	public function getAppointmentEmailTemplate($mailBody,$account,$clinic,$subject,$appointment_header, $isCovidEmail="false"){
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

		$view 				= View::make('appointments.appointment_email_template', ['email_content' => $email_content,'clinic_location' => $clinic_location,'account_logo' => $account_logo, 'site_url' => $site_url,'storage_folder' => $storage_folder,'appointment_status' => $appointment_status, 'appointment_header'=>$appointment_header,'clinic_address'=>$clinic_address, 'is_covid_email' => $isCovidEmail]);
		$contents			= $view->render();
		return $contents;
	}

	private function getWelcomeEmailTemplate($mailBody,$account,$clinic,$subject, $template_used){

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
		$business_name		= @$account->name;
		$clinic_address		= @$clinic->address;
		$account_logo  		= @$account->logo;
		$account_name		= @$account->name;
		$storage_folder		= @$account->storage_folder;
		$appointment_status = $subject;
		$site_url			= config('constants.urls.site');
	//	$view 				= View::make('appointments.email_template', ['email_content' => $email_content,'clinic_location' => $clinic_location,'account_logo' => $account_logo, 'site_url' => $site_url,'storage_folder' => $storage_folder,'appointment_status' => $appointment_status,'account_name' => $account_name]);
		$view 				= View::make('patient_welcome_email', ['email_content' => $email_content,'clinic_location' => $clinic_location,'account_logo' => $account_logo, 'site_url' => $site_url,'storage_folder' => $storage_folder,'appointment_status' => $appointment_status,'clinic_address' => $clinic_address,'business_name' => $business_name, 'template_used' => $template_used]);
		$contents			= $view->render();
		return $contents;

	}


	public function sendCovidMail($appointmentData, $mailBody, $account)
	{
		$appointment 					= Appointment::find($appointmentData['appointment_id']);
		$database_name 					= Session::get('selectedDB');

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

		$this->switchDatabase($database_name);
		$clinic 						= Clinic::findOrFail(@$appointmentData['selClinic']);
		$sender	 						= $this->getSenderEmail();
		$subject 						= @$accountPrefData['covid_email_subject'];

		$location 			= array();
		if(!empty($clinic->address)){
			$location[] 		= $clinic->address;
		}
		if(!empty($clinic->city)){
			$location[] 		= !empty($clinic->clinic_city) ? $clinic->clinic_city : $clinic->city;
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
		$selDate 						= changeFormatByPreference($appointmentData['selDate']);
		//$appointment_time 				= date('g:i a',strtotime($appointmentData['selTime']));
		$appointment_time 				= changeFormatByPreference(@$appointmentData['selTime'], null, true, true );
		$appointment_date_time 			= date('l',strtotime($appointmentData['selDate'])).' '.$selDate.' @ '.$appointment_time;

		$provider = Users :: where('id',@$appointmentData['selDoc'])->first();

		if(count((array)$provider) > 0) {

			if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

				$provider_name = ucfirst($provider->bio_name);
			} else {

				$provider_name = ucfirst($provider->firstname." ".$provider->lastname);
			}

		} else {
			$provider_name='';
		}

		$services = array();

		if(count((array)$appointmentData['selService'])>0) {

			$allBookedServices 	= Service :: whereIn('id',$appointmentData['selService'])->pluck('name');
			$allBookedServices = $allBookedServices->toArray();
			if(count((array)$allBookedServices)>0){
				foreach($allBookedServices as $key => $val){
					$services[] = ucfirst($val);
				}
			}

		}
		$appointment_header['APPOINTMENTDATE'] 	= $selDate;
		$appointment_header['APPOINTMENTTIME'] 	= $appointment_time;
		$appointment_header['PROVIDERNAME'] 	= $provider_name;
		$appointment_header['BOOKEDSERVICES'] 	= implode(', ',$services);
		$appointment_header['CLINICNAME'] 	= $clinic->clinic_name;

		$replace 							= array();
	//	$replace['PATIENTNAME'] 			= ucfirst($appointmentData['formData']['firstname'] ." ". $appointmentData['formData']['lastname']);
		$replace['PATIENTNAME'] 			= ucfirst($appointmentData['formData']['firstname']);
		$replace['CLINICNAME']				= ucfirst($clinic->clinic_name);
		$replace['CLINICLOCATION']			= $clinic_location;
		$replace['CLINICINSTRUCTIONS']		= $email_special_instructions;
		$replace['APPOINTMENTDATETIME']		= $appointment_date_time;
		$replace['PROVIDERNAME']			= $provider_name;
		$replace['BUSINESSNAME']			= $business_name;
		$replace['BOOKEDSERVICES']			= implode(', ',$services);
		$replace['CANFEECHARGEDAYS']		= $cancelation_fee_charge_days;
		$replace['CANCELATIONFEES']			= $cancelation_fees;
		$replace['CLIENTPATIENTURL']		= URL::to('/');

		$tags								= array();
		$tags['PATIENTNAME'] 				= "{{PATIENTNAME}}";
		$tags['CLINICNAME']					= "{{CLINICNAME}}";
		$tags['APPOINTMENTDATETIME']		= "{{APPOINTMENTDATETIME}}";
		$tags['CLINICLOCATION']				= "{{CLINICLOCATION}}";
		$tags['CLINICINSTRUCTIONS']			= "{{CLINICINSTRUCTIONS}}";
		$tags['PROVIDERNAME']				= "{{PROVIDERNAME}}";
		$tags['BOOKEDSERVICES']				= "{{BOOKEDSERVICES}}";
		$tags['CANFEECHARGEDAYS']			= "{{CANFEECHARGEDAYS}}";
		$tags['CANCELATIONFEES']			= "{{CANCELATIONFEES}}";
		$tags['CLIENTPATIENTURL']			= "{{CLIENTPATIENTURL}}";
		$tags['BUSINESSNAME']				= "{{BUSINESSNAME}}";
		$tags['MEETINGLINK']			= "{{MEETINGLINK}}";

		$encoded_account_id = $this->encryptKey($account->id);
		$meeting_id =  $appointment['meeting_id'];
		$appointment_type = $appointment['appointment_type'];

		if ($appointment_type == "virtual" && $meeting_id) {

			$meeting_link = config('constants.urls.top_box');
			//~ $replace['MEETINGLINK'] = "<a href=".$meeting_link."/client/".$meeting_id."><h5><b>Join Your Virtual Meeting<b></h5></a><br>
			//~ Please click the link below on the day & time of your appointment to enter our Virtual Clinic. For the best experience, we recommend joining your appointment from a quiet place with a strong wifi or cellular connection. Ensure your camera and audio are both enabled as you enter the portal. While settings vary by browser, but you should be prompted once you login.
			//~ <br><br>"."Appointment Link: $meeting_link/client/".$meeting_id;

			//~ $replace['MEETINGLINK'] = "<a target='_blank' href=".$meeting_link."/client/".$meeting_id.">".$meeting_link."/client/".$meeting_id."</a>";
			$replace['MEETINGLINK'] = $meeting_link."/client/".$meeting_id;;

		} else {
			$replace['MEETINGLINK'] = "";
		}


		foreach ( $tags as $key => $val ) {
			if ( $val ) {
				 $mailBody  = str_replace($val, $replace[$key], $mailBody);
			}
		}


		$email_content = $this->getAppointmentEmailTemplate($mailBody,$account,$clinic,$subject,$appointment_header, "true");
		$noReply = config('mail.from.address');

        $response_data = EmailHelper::sendEmail($noReply, trim($appointmentData['formData']['email']), $sender, $email_content, $subject);
		if ($response_data) {
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

    public function authorizeCardUsingClearent($account, $input, $patientID, $type = "new", $appointmentType, $isFreeVirtualService)
    {
        $responseArray = array("status" => "error", "data" => array(), "message" => "Unable to connect with payment gateway, please try again");
        $hostTransactionData = array();

        $ip = null;
        if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }

        if (count((array)$account) > 0) {
            $cancellation_fees = 0;
            $dbname = $account->database_name;
            $cancelation_fee = $account->cancellation_fees;

            $stripeConnectionType = $account->stripe_connection;
            if ($stripeConnectionType == 'global') {
                $clinicID = 0;
            } else {
                $clinicID = $input['selClinic'];
            }
            $stipeCon = [
                ['account_id', $account->id],
                ['clinic_id', $clinicID]
            ];

            $this->switchDatabase(config('database.default_database_name'));

            //$accountClearentConfig	= AccountClearentConfig::where($stipeCon)->first();
            $accountClearentConfig = AccountClearentConfig::whereHas('clearent_setup', function ($q) {
                $q->where('status', 'completed');
            })->with(['clearent_setup' => function ($q) {
                $q->where('status', 'completed');
            }])->where($stipeCon)->first();

            if ($accountClearentConfig) {
                $accountClearentConfig = $accountClearentConfig->toArray();
            } else {
                $accountClearentConfig = array();
            }

            if (empty($accountClearentConfig)) {
                $responseArray = array("status" => "error", "data" => array(), "message" => "Unable to process: Clearent connection not found");
            }

            if (empty($accountClearentConfig['apikey'])) {
                $responseArray = array("status" => "error", "data" => array(), "message" => "Unable to process: Clearent api key not found");
            }

            $stripeUserID = $accountClearentConfig['merchant_id'];
            $platformFee = $accountClearentConfig['platform_fee'];

            $input = $input['formData'];
            $bookAppointMent = Session::get('bookAppointMent');

            $this->switchDatabase($dbname);

            if ($appointmentType && $appointmentType == 'virtual' && $isFreeVirtualService == "false") {
                $serviceAmount = $this->getServiceAmount($dbname, $bookAppointMent);

                $serviceAmount = $serviceAmount->price;
            } else {
                $serviceAmount = 0;
            }

            if (strlen($stripeUserID) > 0) {
                /*(
                            !empty($input['card_number']) && !empty($input['expiry_month']) && !empty($input['expiry_year']) &&
                            !empty($input['cvv']) && $type == "new"
                        )*/
                if (!empty($input['clearentToken']) && $type == "new") {

                    // $input['expiry_year'] = substr($input['expiry_year'],-2);
                    $result_set = [];
                    $result_set = Clearent::createToken($input['clearentToken'], $accountClearentConfig['apikey']);

                    if (count((array)$result_set)) {
                        if (!empty($result_set["status"]) && $result_set["status"] == 200) {

                            $accountName = !empty($account->name) ? $account->name : 'Aesthetic Record';
                            $accountName = substr(strtoupper(str_replace(' ', '', $accountName)), 0, 20);
                            $accountName = $this->cleanString($accountName);
                            $accountName = preg_replace('/[^A-Za-z0-9\-]/', '', $accountName);

                            $accountCurrency = $account->stripe_currency;
                            $currencyMinAmnt = $this->stripeMinimumAmount($accountCurrency);

                            if ($appointmentType && $appointmentType == 'in_person') {

                                //~ $chargeArr	= array(
                                //~ "amount" 	  					=> $currencyMinAmnt,
                                //~ "capture"						=> 'false',
                                //~ "customer" 	  					=> $customerTokenID,
                                //~ "currency"	  					=> $accountCurrency,
                                //~ //"statement_descriptor"			=> strtoupper($accountName),
                                //~ "statement_descriptor_suffix"	=> strtoupper($accountName),
                                //~ "description" 					=> $input['email'] . ' booked an appointment',
                                //~ //"application_fee_amount" 		=> round($platformFee, 2) * 100,
                                //~ "on_behalf_of" 					=> $stripeUserID,
                                //~ "transfer_data"					=> array(
                                //~ "destination" => $stripeUserID
                                //~ )
                                //~ );
                                $zip = $input['pincode'] ?? '';
                                $result_set = [];
                                $result_set = Clearent::authoriseCard($input['clearentToken'], $accountClearentConfig['apikey'], $zip);
                                if (!empty($result_set["status"]) && $result_set["status"] == 200) {
                                    $responseArray["status"] = "success";
                                    $responseArray["data"] = $result_set["data"];
                                    $responseArray["message"] = "";
                                } else {
                                    $responseArray["status"] = "error";
                                    $responseArray["data"] = $result_set["data"];
                                    $responseArray["message"] = $result_set["message"];
                                }
                                return $responseArray;
                            } else if ($appointmentType && $appointmentType == 'virtual' && $isFreeVirtualService == "true") {

                                //~ $chargeArr	= array(
                                //~ "amount" 	  					=> $currencyMinAmnt,
                                //~ "capture"						=> 'false',
                                //~ "customer" 	  					=> $customerTokenID,
                                //~ "currency"	  					=> $accountCurrency,
                                //~ //"statement_descriptor"			=> strtoupper($accountName),
                                //~ "statement_descriptor_suffix"	=> strtoupper($accountName),
                                //~ "description" 					=> $input['email'] . ' booked a free virtual appointment',
                                //~ //"application_fee_amount" 		=> round($platformFee, 2) * 100,
                                //~ "on_behalf_of" 					=> $stripeUserID,
                                //~ "transfer_data"					=> array(
                                //~ "destination" => $stripeUserID
                                //~ )
                                //~ );
                                $zip = $input['pincode'] ?? '';
                                $result_set = [];
                                $result_set = Clearent::authoriseCard($input['clearentToken'], $accountClearentConfig['apikey'], $zip);
                                if (!empty($result_set["status"]) && $result_set["status"] == 200) {
                                    $responseArray["status"] = "success";
                                    $responseArray["data"] = $result_set["data"];
                                    $responseArray["message"] = "";
                                } else {
                                    $responseArray["status"] = "error";
                                    $responseArray["data"] = $result_set["data"];
                                    $responseArray["message"] = $result_set["message"];
                                }
                                return $responseArray;
                            } else if ($appointmentType && $appointmentType == 'virtual' && $isFreeVirtualService == "false") {
                                $amnt = (float)$serviceAmount;
                                $finalAmnt = $amnt * 100;

                                //~ $chargeArr	= array(
                                //~ "amount" 	  					=> $finalAmnt,
                                //~ "capture"						=> 'true',
                                //~ "customer" 	  					=> $customerTokenID,
                                //~ "currency"	  					=> $accountCurrency,
                                //~ //"statement_descriptor"			=> strtoupper($accountName),
                                //~ "statement_descriptor_suffix"	=> strtoupper($accountName),
                                //~ "description" 					=> $input['email'] . ' booked a virtual appointment',
                                //~ //"application_fee_amount" 		=> round($platformFee, 2) * 100,
                                //~ "on_behalf_of" 					=> $stripeUserID,
                                //~ "transfer_data"					=> array(
                                //~ "destination" => $stripeUserID
                                //~ )
                                //~ );

                                $headers = [
                                    'content-type: application/json',
                                    'accept: application/json',
                                    'api-key: ' . $accountClearentConfig['apikey'],
                                    'mobilejwt: ' . $input['clearentToken'],
                                ];
                                $endPoint = rtrim(config('clearent.payment_url'), '/') . '/mobile/transactions/sale';
                                $invoice_number = 'AR00' . $account->id . '0' . $patientID . '0' . time();
                                $postData = array(
                                    "type" => 'SALE',
                                    //"exp-date" => $cardExpiryDate,
                                    "amount" => number_format((float)$serviceAmount, 2, '.', ''),
                                    //	"card" => $customerTokenID,
                                    "description" => strtoupper($accountName),
                                    "order-id" => 0,
                                    "invoice" => $invoice_number ?? '',
                                    "email-address" => $input['email'] ?? '',
                                    "customer-id" => $this->getClearentCustomerData($patientID) ?? '',
                                    'software-type' => config('clearent.software.type'),
                                    'software-type-version' => config('clearent.software.version'),
                                    "client-ip" => isset($ip) ?? null,
                                    "billing" => ["zip" => $input['pincode'] ?? ''],
                                    "create-token" => true
                                );

                                $response_data = [];
                                $response_data = Clearent::curlRequest($endPoint, $headers, $postData, 'POST');

                                $clearent_array = json_decode(json_encode($response_data["result"]), true);

                                if (!empty($clearent_array) && isset($clearent_array) && $clearent_array["status"] == "success") {
                                    $responseArray["status"] = "success";
                                    $responseArray["data"] = $clearent_array;
                                    if (isset($invoice_number)) {
                                        $responseArray["data"]['platformFee'] = $platformFee;
                                        $responseArray["data"]['invoice_number'] = $invoice_number;
                                    }
                                    $responseArray["message"] = "Card authorized successfully";
                                } else {
                                    $this->clearentFailedTransactions('', $clearent_array);
                                    $responseArray["status"] = "error";

                                    if (!empty($clearent_array["payload"]["transaction"]['result']) && ($clearent_array["payload"]["transaction"]['result'] != 'APPROVED')) {
                                        $responseArray["message"] = $clearent_array["payload"]["transaction"]["display-message"];
                                    } else if (!empty($clearent_array["payload"]["error"]) && isset($clearent_array["payload"]["error"])) {
                                        $responseArray["message"] = $clearent_array["payload"]["error"]["error-message"];

                                    }
                                }
                                return $responseArray;
                            }


                            //~ $chargeCustomerResponse 	= callStripe('charges', $chargeArr);

                            /*if(!empty($clearent_array) && isset($clearent_array) ){

                              $responseArray["status"]  = "success";
                              $responseArray["data"]    = $clearent_array;
                              if(isset($invoice_number)){
                                $responseArray["data"]['platformFee']     = $platformFee;
                                $responseArray["data"]['invoice_number']    = $invoice_number;
                              }
                              $responseArray["message"]   = "Card authorized successfully";
                            } else {
                              $responseArray["message"]   = "An error occured - " . $clearent_array;
                            }*/
                        } else {
                            $responseArray["message"] = "An error occured - " . $result_set["message"];
                        }
                    } else {
                        $responseArray["message"] = "We are unable to authorize your card, please try again";
                    }
                } else {
                    if ($type == "saved") {
                        if ($patientID) {
                            $patient_on_file = array();
                            $patient_on_file = PatientCardOnFile::where('patient_id', $patientID)->first();

                            if (count((array)$patient_on_file)) {
                                /// TO DO IN FUTURE
                            }
                        }
                    }
                }
            } else {
                $responseArray = array("status" => "error", "data" => array(), "message" => "Unable to process: Stripe connection not found");
            }
        }
        return $responseArray;
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

    public function getAccountClearenteConfig($account, $input)
    {
        $this->switchDatabase(config('database.default_database_name'));
        $stripeUserID = '';

        if (count((array)$account) > 0) {
            $stripeConnectionType = $account->stripe_connection;

            if ($stripeConnectionType == 'global') {
                $clinicID = 0;
            } else {
                $clinicID = $input['selClinic'];
            }

            $accountClearentConfig = AccountClearentConfig::whereHas('clearent_setup', function ($q) {
                $q->where('status', 'completed');
            })->with(['clearent_setup' => function ($q) {
                $q->where('status', 'completed');
            }])->where('account_id', $account->id)->where('clinic_id', $clinicID)->first();

            if (count((array)$accountClearentConfig) > 0) {
                $stripeUserID = $accountClearentConfig->merchant_id;
            }
        }
        $this->switchDatabase($account->database_name);
        return $stripeUserID;
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

    public function clearentFailedTransactions($invoice_id, $response)
    {
        ClearentFailedTransaction::create([
            'invoice_id' => $invoice_id ?? 0,
            'clearent_response' => json_encode($response),
            'created' => date('Y-m-d H:i:s')
        ]);
    }

    private function rollbackAppointment($appointment_id)
    {
        if (!empty($appointment_id) && isset($appointment_id)) {
            if (AppointmentReminderLog::where('appointment_id', $appointment_id)->exists()) {
                AppointmentReminderLog::where('appointment_id', $appointment_id)->delete();
            }

            if (SurveySmsLog::where('appointment_id', $appointment_id)->exists()) {
                SurveySmsLog::where('appointment_id', $appointment_id)->delete();
            }

            if (Appointment::where('id', $appointment_id)->exists()) {
                Appointment::where('id', $appointment_id)->delete();
            }
        }
    }
}
