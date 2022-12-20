<?php

namespace App\Services;

use App\Account;
use App\AccountClearentConfig;
use App\AccountPrefrence;
use App\AccountStripeConfig;
use App\Appointment;
use App\AppointmentCancellationTransaction;
use App\AppointmentReminderConfiguration;
use App\AppointmentReminderLog;
use App\Clinic;
use App\Helpers\AccountHelper;
use App\Helpers\BookingHelper;
use App\Helpers\StripeHelper;
use App\Http\Controllers\BookController;
use App\Http\Controllers\SubcriptionController;
use App\Patient;
use App\PatientAccount;
use App\PatientCardOnFile;
use App\PatientNote;
use App\PosInvoice;
use App\PosInvoiceItem;
use App\PosTransaction;
use App\PosTransactionsPayment;
use App\Service;
use App\ServicePackage;
use App\ServiceSurvey;
use App\SurveySmsLog;
use App\UserLog;
use App\Users;
use App\User;
use DateTime;
use DateTimeZone;
use Auth;
use DB;
use URL;
use Config;
use Illuminate\Http\Response;
use App\Helpers\SmsHelper;
use App\Helpers\EmailHelper;
use App\Helpers\TelehealthHelper;
use App\Traits\Clearent;
use App\AppointmentService as AppointmentServiceModel;

class AppointmentService
{
    use Clearent;
    /**
     * Get today time in Clinic with local TZ
     * @param Appointment $past_appointment
     * @return string
     */
    static public function getTodayClinicTZ(Appointment $past_appointment): string
    {
        $clinicTimeZone = self::getClinicTZFromAppointment($past_appointment);

        date_default_timezone_set($clinicTimeZone);
        $todayDateTime = new DateTime(date('Y-m-d H:i:s'));
        $todayTimeZone = new DateTimeZone($clinicTimeZone);
        $todayDateTime->setTimezone($todayTimeZone);
        date_default_timezone_set('UTC');

        return $todayDateTime->format('Y-m-d H:i:s');
    }

    /**
     * Get today time in Clinic with local TZ
     * @param int $clinic_id
     * @return string
     */
    static public function getTodayClinicTZFromClinicId($clinic_id): string
    {
        $clinicTimeZone = self::getClinicTZFromClinicId($clinic_id);
        date_default_timezone_set($clinicTimeZone);
        $todayDateTime = new DateTime(date('Y-m-d H:i:s'));
        $todayTimeZone = new DateTimeZone($clinicTimeZone);
        $todayDateTime->setTimezone($todayTimeZone);
        date_default_timezone_set('UTC');

        return $todayDateTime->format('Y-m-d H:i:s');
    }

    /**
     * Get Clinic TZ
     * @param int $clinic_id
     * @return string
     */
    static public function getClinicTZFromClinicId($clinic_id): string
    {
        $timezone = Clinic::where('id', $clinic_id)->pluck('timezone');
        //return isset($past_appointment->clinic->timezone) ? $past_appointment->clinic->timezone : 'America/New_York';
        return isset($timezone) ? $timezone[0] : 'America/New_York';
    }

    /**
     * Get date time in Clinic with local TZ
     * @param Appointment $past_appointment
     * @return string
     */
    static public function getAppDateTimeClinicTZ(Appointment $past_appointment): string
    {
        $clinicTimeZone = self::getClinicTZFromAppointment($past_appointment);

        $appDateTime = new DateTime($past_appointment->appointment_datetime);
        $appTimeZone = new DateTimeZone($clinicTimeZone);
        $appDateTime->setTimezone($appTimeZone);
        return $appDateTime->format('Y-m-d H:i:s');
    }

    /**
     * Get Clinic TZ
     * @param Appointment $past_appointment
     * @return string
     */
    static public function getClinicTZFromAppointment(Appointment $past_appointment): string
    {
        return isset($past_appointment->clinic->timezone) ? $past_appointment->clinic->timezone : config('app.default_timezone');
    }

    static public function createNewAppointment($db, $patientID, $data, $account_id = null)
    {
        $appType							= $data['selServiceType'];

        switchDatabase($db);

        $serviceIDs							= implode(',', [$data['selService']]);

        if ( $appType == 'package' ) {
            $duration 						= DB::select("SELECT SUM(duration) AS duration FROM $db.`packages` where id in ($serviceIDs)");
        } else {
            $duration 						= DB::select("SELECT SUM(duration) AS duration FROM $db.`services` where id in ($serviceIDs)");
        }

        $aptDateTime						= date('Y-m-d H:i:s', strtotime(str_replace("/", "-", $data['selDate']) . " " . @$data['selTime']));

        $aptDateTimeZone					= isset($data['selTimeZone']) ? $data['selTimeZone'] : 'America/New_York';
        $clinicID							= $data['selClinic'];
        $appointment_type					= $data['appointment_type'];

        $clinicInfo 						= Clinic::where('id', $clinicID)->first();

        if ( $clinicInfo ) {
            $clinic 						= $clinicInfo->toArray();
            if ( count($clinic) ) {
                $aptDateTimeZone			= $clinic['timezone'];
            }
        }

        $providerID							= $data['selDoc'];

        $MeetingId = $appointment_type===Appointment::TYPE_VIRTUAL ? BookingHelper::getVirtualMeetingId() : '';

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
        //$appointment->modified				= date('Y-m-d');
        $appointment->appointment_type		= $appointment_type;
        $appointment->meeting_id 			= $MeetingId;
        $appointment->meeting_type 			= 'tokbox';
        $appointment->appointment_source 	= 'pportal';

        $system_appointment_datetime = convertTzToSystemTz($aptDateTime,$aptDateTimeZone);

        $appointment->system_appointment_datetime  = $system_appointment_datetime;

        if ( $appType == 'package' ) {
            $appointment->package_id		= $data['selService'][0];
        }

        $saved 								= $appointment->save();

        self::saveAppointmentReminderLogs($appointment->id , $system_appointment_datetime);

        $sms_date = date('Y-m-d H:i:s', strtotime('+'.$appointment->duration.' minutes', strtotime($system_appointment_datetime)));
        self::save_sms_log($patientID, $appointment->id, $sms_date, [$data['selService']]);

        if ( $saved ) {
            if($appointment_type===Appointment::TYPE_VIRTUAL){
                $appointmentData = [];
                $appointmentData['appointment_id'] = $appointment->id;
                TelehealthHelper::addMeetingSessions($account_id, $appointmentData);
            }

            return $appointment->id;
        }

        return 0;

    }

    public static function saveAppointmentServices($db, $appointmentID, $data)
    {
        $status									= false;
        $app_type								= $data['selServiceType'];
        switchDatabase($db);

        \App\AppointmentService::where('appointment_id', $appointmentID)->delete();

        if ( $app_type == 'package' ) {
            $package_id							= $data['selService'][0];
            $servicePackages					= ServicePackage::where('package_id', $package_id)->get();

            if ( count($servicePackages) ) {
                $servicePackages				= $servicePackages->toArray();

                foreach( $servicePackages as $key => $value ) {
                    $packageServiceArr[] 		= $value['service_id'];
                }

            }
            $selSerives							= $packageServiceArr;
        } else {
            $selSerives = $data['selService'];
        }

        if (!is_array($selSerives)) {
            $selSerives = [$selSerives];
        }

        foreach ($selSerives as $service) {
            BookingHelper::savePreAndPostLog($appointmentID, $service);

            $serviceData = Service::where('id', $service)->first();
            $duration = $serviceData->duration;

            $appservice = new \App\AppointmentService();
            $appservice->appointment_id = $appointmentID;
            $appservice->service_id = $service;
            $appservice->duration = $duration;
            $appservice->created = date('Y-m-d');
            $appservice->modified = date('Y-m-d');
            $saved = $appservice->save();

            if ($saved) {
                $status = true;
            }
        }

        return $status;
    }


