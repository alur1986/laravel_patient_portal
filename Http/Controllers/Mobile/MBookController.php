<?php

namespace App\Http\Controllers\Mobile;

use App\Account;
use App\Helpers\BookingHelper;
use App\ServiceProvider;
use App\Patient;
use App\PatientAccount;
use App\Service;
use App\Services\BookService;
use App\Services\ProviderService;
use Auth;
use DB;
use App\ServiceClinic;
use App\Clinic;
use App\ProvidersAdvanceSchedule;
use App\Appointment;
use App\Services\AppointmentService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MBookController extends Controller
{
    /**
     * @SWG\Get(
     *      path="/mobile/booking/clinics",
     *      operationId="getClinics",
     *      summary="Get clinics from account",
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
     *          name="service_type",
     *          description="Service type (virtual or real)",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="virtual"
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
     *          description="Clinics' info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(
     *                      property="clinics",
     *                      type="array",
     *                      @SWG\Items(
     *                         @SWG\Property(property="clinic_id", type="string", example="1"),
     *                         @SWG\Property(property="clinic_name", type="string", example="1"),
     *                      )
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function getClinics(Request $request)
    {
        $input = $request->all();

        $client_rules = array(
            'account_id' => 'required|numeric',
            'service_type' => 'required|in:virtual,real',
        );

        $client_array = array(
            'account_id' => isset($input['account_id']) ? $input['account_id'] : '',
            'service_type' => isset($input['service_type']) ? $input['service_type'] : '',
        );

        $validatorMsg = $this->validatorFails($client_rules, $client_array);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_data = Account::find($input['account_id']);

        $database_name = $account_data->database_name;
        $cancellation_policy_status = BookingHelper::checkCancelationPolicyStatus($account_data);
        $cancellation_policy_text = '';
        if($cancellation_policy_status){
            $cancellation_policy_text = strip_tags($account_data->cancellation_policy);
        }

        #connect account db
        switchDatabase($database_name);

        if($client_array['service_type']=='real'){
            $client_array['service_type']='in_person';
        }

        $service_ids = Service::where('service_type', $client_array['service_type'])
            ->pluck('id')
            ->all();

        $clinic_ids = ServiceClinic::whereIn('service_id', $service_ids)
            ->pluck('clinic_id')
            ->all();

        $clinic_ids = array_unique($clinic_ids);

        $available_clinics_infos = Clinic::whereIn('id', $clinic_ids)->select('id', 'clinic_name')->get()->toArray();
        $clinics = [];

        foreach ($available_clinics_infos as $available_clinics_info) {
            $clinic['clinic_id'] = strval($available_clinics_info['id']);
            $clinic['clinic_name'] = $available_clinics_info['clinic_name'];
            $clinics[] = $clinic;
        }

        $data['clinics'] = $clinics;
        $data['cancellation_policy_status'] = $cancellation_policy_status;
        $data['cancellation_policy_text'] = $cancellation_policy_text;

        switchDatabase();
        return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
    }

    /**
     * @SWG\Post(
     *      path="/mobile/booking/book-first-available",
     *      operationId="bookFirstAvailable",
     *      summary="Book first available appointment",
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
     *          name="clinic_id",
     *          description="Id of clinic",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="2"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="service_id",
     *          description="Id of service",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="2"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Nonexistent service with the requested id | This service isn't provided in this clinic",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | nonexistent_service | service_isn't_provided_in_this_clinic"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="First available provider info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(property="provider_id", type="string", example="1"),
     *                  @SWG\Property(property="first_day", type="integer", example="1615420800"),
     *                  @SWG\Property(property="first_hour", type="string", example="12"),
     *                  @SWG\Property(
     *                      property="days",
     *                      type="array",
     *                      @SWG\Items(
     *                          type="object",
     *                          @SWG\Property(property="day", type="integer", example="1615420800"),
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
    public function bookFirstAvailable(Request $request)
    {
        $input = $request->all();

        $client_rules = array(
            'account_id' => 'required|numeric',
            'clinic_id' => 'required',
            'service_id' => 'required',
        );

        $client_array = array(
            'account_id' => isset($input['account_id']) ? $input['account_id'] : '',
            'clinic_id' => isset($input['clinic_id']) ? $input['clinic_id'] : '',
            'service_id' => isset($input['service_id']) ? $input['service_id'] : '',
        );

        $validatorMsg = $this->validatorFails($client_rules, $client_array);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];
        $clinic_id = $input['clinic_id'];
        $service_id = $input['service_id'];

        $account_data = Account::where('id', $account_id)->select('database_name', 'booking_time_interval')->get()->toArray();
        $database_name = $account_data[0]['database_name'];
        $booking_time_interval = $account_data[0]['booking_time_interval'];
        switchDatabase($database_name);

        $service_type = Service::where('id', $service_id)->pluck('service_type')->all();
        $service_duration = Service::where('id', $service_id)->pluck('duration')->all();
        $service_duration = $service_duration[0];

        if (!$service_type) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, "nonexistent service ");
        }

        if (!ServiceClinic::where('clinic_id', $clinic_id)->where('service_id', $service_id)->exists()) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, "service_isn't_provided_in_this_clinic");
        }

        try {
            $data = ProviderService::getFirstAvailable($clinic_id, $service_id, $service_type, $service_duration, $booking_time_interval);
        } catch (\Exception $e) {
            return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
        }

        switchDatabase();

        if($data=="providers_unavailable"){
            $data = new \stdClass();
            $data->days = [];
        }

        return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
    }

    /**
     * @SWG\Get(
     *      path="/mobile/booking/provider-schedule",
     *      operationId="getProviderSchedule",
     *      summary="Get Provider schedule",
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
     *          name="clinic_id",
     *          description="Id of clinic",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="2"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="provider_id",
     *          description="Id of provider",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="2"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="service_id",
     *          description="Id of service",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="2"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Nonexistent provider with the requested id | Nonexistent service with the requested id | This service isn't provided in this clinic",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | nonexistent_provider | nonexistent_service | service_isn't_provided_in_this_clinic"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Provider schedule info successfully retrieved",
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
    public function getProviderSchedule(Request $request)
    {
        $input = $request->all();

        $client_rules = array(
            'account_id' => 'required|numeric',
            'clinic_id' => 'required',
            'service_id' => 'required',
            'provider_id' => 'required',
        );

        $client_array = array(
            'account_id' => isset($input['account_id']) ? $input['account_id'] : '',
            'clinic_id' => isset($input['clinic_id']) ? $input['clinic_id'] : '',
            'service_id' => isset($input['service_id']) ? $input['service_id'] : '',
            'provider_id' => isset($input['provider_id']) ? $input['provider_id'] : '',
        );

        $validatorMsg = $this->validatorFails($client_rules, $client_array);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];
        $clinic_id = $input['clinic_id'];
        $service_id = $input['service_id'];
        $provider_id = $input['provider_id'];

        $account_data = Account::where('id', $account_id)->select('database_name', 'booking_time_interval')->get()->toArray();
        $database_name = $account_data[0]['database_name'];
        $booking_time_interval = $account_data[0]['booking_time_interval'];
        switchDatabase($database_name);

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

    /**
     * @SWG\Post(
     *      path="/mobile/booking/book-appointment",
     *      operationId="bookAppointment",
     *      summary="Cancel Appointment",
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
     *       @SWG\Parameter(
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
     *          name="clinic_id",
     *          description="Id of clinic",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="2"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="service_id",
     *          description="Id of service",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="2"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="provider_id",
     *          description="Id of provider",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="2"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="appointment_type",
     *          description="Appointment type",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="real"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="datetime",
     *          description="Datetime timestamp",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="integer",
     *              example="1616198400"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | missed_appointment_id | nonexistent_appointment"),
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
     *          description="Appointment booked successfully",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="appointment_booked_successfully"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(
     *                      property="appointment_id",
     *                      type="string",
     *                      example="660"
     *                  ),
     *                  @SWG\Property(
     *                      property="service_name",
     *                      type="string",
     *                      example="service"
     *                  ),
     *                  @SWG\Property(
     *                      property="provider_name",
     *                      type="string",
     *                      example="provider"
     *                  ),
     *                  @SWG\Property(
     *                      property="clinic_address",
     *                      type="string",
     *                      example="234 Jones Line, Dallas, TX 34527"
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function bookAppointment(Request $request)
    {
        $input = $request->all();

        $client_rules = array(
            'account_id' => 'required|numeric',
            'clinic_id' => 'required',
            'service_id' => 'required',
            'provider_id' => 'required',
            'appointment_type' => 'required|in:virtual,real',
            'datetime' => 'required|numeric',
        );

        $client_array = array(
            'account_id' => isset($input['account_id']) ? $input['account_id'] : '',
            'clinic_id' => isset($input['clinic_id']) ? $input['clinic_id'] : '',
            'service_id' => isset($input['service_id']) ? $input['service_id'] : '',
            'provider_id' => isset($input['provider_id']) ? $input['provider_id'] : '',
            'appointment_type' => isset($input['appointment_type']) ? $input['appointment_type'] : '',
            'datetime' => isset($input['datetime']) ? $input['datetime'] : '',
        );

        $validatorMsg = $this->validatorFails($client_rules, $client_array);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];
        $payment_token = isset($input['payment_token']) ? $input['payment_token'] : '';
        $account = Account::where('id', $account_id)->first();

        $patientUserId = Auth::user()->id;
        $patient_account = PatientAccount::where('patient_user_id', $patientUserId)->where('account_id', $account_id)->first();

        if (!isset($patient_account['id'])) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_patient');
        }

        $patient_id = $patient_account['patient_id'];
        $database_name = $account['database_name'];

        $date = date('d/m/Y', $input['datetime']);
        $time = date('H:i', $input['datetime']);

        switchDatabase($database_name);
        $patient = Patient::find($patient_id);

        if (!$patient) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_patient');
        }

        $bookAppointment = [
            'patient_id' => $patient_id,
            'selClinic' => $input['clinic_id'],
            'selService' => $input['service_id'],
            'selDoc' => $input['provider_id'],
            'selDate' => $date,
            'selTime' => $time,
            'selServiceType' => 'service',
            'appointment_type' => $input['appointment_type'],
            'selTimeZone' => isset($input['selTimeZone']) ? $input['selTimeZone'] : 'America/New_York',
            'formData' => [
                'firstname' => $patient->firstname,
                'lastname' => $patient->lastname,
                'email' => $patient->email,
                'phone' => $patient->phoneNumber,
            ],
        ];

        $con = DB::connection('juvly_practice');
        $con->beginTransaction();

        $bookingData = BookService::bookAppointment($account, $patient_id, $bookAppointment, $database_name, $account->pos_gateway, $payment_token);

        if (isset($bookingData['data'])) {
            $con->commit();
            $statusCode = $bookingData['status'] == 'success' ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST;

            return $this->sendResponse($statusCode, $bookingData['message'], $bookingData['data']);
        } else {
            $con->rollBack();
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $bookingData["message"]);
        }

        return $this->sendResponse(Response::HTTP_OK, $data);
    }
}
