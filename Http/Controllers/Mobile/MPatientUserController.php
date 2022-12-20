<?php

namespace App\Http\Controllers\Mobile;

use App\Appointment;
use App\Helpers\EmailHelper;
use App\PatientAccount;
use DateTimeImmutable;
use DateTimeZone;
use App\Clinic;
use App\EgiftCardRedemption;
use App\Patient;
use App\Account;
use App\PatientPackage;
use App\PatientWalletRemoval;
use App\PosInvoiceItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Auth;
use App\User;
use DateTime;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Redirect;
use Intervention\Image\ImageManagerStatic as Image;
use phpDocumentor\Reflection\Types\Boolean;
use Validator;
use Session;
use DB;
use URL;
use Config;
use View;
use App\ConvertAttributesTrait;
use App\PatientSmsVerifications;
use App\Services\PatientUserAccountService;
use App\PatientUser;
use App\Http\Controllers\Controller;

class MPatientUserController extends Controller
{
    use ConvertAttributesTrait;

    /**
     * @SWG\Post(
     *      path="/mobile/patient-user/get-user-clinics",
     *      operationId="getUserClinics",
     *      summary="Returns all user clinics",
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
     *      @SWG\Response(
     *          response=200,
     *          description="All user clinics succesfully returned",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="otp_sent_to_sms_success"),
     *              @SWG\Property(
     *                  property="date",
     *                  type="array",
     *                  @SWG\Items(
     *                      type="object",
     *                      @SWG\Property(property="id", type="string", example="1421"),
     *                      @SWG\Property(property="name", type="string", example="Beautiful Aesthetics"),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @return JsonResponse
     */
    public function getUserClinics(): JsonResponse
    {
        #get patient from phone
        $patientUserId = Auth::user()->id;

        #get all patient accounts
        $patientAllAccounts = DB::table('patient_accounts as pa')
            ->join('accounts as a', 'pa.account_id', '=', 'a.id')
            ->where('pa.patient_user_id', '=', $patientUserId)
            ->get();

        return $this->sendResponse(Response::HTTP_OK, 'successfully_retrieved', $patientAllAccounts);
    }

    public function getCurrencySymbol($currency_code)
    {
        $country = \Illuminate\Support\Facades\DB::table('stripe_countries')->where('currency_code', $currency_code)->first();
        return $country->currency_symbol;
    }