    public static function saveAppointmentReminderLogs($appointment_id =null, $combinedDT=null) {

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
                    $currentTime	= getCurrentTimeNewYork($currentTime);
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

    public static function save_sms_log($patient_id, $appointmentid, $sms_date, $serviceids){
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

    public static function chargeCustomer($patientID, $account = array(), $appointment_id = null,$timezone = null, $transaction, $rescheduled="false", $patient_user)
    {
        $response  = array();

        if ( $account ) {

            $accountID				= $account['id'];
            $dbname					= $account['database_name'];
            $cardsOnfilesData 		= PatientCardOnFile::where('patient_id', $patientID)->first();
            $accountStripeConfig	= AccountStripeConfig::where('account_id', $accountID)->first();
            if ($accountStripeConfig) {
                if ($cardsOnfilesData) {
                    $customerTokenID		= $cardsOnfilesData["card_on_file"];
                    $host_transaction_id 	= $transaction->authorize_transaction_id;
                    $cancelation_fee		= $transaction->cancellation_fee;
                    $platformFee			= $accountStripeConfig->platform_fee;

                    //~ $stripeUserID			= $accountStripeConfig->stripe_user_id;
                    $stripeUserID			= $transaction->stripe_user_id;
                    $platformFee			= ($cancelation_fee * $platformFee ) / 100;

                    $accountName			= isset($account['name']) && !empty($account['name']) ? $account['name'] : 'Aesthetic Record';
                    $accountName			= substr(strtoupper(str_replace(' ', '', $accountName)), 0, 20);
                    $accountName			= cleanString($accountName);
                    $accountName			= preg_replace('/[^A-Za-z0-9\-]/', '', $accountName);

                    $accountCurrency		= $account['stripe_currency'];


                    $accountName     = (strlen($accountName) > 20) ? substr($accountName,0,20) : $accountName;

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

                    if ( $chargeCustomerResponse && isset($chargeCustomerResponse->id) )
                    {
                        $hostTransactionID 						= $chargeCustomerResponse->id;
                        $currentTime	= date('Y-m-d H:i:s');
                        $currentTime	= getCurrentTimeNewYork($currentTime);
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
                            $transaction->authorize_transaction_id	= '1111111112';
                            $transaction->cancellation_fee			= $cancelation_fee;
                            $transaction->created					= $currentTime;
                            $transaction->modified					= $currentTime;
                            $transaction->stripe_user_id			= $stripeUserID;
                            $saved 									= $transaction->save();
                        }
                        if($hostTransactionID){
                            self::createCancellationInvoice($chargeCustomerResponse, $account, $patientID, $appointment_id, $cancellationTransID);
                        }
                        $response = array('status'=>'success','msg' => 'Transaction successfully completed');
                    } else {
                        self::savePatientNoteIfThereIsError($patientID, '2', $patient_user);
                        $response = array('status'=>'success','msg' => 'Transaction successfully completed');
                    }
                } else {
                    self::savePatientNoteIfThereIsError($patientID, '1', $patient_user);
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

    public static function chargeUsingClearent($request, $appointment, $patientID, $account = array(), $appointment_id = null,$timezone = null, $transaction, $rescheduled="false", $patient_user)
    {
        $ip = null;
        $result_set = [];
        if(array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }

        if ( count($account) > 0 ) {

            $cancellation_fees		= 0;
            $dbname					= $account['database_name'];
            $cancelation_fee 		= $account['cancellation_fees'];
            $clinicID = $appointment->clinic_id;
            $stripeConnectionType	= $account['stripe_connection'];
//            if($stripeConnectionType == 'global'){
//                $clinicID 			= 0;
//            } else {
//                $clinicID 			= $clinicID;
//            }
            $stipeCon				= [
                ['account_id', $account['id']],
                ['clinic_id', $clinicID]
            ];

            $accountClearentConfig	= AccountClearentConfig::where($stipeCon)->first();
            if($accountClearentConfig){
                $accountClearentConfig	= $accountClearentConfig->toArray();
            }else{
                $accountClearentConfig	= array();
            }

            if ( empty($accountClearentConfig) ) {
                $responseArray 			= array("status" => "error", "data" => array(), "message" => "Unable to process: Clearent connection not found");
            }

            $cardsOnfilesData 		= PatientCardOnFile::where('patient_id', $patientID)->first();
            $patient 		= Patient::where('id', $patientID)->first();
            $stripeUserID	= $accountClearentConfig['merchant_id'];
            $platformFee	= $accountClearentConfig['platform_fee'];
//            $bookAppointMent		= Session::get('bookAppointMent');

            if ($cardsOnfilesData) {

                $customerTokenID = $cardsOnfilesData["card_on_file"];
                $cardExpiryDate  = $cardsOnfilesData["card_expiry_date"];
                $cancelation_fee		= $transaction->cancellation_fee;

                $accountName			= !empty($account['name']) ? $account['name'] : 'Aesthetic Record';
                $accountName			= substr(strtoupper(str_replace(' ', '', $accountName)), 0, 20);
                $accountName			= cleanString($accountName);
                $accountName			= preg_replace('/[^A-Za-z0-9\-]/', '', $accountName);

                $accountCurrency		= $account['stripe_currency'];
                $currencyMinAmnt		= StripeHelper::stripeMinimumAmount($accountCurrency);

                $headers = 	[
                    'content-type: application/json',
                    'accept: application/json',
                    'api-key: '.$accountClearentConfig['apikey']
                ];

                $endPoint = env('ClEARENT_PAYMENT_URL').'transactions/sale';
                $invoice_number 		= 'AR00'.$account['id'].'0'.$patientID.'0'.time();

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
                    'software-type' => env('CLEARENT_SOFTWARE_TYPE'),
                    'software-type-version' => env('CLEARENT_SOFTWARE_VERSION'),
                    "client-ip" => isset($ip) ?? null
                );

                $response_data = [];
                $response_data = Clearent::curlRequest($endPoint,$headers,$postData,'POST');

                if( isset($response_data["result"]) && !empty($response_data["result"]) ){
                    $clearent_array = json_decode(json_encode($response_data["result"]), true);
                    if($clearent_array['code'] == 200){
                        $clearent_array['platformFee'] = $platformFee;
                        $hostTransactionID 	= $clearent_array["payload"]["transaction"]["id"];
                        $currentTime	= date('Y-m-d H:i:s');
                        $currentTime	= getCurrentTimeNewYork($currentTime);
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
                            $gatewayType = 'clearent';
                            self::createCancellationInvoice($clearent_array, $account, $patientID, $appointment_id, $cancellationTransID, $gatewayType);
                        }
                        $responseArray = array('status'=>'success','msg' => 'Transaction successfully completed');
                    } else {
                        self::savePatientNoteIfThereIsError($patientID, '2', $patient_user);
                        $responseArray = array('status'=>'success','msg' => 'Transaction successfully completed');
                    }
                } else {
                    self::savePatientNoteIfThereIsError($patientID, '2', $patient_user);
                    $responseArray = array('status'=>'success','msg' => 'Transaction successfully completed');
                }
            } else {
                self::savePatientNoteIfThereIsError($patientID, '1', $patient_user);
                $responseArray = array('status'=>'success','msg' => 'Transaction successfully completed');
            }
        } else {
            $responseArray["message"] 	= "An error occured - " . $result_set["message"];
        }
        return $responseArray;
    }

    public static function getAprivaAccountDetail($patientID, $account = array(),$appointment_id = null,$timezone = null, $transaction, $rescheduled="false", $patient_user)
    {
        $response  = array();

        if ( count($account) > 0 ) {

            $dbname				= $account['database_name'];
            $storagefolder 		= $account['storage_folder'];
            $aprivaProductId 	= $account['pos_product_id'];
            $aprivaClientId		= $account['pos_client_id'];
            $aprivaClientSecret	= $account['pos_secret'];
            $aprivaPlatformKey	= $account['pos_platform_key'];
            $aprivaPosEnabled	= $account['pos_enabled'];
            $uniqueIdentifier	= BookingHelper::generateRandomString(6)."-".BookingHelper::generateRandomString(5)."-".BookingHelper::generateRandomString(4);

            $cardsOnfilesData 	=	PatientCardOnFile::where('patient_id', $patientID)->first();

            if ( count($cardsOnfilesData) ) {
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
                        self::savePatientNoteIfThereIsError($patientID, '2', $patient_user);
                        $response = array('status'=>'success','msg' => 'Transaction successfully completed');
                    }
                    disconnect($access_token);
                } else {
                    $response = array('status'=>'error','msg' => 'Unable to connect with payment gateway, please try again');
                }
            } else {
                self::savePatientNoteIfThereIsError($patientID, '1', $patient_user);
                $response = array('status'=>'success','msg' => 'Transaction successfully completed');
            }
        } else {
            $response = array('status'=>'error','msg' => 'Invalid account detail');
        }
        return $response;
    }

    public static function savePatientNoteIfThereIsError($patientID, $errorType, $patient_user)
    {
        $note	= "Unable to charge Cancellation Fee as Error occurred while charging from Processor";
        if ( $errorType == '1' ) {
            $note	= "Unable to charge Cancellation Fee as No card found in Customer profile";
        }
//        $addedBY						= ucfirst(trim($this->user["firstname"])).' '.ucfirst(trim($this->user["lastname"]));
        $addedBY						= ucfirst($patient_user['first_name']." ".$patient_user['last_name']);
        $patientNote					= new PatientNote();
        $patientNote->user_id			= $patientID;
        $patientNote->patient_id		= $patientID;
        $patientNote->notes				= $note;
        $patientNote->added_by			= $addedBY;
        $patientNote->created			= date("Y-m-d H:i:s");

        $saved 							= $patientNote->save();
    }

    public static function createCancellationInvoice($charge_data, $account, $patient_id, $appointment_id, $cancellationTransID, $pos_gateway = null){
        $account_id = $account['id'];
        $db = $account['database_name'];
        $appointmentDetail = Appointment::where('id',$appointment_id)->with('appointment_services')->first();
        $patient = Patient::where('id',$patient_id)->first();
        $clinicID = $appointmentDetail->clinic_id;
        //~ $service_ids = [];
        //~ if(!empty($appointmentDetail['appointment_services'])){
        //~ foreach($appointmentDetail['appointment_services'] as $appointment_services){
        //~ $service_ids[] = $appointment_services->service_id;
        //~ }
        //~ }
        $invoiceNumber 		= 'AR00'.$account_id.'0'.$patient_id.'0'.time();
        $customerCardBrand = '';
        $customerCardLast4 = '';
        $apriva_transaction_data = null;
        if(!empty($pos_gateway) && $pos_gateway == "clearent"){
            if(!empty($charge_data["payload"]["tokenResponse"]) && isset($charge_data["payload"]["tokenResponse"])){
                $customerCardBrand 	= $charge_data["payload"]["tokenResponse"]["card-type"];
            }else{
                $customerCardBrand = $charge_data["payload"]["transaction"]["card-type"] ?? '';
            }
            $customerCardLast4 	= $charge_data["payload"]["transaction"]["last-four"];
            $total_amount = $charge_data["payload"]["transaction"]["amount"];
            $apriva_transaction_data = json_encode($charge_data["payload"]);
        }else{
            if(isset($charge_data->source->brand)){
                $customerCardBrand 	= $charge_data->source->brand;
            }
            if(isset($charge_data->source->last4)){
                $customerCardLast4 	= $charge_data->source->last4;
            }

            $total_amount = $charge_data->amount / 100;
        }
        $currentTime	= date('Y-m-d H:i:s');

        $currentTime	= getCurrentTimeNewYork($currentTime);

        //TODO: is $charge_data["platformFee"] equivalent to $charge_data->application_fee_amount

        $platformFee = $charge_data->application_fee_amount;
//         $platformFee = $charge_data["platformFee"];   //old solution

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

        if(!empty($pos_gateway) && $pos_gateway == "clearent"){
            $posInvoiceData['host_transaction_id'] = $charge_data["payload"]["transaction"]["id"];
        }else{
            $posInvoiceData['host_transaction_id'] = $charge_data->id;
        }
        $invoice_id  = (new SubcriptionController)->createPosInvoice($posInvoiceData, 'custom', $appointmentDetail->user_id,null,$pos_gateway);
        $posInvoiceData['invoice_id'] = $invoice_id;
        $posInvoiceData['account_id'] = $account_id;
        $posInvoiceData['admin_id'] = $account['admin_id'];

        return self::sendCancellationInvoiceEmail($posInvoiceData);
    }

    public static function sendCancellationInvoiceEmail($posInvoiceData)
    {
        $patientID 		= 	$posInvoiceData['patient_id'];
        $amount 		=	$posInvoiceData['total_amount'];
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
            $ar_media_path = env('MEDIA_URL');
            if(isset($accountData->logo) && $accountData->logo != '') {
                $logo_img_src 		= $ar_media_path.$storagefolder.'/admin/'.$accountData->logo;
            } else {
                $logo_img_src 		= env('NO_LOGO_FOR_PDF');
            }
            $filename		= '';
            $attachments	= null;
            $email_content 	= '';
            $subject 		= "Appointment Cancellation/Rescheduling Charges";
            $data = [];
            $data['invoice_amount'] = $amount;
            $data['invoice_data'] = $Invoices;
            $data['logo_img_src'] = $logo_img_src;
            $data['name'] = env('AR_NAME');
            $data['address'] = env('AR_ADDRESS');
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
            $site_url			= getenv('SITE_URL');


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
                $clinic_location  = implode(",",$clinic_location_tmp);
            }

            //$view 	=  \View::make('subscription.membership_email_template', ['data' => $data]);
            $view 	= \View::make('appointments.cancel_charge_invoice_template', ['data' => $data,'clinic_location' => $clinic_location,'account_logo' => $account_logo, 'site_url' => $site_url,'storage_folder' => $storage_folder,'appointment_status' => $appointment_status, 'clinic_address'=>$clinic_address]);


            $mail_body 	= "Dear $patientName,<br><br>

We noticed you canceled your upcoming appointment. Please see your attached receipt for this one time charge per our cancellation policy. If you would like to reschedule, please contact our practice or rebook online. <br><br>

Sincerely,<br>
$account_name";
            $email_content 		= EmailHelper::getEmailTemplate($mail_body, $accountData, $clinic, $subject);
            if($amount > 0 ){
                $pdf = \PDF::loadView('appointments.cancel_charge_invoice_template', ['data' => $data]);
                $invoive_title 		= rand(10,100).$account_id.$patientID.$invoice_id.rand(10,100).date('ymdhis');
                $dir 			= $media_path.'/stripeinvoices/';
                if (!is_dir($dir)) {
                    mkdir($dir);
                }
                $filename 			= $dir.$invoive_title.".pdf";
                $fpath = $dir . $filename;

                # Check if file already exists
                if (!file_exists($fpath)) {
                    $pdf->save($filename,'F');
                }

                $attachments 	= $media_url.'/stripeinvoices/'.$invoive_title.'.pdf';
            }
            return EmailHelper::sendEmail($from_email, $patientEmail, $replyToEmail, $email_content, $subject, $attachments, $posInvoiceData['invoice_number']);
        }
    }

    public static function sendAppointmentCancelPatientSMS($smsBody,$appointment,$request, $account_id, $patient_user)
    {
        if(!empty($smsBody)) {
            $twilio_response 	= array();
            $database_name 		= $request->session()->get('database');
            config(['database.connections.juvly_practice.database'=> $database_name]);
            $clinic 				= Clinic :: findOrFail($appointment->clinic_id);

            $services = array();

            if(count($appointment->services)>0) {
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
            if(count($location)>0) {
                $clinic_location = implode(",",$location);
            } else {
                $clinic_location = '';
            }
            if(!empty($clinic->email_special_instructions))	{

                $email_special_instructions  = $clinic->email_special_instructions;

            } else {
                $email_special_instructions = '';
            }
            if(!isset($account_id)){
                $getSession 	= Session::all();
                $account_id 	= trim($getSession['account_preference']->account_id);
            }

            $phpDateFormat 	= AccountHelper::phpDateFormat($account_id);

            $time							= date("H:i:s",strtotime($appointment->appointment_datetime));
            $date 							= date($phpDateFormat,strtotime($appointment->appointment_datetime));
            //$appointment_time 				= date('g:i a',strtotime($time));
            $appointment_time 				= changeFormatByPreference(@$time, null, true, true, $account_id);
            $appointment_date_time 			= date('l',strtotime($date)).' '.$date.' @ '.$appointment_time;

            $replace 						= array();
            $replace['PROVIDERNAME'] 		= '';
            $replace['CLINICNAME']			= ucfirst($clinic->clinic_name);
            $replace['APPOINTMENTDATETIME']	= $appointment_date_time;
            //$replace['PATIENTNAME'] 		= ucfirst($this->user->firstname ." ". $this->user->lastname);
            $replace['PATIENTNAME'] 		= $patient_user['first_name'];
            $replace['CLINICLOCATION']		= $clinic_location;
            $replace['CLINICINSTRUCTIONS']	= $email_special_instructions;
            $replace['BOOKEDSERVICES']		= implode(', ',$services);


            $tags							= array();
            $tags['PATIENTNAME'] 			= "{{PATIENTNAME}}";
            $tags['CLINICNAME']				= "{{CLINICNAME}}";
            $tags['APPOINTMENTDATETIME']	= "{{APPOINTMENTDATETIME}}";
            $tags['CLINICLOCATION']			= "{{CLINICLOCATION}}";
            $tags['PROVIDERNAME']			= "{{PROVIDERNAME}}";
            $tags['CLINICINSTRUCTIONS']		= "{{CLINICINSTRUCTIONS}}";
            $tags['BOOKEDSERVICES']				= "{{BOOKEDSERVICES}}";


            foreach( $tags as $key => $val ) {
                if ( $val ) {
                    $smsBody  = str_replace($val,$replace[$key], $smsBody);
                }
            }

            $account		= $request->session()->get('account_detail');

            $logged_in_patient_phone = $patient_user['phone'];

            $to 			 = $logged_in_patient_phone;

            if ( !empty($to) ) {
                $sms_response = SmsHelper::sendSMS($to, $smsBody, $account);
                if($sms_response){
                    if( !SmsHelper::checkSmsLimit($account_id) && SmsHelper::checkSmsAutofill($account_id)){
                        SmsHelper::updateUnbilledSms($account_id);
                    }else{
                        SmsHelper::saveSmsCount($account_id);
                    }
                    return true;
                }else{
                    return true;
                }
            } else {
                return true;
            }
        }
    }

    public static function sendAppointmentCancelPatientMail($mailBody,$account,$appointment,$request, $patient_user)
    {
        $database_name = $request->session()->get('database');
        config(['database.connections.juvly_practice.database'=> $database_name]);
        $clinic 						= Clinic :: findOrFail($appointment->clinic_id);
        $sender	 						= EmailHelper::getSenderEmail($account->id);
        $subject 						= "YOUR APPOINTMENT IS CANCELED!";

        $location 			= array();
        if(!empty($clinic->address)){
            $location[] 		= $clinic->address;
        }
        if (!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
            $location[] 		= $clinic->clinic_city;
        } else if (!empty($clinic->city)){
            $location[] 		= $clinic->city;
        }
        if(count($location)>0) {
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

        if ($accountPrefData) {
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

        $serviceIds = \App\AppointmentService :: where('appointment_id',$appointment->id)->pluck('service_id');

        if(count($serviceIds)>0) {

            $serviceIds = $serviceIds->toArray();
            $allBookedServices 	= Service :: whereIn('id',$serviceIds)->pluck('name');
            $allBookedServices = $allBookedServices->toArray();
            if(count($allBookedServices)>0){
                foreach($allBookedServices as $key => $val){
                    $services[] = ucfirst($val);
                }
            }

        }

        $account_id 	= $account->id;
        $phpDateFormat = AccountHelper::phpDateFormat($account_id);

        $time							= date("H:i:s",strtotime($appointment->appointment_datetime));
        $date 							= date($phpDateFormat,strtotime($appointment->appointment_datetime));
        $appointment_time 				= changeFormatByPreference(@$time, null, true, true, $account_id);
        $appointment_date_time 			= date('l',strtotime($date)).' '.$date.' @ '.$appointment_time;

        $provider = Users :: where('id',@$appointment->user_id)->first();

        if($provider) {

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
        $replace['PATIENTNAME'] 		= ucfirst($patient_user['first_name']);
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

        $tags							=  array();
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

        foreach($tags as $key => $val){
            if($val){
                $mailBody  =	 str_replace($val,$replace[$key], $mailBody);
            }
        }

        $email_content = BookingHelper::getAppointmentEmailTemplate($mailBody,$account,$clinic,$subject,$appointment_header);

        $noReply = getenv('MAIL_FROM_EMAIL');

        $response_data =  EmailHelper::sendEmail($noReply, $patient_user['email'], $sender, $email_content, $subject);

        if($response_data){
            if( !EmailHelper::checkEmailLimit($account_id) && EmailHelper::checkEmailAutofill($account_id)){
                EmailHelper::updateUnbilledEmail($account_id);
            }else{
                EmailHelper::saveEmailCount($account_id);
            }
            return true;
        } else {
            return false;
        }

    }

    public static function sendAppointmentCancelClinicMail($account,$appointment,$request,$patient_user)
    {
        $database_name 					= $request->session()->get('database');

        config(['database.connections.juvly_practice.database'=> $database_name]);

        $clinic 						= Clinic :: findOrFail($appointment->clinic_id);
        $sender	 						= EmailHelper::getSenderEmail($account->id);
        $subject 						= "Appointment Canceled";
        $email_ids 					= explode(",",$clinic->appointment_notification_emails);

        $services					= array();
        if ( isset($appointment->services) ) {
            if ( count($appointment->services->toArray()) ) {
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

        if(count($location)>0) {
            $clinic_location = implode(",",$location);
        } else {
            $clinic_location = '';
        }

        $provider = Users :: where('id',@$appointment->user_id)->first();
        if($provider) {

            if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

                $provider_name = ucfirst($provider->bio_name);
            } else {

                $provider_name = ucfirst($provider->firstname." ".$provider->lastname);
            }

        } else {
            $provider_name='';
        }

        $account_id 	= $account->id;
        $phpDateFormat = AccountHelper::phpDateFormat($account_id);

        $body_content					= Config::get('app.mail_body');
        $mail_body						= $body_content['CANCEL_APPOINTMENT_CLINIC_EMAIL'];
        $time							= date("H:i:s",strtotime($appointment->appointment_datetime));
        $date 							= date($phpDateFormat,strtotime($appointment->appointment_datetime));
        $appointment_time 				= changeFormatByPreference(@$time, null, true, true, $account_id);
        $appointment_date_time 			= date('l',strtotime($date)).' '.$date.' @ '.$appointment_time;

        $client_name = AccountHelper::getUserUsedClientName($account->id);
        $mail_body						= "Appointment canceled by customer using ".ucfirst($client_name)." Portal" . "\n";
        $mail_body						.= ucfirst($client_name)." : " . ucfirst($patient_user['first_name']) . ' ' . ucfirst($patient_user['last_name']) . "\n";
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
        $email_content = BookingHelper::getAppointmentEmailTemplate($mail_body,$account,$clinic,$subject,$appointment_header);
        $noReply = getenv('MAIL_FROM_EMAIL');

        $response_data =  EmailHelper::sendEmail($noReply, $email_ids, $sender, $email_content, $subject);

        if($response_data){
            if( !EmailHelper::checkEmailLimit($account->id) && EmailHelper::checkEmailAutofill($account->id)){
                EmailHelper::updateUnbilledEmail($account->id);
            }else{
                EmailHelper::saveEmailCount($account_id);
            }
            return true;
        } else {
            return false;
        }

    }

    public static function sendAppointmentReschedulePatientMail($mailBody, $account, $appointment, $patient_user, $database_name)
    {
        $cancelation_fee_charge_days	= 0;
        $accountPrefData				= AccountPrefrence::where('account_id', $account->id)->first();

        if ( $accountPrefData ) {
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
        $sender	 						= EmailHelper::getSenderEmail($account->id);
        $subject 						= "YOUR APPOINTMENT IS RESCHEDULED!";

        $location 			= array();
        if(!empty($clinic->address)){
            $location[] 		= $clinic->address;
        }

        if (!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
            $location[] 		= $clinic->clinic_city;
        } else if(!empty($clinic->city)){
            $location[] 		= $clinic->city;
        }

        if(count($location)>0) {
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
        $services = array();

        $serviceIds = AppointmentServiceModel :: where('appointment_id',$appointment->id)->pluck('service_id');

        if(count($serviceIds)>0) {
            $serviceIds = $serviceIds->toArray();
            $allBookedServices 	= Service :: whereIn('id',$serviceIds)->pluck('name');
            $allBookedServices = $allBookedServices->toArray();
            if(count($allBookedServices)>0){
                foreach($allBookedServices as $key => $val){
                    $services[] = ucfirst($val);
                }
            }
        }

        $phpDateFormat = AccountHelper::phpDateFormat($account->id);

        $time							= date("H:i:s",strtotime($appointment->appointment_datetime));
        $date 							= date($phpDateFormat,strtotime($appointment->appointment_datetime));
        //$appointment_time 				= date('g:i a',strtotime($time));
        $appointment_time 				= changeFormatByPreference(@$time, null, true, true, $account->id);
        $appointment_date_time 			= date('l',strtotime($date)).' '.$date.' @ '.$appointment_time;

        $provider = Users :: where('id',@$appointment->user_id)->first();

        if($provider) {

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
        $replace['PATIENTNAME'] 		= ucfirst($patient_user['first_name']);
        $replace['CLINICNAME']			= ucfirst($clinic->clinic_name);
        $replace['CLINICLOCATION']		= $clinic_location;
        $replace['APPOINTMENTDATETIME']	= $appointment_date_time;
        $replace['PROVIDERNAME']		= $provider_name;
        $replace['CLINICINSTRUCTIONS']	= $email_special_instructions;
        $replace['BOOKEDSERVICES']		= implode(', ',$services);
        //$replace['SERVICEINSTRURL']		= implode(', ',$instruction_url);
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
        //$tags['SERVICEINSTRURL']		= "{{SERVICEINSTRURL}}";
        $tags['CANFEECHARGEDAYS']		= "{{CANFEECHARGEDAYS}}";
        $tags['CANCELATIONFEES']		= "{{CANCELATIONFEES}}";
        $tags['BUSINESSNAME']			= "{{BUSINESSNAME}}";
        $tags['CLIENTPATIENTURL']			= "{{CLIENTPATIENTURL}}";

        $replace['MEETINGLINK'] = "";
        $tags['MEETINGLINK']			= "{{MEETINGLINK}}";

        foreach($tags as $key => $val){
            if($val){
                $mailBody  =	 str_replace($val,$replace[$key], $mailBody);
            }
        }
        $email_content = BookingHelper::getAppointmentEmailTemplate($mailBody,$account,$clinic,$subject,$appointment_header);
        $noReply = getenv('MAIL_FROM_EMAIL');

        $response_data = EmailHelper::sendEmail($noReply, $patient_user['email'], $sender, $email_content, $subject);

        if($response_data){
            if( !EmailHelper::checkEmailLimit($account->id) && EmailHelper::checkEmailAutofill($account->id)){
                EmailHelper::updateUnbilledEmail($account->id);
            }else{
                EmailHelper::saveEmailCount($account->id);
            }
            return true;
        } else {
            return false;

        }
    }

    public static function sendAppointmentRescheduleClinicMail($old_appointment_datetime,$account,$appointment, $patient_user, $database_name)
    {
        config(['database.connections.juvly_practice.database'=> $database_name]);

        $clinic 					= Clinic :: findOrFail($appointment->clinic_id);
        $email_ids 				= explode(",", $clinic->appointment_notification_emails);

        $services					= array();
        $services[] = ucfirst($appointment->services->name);

        if(!empty($clinic->address)){
            $location[] 		= $clinic->address;
        }

        if (!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
            $location[] 		= $clinic->clinic_city;
        } else if(!empty($clinic->city)){
            $location[] 		= $clinic->city;
        }
        if(count($location)>0) {
            $clinic_location = implode(",",$location);
        } else {
            $clinic_location = '';
        }

        $provider = Users :: where('id',@$appointment->user_id)->first();
        if($provider) {
            if(!empty($provider->bio_name) && ($provider->bio_name != '')) {
                $provider_name = ucfirst($provider->bio_name);
            } else {
                $provider_name = ucfirst($provider->firstname." ".$provider->lastname);
            }
        } else {
            $provider_name='';
        }

        $phpDateFormat = AccountHelper::phpDateFormat($account->id);

        $sender	 					= 	EmailHelper::getSenderEmail($account->id);
        $subject 					= 	"Appointment Rescheduled";
        $time						= 	date("H:i:s",strtotime($appointment->appointment_datetime));
        $date						= 	date("m/d/Y",strtotime($appointment->appointment_datetime));
        $appointment_time 				= changeFormatByPreference(@$time, null, true, true, $account->id);
        $appointment_date_time 		= 	date('l',strtotime($date)).' '.$date.' @ '.$appointment_time;
        $old_time					= 	date("H:i:s",strtotime($old_appointment_datetime));
        $old_date 					= date($phpDateFormat,strtotime($old_appointment_datetime));
        $old_appointment_time 				= changeFormatByPreference(@$old_time, null, true, true, $account->id);
        $old_appointment_date_time 	= 	date('l',strtotime($old_date)).' '.$old_date.' @ '.$old_appointment_time;

        $client_name = AccountHelper::getUserUsedClientName($account->id);
        $mail_body						= "Appointment Rescheduled by customer using ".ucfirst($client_name)." Portal" . "\n";
        $mail_body						.= ucfirst($client_name)." : " . ucfirst($patient_user['first_name']) . ' ' . ucfirst($patient_user['last_name']) . "\n";
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
        $email_content = BookingHelper::getAppointmentEmailTemplate($mail_body,$account,$clinic,$subject,$appointment_header);
        $noReply = getenv('MAIL_FROM_EMAIL');


        $response_data = EmailHelper::sendEmail($noReply, $email_ids, $sender, $email_content, $subject);

        if($response_data){
            if( !EmailHelper::checkEmailLimit($account->id) && EmailHelper::checkEmailAutofill($account->id)){
                EmailHelper::updateUnbilledEmail($account->id);
            }else{
                EmailHelper::saveEmailCount($account->id);
            }
            return true;
        } else {
            return false;
        }
    }

    public static function rescheduleAppointment($id, $appointment_date, $appointment_time = '00:00', $account = 0, $database_name = 'juvly_master'){
        $userID = Auth::user()->id;

        $patient_user = User::where('id', $userID)->first()->toArray();
        switchDatabase($database_name);

        $appointment 	= 	Appointment :: with('appointment_services')->with('clinic')->find($id);

        if(!$appointment){return 'nonexistent_appointment';}

        switchDatabase();

        $patID = $appointment->patient_id;
        if (!PatientAccount::where('patient_id', $patID)->where('patient_user_id', $userID)->exists()) {
            return "action_forbidden";
        }

        $providerInfo = Users::where('id', $appointment->user_id)->first();
        $providerName = $providerInfo->firstname . ' ' . $providerInfo->lastname;

        switchDatabase($database_name);

        $service					= array();
        $service['id'] = $appointment->appointment_services[0]->service_id;
        $service = Service::find($service['id']);
        $service['name'] = $service->name;

        $patientID					= $appointment->patient_id;
        $clinicID					= $appointment->clinic_id;
        $providerID					= $appointment->user_id;
        $selDate					= $appointment_date;
        $selTime					= $appointment_time;

        $clinic = Clinic::find($clinicID);
        $clinic_address = $clinic->address;

        $appt_type = $appointment->appointment_timezone;
        $bookAppointment = [];
        $bookAppointment['selDoc'] = $providerID;
        $bookAppointment['selClinic'] = $clinicID;
        $bookAppointment['selDate'] = $selDate;
        $bookAppointment['selTime'] = $selTime;
        $bookAppointment['selService'] = $service['id'];

        $accPrefs				= AccountPrefrence::where('account_id', $account['id'])->first();
        $format 	= trim($accPrefs->date_format);

        if ( $format == 'dd/mm/yyyy') {
            $date 		= DateTime::createFromFormat('d/m/Y', $appointment_date);
            $selDate	= $date->format('Y-m-d');
        }
        $selDate = str_replace("/", "-", $selDate);
        $timezone					= $appointment->appointment_timezone;

        $aptDateTime						= date('Y-m-d H:i:s', strtotime($selDate . " " . $selTime));
        $aptDateTimeTimestamp = strtotime($selDate . " " . $selTime);
        $system_appointment_datetime = convertTzToSystemTz($aptDateTime,$timezone);
        $appointment->system_appointment_datetime  = $system_appointment_datetime;

        $gatewayType				= $account['pos_gateway'];

        $isProviderAvailableAtThatTime = BookingHelper::isProviderAvailableAtThatTime($appt_type, $account, $database_name, $bookAppointment);

        $canBeBooked = true;
        if ($isProviderAvailableAtThatTime == '0') {
            $canBeBooked = false;
        }

        if($appointment){

            $canCharge 				= false;

            $apptOldDateTime		= $appointment['appointment_datetime'];

            $clinic					=	Clinic :: where('id',$appointment['clinic_id'])->first();

            if($clinic){
                $timezone	=	$clinic->timezone;
            } else {
                $timezone	=	'';
            }

            $clinicTimeZone			= isset($timezone) ? $timezone : 'America/New_York';
            $todayInClinicTZ		= convertTZ('America/New_York');

            if ( !$canBeBooked) {
                return 'appointment_time_unavailable';
            } else {
                $curDateTimeInApptTZ	= convertTZ($clinicTimeZone);

                if ( $accPrefs ) {
                    $daysForCharge 			= $accPrefs['cancelation_fee_charge_days'];

                    $apptDateTimeForCharge	= date("Y-m-d H:i:s", strtotime("-".$daysForCharge." days", strtotime($apptOldDateTime)));

                    if ( strtotime($curDateTimeInApptTZ) > strtotime($apptDateTimeForCharge) ) {
                        $canCharge = true;
                    }
                }

                $account 				= Account::find($account['id']);
                $appointmentTransaction	= AppointmentCancellationTransaction::where('appointment_id',$id)->where('status','authorised')->first();

                if ($appointmentTransaction && $canCharge) {
                    if ( $gatewayType && $gatewayType == 'stripe' ) {
                        $gatewayResponse =	AppointmentService::chargeCustomer($patientID, $account,$id,$timezone, $appointmentTransaction, "true", $patient_user);
                    }  elseif ( $gatewayType && $gatewayType == 'clearent' ) {
//                        $gatewayResponse =	AppointmentService::chargeUsingClearent($request, $appointment, $patientID, $account, $id, $timezone, $appointmentTransaction, 'true', $patient_user);
                    } else {
                        $gatewayResponse =	AppointmentService::getAprivaAccountDetail($patientID, $account,$id,$timezone, $appointmentTransaction, "true", $patient_user);
                    }
                } else {
                    $gatewayResponse = array('status'=>'success','msg' => '');
                }

                $old_appointment_datetime = $appointment->appointment_datetime;
                $appointment->appointment_datetime = date("Y-m-d",strtotime($selDate))." ".date("H:i:s",strtotime($appointment_time));

                if(isset($gatewayResponse['status']) && $gatewayResponse['status'] == 'success') {

                    if($appointment->save()){
                        if(AppointmentReminderLog::where('appointment_id',$appointment->id)->exists()){
                            AppointmentReminderLog::where('appointment_id',$appointment->id)->delete();
                        }

                        AppointmentService::saveAppointmentReminderLogs($appointment->id , $system_appointment_datetime);

                        $sms_date = date('Y-m-d H:i:s', strtotime('+'.$appointment->duration.' minutes', strtotime($system_appointment_datetime)));

                        AppointmentService::save_sms_log($patientID, $appointment->id, $sms_date, array($service['id']));

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

                        $appointment->services = $service;

                        $account 		=	Account :: find($account['id']);
                        if($account && $account->appointment_reschedule_status) {
                            $smsBody = $account->appointment_reschedule_sms;
                            if (SmsHelper::checkSmsLimit($account['id'])) {
                                AppointmentService::sendAppointmentReschedulePatientSMS($smsBody, $appointment, $account, $patient_user, $database_name);
                                AppointmentService::sendClinicBookingSMS($appointment, $account, "reschedule", $patient_user['first_name'], $database_name);
                            } elseif (SmsHelper::checkSmsAutofill($account['id']) && AccountHelper::paidAccount($account['id']) == 'paid') {
                                AppointmentService::sendAppointmentReschedulePatientSMS($smsBody, $appointment, $account, $patient_user, $database_name);
                                AppointmentService::sendClinicBookingSMS($appointment, $account, "reschedule", $patient_user['first_name'], $database_name);
                            }

                            $mailBody = $account->appointment_reschedule_email;
                            if (EmailHelper::checkEmailLimit($account['id'])) {
                                AppointmentService::sendAppointmentReschedulePatientMail($mailBody, $account, $appointment, $patient_user, $database_name);
                            } elseif (EmailHelper::checkEmailAutofill($account['id']) && AccountHelper::paidAccount($account['id']) == 'paid') {
                                AppointmentService::sendAppointmentReschedulePatientMail($mailBody, $account, $appointment, $patient_user, $database_name);
                            }

                            if (EmailHelper::checkEmailLimit($account['id'])) {
                                AppointmentService::sendAppointmentRescheduleClinicMail($old_appointment_datetime, $account, $appointment, $patient_user, $database_name);
                            } elseif (EmailHelper::checkEmailAutofill($account['id']) && AccountHelper::paidAccount($account['id']) == 'paid') {
                                AppointmentService::sendAppointmentRescheduleClinicMail($old_appointment_datetime, $account, $appointment, $patient_user, $database_name);
                            }
                        }

                        $old_default_timezone = date_default_timezone_get();
                        date_default_timezone_set('UTC');

                        $response_data = [
                            'appointment_id' => $id,
                            'service_name' => ucfirst($service['name']),
                            'provider_name' => $providerName,
                            'clinic_address' => $clinic_address,
                            'old_datetime' => strtotime($apptOldDateTime),
                            'new_datetime' => $aptDateTimeTimestamp,
                        ];

                        date_default_timezone_set($old_default_timezone);

                        return $response_data;
                    } else {
                        return 'server_error';
                    }
                }  else {
                    return 'payment_error';
                }
            }
        }
    }

    public static function sendClinicBookingSMS($appointmentData, $account, $smsType="reschedule", $firstname=null, $database_name="juvly_master")
    {
        $clinic_name 		= '';
        config(['database.connections.juvly_practice.database'=> $database_name]);

        $services					= array();

        if ( isset($appointmentData->services) ) {
            if($smsType=="reschedule"){
                $services[] = ucfirst($appointmentData->services->toArray()['name']);
            }else{
                if ( count($appointmentData->services->toArray()) ) {
                    foreach ($appointmentData->services->toArray() as $service) {
                        $services[] = ucfirst($service['name']);
                    }
                }else{
                    $services[] = ucfirst($appointmentData->appointment_services[0]->service->name);
                }
            }
        }

        $provider = Users :: where('id',@$appointmentData->user_id)->first();

        if($provider) {

            if(!empty($provider->bio_name) && ($provider->bio_name != '')) {

                $provider_name = ucfirst($provider->bio_name);
            } else {

                $provider_name = ucfirst($provider->firstname." ".$provider->lastname);
            }

        } else {
            $provider_name='';
        }



        $clinic 		= Clinic::findOrFail(@$appointmentData->clinic_id);

        if($clinic) {

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
        if(count($location)>0) {
            $clinic_location = implode(",",$location);
        } else {
            $clinic_location = '';
        }

        $phpDateFormat = AccountHelper::phpDateFormat($account->id);

        $dateOfAppt						= explode(' ', $appointmentData->appointment_datetime);
        $apptDate 						= date($phpDateFormat, strtotime($dateOfAppt[0]));
        $client_name = AccountHelper::getUserUsedClientName($account->id);
        if ( $smsType != "reschedule" ) {
            $headingText 	= "Appointment canceled by customer using ".ucfirst($client_name)." Portal";
            $dateTimeText	= "Appt Date Time Was";
        } else {
            $headingText	 = "Appointment rescheduled by customer using ".ucfirst($client_name)." Portal";
            $dateTimeText	= "Appt Date Time";
        }

        //$appointment_time 				= date('g:i a',strtotime(@$appointmentData->appointment_datetime));
        $appointment_time 				= changeFormatByPreference(@$appointmentData->appointment_datetime, null, true, true, $account->id);
        $appointment_date_time			= date('l',strtotime(@$appointmentData->appointment_datetime)).' '.$apptDate.' @ '.$appointment_time;
        $smsBody						=  $headingText . "\n";
        $smsBody						.= ucfirst($client_name)." : " .  ucfirst($firstname) . "\n";
        $smsBody						.= "Provider : " . $provider_name . "\n";
        $smsBody						.= "Clinic : " . $clinic_name . "\n";
        $smsBody						.= "Location : " . $clinic_location . "\n";
        $smsBody						.= $dateTimeText  . " : " . $appointment_date_time . "\n";
        $smsBody						.= "Services : " . implode(', ',$services) . "\n";

        $to 							= $clinic->sms_notifications_phone;

        if ( !empty($to) ) {
            $sms_response = SmsHelper::sendSMS($to, $smsBody, $account);
            if($sms_response){
                if( !SmsHelper::checkSmsLimit($account->id, $database_name) && SmsHelper::checkSmsAutofill($account->id)){
                    SmsHelper::updateUnbilledSms($account->id);
                }else{
                    SmsHelper::saveSmsCount($account->id);
                }
                return true;
            }else{
                return true;
            }

        } else {
            return true;
        }
    }

    public static function sendAppointmentReschedulePatientSMS($smsBody,$appointment, $account, $patient_user, $database_name = 'juvly_master')
    {
        if (empty($smsBody)) {
            return;
        }

        config(['database.connections.juvly_practice.database' => $database_name]);
        $clinic = Clinic:: findOrFail($appointment->clinic_id);
        $location = array();

        $services = array();

        $cancelation_fee_charge_days = 0;
        $accountPrefData = AccountPrefrence::where('account_id', $account->id)->first();

        if ($accountPrefData) {
            $cancelation_fee_charge_days = $accountPrefData['cancelation_fee_charge_days'];
        }

        if ($cancelation_fee_charge_days <= 1) {
            $cancelation_fee_charge_days = '24 Hrs';
        } else {
            $cancelation_fee_charge_days = $cancelation_fee_charge_days . ' Days';
        }

        $cancelation_fees = '$' . number_format(@$account->cancellation_fees, 2);

        $services[] = ucfirst($appointment->services->name);

        if (!empty($clinic->address)) {
            $location[] = $clinic->address;
        }

        if (!empty($clinic->clinic_city) && !empty($clinic->clinic_state) && !empty($clinic->clinic_zipcode)) {
            $location[] = $clinic->clinic_city;
        } else if (!empty($clinic->city)) {
            $location[] = $clinic->city;
        }
        if (count($location) > 0) {
            $clinic_location = implode(",", $location);
        } else {
            $clinic_location = '';
        }

        if (!empty($clinic->email_special_instructions)) {

            $email_special_instructions = $clinic->email_special_instructions;

        } else {
            $email_special_instructions = '';
        }

        $phpDateFormat = AccountHelper::phpDateFormat($account->id);

        $time = date("H:i:s", strtotime($appointment->appointment_datetime));
        $date = date($phpDateFormat, strtotime($appointment->appointment_datetime));
        $appointment_time = changeFormatByPreference(@$time, null, true, true, $account->id);
        $appointment_date_time = date('l', strtotime($date)) . ' ' . $date . ' @ ' . $appointment_time;

        $replace = array();
        $replace['PROVIDERNAME'] = '';
        $replace['CLINICNAME'] = ucfirst($clinic->clinic_name);
        $replace['APPOINTMENTDATETIME'] = $appointment_date_time;
        //	$replace['PATIENTNAME'] 		= ucfirst($this->user->firstname ." ". $this->user->lastname);
        $replace['PATIENTNAME'] = ucfirst($patient_user['first_name']);
        $replace['CLINICLOCATION'] = $clinic_location;
        $replace['CLINICINSTRUCTIONS'] = $email_special_instructions;
        $replace['BOOKEDSERVICES'] = implode(', ', $services);
        //$replace['SERVICEINSTRURL']		= '';
        $replace['CANFEECHARGEDAYS'] = $cancelation_fee_charge_days;
        $replace['CANCELATIONFEES'] = $cancelation_fees;

        $tags = array();
        $tags['PATIENTNAME'] = "{{PATIENTNAME}}";
        $tags['CLINICNAME'] = "{{CLINICNAME}}";
        $tags['APPOINTMENTDATETIME'] = "{{APPOINTMENTDATETIME}}";
        $tags['CLINICLOCATION'] = "{{CLINICLOCATION}}";
        $tags['PROVIDERNAME'] = "{{PROVIDERNAME}}";
        $tags['CLINICINSTRUCTIONS'] = "{{CLINICINSTRUCTIONS}}";
        $tags['BOOKEDSERVICES'] = "{{BOOKEDSERVICES}}";
        //$tags['SERVICEINSTRURL']		= "{{SERVICEINSTRURL}}";
        $tags['CANFEECHARGEDAYS'] = "{{CANFEECHARGEDAYS}}";
        $tags['CANCELATIONFEES'] = "{{CANCELATIONFEES}}";

//            $meeting_id 		=  $appointment->meeting_id;
//            $appointment_type 	= $appointment->appointment_type;
//            if($appointment_type == "virtual") {
//                $meeting_link = getenv('TOPBOX_URL');
//                $replace['MEETINGLINK'] = "\n"."Your Appointment Meeting Link is $meeting_link/client/".$meeting_id;
//
//            }else{
//                $replace['MEETINGLINK'] = "";
//            }
        $replace['MEETINGLINK'] = "";
        $tags['MEETINGLINK'] = "{{MEETINGLINK}}";

        foreach ($tags as $key => $val) {
            if ($val) {
                $smsBody = str_replace($val, $replace[$key], $smsBody);
            }
        }

        $to = $patient_user['phone'];

        if (!empty($to)) {
            $sms_response = SmsHelper::sendSMS($to, $smsBody, $account);

            if ($sms_response) {
                if (!SmsHelper::checkSmsLimit($account->id) && SmsHelper::checkSmsAutofill($account->id)) {
                    SmsHelper::updateUnbilledSms($account->id);
                } else {
                    SmsHelper::saveSmsCount($account->id);
                }
                return true;
            } else {
                return true;
            }
        } else {
            return true;
        }

    }

    public static function exportReceiptHTML($receipt_id, $account_id, $patient_account)
    {
        # Connect account db
        $account = Account::where('id', $account_id)->with(['AccountPrefrence'])->first();
        $database_name = $account['database_name'];
        switchDatabase($database_name);

        $patient_id = $patient_account['patient_id'];
        $patient = Patient::where('id', $patient_id)->first();

        # Get PosTransaction by PosInvoiceItem
        $pos_transaction = PosTransaction::where('receipt_id', $receipt_id)->first();

        if (!$pos_transaction) {
            return "Nonexistent receipt";
        }

        $posTransactionsPayments = PosTransactionsPayment::where('pos_transaction_id', $pos_transaction->id)->get();

        $invoice = PosInvoice::where('id', $pos_transaction->invoice_id)->first();
        $pos_invoice_item = PosInvoiceItem::where('invoice_id', $invoice->id)->first();

        $data = [];

        $data['website'] = $account['website'];

        $data['invoice_data'] = [
            'Patient' => $patient,
            'PosInvoice' => $invoice,
            'PosTransaction' => [
                'PosTransactionsPayments' => $posTransactionsPayments,
            ],
            'PosInvoiceItem' => [[
                'product_type' => $pos_invoice_item->product_type,
                'product_units' => $pos_invoice_item->product_units,
                'total_product_price' => $pos_invoice_item->total_product_price,
                'custom_product_id' => $pos_invoice_item->custom_product_name,
            ]],
        ];

        return view('subscription.procedure_receipt_pdf', $data);
    }

    public static function getAppointmentsByPeriod($patient_id, $additional_relations = [], $period='upcoming')
    {
        $data = array();
        $default_appointment_relations = ['appointment_services.service'];
        $appointment_relations = array_merge($default_appointment_relations, $additional_relations);

        $appointments = Appointment:: with($appointment_relations)
            ->where('patient_id', $patient_id)
            ->where('patient_id', $patient_id)
            ->where('status', 'booked')
            ->orderBy('appointment_datetime', 'ASC')
            ->get()
            ->toArray();

        foreach ($appointments as $appointment) {
            $todayInClinicTZ = AppointmentService::getTodayClinicTZFromClinicId($appointment['clinic_id']);

            if ($period == 'upcoming' ? $appointment['appointment_datetime'] > $todayInClinicTZ : $appointment['appointment_datetime'] <= $todayInClinicTZ) {
                $appointment_data = [];

                $appointment_data['id'] = $appointment['id'];
                $appointment_data['appointment_datetime'] = $appointment['appointment_datetime'];
                $appointment_data['service_id'] = $appointment['appointment_services'][0]['service_id'];
                $appointment_data['service_name'] = ucfirst($appointment['appointment_services'][0]['service']['name']);

                foreach ($additional_relations as $relation) {
                    $appointment_data[$relation] = $appointment[$relation];
                }

                $data[] = $appointment_data;
            }
        }
        return $data;
    }

    public static function convertHTMLtoPDF($html, $filename)
    {
        error_reporting(0);
        $mpdf = new \Mpdf\Mpdf();
        $mpdf->curlAllowUnsafeSslRequests = true;
        $mpdf->WriteHTML($html);
        $dir = public_path() . '/excel/';
        $fpath = $dir . $filename;
        $mpdf->Output($fpath, 'F');
        return url('/') . '/excel/' . $filename;
    }

}

