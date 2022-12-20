<?php

namespace App\Http\Controllers\Mobile;

use App\AccountClearentConfig;
use App\Clinic;
use App\AccountPrefrence;
use App\Helpers\AccountHelper;
use App\Helpers\BookingHelper;
use App\Helpers\EmailHelper;
use App\Helpers\StripeHelper;
use App\Http\Controllers\BookController;
use App\PosInvoiceItem;
use App\PosTransactionsPayment;
use App\Helpers\SmsHelper;
use App\Http\Controllers\SubcriptionController;
use App\ServiceClinic;
use App\Services\ProviderService;
use App\User;
use App\PatientCardOnFile;
use App\PatientNote;
use App\UserLog;
use App\ProcedurePrescriptionInformation;
use App\Patient;
use App\Product;
use App\ServiceProvider;
use App\PosInvoice;
use App\PosTransaction;
use App\Procedure;
use App\ProcedurePrescription;
use App\AppointmentCancellationTransaction;
use App\AppointmentReminderLog;
use App\Users;
use mpdf;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Auth;
use Validator;
use App\Appointment;
use App\PatientAccount;
use App\Account;
use Mpdf\MpdfException;
use Session;
use Config;
use DateTime;
use URL;
use DB;
use App\Services\AppointmentService;
use App\Services\SalesService;
use App\Services\ProcedureService;
use App\Http\Controllers\Controller;
use App\AppointmentService as AppointmentServiceModel;
use App\Service;
use App\Validators\AppointmentValidator;

class MAppointmentController extends Controller
{

