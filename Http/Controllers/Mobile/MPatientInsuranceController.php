<?php


namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\PatientAccount;
use App\Services\PatientInsuranceService;
use App\Validators\PatientInsuranceValidator;
use App\Validators\QuestionnaireConsentValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Auth;

class MPatientInsuranceController extends Controller
{
    /**
     * @SWG\Get(
     *      path="/mobile/get-insurance",
     *      operationId="getInsurance",
     *      summary="Get user's insurance",
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
     *              example="19"
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
     *                              @SWG\Property(property="status", type="string", example="assigned|not assigned"),
     *                              @SWG\Property(property="title", type="string", example="Injectable First consultation"),
     *                              @SWG\Property(property="date", type="timestamp", example="1643985000"),
     *                              @SWG\Property(
     *                                  property="questions",
     *                                  type="array",
     *                                  @SWG\Items(
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
    public function getInsurance(Request $request) :JsonResponse
    {
        $input = $request->all();

        $validatorMsg = QuestionnaireConsentValidator::tasksToCompleteValidate($input);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];

        $user_id = Auth::user()->id;
        $patient_account 	=	PatientAccount::where('patient_user_id', $user_id)->where('account_id',$account_id)->first();
        $patient_id = $patient_account->patient_id;

        $insurance_info = PatientInsuranceService::getPatientInsurance($account_id, $patient_id);

        if($insurance_info){
            return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $insurance_info);
        }

        return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'server_error');
    }

    /**
     * @SWG\Post(
     *      path="mobile/update-insurance",
     *      operationId="updateInsurance",
     *      summary="Update insurance",
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
     *              example="19"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="insurance_provider",
     *          description="Insurance provider",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="Allianz"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="policy_id",
     *          description="Policy id",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="500"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="policy_group",
     *          description="Policy group",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="400"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="phone",
     *          description="Phone number",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="+380971513147"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="name_of_insured",
     *          description="Name of insured",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="Victoro"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="relationship",
     *          description="Relationship type",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="self"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="employer",
     *          description="Employer name",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="Ajay"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="prescription_card",
     *          description="If prescription card",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="no"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="carrier",
     *          description="Account id",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="70"
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
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Items(
     *                      @SWG\Property(
     *                          property="consents",
     *                          type="array",
     *                          @SWG\Items(
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
     *                              @SWG\Property(property="status", type="string", example="assigned|not assigned"),
     *                              @SWG\Property(property="title", type="string", example="Injectable First consultation"),
     *                              @SWG\Property(property="date", type="timestamp", example="1643985000"),
     *                              @SWG\Property(
     *                                  property="questions",
     *                                  type="array",
     *                                  @SWG\Items(
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
     * @param null $id
     * @return JsonResponse
     */
    public function updateInsurance(Request $request)
    {
        $input = $request->all();

        $validatorMsg = PatientInsuranceValidator::updateInsuranceValidate($input);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $user_id = Auth::user()->id;
        $account_id = $input["account_id"];
        $patient_account 	=	PatientAccount::where('patient_user_id', $user_id)->where('account_id',$account_id)->first();

        if (!$patient_account) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'none_existing_patient');
        }

        $patient_id = $patient_account->patient_id;
        $response = PatientInsuranceService::updatePatientInsurance($input, $account_id, $patient_id);

        if($response){
            return $this->sendResponse(Response::HTTP_OK, 'successfully_updated', $response);
        }

        return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'server_error');
    }

}
