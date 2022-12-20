<?php


namespace App\Http\Controllers\Mobile;

use App\Account;
use App\Appointment;
use App\AppointmentHealthtimelineConsent;
use App\Helpers\AccountHelper;
use App\Helpers\PatientUserHelper;
use App\Helpers\UploadExternalHelper;
use App\Http\Controllers\Controller;
use App\PatientAccount;
use App\Procedure;
use App\ProcedureHealthtimelineConsent;
use App\Services\QuestionnaireConsentService;
use App\Validators\QuestionnaireConsentValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Auth;

class MQuestionnaireConsentController extends Controller
{
    /**
     * @SWG\Get(
     *      path="/mobile/get-tasks-to-complete",
     *      operationId="getTasksToComplete",
     *      summary="Get Appointment Questionnaires and Consents",
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
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="The account id field is required."),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *     @SWG\Response(
     *          response=200,
     *          description="Tasks to complete successfully retrieved",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Items(
     *                      @SWG\Property(
     *                          property="consents",
     *                          type="array",
     *                          @SWG\Items(
     *                              @SWG\Property(property="id", type="string", example="1"),
     *                              @SWG\Property(property="status", type="string", example="assigned|not assigned"),
     *                              @SWG\Property(property="title", type="string", example="COVID-19 Informed Consent Agreement"),
     *                              @SWG\Property(property="date", type="timestamp", example="1643985000"),
     *                              @SWG\Property(property="service", type="string", example="Botox service"),
     *                              @SWG\Property(property="appointment_id", type="string", example="12"),
     *                              @SWG\Property(property="description", type="string", example="Consent text..."),
     *                          ),
     *                      ),
     *                      @SWG\Property(
     *                          property="questionnaires",
     *                          type="array",
     *                          @SWG\Items(
     *                              @SWG\Property(property="id", type="string", example="1"),
     *                              @SWG\Property(property="status", type="string", example="assigned|not assigned"),
     *                              @SWG\Property(property="title", type="string", example="Injectable First consultation"),
     *                              @SWG\Property(property="date", type="timestamp", example="1643985000"),
     *                              @SWG\Property(
     *                                  property="questions",
     *                                  type="array",
     *                                  @SWG\Items(
     *                                      @SWG\Property(property="id", type="string", example="1"),
     *                                      @SWG\Property(property="title", type="string", example="Do you sleep well?"),
     *                                      @SWG\Property(property="type", type="string", example="multitext"),
     *                                      @SWG\Property(property="multianswers", type="bool", example="true"),
     *                                      @SWG\Property(
     *                                          property="variants",
     *                                          type="array",
     *                                          @SWG\Items(
     *                                              @SWG\Property(property="id", type="string", example="1"),
     *                                              @SWG\Property(property="title", type="string", example="Very well"),
     *                                              @SWG\Property(property="selected", type="string", example="true"),
     *                                          ),
     *                                      ),
     *                                  ),
     *                              ),
     *                          ),
     *                      ),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function getTasksToComplete(Request $request) :JsonResponse
    {
        $input = $request->all();

        $validatorMsg = QuestionnaireConsentValidator::tasksToCompleteValidate($input);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];
        $account_data = Account::where('id', $account_id)->select('database_name')->get()->toArray();
        $database_name = $account_data[0]['database_name'];

        $user_id = Auth::user()->id;
        $patient_account 	=	PatientAccount::where('patient_user_id', $user_id)->where('account_id',$account_id)->first();
        $patient_id = $patient_account->patient_id;

        switchDatabase($database_name);

        $consents = QuestionnaireConsentService::getConsentsByUser($patient_id);
        $questionnaires = QuestionnaireConsentService::getQuestionnairesByUser($account_id, $patient_id);

        $data = [
            'consents' => $consents,
            'questionnaires' => $questionnaires,
        ];

        return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
    }

    /**
     * @SWG\Post(
     *      path="mobile/tasks-to-complete/consent/{id}",
     *      operationId="saveConsent",
     *      summary="Save Consent",
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
     *          description="Account id",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="70"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="appointment_id",
     *          description="Appointment id",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="375"
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="file",
     *          description="Signature image",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="file",
     *              format="binary",
     *              example=""
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Nonexistent appointment with the requested id | Nonexiststent consent",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *     @SWG\Response(
     *          response=500,
     *          description="Internal server error",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="server_error"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Successfully_signed",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_signed"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @param null $id
     * @return JsonResponse
     */
    public function saveConsent(Request $request, $id)
    {
        $input = $request->all();
        $user_id = Auth::user()->id;

        $validatorMsg = QuestionnaireConsentValidator::saveConsentValidate($input);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $appointment_id = $input['appointment_id'];
        $account_id = $input['account_id'];
        $image_data = $input['file'];

        $user_in_appointment = AccountHelper::ifUserInAppointment($user_id, $appointment_id, $account_id);
        if (!$user_in_appointment) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'access_denied');
        }

        $affected_rows = QuestionnaireConsentService::savePatientUserConsent($id, $image_data, $appointment_id, $account_id);

        if($affected_rows){
            return $this->sendResponse(Response::HTTP_OK, 'successfully_signed');
        }

        return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'server_error');
    }

    /**
     * @SWG\Post(
     *      path="mobile/tasks-to-complete/questionnaire/{id}",
     *      operationId="saveQuestionnaire",
     *      summary="Save Questionnaire",
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
     *          description="Account id",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="70"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="appointment_id",
     *          description="Appointment id",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="375"
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="questionnaire_answers",
     *          description="Post questionnaire answers",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="array",
     *              @SWG\Items(
     *                  @SWG\Property(property="id", type="string", example="1"),
     *                  @SWG\Property(
     *                      property="variants",
     *                      type="array",
     *                      @SWG\Items(
     *                          @SWG\Property(property="id", type="string", example="1"),
     *                          @SWG\Property(property="selected", type="bool", example="true"),
     *                      ),
     *                  ),
     *              ),
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields | Nonexiststent questionnaire",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *     @SWG\Response(
     *          response=500,
     *          description="Internal server error",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="server_error"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Successfully_signed",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @param null $id
     * @return JsonResponse
     */
    public function saveQuestionnaire(Request $request, $id)
    {
        $input = $request->all();

        $validatorMsg = QuestionnaireConsentValidator::saveQuestionnaireValidate($input);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $user_id = Auth::user()->id;
        $appointment_id = $input['appointment_id'];
        $account_id = $input['account_id'];

        $appointment_is_exists = AccountHelper::ifUserInAppointment($user_id, $appointment_id, $account_id);
        if (!$appointment_is_exists) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'access_denied');
        }

        $questionnaire_answers = json_decode($input['questionnaire_answers']);

        $database_name = AccountHelper::accountDatabaseName($account_id);
        $affected_rows = QuestionnaireConsentService::savePatientUserQuestionnaire($id, $questionnaire_answers, $appointment_id, $user_id, $account_id, $database_name);

        if($affected_rows){
            return $this->sendResponse(Response::HTTP_OK, 'successfully_updated');
        }

        return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'something_went_wrong');
    }
}
