<?php

namespace App\Services;

use App\Account;
use App\Appointment;
use App\AppointmentBooking;
use App\AppointmentCancellationTransaction;
use App\Clinic;
use App\Helpers\AccountHelper;
use App\Helpers\TelehealthHelper;
use App\Helpers\EmailHelper;
use App\Helpers\SmsHelper;
use App\Http\Controllers\SubcriptionController;
use App\Patient;
use App\PostTreatmentInstruction;
use App\PreTreatmentInstruction;
use App\ServicePostTreatmentInstruction;
use App\Services\AppointmentService;
use App\Helpers\BookingHelper;
use App\Services\CardService;
use App\Services\PatientService;
use App\User;
use App\Users;
use Exception;
use App\Service;
use DB;
use Auth;
use DateTimeZone;
use DateTime;
use App\ServiceTreatmentInstruction;

class BookService
{
    const STRIPE_PAYMENT = 'stripe';
    const CLEARENT_PAYMENT = 'clearent';
    const APRIVA_PAYMENT = 'apriva';

    protected const PAYMENT_TYPES = [
        self::STRIPE_PAYMENT => 1,
        self::CLEARENT_PAYMENT => 2,
        self::APRIVA_PAYMENT => 3,
    ];

    public static function bookAppointment($account, $patient_id, $bookAppointment, $db, $gatewayType, $paymentToken='')
    {
        try {
            $appointmentType = $bookAppointment['appointment_type'] === Appointment::TYPE_REAL ? Appointment::TYPE_IN_PERSON : Appointment::TYPE_VIRTUAL;

            $providerInfo = Users::where('id', $bookAppointment['selDoc'])->first();
            $clinicInfo = Clinic::where('id', $bookAppointment['selClinic'])->where('is_available_online', 1)->first();

            if (!$providerInfo) {
                return array("status" => "error", "message" => "provider_not_available");
            }

            $ifClinicCanBeUsed = BookingHelper::checkIfClinicCanBeUsed($db, $clinicInfo);
            $IfServOrPackCanBeUsed = BookingHelper::checkIfServOrPackCanBeUsed($db, $bookAppointment);
            $ifProviderCanBeUsed = BookingHelper::checkIfProviderCanBeUsed($providerInfo);
            $isServicePaid = BookingHelper::checkServicePaid($db, $bookAppointment, $appointmentType);
            $isApptTimeUpcoming = BookingHelper::checkIfApptTimeIsUpcoming($bookAppointment);
            $isProviderAvailableAtThatTime = BookingHelper::isProviderAvailableAtThatTime($appointmentType, $account, $db, $bookAppointment);
if (false) {
//            if ($ifProviderCanBeUsed == '0') {
//                $data = array("status" => "error", "message" => "provider_not_available");
//                return $data;
//                exit;
//            } else if ($isApptTimeUpcoming == '0') {
//                $data = array("status" => "error", "message" => "time_is_not_upcoming");
//                return $data;
//                exit;
//            } else if ($isProviderAvailableAtThatTime == '0') {
//                $data = array("status" => "error", "message" => "provider_not_available_time");
//                return $data;
//                exit;
//            }else if ($ifClinicCanBeUsed == '0') {
//                $data = array("status" => "error", "message" => "clinic_not_available");
//                return $data;
//                exit;
//            } else if ($IfServOrPackCanBeUsed == '0') {
//                $data = array("status" => "error", "message" => "serv_or_pack_not_available");
//                return $data;
//                exit;
            } else {
                self::updatePhoneOfPatient($patient_id, $bookAppointment);
                $bookAppointment['patient_id'] = $patient_id;

                $card_save_on_file = false;
                $authorizeCardData = array();
                $needToSaveOnFile = false;
                $accCancellationFee = $account->cancellation_fees;

                $appointmentID = AppointmentService::createNewAppointment($db, $patient_id, $bookAppointment, $account->id);

                if ($appointmentID == 0) {
                    throw new Exception();
                }

                $bookAppointment['appointment_id'] = $appointmentID;

                if (isset($bookAppointment['formData'])) {
                    $canTakePayment = BookingHelper::canUseStripe($account, $bookAppointment);

                    if ($account && $account->pos_enabled && $canTakePayment && !$isServicePaid) {
                        $serviceData = Service::where('id', $bookAppointment['selService'])->first();
                        if ($gatewayType && $gatewayType == self::STRIPE_PAYMENT) {
                            $stripeUserID = BookingHelper::getAccountStripeConfig($account, $bookAppointment);

                            if (strlen($stripeUserID) == 0) {
                                return array("status" => "error", "message" => 'Unable to process: Stripe connection not found');
                            }

                        } elseif ($gatewayType == self::APRIVA_PAYMENT) {

                            $stripeUserID = BookingHelper::getAccountClearenteConfig($account, $bookAppointment);

                            if (strlen($stripeUserID) == 0) {
                                return array("status" => "error", "message" => 'Unable to process: Clearent connection not found');
                            }

                        } else {
                            $stripeUserID = "";
                        }
                        self::createNewPatientAppointmentTransaction($db, $appointmentID, array(), $account, "saved", $stripeUserID);
                        $card_save_on_file = true;

                    } else if ($account && $account->pos_enabled && $canTakePayment) {
                        $serviceData = Service::where('id', $bookAppointment['selService'])->first();

                        if ($serviceData && $serviceData->free_consultation > 0) {
                            $needToSaveOnFile = false;
                        } else if ($serviceData && $serviceData->service_charge_type == 'booking' && $serviceData->price > 0.00 && $serviceData->free_consultation == 0 && $paymentToken) {
                            if ($gatewayType && $gatewayType == self::STRIPE_PAYMENT) {
                                $authorizeCardData = CardService::authorizeCardUsingStripeByToken($account, $paymentToken, $bookAppointment['formData']['email'], 'virtual', 'false', $bookAppointment);
                                self::savePosData($authorizeCardData, $bookAppointment, $account, $gatewayType);
                            } elseif ($gatewayType && $gatewayType == self::APRIVA_PAYMENT) {
                                $authorizeCardData = CardService::authorizeCardUsingClearentByToken($account, $bookAppointment, $patient_id, "new", 'virtual', 'false', $bookAppointment);
                                self::savePosData($authorizeCardData, $bookAppointment, $account, $gatewayType);
                            }

                            if ($authorizeCardData["status"] == "error") {
                                $data = array("status" => $authorizeCardData["status"], "message" => $authorizeCardData["message"]);
                                return $data;
                            } else {
                                $authorizeCardData = $authorizeCardData["data"];

                                if ($authorizeCardData) {
                                    $needToSaveOnFile = true;

                                    if ($gatewayType == self::CLEARENT_PAYMENT) {
                                        $stripeUserID = BookingHelper::getAccountClearenteConfig($account, $bookAppointment);
                                    } else {
                                        $stripeUserID = BookingHelper::getAccountStripeConfig($account, $bookAppointment);
                                    }

                                    if (strlen($stripeUserID) == 0) {
                                        return array("status" => "error", "message" => 'Unable to process: Stripe connection not found');
                                    }

                                    $card_save_on_file = PatientService::saveCard($account, (array)$authorizeCardData, $patient_id, $stripeUserID, $gatewayType);
                                }
                            }
                        } else {
                            $card_save_on_file = true;
                            $service_price = Service::find($bookAppointment['selService'])->price;
                            if(!$service_price){
                                $data = array("status" => "error", "message" => "selected_appointment_not_available");
                                return $data;
                            }
                            $stripeData = new \stdClass();
                            $stripeData->amount = $service_price*100;
                            $stripeData->id = config('app.stripe_id');
                            $stripeArr = ['data'=> $stripeData];
                            self::savePosData($stripeArr, $bookAppointment, $account, $gatewayType);
                        }
                    }
                }

                if ($appointmentID) {
                    $appointmentBookingID = self::createNewAppointmentBooking($db, $appointmentID, $bookAppointment);

                    if ($appointmentBookingID) {
                        $updatedAppointMentID = self::updateBookingInAppointments($appointmentID, $appointmentBookingID);

                        if ($updatedAppointMentID == 0) {
                            throw new Exception();
                        }
                    } else {
                        throw new Exception();
                    }


                    $appointmentServicesStatus = AppointmentService::saveAppointmentServices($db, $appointmentID, $bookAppointment);

                    $bookingPaymentID = BookingHelper::saveBookingsPayment($db, $appointmentID);

                    if (!$appointmentServicesStatus || $bookingPaymentID == 0) {
                        throw new Exception();
                    }

                    if ($gatewayType && $gatewayType == 'stripe') {
                        if ($needToSaveOnFile && $accCancellationFee && $authorizeCardData && $isServicePaid) {
                            $stripeUserID = BookingHelper::getAccountStripeConfig($account, $bookAppointment);

                            if (strlen($stripeUserID) == 0) {
                                return array("status" => "error", "message" => 'Unable to process: Stripe connection not found');
                            }

                            self::createNewPatientAppointmentTransaction($db, $appointmentID, $authorizeCardData, $account, "new", $stripeUserID);
                        }

                    }

                    BookingHelper::saveUserLog($db, $appointmentID, $bookAppointment);

                    if ($bookingPaymentID && $appointmentServicesStatus && $card_save_on_file) {
                        $account_pre = DB::table('account_prefrences')->where('account_id',$account->id)->first();
                        $providerName = $providerInfo->firstname . ' ' . $providerInfo->lastname;

                        /// for inperson
                        if($account->appointment_booking_status == 1 && isset($bookAppointment['selService']) && $appointmentType == "real"){

                            self::sendAllAppointmentBookingSMS($bookAppointment, $account, $appointmentType, $db);

                            self::sendAllAppointmentBookingEmails($bookAppointment, $account, $appointmentType, $db, $account_pre);

                            $covidMailBody			= $account_pre->covid_email_body;
                            $hyphenedDate = str_replace("/", "-", $bookAppointment['selDate']);

                            $aptDateTime			= date('Y-m-d', strtotime($hyphenedDate));

                            date_default_timezone_set("GMT");

                            $today								= date('Y-m-d');
                            $nextDay							= date('Y-m-d', strtotime("+1 day"));

                            if ((strtotime($today) == strtotime($aptDateTime) || strtotime($nextDay) == strtotime($aptDateTime)) && $account_pre->covid_email_status == '1') {
                                date_default_timezone_set("America/New_York");
                                if (EmailHelper::checkEmailLimit($account->id)) {
                                    BookingHelper::sendCovidMail($bookAppointment, $covidMailBody, $account, $db);
                                } elseif(EmailHelper::checkEmailAutofill($account->id) && AccountHelper::paidAccount($account->id) == 'paid' ) {
                                    BookingHelper::sendCovidMail($bookAppointment, $covidMailBody, $account, $db);
                                }
                            }

                        }
                        date_default_timezone_set("America/New_York");
                        /// for virtual
                        if($account['accountCommunication']->appointment_virtual_booking_status == 1 && isset($appointmentData['selService']) && $appointmentType == "virtual"){

                            self::sendAllAppointmentBookingSMS($bookAppointment, $account, $appointmentType, $db);

                            self::sendAllAppointmentBookingEmails($bookAppointment, $account, $appointmentType, $db, $account_pre);

                        }

                        if (count((array)Auth::user())) {
                            self::updateTimeZoneOfUser($bookAppointment);
                        }
                    } else {
                        throw new Exception();
                    }
                }
            }
        } catch (\Exception $e) {
            return false;
        }

        if (!isset($data)) {
            $data = [
                "status" => "success",
                "message" => "appointment_booked_successfully",
                "data" => [
                    'appointment_id' => (string)$appointmentID,
                    'service_name' => ucfirst($serviceData->name),
                    'provider_name' => $providerName,
                    'clinic_address' => $clinicInfo->address
                ]
            ];
        }

        return $data;
    }

