<?php

namespace App\Http\Controllers\Mobile;

use App\Account;
use App\AppointmentTreatmentInstruction;
use App\PostTreatmentInstruction;
use App\PreTreatmentInstruction;
use App\ServicePostTreatmentInstruction;
use App\ServiceProvider;
use App\Patient;
use App\PatientAccount;
use App\Service;
use App\Services\BookService;
use App\Services\ProviderService;
use App\Validators\TreatmentInstructionValidator;
use App\Services\TreatmentInstructionService;
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

class MTreatmentInstructionController extends Controller
{
    /**
     * @SWG\Get(
     *      path="/mobile/treatment-instructions",
     *      operationId="getTreatmentInstruction",
     *      summary="Get TreatmentInstruction",
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
     *          description="Missed required fields | Missed appointment ID",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | missed_appointment_id"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="TreatmentInstructions info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Items(
     *                      @SWG\Property(
     *                          property="pre_treatment_instructions",
     *                          type="array",
     *                          @SWG\Items(
     *                              @SWG\Property(property="id", type="string", example="1"),
     *                              @SWG\Property(property="title", type="string", example="1"),
     *                              @SWG\Property(property="description", type="string", example="1")
     *                          ),
     *                      ),
     *                      @SWG\Property(
     *                          property="post_treatment_instructions",
     *                          type="array",
     *                          @SWG\Items(
     *                              @SWG\Property(property="id", type="string", example="1"),
     *                              @SWG\Property(property="title", type="string", example="1"),
     *                              @SWG\Property(property="description", type="string", example="1")
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
    public function getTreatmentInstructions(Request $request)
    {
        $input = $request->all();

        $validatorMsg = TreatmentInstructionValidator::treatmentInstructionListValidate($input);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];
        $account_data = Account::where('id', $account_id)->select('database_name')->get()->toArray();
        $database_name = $account_data[0]['database_name'];
        $user_id = Auth::user()->id;

        $pre_treatment_instructions = TreatmentInstructionService::getPreTreatmentInstructionsByUser($user_id, $database_name, $account_id);
        $post_treatment_instructions = TreatmentInstructionService::getPostTreatmentInstructionsByUser($user_id, $database_name, $account_id);

        $data = [
            'pre_treatment_instructions' => $pre_treatment_instructions,
            'post_treatment_instructions' => $post_treatment_instructions,
        ];

        return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
    }


}