    /**
     * @SWG\Get(
     *      path="/mobile/appointments/{period}",
     *      operationId="getUpcomingAppointments",
     *      summary="Get user's upcoming appointments",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="account_id",
     *          description="Id of patient's account",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Upcoming appointments info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="array",
     *                  @SWG\Items(
     *                      type="object",
     *                      @SWG\Property(property="appointment_id", type="string", example="1"),
     *                      @SWG\Property(property="invoice_id", type="string", example="1"),
     *                      @SWG\Property(property="date_time", type="string", example="2 June 2020 3:30 PM"),
     *                      @SWG\Property(property="service_name", type="string", example="Botox/Dysport New Patient"),
     *                      @SWG\Property(property="service_provider_firstname", type="string", example="John"),
     *                      @SWG\Property(property="service_provider_lastname", type="string", example="Smith"),
     *                      @SWG\Property(property="receipt_id", type="string", example="AH34534KJ3L", description="Only in past appointments"),
     *                      @SWG\Property(property="clinic_id", type="string", example="1"),
     *                      @SWG\Property(property="clinic_name", type="string", example="Clinic ABC"),
     *                      @SWG\Property(property="clinic_email", type="string", example="david@evasoft.tech"),
     *                      @SWG\Property(property="clinic_contact_no", type="string", example="Clinic ABC"),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function getAppointments(Request $request, $period): JsonResponse
    {
        $input = $request->all();

        $client_rules = array(
            'account_id' => 'required|numeric',
            'period' => 'required|in:past,upcoming',
        );

        $client_array = array(
            'account_id' => isset($input['account_id']) ? $input['account_id'] : '',
            'period' => isset($period) ? $period : '',
        );

        $validatorMsg = $this->validatorFails($client_rules, $client_array);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        if (isset($input['account_id'])) {
            $account_id = $input['account_id'];
            $account_data = Account::where('id', $account_id)->select('database_name')->get()->toArray();
            $database_name = $account_data[0]['database_name'];

            $patientUserId = Auth::user()->id;

            $patient = PatientAccount::where('patient_user_id', $patientUserId)->where('account_id', $account_id)->first();
            $patient_id = $patient->patient_id;

            #connect account db
            $this->switchDatabase($database_name);

            $appointments = Appointment:: with('users', 'clinic', 'appointment_booking', 'pos_transaction','provider', 'services', 'appointment_services.service')
                ->where('patient_id', $patient_id)
                ->get();

            $data = [];
            foreach ($appointments as $key => $appointment) {
                $todayInClinicTZ = AppointmentService::getTodayClinicTZ($appointment);
//                $appDateTimeInClinicTZ = AppointmentService::getAppDateTimeClinicTZ($appointment);
                $appDateTimeInClinicTZ = $appointment['appointment_datetime'];
                if (($period == 'past' && strtotime($appDateTimeInClinicTZ) >= strtotime($todayInClinicTZ)) ||
                    ($period == 'upcoming' && !(strtotime($appDateTimeInClinicTZ) > strtotime($todayInClinicTZ) && $appointment->status == 'booked'))) {
                    continue;
                }

                $service = $appointment['appointment_services'][0]['service'];
                $service_provider = ServiceProvider::where('user_id', $appointment['user_id'])
                    ->where('service_id', $service['id'])
                    ->select('id')
                    ->get()
                    ->toArray();

                $clinic_timezone = AppointmentService::getClinicTZFromAppointment($appointment);

                $date_time = $appointment['appointment_datetime'];
                $timestamp_date = strtotime($date_time);
                $appointment_data = [
                    'appointment_id' => (string)$appointment['id'],
                    'invoice_id' => $appointment['invoice_id'],
                    'meeting_id' => $appointment['meeting_id'],
                    'date' => $timestamp_date,
                    'clinic_timezone' => $clinic_timezone,
                    'period' => $service['duration'],
                    'service_id' => trim($service['id']),
                    'service_name' => ucfirst(trim($service['name'])),
                    'provider_id' => (string)(isset($service_provider[0]['id']) ? $service_provider[0]['id'] : 0),
                    'clinic_id' => (string)$appointment['clinic_id'],
                    'clinic_name' => $appointment['clinic']['clinic_name'],
                    'clinic_email' => $appointment['clinic']['appointment_notification_emails'],
                    'clinic_contact_no' => $appointment['clinic']['contact_no'],
                    'provider_name' => $appointment['users']['firstname'],
                ];

                if ($period == 'past') {
                    $appointment_data['receipt_id'] = $appointment->pos_transaction['receipt_id'];
                }

                if ($period == 'upcoming') {
                    $telehealth_service_url = config('app.telehealth_url');
                    $appointment_data['meeting_link'] = $appointment_data['meeting_id'] ? $telehealth_service_url.$appointment_data['meeting_id'] : null;
                }

                unset($appointment_data['meeting_id']);
                $data[] = $appointment_data;
            }

            if($period=='upcoming'){
                usort($data, function($a, $b) {
                    $dateTimestamp1 = $a['date'];
                    $dateTimestamp2 = $b['date'];

                    return $dateTimestamp1 < $dateTimestamp2 ? -1 : 1;
                });
            }else{
                usort($data, function($a, $b) {
                    $dateTimestamp1 = $a['date'];
                    $dateTimestamp2 = $b['date'];

                    return $dateTimestamp1 < $dateTimestamp2 ? 1 : -1;
                });
            }

            #connect main db
            $this->switchDatabase();

            return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
        }

        return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'missed_required_fields');
    }

    /**
     * @SWG\Get(
     *      path="/mobile/appointment/detail",
     *      operationId="getAppointmentDetails",
     *      summary="Get appointment info by appointment ID",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="account_id",
     *          description="Id of patient's account",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="appointment_id",
     *          description="Id of the appointment",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Missed appointment ID | Nonexistent appointment with the requested id",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | missed_appointment_id | nonexistent_appointment"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Appointment info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function getAppointmentDetails(Request $request): JsonResponse
    {
        $input = $request->all();

        if (isset($input['account_id']) && isset($input['appointment_id'])) {
            $appointment_id = $input['appointment_id'];
            $account_id = $input['account_id'];

            $account_data = Account::where('id', $account_id)->select('database_name')->get()->toArray();
            $database_name = $account_data[0]['database_name'];

            #connect account db
            $this->switchDatabase($database_name);

            $appointment = Appointment:: with('users', 'clinic', 'appointment_booking', 'services', 'appointment_services.service')->where('id', $appointment_id)->get();

            #connect main db
            $this->switchDatabase();

            if (count($appointment) > 0) {
                return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $appointment);
            }

            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_appointment');
        }

        return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'missed_required_fields');
    }

    /**
     * @SWG\Get(
     *      path="/mobile/appointment/invoices",
     *      operationId="getAppointmentDetails",
     *      summary="Get appointment info by appointment ID",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="account_id",
     *          description="Id of patient's account",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="appointment_id",
     *          description="Id of the appointment",
     *          required=false,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Missed appointment ID | Nonexistent appointment with the requested id | Nonexistent Patient",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | missed_appointment_id | nonexistent_appointment | nonexistent_patient"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Appointment info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *              ),
     *          ),
     *     ),
     * )
     *
     */
    public function getAppointmentInvoices(Request $request)
    {
        $input = $request->all();

        $client_rules = array(
            'account_id' => 'required|numeric',
            'invoice_status' => 'in:null,paid,draft,partial paid',
            'appointment_id' => 'integer'
        );

        $client_array = array(
            'account_id' => isset($input['account_id']) ? $input['account_id'] : '',
            'invoice_status' => isset($input['invoice_status']) ? $input['invoice_status'] : '',
            'appointment_id' => isset($input['appointment_id']) ? $input['appointment_id'] : '',
        );

        $validator = Validator::make($client_array, $client_rules);

        if ($validator->fails() || count($input) == 0) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validator->getMessageBag());
        }

        $appointment_id = isset($input['appointment_id']) ? $input['appointment_id'] : null;
        $invoice_status = isset($input['invoice_status']) ? $input['invoice_status'] : null;

        $account_id = $input['account_id'];
        $account = Account::where('id', $account_id)->first();

        $patientUserId = \Illuminate\Support\Facades\Auth::user()->id;
        $patient_account = PatientAccount::where('patient_user_id', $patientUserId)->where('account_id', $account_id)->first();
        $patient_id = $patient_account['patient_id'];

        if (!$patient_account['id']) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_patient');
        }

        $database_name = $account['database_name'];

        #connect account db
        $this->switchDatabase($database_name);