    /**
     * @SWG\Post(
     *      path="/mobile/save/profile",
     *      operationId="editMobileProfile",
     *      summary="Update user profile",
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
     *          name="first",
     *          description="User firstname",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="Rahul"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="last",
     *          description="User lastname",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="Dr"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="username",
     *          description="User email",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="email@gmail.com"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="full_number",
     *          description="User phone",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="+380000000000"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="country",
     *          description="User country",
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="US"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="date_of_birth",
     *          description="Date of birth",
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="1997-08-12"
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
     *          response=500,
     *          description="Internal server error",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="internal_server_error"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Profile updated successful",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="profile_updated_successful"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(property="first_name", type="string", example="Rahul"),
     *                  @SWG\Property(property="last_name", type="string", example="Sati"),
     *                  @SWG\Property(property="email", type="string", example="email@gmail.com"),
     *                  @SWG\Property(property="phone", type="string", example="+380000000000"),
     *                  @SWG\Property(property="address", type="string", example="C-86, Pannu Towers, Mohali, India"),
     *                  @SWG\Property(property="date_of_birth", type="string", example="05/05/2000"),
     *                  @SWG\Property(property="full_profile_img_path", type="string", example="http://stage-ar-patient-portal.eba-5uzknmmq.us-west-2.elasticbeanstalk.com/uploads/profiles/1610450241_Sappire.png"),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function editMobileProfile(Request $request): JsonResponse
    {
        $user = Auth::user();
        $user_data = User:: where('id', $user->id)->first();

        $request_data = $request->all();

        $client_rules = array(
            'first' => 'required',
            'last' => 'required',
            'full_number' => 'required',
            'email' => 'required',
            'date_of_birth' => 'required',
            'middle' => 'string',
        );
        $client_array = array(
            'first' => isset($request_data['first']) ? $request_data['first'] : '',
            'last' => isset($request_data['last']) ? $request_data['last'] : '',
            'full_number' => isset($request_data['full_number']) ? $request_data['full_number'] : '',
            'email' => isset($request_data['username']) ? $request_data['username'] : '',
            'date_of_birth' => isset($request_data['date_of_birth']) ? $request_data['date_of_birth'] : '',
            'middle' => isset($request_data['middle']) ? $request_data['middle'] : '',
        );

        $validator = Validator::make($client_array, $client_rules);

        if ($validator->fails()) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validator->errors()->messages());
        }

        $patientAllAccounts = DB::table('patient_accounts')
            ->where('patient_user_id', '=', $user->id)->select('patient_id', 'account_id')
            ->get();

        foreach($patientAllAccounts as $patientAccount){
            $account_data = Account::where('id', $patientAccount->account_id)->select('database_name')->get()->toArray();
            $database_name = $account_data[0]['database_name'];
            switchDatabase($database_name);
            DB::purge('juvly_practice');

            $patient = Patient::where('id', $patientAccount->patient_id)->first();

            if (!$patient) {
                switchDatabase();
                continue;
            }

            $patient->firstname = $request_data['first'];
            $patient->lastname = $request_data['last'];
            $patient->middlename = $request_data['middle'];

            $patient->save();

            switchDatabase();
        }

        $date_american_format = str_replace('-', '/', $request_data['date_of_birth']);
        $date_universal_format = date('Y-m-d',strtotime($date_american_format));

        $user_data->firstname = $request_data['first'];
        $user_data->lastname = $request_data['last'];
        $user_data->phone = $request_data['full_number'];
        $user_data->email = $request_data['username'];
        $user_data->date_of_birth = $date_universal_format;
        $user_data->address = isset($request_data['address']) ? $request_data['address'] : $user_data->address;
        $user_data->country = isset($request_data['country']) ? $request_data['country'] : $user_data->country;
        $user_data->pincode = isset($request_data['pincode']) ? $request_data['pincode'] : $user_data->pincode;
        $user_data->state = isset($request_data['state']) ? $request_data['state'] : $user_data->state;
        $user_data->city = isset($request_data['city']) ? $request_data['city'] : $user_data->city;

        if ($user_data->save()) {
            $user_data->date_of_birth = $request_data['date_of_birth'];
            $user_data = mdimObjectToArray($user_data);
            $user_data['middle_name'] = $request_data['middle'];
            $user_data = mdimArrayToObject($user_data);

            return $this->sendResponse(Response::HTTP_OK, 'profile_updated_successful', $user_data);
        }

        return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'internal_server_error');
    }

    /**
     * @SWG\Post(
     *      path="/mobile/save/profile-image",
     *      operationId="editMobileProfileImage",
     *      summary="Update user profile image",
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
     *          name="file",
     *          description="User image",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="file",
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
     *          response=500,
     *          description="Internal server error",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="internal_server_error"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Profile image updated successful",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="profile_image_updated_successfully"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(property="first_name", type="string", example="Rahul"),
     *                  @SWG\Property(property="last_name", type="string", example="Sati"),
     *                  @SWG\Property(property="email", type="string", example="email@gmail.com"),
     *                  @SWG\Property(property="phone", type="string", example="+380000000000"),
     *                  @SWG\Property(property="address", type="string", example="C-86, Pannu Towers, Mohali, India"),
     *                  @SWG\Property(property="full_profile_img_path", type="string", example="http://stage-ar-patient-portal.eba-5uzknmmq.us-west-2.elasticbeanstalk.com/uploads/profiles/1610450241_Sappire.png"),
     *                  @SWG\Property(property="date_of_birth", type="string", example="05/05/2000"),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function editMobileProfileImage(Request $request): JsonResponse
    {
        $user = Auth::user();
        $user_data = User:: where('id', $user->id)->first();

        if (!empty($file = $request->file('file')) && !empty($file->getClientOriginalName())) {
            $originalName = $file->getClientOriginalName();
            $imageRealPath = $file->getRealPath();
            $uploadImage = strtotime(date('Y-m-d H:i:s')) . '_' . trim(str_replace(" ", "_", $originalName));
            $destinationPath = public_path('uploads/profiles');;

            Image::make($imageRealPath)->resize(300, null, function ($constraint) {
                $constraint->aspectRatio();
            })->orientate()->save($destinationPath . '/' . $uploadImage);

            $profile_image = $uploadImage;
            if (!empty($profile_image)) {
                $user_data->profile_pic = $profile_image;
            }

            if ($user_data->save()) {
                return $this->sendResponse(Response::HTTP_OK, 'profile_image_updated_successfully', $user_data->toArray());
            }

            return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'internal_server_error');
        }

        return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'missed_required_fields');
    }

    /**
     * @SWG\Post(
     *      path="/mobile/save/clinic",
     *      operationId="addToClinic",
     *      summary="Add User to Clinic",
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
     *          description="Id of the Account",
     *          required=true,
     *          in="body",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="19"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields | account_not_exists"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=500,
     *          description="Internal server error",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="internal_server_error"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Profile image updated successful",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="clinic_added_successfully"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function addToClinic(Request $request): JsonResponse
    {
        $user = Auth::user();
        $user_data = User:: where('id', $user->id)->first();
        $request_data = $request->all();

        $client_rules = array(
            'account_id' => 'required|numeric',
        );
        $client_array = array(
            'account_id' => isset($request_data['account_id']) ? $request_data['account_id'] : '',
        );

        #validate data
        $validator = Validator::make($client_array, $client_rules);
        if ($validator->fails()) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'missed_required_fields');
        }

        #gets Account by id
        $account = Account::where('id', $request_data['account_id'])->first();
        if (!$account) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'account_not_exists');
        }

        #add PatientUser to Account
        if (PatientUserAccountService::addPatientUserToAccount($user_data, $account)) {
            return $this->sendResponse(Response::HTTP_OK, 'clinic_added_successfully');
        }

        return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'internal_server_error');
    }

    public function sendActivationLink($patient_user_id, $email)
    {
        $sender = EmailHelper::getSenderEmail();
        $activation_key = $this->generateRandomString(30);
        DB::table('patient_users')->where('id', $patient_user_id)->update(['activation_key' => $activation_key]);
        $url = URL::to('/') . '/activate' . '/' . $activation_key;
        $noReply = getenv('MAIL_FROM_EMAIL');
        $subject = "You're almost there! Just confirm your email address.";
        $mail_body = "<p>Thank you for signing up for our Patient Portal. Please click below to verify your registration. <a href='" . $url . "'> <br><br> Activate Your Account</a> <br><br> Once you have done this, you will be able to login and complete any paperwork required before your appointment. See you soon! <br><br> If for any reason you are unable to click above, you can paste the link below in your browser: $url
		  </p>";
        $email_content 		= $this->getDefaultEmailTemplate($mail_body, $subject);

        $response 			= $this->sendEmail($noReply, $email, $sender, $email_content, $subject, false);

        if ($response) {
            return $this->sendResponse(Response::HTTP_OK, 'activation_link_sent');
        }

        return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'unable_to_send_activation_link');
    }

    public function activatePatient(Request $request, $key)
    {
        $patient_user = User::where('activation_key', $key)->with('patientAccountMany')->first();

        if ($patient_user) {
            if ($patient_user->patientAccountMany) {

                foreach($patient_user->patientAccountMany as $patientAccount){

                    $patient_id = $patientAccount->patient_id;
                    $account_id = $patientAccount->account_id;

                    $account = Account:: where('id', $account_id)->first();

                    if ($account) {
                        switchDatabase($account->database_name);
                        $patient = Patient::where('id', $patient_id)->update(array('status' => 0));
                        switchDatabase();
                    }
                    PatientAccount::where('patient_id', $patient_id)->where('account_id', $account_id)->where('patient_user_id', '!=', $patient_user->id)->delete();
                }
            }
            $patient_user->status = 0;
            $patient_user->save();
            return view('errors.activatedEmail_page');
        } else {
            return view('errors.wrong_account_activation');
        }
    }

    /**
     * @SWG\Post(
     *      path="/mobile/patient-user/notification/{status}",
     *      operationId="switchNotifications",
     *      summary="ON|OFF notifications",
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
     *          description="providers' info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_switched_notifications"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function switchNotifications(Request $request, $status = 'on'): JsonResponse
    {
        $patientUserId = \Illuminate\Support\Facades\Auth::user()->id;

        $patientUser = User::where('id', $patientUserId)->first();

        $client_rules = array(
            'status' => 'required|in:on,off',
        );

        $client_array = array(
            'status' => $status,
        );

        $validatorMsg = $this->validatorFails($client_rules, $client_array);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $isNotificationOn = $status == 'on';
        $patientUser->notification_status = $isNotificationOn;
        $patientUser->save();

        return $this->sendResponse(Response::HTTP_OK, 'successfully_switched_notifications');
    }
}