    public static function sendAllAppointmentBookingSMS($bookAppointment, $account, $appointmentType, $db){
        if($appointmentType=='real'){
            $smsBody		= $account->appointment_booking_sms;
        }else{
            $smsBody		= $account['accountCommunication']->appointment_virtual_booking_sms;
        }

        if(SmsHelper::checkSmsLimit($account->id)) {
            BookingHelper::sendAppointmentBookingSMS($bookAppointment,$smsBody, $account, $db);
        }elseif(SmsHelper::checkSmsAutofill($account->id) && AccountHelper::paidAccount($account->id) == 'paid' ) {
            BookingHelper::sendAppointmentBookingSMS($bookAppointment,$smsBody, $account, $db);
        }

        if(SmsHelper::checkSmsLimit($account->id)) {
            BookingHelper::sendClinicBookingSMS($bookAppointment, $account, $db);
        }elseif(SmsHelper::checkSmsAutofill($account->id) && AccountHelper::paidAccount($account->id) == 'paid' ) {
            BookingHelper::sendClinicBookingSMS($bookAppointment, $account, $db);
        }
    }

    public static function sendAllAppointmentBookingEmails($bookAppointment, $account, $appointmentType, $db, $account_pre){
        if($appointmentType=='real'){
            $mailBody		= $account->appointment_booking_email;
        }else{
            $mailBody		= $account['accountCommunication']->appointment_virtual_booking_email;
        }

        if(EmailHelper::checkEmailLimit($account->id)) {
        BookingHelper::sendAppointmentBookingPatientMail($bookAppointment,$mailBody,$account, $db);

        }elseif(EmailHelper::checkEmailAutofill($account->id) && AccountHelper::paidAccount($account->id) == 'paid' ) {
        BookingHelper::sendAppointmentBookingPatientMail($bookAppointment,$mailBody,$account, $db);
        }

        if(EmailHelper::checkEmailLimit($account->id)) {
            BookingHelper::sendAppointmentBookingClinicMail($bookAppointment,$account, $db);

        }elseif(EmailHelper::checkEmailAutofill($account->id) && AccountHelper::paidAccount($account->id) == 'paid' ) {
            BookingHelper::sendAppointmentBookingClinicMail($bookAppointment,$account, $db);
        }

        $pre_treatment_body		= $account_pre->pre_treatment_body;
        $pre_tret_subject		= $account_pre->pre_treatment_subject;

        if(EmailHelper::checkEmailLimit($account->id)) {
            BookingHelper::sendPreInstrucionMail($bookAppointment,$pre_treatment_body,$pre_tret_subject,$account, $db);

        }elseif(EmailHelper::checkEmailAutofill($account->id) && AccountHelper::paidAccount($account->id) == 'paid' ) {
            BookingHelper::sendPreInstrucionMail($bookAppointment,$pre_treatment_body,$pre_tret_subject,$account, $db);
        }
    }

