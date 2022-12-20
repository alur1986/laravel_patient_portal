<?php

namespace App\Http\Controllers\Mobile;

use App\Account;
use App\Helpers\AccountHelper;
use App\Helpers\PatientUserHelper;
use App\PatientMembershipSubscription;
use App\Services\MembershipService;
use App\Validators\MembershipValidator;
use Auth;
use DB;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redirect;

class MMembershipController extends Controller
{
    /**
     * @SWG\Get(
     *      path="/mobile/memberships/active",
     *      operationId="getActiveMemberships",
     *      summary="Get active memberships",
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
     *                  type="array",
     *                  @SWG\Items(
     *                      type="object",
     *                      @SWG\Property(property="membership_id", type="string", example="1"),
     *                      @SWG\Property(property="name", type="string", example="Test Type"),
     *                      @SWG\Property(property="date", type="number", example="1614650765"),
     *                      @SWG\Property(property="frequency", type="string", example="monthly"),
     *                      @SWG\Property(property="payment_amount", type="string", example="2.00"),
     *                      @SWG\Property(property="discount_percentage", type="string", example="10.00"),
     *                      @SWG\Property(property="one_time_setup_fee", type="string", example="100.00"),
     *                      @SWG\Property(property="subscription_valid_upto", type="number", example="1612555588"),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function getActiveMemberships(Request $request)
    {
        $input = $request->all();

        $validatorMsg = MembershipValidator::activeMemberships($input);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];
        $user_id = Auth::user()->id;

        $data = [];
        $activeMemberships = MembershipService::getActiveMemberships($user_id, $account_id);

        foreach ($activeMemberships as $activeMembership) {
            $data[] = [
                "membership_id" => $activeMembership->id,
                "name" => $activeMembership->tier_name,
                "date" => strtotime($activeMembership->signed_on),
                "frequency" => $activeMembership->payment_frequency,
                "payment_amount" => $activeMembership->price_per_month,
                "discount_percentage" => $activeMembership->discount_percentage,
                "one_time_setup_fee" => $activeMembership->one_time_setup_fee,
                "subscription_valid_upto" => strtotime($activeMembership->subscription_valid_upto)
            ];
        }

        return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
    }

    /**
     * @SWG\Get(
     *      path="/mobile/memberships/get-membership-types",
     *      operationId="getMembershipAvailableTypes",
     *      summary="Get available membership types",
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
     *          description="Missed required fields | Missed account ID",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | missed_account_id"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Membership types info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="array",
     *                  @SWG\Items(
     *                      type="object",
     *                      @SWG\Property(property="membership_type_id", type="string", example="1"),
     *                      @SWG\Property(property="membership_type_name", type="string", example="Test Type"),
     *                      @SWG\Property(property="discount_percentage", type="string", example="1.00"),
     *                      @SWG\Property(property="price_per_period", type="string", example="20.00"),
     *                      @SWG\Property(property="one_time_setup_fee", type="string", example="5.00"),
     *                      @SWG\Property(property="membership_payment_options", type="string", example="yearly"),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function getMembershipAvailableTypes(Request $request) : JsonResponse
    {
        $input = $request->all();

        $validatorMsg = MembershipValidator::membershipAvailableTypes($input);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];

        $data = [];
        $membershipTypes = MembershipService::getMembershipAvailableTypes($account_id);

        foreach ($membershipTypes as $membershipType) {
            $membership_payment_options = $membershipType->membership_payment_options;
            $is_year_type = $membership_payment_options === 'year';
            $membership_fees = $is_year_type ? $membershipType->price_per_year : $membershipType->price_per_month;

            $agreement_text = $membershipType->membershipAgreement && $membershipType->membershipAgreement->agreement_text;

            $data[] = [
                "membership_type_id" => (string)$membershipType->id,
                "membership_type_name" => $membershipType->tier_name,
                "agreement_text" => (string)$agreement_text,
                "discount_percentage" => $membershipType->discount_percentage,
                "price_per_period" => $membership_fees,
                "one_time_setup_fee" => $membershipType->one_time_setup_fee,
                "membership_payment_options" => $membershipType->membership_payment_options,
            ];
        }

        return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
    }

    /**
     * @SWG\Get(
     *      path="/mobile/memberships/get-membership-contract",
     *      operationId="getMembershipContract",
     *      summary="Get membership contract PDF url",
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
     *          description="Missed required fields | Missed account ID",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | missed_account_id"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Membership contract url successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(property="membership_contract_url", type="string", example="http://localhost:8000/stripeinvoices/341918840819210303113052.pdf"),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function getMembershipContract(Request $request, $membership_id = 0 )
    {
        $data = array();
        $input 				= $request->all();

        $validatorMsg = MembershipValidator::membershipContract($input);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];
        $user_id = Auth::user()->id;

        $membership_contract_url = MembershipService::getMembershipContract($user_id, $account_id, $membership_id);

        switch($membership_contract_url){
            case "nonexistent":
                return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'nonexistent_membership');
            case "forbidden":
                return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'access_denied');
            default:
                $data['membership_contract_url'] = $membership_contract_url;
                return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $data);
        }
    }

    /**
     * @SWG\Get(
     *      path="/mobile/memberships/create-membership",
     *      operationId="createMembership",
     *      summary="Create a new membership for a patient",
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
     *     @SWG\Parameter(
     *          name="membership_type_id",
     *          description="Id of the requested membership",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="2"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="frequency",
     *          description="Desired frequency",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="year"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="first_name",
     *          description="First name",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="last_name",
     *          description="Last name",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="image_data",
     *          description="Patient signature file",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="38"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="stripeToken",
     *          description="Stripe token",
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
     *          description="Missed required fields | Missed account ID | Nonexistent requested membership id",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | missed_account_id"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="New membership for a patient successfully added",
     *           @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="membership_type_id", type="string", example="2"),
     *              @SWG\Property(property="membership_type_name", type="string", example="Royal Membership"),
     *              @SWG\Property(property="first_name", type="string", example="Ajay"),
     *              @SWG\Property(property="last_name", type="string", example="Lanister"),
     *              @SWG\Property(property="email", type="string", example="ajay@gmail.com"),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function createMembership(Request $request)
    {
        $input 				= $request->all();
        $user_id = Auth::user()->id;

        if($request->isMethod('post')) {
            $validatorMsg = MembershipValidator::membership($input);
            if ($validatorMsg) {
                return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
            }
        }

        $account_id = $input['account_id'];
        $account_preferences = AccountHelper::getAccountPreferences($account_id);

        $account = Account::with('user')->find($account_id);
        $is_pos_enabled = $this->isPosEnabled($account_id);

        if(!$is_pos_enabled){
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'POS disabled, please try later');
        }

        $clinic_id = $account['user']->clinic_id;
        $database_name = AccountHelper::accountDatabaseName($account_id);
        $pos_gateway =  $account->pos_gateway;
        $this->switchDatabase($database_name);

        if(array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $request->ip();
        }

        $data = MembershipService::postCreateMembership($input, $database_name, $ip, $user_id, $account, $account_preferences, $pos_gateway, $clinic_id);

        $res_status = $data['status'] ? Response::HTTP_OK : Response::HTTP_BAD_REQUEST;
        $res_msg = $data['msg'];
        $res_data = isset($data['data']) ? $data['data'] : null;

        return $this->sendResponse($res_status, $res_msg, $res_data);
    }

}