//        if ($appointment_id) {
//            $patientInvoiceProcedureIds = Procedure::where('patient_id', $patient_id)->where('appointment_id', $appointment_id)->select('id')->get();
//        } else {
//            $patientInvoiceProcedureIds = Procedure::where('patient_id', $patient_id)->select('id')->get();
//        }
//
//        if ($invoice_status) {
//            $PosInvoices = PosInvoice::with(['posInvoiceItems'])->whereIn('procedure_id', $patientInvoiceProcedureIds)->where('is_deleted', 0)->where('patient_id', $patient_id)->where('invoice_status', $invoice_status)->get();
//        } else {
//            $PosInvoices = PosInvoice::with(['posInvoiceItems'])->whereIn('procedure_id', $patientInvoiceProcedureIds)->where('is_deleted', 0)->where('patient_id', $patient_id)->get();
//        }


        if ($appointment_id) {
            $patientInvoiceIds = Appointment::where('patient_id', $patient_id)->where('id', $appointment_id)->select('invoice_id')->get();
        } else {
            $patientInvoiceIds = Appointment::where('patient_id', $patient_id)->select('invoice_id')->get();
        }

        if ($invoice_status) {
            $PosInvoices = PosInvoice::with(['posInvoiceItems'])->whereIn('id', $patientInvoiceIds)->where('is_deleted', 0)->where('patient_id', $patient_id)->where('invoice_status', $invoice_status)->get();
        } else {
            $PosInvoices = PosInvoice::with(['posInvoiceItems'])->whereIn('id', $patientInvoiceIds)->where('is_deleted', 0)->where('patient_id', $patient_id)->get();
        }

        $special_fields = ['created', 'modified', 'payment_datetime'];
        $PosInvoices = mdim_object_map('dateStandartToAmerican', $PosInvoices, $special_fields);

        $PosInvoices=mdimObjectToArray($PosInvoices);
        usort($PosInvoices , function($a, $b) {
            return $a['payment_datetime'] < $b['payment_datetime'] ? 1 : -1;
        });

        #connect main db
        $this->switchDatabase();

        return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $PosInvoices);
    }

    /**
     * @SWG\Get(
     *      path="/mobile/appointment/prescriptions",
     *      operationId="getAppointmentPrescriptions",
     *      summary="Get prescriptions info by appointment ID",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="appointment_id",
     *          description="Id of the appointment",
     *          required=false,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="83"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="account_id",
     *          description="Id of patient's account",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="6818"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Missed appointment ID | Nonexistent appointment with the requested id | Nonexistent Patient",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | missed_appointment_id | nonexistent_appointment | nonexistent_patient"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Prescription info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="array",
     *                  @SWG\Items(
     *                      @SWG\Property(property="id", type="string", example="1"),
     *                      @SWG\Property(property="invoice_id", type="string", example="2"),
     *                      @SWG\Property(property="medicine_name", type="string", example="Vitamin D"),
     *                      @SWG\Property(property="date", type="date", example="2016–10–15"),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     *
     */
    public function getAppointmentPrescriptions(Request $request)
    {
        $input = $request->all();

        if (isset($input['account_id'])) {
            $appointment_id = isset($input['appointment_id']) ? $input['appointment_id'] : null;
            $account_id = $input['account_id'];
            $account = Account::where('id', $account_id)->first();

            $patientUserId = \Illuminate\Support\Facades\Auth::user()->id;
            $patient_account = PatientAccount::where('patient_user_id', $patientUserId)->where('account_id', $account_id)->first();
            $patient_id = $patient_account['patient_id'];

            if (!$patient_account['id']) {
                return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_patient');
            }

            #connect account db
            $database_name = $account['database_name'];
            $this->switchDatabase($database_name);

            if ($appointment_id) {
                $patientProcedureIds = Procedure::where('patient_id', $patient_id)->where('appointment_id', $appointment_id)->select('id')->get();
            } else {
                $patientProcedureIds = Procedure::where('patient_id', $patient_id)->select('id')->get();
            }

            $prescriptions = ProcedurePrescription::join('procedure_prescription_informations', 'procedure_prescriptions.procedure_prescription_information_id', '=', 'procedure_prescription_informations.id')
                ->select('procedure_prescriptions.id', 'procedure_prescriptions.medicine_name', 'procedure_prescription_informations.pdf_file_name', 'procedure_prescription_informations.created')
                ->whereIn('procedure_prescriptions.procedure_id', $patientProcedureIds)
                ->get();

            $invoices = PosInvoice::whereIn('procedure_id', $patientProcedureIds)->select('invoice_number')->get()->toArray();
            $data = [];

            foreach ($prescriptions as $key => $prescription) {
                $date = new DateTime($prescription['created']);
                $datetime = $date->format('m-d-Y');

                $data[] = [
                    'id' => $prescription['id'],
                    'invoice_number' => $invoices[$key]['invoice_number'],
                    'medicine_name' => $prescription['medicine_name'],
                    'date' => $datetime
                ];
            }

            #connect main db
            $this->switchDatabase();

            return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
        }

        return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'missed_required_fields');
    }

    /**
     * @SWG\Get(
     *      path="/mobile/appointment/invoice-PDF",
     *      operationId="getAppointmentInvoicePDF",
     *      summary="get appointment invoice PDF",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="account_id",
     *          description="Id of the account",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="19"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="invoice_number",
     *          description="Number of the invoice",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="AR01300009215"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Nonexistent Patient",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | nonexistent_patient"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Prescription info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="array",
     *                  @SWG\Items(
     *                      @SWG\Property(property="invoice_pdf_url", type="string", example="https://app-stage.aestheticrecord.com/invoice/AR01300009215.pdf"),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     *
     */
    public function getAppointmentInvoicePDF(Request $request)
    {
        $input = $request->all();

        if (isset($input['account_id']) && isset($input['invoice_number'])) {

            $account_id = $input['account_id'];
            $invoice_number = $input['invoice_number'];

            $account = Account::where('id', $account_id)->first();

            # Connect account db
            $database_name = $account['database_name'];
            $this->switchDatabase($database_name);

            # Get Patient by PatientUser and Account
            $patientUserId = Auth::user()->id;
            $patient_account = PatientAccount::where('patient_user_id', $patientUserId)->where('account_id', $account_id)->first();
            $patient_id = $patient_account['patient_id'];
            $patient = Patient::where('id', $patient_id)->first();

            if (!$patient) {
                return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_patient');
            }

            # Get Invoice by code-number
            $invoice_data = PosInvoice::with(['posInvoiceItems'])->where('invoice_number', $invoice_number)->first();

            # Compose filepath
            $invoice_number = $invoice_data->invoice_number;
            $filename = $invoice_number . "_invoice.pdf";

            $dir = public_path() . '/excel/';
            $fpath = $dir . $filename;
            $fileURL = url('/') . '/excel/' . $filename;

            # Check if file already exists
            if (file_exists($fpath)) {
                $data = ['invoice_pdf_url' => $fileURL];
                return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
            }

            # Get first PosInvoice item
            $PosInvoiceItem = $invoice_data['pos_invoice_items'][0];

            $PosInvoiceItem = \App\PosInvoiceItem::where('invoice_id', $invoice_data->id)->first();

            $PosInvoiceItems[] = $PosInvoiceItem;

            # Get PosTransaction by PosInvoiceItem
            $posTransaction = PosTransaction::where('invoice_id', $PosInvoiceItem['id']);

            # Get Patient clinic
            $clinic = Clinic::where('id', $patient->clinic_id)->first();

            # Convert invoice into HTML
            $html = SalesService::exportInvoiceHTML($invoice_data, $PosInvoiceItems, $patient, $posTransaction, $clinic, $account_id, $database_name, '$');

            # Create file folder if it's not exist
            if (!is_dir($dir)) {
                mkdir($dir);
            }

            # Write PDF file
            error_reporting(0);
            $mpdf = new \Mpdf\Mpdf();
            $mpdf->curlAllowUnsafeSslRequests = true;
            $mpdf->WriteHTML($html);
            $mpdf->Output($fpath, 'F');
            $invoice_pdf = url('/') . '/excel/' . $filename;

            $data = ['invoice_pdf_url' => $invoice_pdf];

            return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
        }

        return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'missed_required_fields');
    }

    /**
     * @SWG\Get(
     *      path="/mobile/appointment/prescription-PDF",
     *      operationId="getAppointmentPrescriptionPDF",
     *      summary="get appointment prescription PDF",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="account_id",
     *          description="Id of the account",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="19"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="prescription_id",
     *          description="ID of the prescription",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="1"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Nonexistent Patient",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | nonexistent_patient | nonexistent_procedure"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Prescription info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="array",
     *                  @SWG\Items(
     *                      @SWG\Property(property="prescription_pdf_url", type="string", example="https://app-stage.aestheticrecord.com/excel/234.pdf"),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     *
     */
    public function getAppointmentPrescriptionPDF(Request $request)
    {
        $input = $request->all();

        if (isset($input['account_id']) && isset($input['prescription_id'])) {
            $prescription_id = $input['prescription_id'];
            $account_id = $input['account_id'];

            $account = Account::where('id', $account_id)->with(['AccountPrefrence'])->first();
            $patient = null;
            $clinic = null;

            # Connect account db
            $database_name = $account['database_name'];
            $this->switchDatabase($database_name);

            # Get Patient by PatientUser and Account
            $patientUserId = Auth::user()->id;
            $patient_account = PatientAccount::where('patient_user_id', $patientUserId)->where('account_id', $account_id)->select('patient_id')->first();
            $patient_id = $patient_account['patient_id'];
            $patient = Patient::where('id', $patient_id)->first();
            if (!$patient) {
                return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_patient');
            }

            $procedure_prescription = ProcedurePrescription::where('id', $prescription_id)->select('procedure_id', 'medicine_name', 'form', 'strength', 'quantity', 'frequency', 'dosage', 'refills')->first();
            $procedure_id = $procedure_prescription->procedure_id;

            # Get Prescription by Procedure
            $prescription_info = ProcedurePrescriptionInformation::where('procedure_id', $procedure_id)->with(['procedurePrescription', 'pharmacy'])->first();
            if (!$prescription_info) {
                return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_prescription');
            }
            $prescription_info = $prescription_info->toArray();

            # Check if file already exists
            $db_file_name = $prescription_info['pdf_file_name'];
            $db_file_path = public_path() . '/excel/' . $db_file_name;
            if (file_exists($db_file_path)) {
                $db_file_url = url('/') . '/excel/' . $db_file_name;
                $data = ['prescription_pdf_url' => $db_file_url];
                return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
            }

            # Get Procedure by Prescription
            $procedures = Procedure::where('id', $procedure_id)->where('status', 1)->with('user')->get();

            if (count($procedures) > 0) {
                $patient = DB::connection('juvly_practice')->table('patients as pts')
                    ->join('patient_insurances as pti', 'pts.id',  '=', 'pti.patient_id')->where('pts.id', $procedures[0]['patient_id'])->first();

                $clinic = Clinic::where('id', $procedures[0]['clinic_id'])->select('id', 'clinic_name')->first();
            } else {
                return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_procedure');
            }

            # Compose prescription_info
            $prescription_info['patient'] = null;
            $prescription_info['provider'] = $procedures[0]['user']; /*object*/
            $prescription_info['procedure_date'] = $procedures[0]['procedure_date']; /*object*/
            $prescription_info['clinic_name'] = null;
            $prescription_info['business_name'] = $account['name'];
            $prescription_info['date_format'] = $account['accountPreference']['date_format'];
            $prescription_info['procedure_prescription'] = [0=> $procedure_prescription];

            if ($patient) {
                $prescription_info['patient'] = (array)$patient;
            }

            if ($clinic) {
                $prescription_info['clinic_name'] = $clinic->clinic_name;
            }

            # Convert prescription into HTML
            $html = ProcedureService::exportProcedureHTML($prescription_info);

            # Create file folder if it's not exist
            $dir = public_path() . '/excel/';
            if (!is_dir($dir)) {
                mkdir($dir);
            }

            # Write PDF file
            $filename = 'prescription' . date('mdyhis') . $procedure_id . ".pdf";
            $file_url = AppointmentService::convertHTMLtoPDF($html, $filename);

            # Save new file name into ProcedurePrescriptionInformation
            $info_obj = ProcedurePrescriptionInformation::where('procedure_id', $procedure_id)->first();
            $info_obj->pdf_file_name = $filename;
            $info_obj->save();

            $data = ['prescription_pdf_url' => $file_url];

            return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
        }

        return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'missed_required_fields');
    }

    /**
     * @SWG\Get(
     *      path="/mobile/appointment/receipt-PDF",
     *      operationId="getReceiptPDF",
     *      summary="Get recipe PDF file URL",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="account_id",
     *          description="Id of patient's account",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="receipt_id",
     *          description="Id of receipt",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="2342"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Missed appointment ID | Nonexistent appointment with the requested id",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | missed_appointment_id | nonexistent_appointment"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Clinnics info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(
     *                      property="recipe_pdf_url",
     *                      type="string",
     *                      example="https://app-stage.aestheticrecord.com/invoice/AR01300009215.pdf"
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function getReceiptPDF(Request $request)
    {
        $input = $request->all();

        $client_rules = array(
            'account_id' => 'required|numeric',
            'receipt_id' => 'required',
        );

        $client_array = array(
            'account_id' => isset($input['account_id']) ? $input['account_id'] : '',
            'receipt_id' => isset($input['receipt_id']) ? $input['receipt_id'] : '',
        );

        $validatorMsg = $this->validatorFails($client_rules, $client_array);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $receipt_id = $input['receipt_id'];
        $account_id = $input['account_id'];

        # Get Patient by PatientUser and Account
        $patientUserId = Auth::user()->id;
        $patient_account = PatientAccount::where('patient_user_id', $patientUserId)->where('account_id', $account_id)->first();

        $filename = $receipt_id . "_receipt.pdf";

        # Check if file already exists
        $db_file_path = public_path() . '/excel/' . $filename;

        if (file_exists($db_file_path)) {
            $db_file_url = url('/') . '/excel/' . $filename;
            $data = ['recipe_pdf_url' => $db_file_url];
            return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
        }

        # Export Receipt to HTML
        $html = AppointmentService::exportReceiptHTML($receipt_id, $account_id, $patient_account);

        if ($html==="Nonexistent receipt") {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $html);
        }

        if (!$html) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'Error retrieving');
        }

        # Write PDF file
        $file_url = AppointmentService::convertHTMLtoPDF($html, $filename);

        $data = ['recipe_pdf_url' => $file_url];

        return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
    }

    /**
     * @SWG\Post(
     *      path="/mobile/appointment/cancel/{id}",
     *      operationId="cancelAppointment",
     *      summary="Book appointment",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="account_id",
     *          description="Id of patient's account",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Missed appointment ID | Nonexistent appointment with the requested id",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | missed_appointment_id | nonexistent_appointment"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Appointment canceled",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(property="date", type="timestamp", example="1506512400"),
     *                  @SWG\Property(property="service_name", type="string", example="botox service"),
     *                  @SWG\Property(property="provider_name", type="string", example="Sudheer"),
     *                  @SWG\Property(property="clinic_logo_url", type="string", example="https://dguwz.com/uploads/1582925146_IMG_1279.JPG"),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function cancelAppointment(Request $request, $id = null)
    {
        $input = $request->all();

        $client_rules = array(
            'account_id' => 'required|numeric',
            'id' => 'required|numeric',
        );

        $client_array = array(
            'account_id' => isset($input['account_id']) ? $input['account_id'] : '',
            'id' => isset($id) ? $id : '',
        );

        $validatorMsg = $this->validatorFails($client_rules, $client_array);

        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];

        $account_db = Account::where('id', $account_id)->select('database_name')->first();
        if (!$account_db) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_account');
        }
        $account_data = Account::where('id', $account_id)->get()->toArray();
        $database_name = $account_db['database_name'];
        switchDatabase($database_name);

        $appointment = Appointment:: with('users', 'services', 'appointment_services.service', 'clinic')->where('id', $id)->first();
        if (!$appointment) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_appointment');
        }
        $date = strtotime($appointment->appointment_datetime);
        $service_name = $appointment->appointment_services[0]['service']['name'];
        $provider_name = $appointment->users['firstname'];

        $response_data = [];
        $response_data['date'] = $date;
        $response_data['service_name'] = ucfirst($service_name);
        $response_data['provider_name'] = $provider_name;

        $account_details = Account::with('accountPrefrence')->where('id', $account_id)->first();
        $storagefolder = $account_details->storage_folder;

        $ar_media_path = env('MEDIA_URL');
        $response_data['clinic_logo_url'] = $ar_media_path . $storagefolder . '/admin/' . $account_details->logo;


        $canCharge = false;

        $accPrefs = AccountPrefrence::where('account_id', $account_data[0]['id'])->first();

        $clinic = Clinic:: where('id', $appointment->clinic_id)->first();
        $gatewayType = $account_data[0]['pos_gateway'];
        $apptDateTime = $appointment->appointment_datetime;
        $patID = $appointment->patient_id;

        switchDatabase();
        $userID = Auth::user()->id;

        if (!PatientAccount::where('patient_id', $patID)->where('patient_user_id', $userID)->exists()) {
            return $this->sendResponse(Response::HTTP_FORBIDDEN, "Action forbidden");
        }

        $patient_user = User::where('id', $userID)->first()->toArray();

        switchDatabase($database_name);

        if ($clinic) {
            $timezone = $clinic->timezone;
        } else {
            $timezone = '';
        }

        $clinicTimeZone = isset($timezone) ? $timezone : 'America/New_York';

        $todayInClinicTZ = convertTZ('America/New_York');
        $curDateTimeInApptTZ = convertTZ($clinicTimeZone);

        if ($accPrefs) {
            $daysForCharge = $accPrefs['cancelation_fee_charge_days'];

            $apptDateTimeForCharge = date("Y-m-d H:i:s", strtotime("-" . $daysForCharge . " days", strtotime($apptDateTime)));

            if (strtotime($curDateTimeInApptTZ) > strtotime($apptDateTimeForCharge)) {
                $canCharge = true;
            }
        }

        $account = Account:: find($account_data[0]['id']);
        $appointmentTransaction = AppointmentCancellationTransaction:: where('appointment_id', $id)->where('status', 'authorised')->first();

        if ($appointmentTransaction && $canCharge) {

            if ($clinic) {
                $timezone = $clinic->timezone;
            } else {
                $timezone = '';
            }
            $account_data = $account->toArray();

            if ($gatewayType && $gatewayType == 'stripe') {
                $gatewayResponse = AppointmentService::chargeCustomer($patID, $account_data, $id, $timezone, $appointmentTransaction, 'false', $patient_user);
            } elseif ($gatewayType && $gatewayType == 'clearent') {
                //TODO: no test api key available
                $gatewayResponse = AppointmentService::chargeUsingClearent($request, $appointment, $patID, $account_data, $id, $timezone, $appointmentTransaction, 'false', $patient_user);
            } else {
                $gatewayResponse = AppointmentService::getAprivaAccountDetail($patID, $account_data, $id, $timezone, $appointmentTransaction, "false", $patient_user);
            }
        } else {
            $gatewayResponse = array('status' => 'success', 'msg' => '');
        }

        if (isset($gatewayResponse['status']) && $gatewayResponse['status'] == 'success') {
            $apptOldDateTime = $appointment['appointment_datetime'];
            $appointment->status = 'canceled';

            if ($appointment->save()) {

                if (AppointmentReminderLog::where('appointment_id', $appointment->id)->exists()) {
                    AppointmentReminderLog::where('appointment_id', $appointment->id)->delete();
                }
                $user_log = new UserLog;
                $user_log->user_id = 0;
                $user_log->object = 'appointment';
                $user_log->object_id = $id;
                $user_log->action = 'cancel';
                $user_log->child = 'customer';
                $user_log->child_id = 0;
                $user_log->child_action = null;
                $user_log->created = $todayInClinicTZ;
                $user_log->appointment_datetime = $apptOldDateTime;
                $user_log->save();

                if ($account) {
                    if ($account->appointment_cancel_status == 1) {
                        $smsBody = $account->appointment_canceled_sms;
                        if (SmsHelper::checkSmsLimit($account->id, $account->database_name)) {
                            AppointmentService::sendAppointmentCancelPatientSMS($smsBody, $appointment, $request, $account->id, $patient_user);
                            AppointmentService::sendClinicBookingSMS($appointment, $account, 'cancel', $patient_user['first_name'], $database_name);
                        } elseif (SmsHelper::checkSmsAutofill($account_id) && AccountHelper::paidAccount($account->id) == 'paid') {
                            AppointmentService::sendAppointmentCancelPatientSMS($smsBody, $appointment, $request, $account->id, $patient_user);
                            AppointmentService::sendClinicBookingSMS($appointment, $account, 'cancel', $patient_user['first_name'], $database_name);
                        }

                        $mailBody = $account->appointment_canceled_email;

                        if (EmailHelper::checkEmailLimit($account->id)) {
                            AppointmentService::sendAppointmentCancelPatientMail($mailBody, $account, $appointment, $request, $patient_user);
                        } elseif (EmailHelper::checkEmailAutofill($account->id) && AccountHelper::paidAccount($account->id) == 'paid') {
                            AppointmentService::sendAppointmentCancelPatientMail($mailBody, $account, $appointment, $request, $patient_user);
                        }

                        if (EmailHelper::checkEmailLimit($account->id)) {
                            AppointmentService::sendAppointmentCancelClinicMail($account, $appointment, $request, $patient_user);
                        } elseif (EmailHelper::checkEmailAutofill($account->id) && AccountHelper::paidAccount($account->id) == 'paid') {
                            AppointmentService::sendAppointmentCancelClinicMail($account, $appointment, $request, $patient_user);
                        }
                    }
                }
                $providerID = $appointment->user_id;
                //$this->syncGoogleCalanderEvent($providerID, $appointment, $patID, 'Cancelled');
                $this->deleteGoogleEvent($providerID, $appointment, $patID);
                return $this->sendResponse(Response::HTTP_OK, 'The appointment has been canceled successfully', $response_data);
            } else {
                $response = array('status' => 'error', 'message' => 'Something went Wrong, Please Try Again');
                return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'internal_server_error');
            }
        } else {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'payment_process_error');
        }

    }

    /**
     * @SWG\Post(
     *      path="/mobile/appointment/reschedule/{id}",
     *      operationId="rescheduleAppointment",
     *      summary="Reschedule appointment",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="account_id",
     *          description="Id of patient's account",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="datetime",
     *          description="New appointment datetime timestamp",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="integer",
     *              example="1615561200"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Missed appointment ID | Nonexistent appointment with the requested id | Requested time cannot be used",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | missed_appointment_id | appointment_time_unavailable"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *     @SWG\Response(
     *          response=500,
     *          description="Internal server error",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="We are unable to process your request at this time, please try again later"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Appointment rescheduled successfully",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="appointment_rescheduled_successfully"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(
     *                      property="appointment_id",
     *                      type="string",
     *                      example="73"
     *                  ),
     *                  @SWG\Property(
     *                      property="service_name",
     *                      type="string",
     *                      example="botox service"
     *                  ),
     *                  @SWG\Property(
     *                      property="provider_name",
     *                      type="string",
     *                      example="Sudheer provider"
     *                  ),
     *                  @SWG\Property(
     *                      property="clinic_address",
     *                      type="string",
     *                      example="234 Jones Line, Dallas, TX 34527"
     *                  ),
     *                  @SWG\Property(
     *                      property="old_datetime",
     *                      type="string",
     *                      example="1614499200"
     *                  ),
     *                  @SWG\Property(
     *                      property="new_datetime",
     *                      type="string",
     *                      example="1614531600"
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @param null $id
     * @return JsonResponse
     */
    public function rescheduleAppointment(Request $request, $id = null): JsonResponse
    {
        $input = $request->all();

        $validatorMsg = AppointmentValidator::rescheduleAppointmentValidate($input, $id);

        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $date = date('d/m/Y', $input['datetime']);
        $time = date('H:i', $input['datetime']);

        $account = Account::where('id', $input['account_id'])->first();

        $response = AppointmentService::rescheduleAppointment($id, $date, $time, $account, $account->database_name);

        if(gettype($response)=='array'){
            return $this->sendResponse(Response::HTTP_OK, 'The appointment has been rescheduled successfully', $response);
        }

        switch ($response) {
            case 'action_forbidden':
                return $this->sendResponse(Response::HTTP_BAD_REQUEST, "action_forbidden");
            case 'nonexistent_appointment':
                return $this->sendResponse(Response::HTTP_BAD_REQUEST, "nonexistent_appointment");
            case 'appointment_time_unavailable':
                return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'appointment_time_unavailable');
            default:
                return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, "We are unable to process your request at this time, please try again later");
        }
    }

    /**
     * @SWG\Get(
     *      path="/mobile/appointment/provider-schedule/{id}",
     *      operationId="getAppointmentProviderSchedule",
     *      summary="Get Provider schedule by Appointment",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="account_id",
     *          description="Id of patient's account",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Nonexistent provider with the requested id | Nonexistent service with the requested id | Nonexistent appointment id | This service isn't provided in this clinic",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | nonexistent_provider | nonexistent_appointment | nonexistent_service | service_isn't_provided_in_this_clinic"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Reschedule schedule info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(
     *                      property="days",
     *                      type="array",
     *                      @SWG\Items(
     *                          type="object",
     *                          @SWG\Property(property="date", type="integer", example="1616198400"),
     *                          @SWG\Property(
     *                              property="hours",
     *                              type="array",
     *                              @SWG\Items(
     *                                  type="string",
     *                                  example="12:00",
     *                              ),
     *                          ),
     *                      )
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function getAppointmentProviderSchedule(Request $request, $id = null)
    {
        $input = $request->all();

        $client_rules = array(
            'account_id' => 'required|numeric',
            'id' => 'required|numeric',
        );

        $client_array = array(
            'account_id' => isset($input['account_id']) ? $input['account_id'] : '',
            'id' => $id,
        );

        $validatorMsg = $this->validatorFails($client_rules, $client_array);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];

        $account_data = Account::where('id', $account_id)->select('database_name', 'booking_time_interval')->get()->toArray();
        $database_name = $account_data[0]['database_name'];
        $booking_time_interval = $account_data[0]['booking_time_interval'];
        switchDatabase($database_name);

        $appointmentDetail = Appointment::where('id', $id)->with(['appointment_services'])->first();

        if (!$appointmentDetail) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_appointment');
        }
        $service_id = $appointmentDetail->appointment_services[0]->service_id;
        $clinic_id = $appointmentDetail['clinic_id'];
        $provider_id = $appointmentDetail['user_id'];

        $service_type = Service::where('id', $service_id)->pluck('service_type')->all();
        $service_duration = Service::where('id', $service_id)->pluck('duration')->all();

        if (!$service_type) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, "nonexistent_service");
        }

        if (!ServiceProvider::where('user_id', $provider_id)->exists()) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, "nonexistent_provider");
        }

        if (!ServiceClinic::where('clinic_id', $clinic_id)->where('service_id', $service_id)->exists()) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, "service_isn't_provided_in_this_clinic");
        }

        try {
            $data = ProviderService::getProviderSchedule($provider_id, $clinic_id, $service_type, $service_duration, $booking_time_interval);
        } catch (\Exception $e) {
            return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
        }

        switchDatabase();

        return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
    }


}