    public static function createNewPatientAppointmentTransaction($db, $appointmentID, $authorizeCardData, $account, $cardType, $stripeUserID = "")
    {
        $currentTime = date('Y-m-d H:i:s');
        $currentTime = getCurrentTimeNewYork($currentTime);
        if ($account) {
            $cancellation_fees = $account->cancellation_fees;
            $gatewayType = $account->pos_gateway;

            switchDatabase($db);

            $transaction = new AppointmentCancellationTransaction();
            $transaction->appointment_id = $appointmentID;
            $transaction->status = 'authorised';
            if ($cardType == "new") {
                if ($gatewayType && $gatewayType == 'stripe') {
                    $transaction->authorize_transaction_id = $authorizeCardData->id;
                    $transaction->stripe_user_id = $stripeUserID;
                } elseif ($gatewayType && $gatewayType == self::CLEARENT_PAYMENT) {
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
                $transaction->authorize_transaction_id = '1111111111';

                if ($gatewayType && $gatewayType == 'stripe') {
                    $transaction->stripe_user_id = $stripeUserID;
                } elseif ($gatewayType && $gatewayType == self::CLEARENT_PAYMENT) {
                    $transaction->stripe_user_id = $stripeUserID;
                }
            }
            $transaction->cancellation_fee = $cancellation_fees;
            $transaction->created = $currentTime;
            $transaction->modified = $currentTime;
            $saved = $transaction->save();

            if ($saved) {
                return $transaction->id;
            } else {
                return 0;
            }
        } else {
            return 0;
        }
    }

    private static function savePosData($charge_data, $bookAppointment, $account, $gatewayType = null)
    {

        $input = $bookAppointment['formData'];
        $account_id = $account->id;
        $patient_id = $bookAppointment['patient_id'];
        $clinicID = $bookAppointment['selClinic'];

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
        $currentTime = date('Y-m-d H:i:s');

        $currentTime = getCurrentTimeNewYork($currentTime);

        $service = Service::where('id', $bookAppointment['selService'])->first();

        $posInvoiceData = array(
            'invoice_number' => $invoiceNumber,
            'customerCardBrand' => $customerCardBrand,
            'customerCardLast4' => $customerCardLast4,
            'patient_id' => $patient_id,
            'clinic_id' => $clinicID,
            'sub_total' => $total_amount,
            'total_tax' => 0,
            'total_amount' => $total_amount,
            'treatment_invoice_id' => 0,
            'patient_email' => $input['email'],
            'status' => "paid",
            'created' => $currentTime,
            'paid_on' => $currentTime,
            'product_type' => 'custom',
            'monthly_amount' => 0,
            'one_time_amount' => 0,
            'total_discount' => 0,
            'title' => 'Virtual appointment',
            'platformFee' => $platformFee,
            'apriva_transaction_data' => $apriva_transaction_data

        );
        $posInvoiceData['custom_product_name'] = ucfirst(@$service->name);

        $posInvoiceData['host_transaction_id'] = $host_transaction_id;
        $invoice_id = (new SubcriptionController)->createPosInvoice($posInvoiceData, 'custom', $bookAppointment['selDoc'], 'virtual', $gatewayType);
        Appointment::where('id', $bookAppointment['appointment_id'])->update(['invoice_id' => $invoice_id]);
    }

    public static function createNewAppointmentBooking($db, $appointmentID, $data)
    {
        $aptDateTimeZone = isset($data['selTimeZone']) ? $data['selTimeZone'] : 'America/New_York';

        $todayInAptTimeZone = convertTZ($aptDateTimeZone);

        $bookedBy = 'patient';
        $bookedByUser = 0;

        if (count($data)) {
            if (array_key_exists('appUserID', $data)) {
                $bookedBy = 'staff';
                $bookedByUser = $data['appUserID'];
            }
        }

        $formData = $data['formData'];

        switchDatabase($db);

        $appointmentBooking = new AppointmentBooking();
        $appointmentBooking->appointment_id = $appointmentID;
        $appointmentBooking->booked_by = $bookedBy;
        $appointmentBooking->booked_by_user = $bookedByUser;
        $appointmentBooking->booking_datetime = $todayInAptTimeZone;
        $appointmentBooking->booking_payment_id = 0;
        $appointmentBooking->firstname = $formData['firstname'];
        $appointmentBooking->lastname = $formData['lastname'];
        $appointmentBooking->email = $formData['email'];
        $appointmentBooking->phone = $formData['phone'];
        $appointmentBooking->appointment_notes = htmlentities(addslashes(trim(preg_replace('/\s+/', ' ', @$formData['appointment_notes']))), ENT_QUOTES, 'utf-8');
        $appointmentBooking->created = date('Y-m-d');
        $appointmentBooking->modified = date('Y-m-d');
        $saved = $appointmentBooking->save();

        if ($saved) {
            return $appointmentBooking->id;
        } else {
            return 0;
        }
    }

    public static function updateBookingInAppointments($appointmentID, $appointmentBookingID)
    {
        $update_arr = array(
            'booking_id' => $appointmentBookingID
        );

        $status = Appointment::where('id', $appointmentID)->update($update_arr);

        if ($status) {
            return $appointmentID;
        } else {
            return 0;
        }
    }

    private static function updateTimeZoneOfUser($bookAppointment)
    {
        if (count((array)Auth::user())) {
            $userID = Auth::user()->id;

            $update_arr = array(
                'timezone' => $bookAppointment['selTimeZone']
            );

            $status = User::where('id', $userID)->update($update_arr);

            if ($status) {
                return true;
            } else {
                return false;
            }
        } else {
            return true;
        }
    }

    public static function updatePhoneOfPatient($patientID, $bookAppointment)
    {
        if (isset($bookAppointment['formData']['full_number']) && !empty($bookAppointment['formData']['full_number'])) {
            $update_arr = array(
                'phoneNumber' => $bookAppointment['formData']['full_number']
            );

            $status = Patient::where('id', $patientID)->update($update_arr);

            if ($status) {
                return $patientID;
            } else {
                return 0;
            }
        } else {
            return $patientID;
        }
    }

}
