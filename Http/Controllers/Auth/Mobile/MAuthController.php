<?php

namespace App\Http\Controllers\Auth\Mobile;

use App\Helpers\SmsHelper;
use App\Http\Controllers\SuggestionController;
use App\Patient;
use App\Services\MAuthService;
use App\Services\PatientUserAccountService;
use App\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Mockery\Exception;
use Validator;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\AuthenticatesAndRegistersUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use App\Account;
use App\PatientSmsVerifications;
use Session;
use Config;
use Illuminate\Support\Str;
use Hashids\Hashids;

class MAuthController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Registration &
     Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users, as well as the
    | authentication of existing users. By default, this controller uses
    | a simple trait to add these behaviors. Why don't you explore it?
    |
    */

    use AuthenticatesAndRegistersUsers;

    /**
     * Where to redirect users after login / registration.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * Create a new authentication controller instance.
     *
     * @return void
     */
    public function __construct()
    {
//        $this->middleware($this->guestMiddleware(), ['except' => 'logout']);
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'name' => 'required|max:255',
            'email' => 'required|email|max:255|unique:users',
            'password' => 'required|min:6|confirmed',
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param array $data
     * @return User
     */
    protected function create(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
        ]);
    }

    public function loginByMobileAuthToken(Request $request)
    {
        $this->validate($request, [
            'mobile_auth_token' => 'required',
        ]);

        $account_id = $this->getNotInactiveAccountsDetails();
        $input = $request->all();

        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        $throttles = $this->isUsingThrottlesLoginsTrait();

        if ($throttles && $lockedOut = $this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendLockoutResponse($request);
        }

        // get patientUser by mobile auth token
        $patientUser = $this->getPatientByMobileAuthToken($input['mobile_auth_token'], $account_id);

        if (Auth::loginUsingId($patientUser->patient_user_id)) {
            return $this->handleUserWasAuthenticated($request, $throttles);
        }

        // If the login attempt was unsuccessful we will increment the number of attempts
        // to login and redirect the user back to the login form. Of course, when this
        // user surpasses their maximum number of attempts they will get locked out.
        if ($throttles && !$lockedOut) {
            $this->incrementLoginAttempts($request);
        }

        return $this->sendFailedLoginResponse($request);
    }

    /**
     * @SWG\Post(
     *      path="/mobile/auth/send-OTP",
     *      operationId="mobileLoginSendOtp",
     *      summary="Sends OTP sms on the existing user phone",
     *      @SWG\Parameter(
     *          name="phone",
     *          description="User phone",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="+380000000000"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=429,
     *          description="Too many attempts",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="too_many_attempts"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *       ),
     *      @SWG\Response(
     *          response=400,
     *          description="Invalid credentials | Unexist some required params | Unactivated user account by Email",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="invalid_credentials | unactivated_account"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=500,
     *          description="Internal server error",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="internal_server_error"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Otp sent to sms success",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="otp_sent_to_sms_success"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function sendOtp(Request $request): JsonResponse
    {
        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        $throttles = $this->isUsingThrottlesLoginsTrait();

        if ($throttles && $lockedOut = $this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendResponse(Response::HTTP_TOO_MANY_REQUESTS, 'too_many_attempts');
        }

        $request_data = $request->all();

        #validate data
        $client_rules = array(
            'phone' => 'required|string',
            'email' => 'email',
            'password' => 'string',
        );
        $client_array = array(
            'phone' => isset($request_data['phone']) ? $request_data['phone'] : '',
            'email' => isset($request_data['email']) ? $request_data['email'] : '',
            'password' => isset($request_data['password']) ? $request_data['password'] : ''
        );

        $validator = Validator::make($client_array, $client_rules);

        if ($validator->fails() || count($request_data) == 0) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validator->getMessageBag());
        }

        $checkauth = 0;

        $patientUserMobileConditions = [
            ['phone', $request_data['phone']]
        ];

        if(!empty($request_data['email']) && !empty($request_data['password'])){
            $patientUserEmail = $request_data['email'];
            $patientUserPassword = $request_data['password'];
                #get patient from email
            $patientUserSettingsConditions = [
                ['email', $patientUserEmail]
            ];
            $checkauth = 1;
        }else{
                #get PatientUser by phone
            $patientUserSettingsConditions = $patientUserMobileConditions;
        }

        $patientUserCount = User::where($patientUserMobileConditions)->count();

        if(!$client_array['email'] && $patientUserCount > 1){
            return $this->sendResponse(Response::HTTP_FORBIDDEN, 'There is more than one same phone number');
        }

        $patientUser = User::where($patientUserSettingsConditions)->first();

        if (empty($patientUser)) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'invalid_credentials');
        }
        if ($patientUser['status'] == 1) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'unactivated_account');
        }
        if ($checkauth && !Auth::attempt(['email' => $patientUser->email, 'password' => $patientUserPassword, 'id' => $patientUser->id])) {
            return $this->sendResponse(Response::HTTP_UNAUTHORIZED, 'authentication_error');
        }

        $phone = $request_data['phone'];

        $otp = mt_rand(100000, 999999);
        $now = time();
        $fifteen = $now + (15 * 60);
        $created = date('y-m-d H:i:s', $now);
        $expire = date('y-m-d H:i:s', $fifteen);
        $otpBody = "$otp is your OTP, will expire after 15 minutes. Do not share your OTP with anyone.";
        $sms_status = '';

        $testNum = config('app.test_values.test_patient_phone_num');

        $isTestNumber = ($phone == $testNum || Str::startsWith($phone, "+355"));

        if(!$isTestNumber){
            $sms_status = SmsHelper::sendSMS($phone, $otpBody, null, true);
        }

        if ($isTestNumber || $sms_status) {
            $patientSMS = array(
                'patient_user_id' => $patientUser['id'],
                'otp' => $otp,
                'otp_status' => 'pending',
                'otp_created' => $created,
                'otp_valid_upto' => $expire,
            );

            PatientSmsVerifications::create($patientSMS);

            #Increment the number of attempts.
            if ($throttles && !$lockedOut) {
                $this->incrementLoginAttempts($request);
            }

            return $this->sendResponse(Response::HTTP_OK, 'otp_sent_to_sms_success');
        }
        $message = $sms_status ?? 'internal_server_error';

        return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, $message);
    }

    /**
     * @SWG\Post(
     *      path="/mobile/auth/verify-OTP",
     *      operationId="verifyOTP",
     *      summary="Verifies OTP sms on the existing user phone",
     *      @SWG\Parameter(
     *          name="phone",
     *          description="User phone",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="+380000000000"
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="smsOTP",
     *          description="Sms otp code",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="156911"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=429,
     *          description="Too many attempts",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="too_many_attempts"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *       ),
     *      @SWG\Response(
     *          response=400,
     *          description="Invalid credentials | Unexist some required params",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="invalid_credentials"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=401,
     *          description="Otp verification error",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="otp_verification_error"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *     ),
     *     @SWG\Response(
     *          response=200,
     *          description="Otp successfully verified",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_authenticated"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="array",
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyOTP(Request $request): JsonResponse
    {
        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        $throttles = $this->isUsingThrottlesLoginsTrait();

        if ($throttles && $lockedOut = $this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendResponse(Response::HTTP_TOO_MANY_REQUESTS, 'too_many_attempts');
        }

        $request_data = $request->all();

        #validate data
        $client_array = array(
            'smsOTP' => isset($request_data['smsOTP']) ? $request_data['smsOTP'] : '',
            'phone' => isset($request_data['phone']) ? $request_data['phone'] : ''
        );

        $testNum = config('app.test_values.test_patient_phone_num');

        $isTestNumber = ($client_array['phone'] && (($client_array['phone'] == $testNum) || Str::startsWith($client_array['phone'], "+355")));

        if ((!$client_array['smsOTP'] && !$isTestNumber) || !$client_array['phone'] || count($request_data) == 0) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'missed_required_fields');
        }

        #get patient from phone
        $phoneNum = $client_array['phone'];
        $smsOtp = $client_array['smsOTP'];
        $patientSettingsConditions = array(
            ['phone', $phoneNum],
        );

        $patientUser = User::where($patientSettingsConditions)->first();

        if (!$patientUser) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'invalid_credentials');
        }

        if (!$isTestNumber) {
            #get last patient otp
            $patientSmsVerificationCon = array(
                ['patient_user_id', $patientUser['id']],
                ['otp_status', 'pending'],
                ['otp_valid_upto', '>=', date('y-m-d H:i:s')]
            );
            $dbOTP = PatientSmsVerifications::where($patientSmsVerificationCon)->orderBy('id', 'desc')->first();
        }

        if ($isTestNumber || (isset($dbOTP['otp']) && $smsOtp == $dbOTP['otp'])) {
            #expire last patient sms verification
            $userSmsVerificationCon = array(
                ['patient_user_id', $patientUser->id],
                ['otp_status', 'pending'],
            );
            PatientSmsVerifications::where($userSmsVerificationCon)->update(['otp_status' => 'expired']);

            $data = [];
            return $this->sendResponse(Response::HTTP_OK, 'otp_successfully_verified', $data);
        }

        #Increment the number of attempts.
        if ($throttles && !$lockedOut) {
            $this->incrementLoginAttempts($request);
        }

        return $this->sendResponse(Response::HTTP_UNAUTHORIZED, 'otp_verification_error');
    }

    /**
     * @SWG\Post(
     *      path="/mobile/auth/register",
     *      operationId="mobileRegister",
     *      summary="Register new user",
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
     *          name="phone",
     *          description="User phone",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="+380000000000"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="User already exists | Unexists some required params",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="user_already_exists|invalid_credentials"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=500,
     *          description="Internal server error",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="internal_server_error"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Successfully registered",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_registered"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(property="first_name", type="string", example="Rahul"),
     *                  @SWG\Property(property="last_name", type="string", example="Sati"),
     *                  @SWG\Property(property="username", type="string", example="email@gmail.com"),
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
    public function register(Request $request): JsonResponse
    {
        $input = $request->all();

        $client_rules = array(
            'first' => 'required',
            'last' => 'required',
            'username' => 'required|email',
            'phone' => 'required'
        );
        $client_array = array(
            'first' => isset($input['first']) ? $input['first'] : '',
            'last' => isset($input['last']) ? $input['last'] : '',
            'username' => isset($input['username']) ? $input['username'] : '',
            'phone' => isset($input['phone']) ? $input['phone'] : ''
        );

        $validator = Validator::make($client_array, $client_rules);

        if ($validator->fails() || count($input) == 0) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validator->getMessageBag());
        }

        $patient_user = DB::table('patient_users')
            ->where('patient_users.email', $input['username'])
            ->orWhere('patient_users.phone', $input['phone'])
            ->first();

        if ($patient_user) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'user_already_exists');
        }

        if(!Str::startsWith($input['phone'], "+")){
            $input['phone'] = "+".$input['phone'];
        }

        $patient_user = new User();
        $patient_user->firstname = $input['first'];
        $patient_user->lastname = $input['last'];
        $patient_user->email = $input['username'];
        $patient_user->status = 0;
        $patient_user->phone = $input['phone'];
        $patient_user->profile_pic = 'user.png';

        $email = $input['username'];

        $status = MAuthService::savePatientUserAndSendActivationEmail($patient_user, $email);
//        if($status) {
            return $this->sendResponse(Response::HTTP_OK, 'successfully_registered', $patient_user->toArray());
//        } else {
//            return $this->sendResponse(Response::HTTP_INTERNAL_SERVER_ERROR, 'internal_server_error');
//        }

    }

    /**
     * @SWG\Post(
     *      path="/mobile/auth/login-OTP",
     *      operationId="mobileLoginOTP",
     *      summary="Authentificate user by OTP",
     *      @SWG\Parameter(
     *          name="phone",
     *          description="User phone",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="+380000000000"
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="smsOTP",
     *          description="Sms otp code",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="156911"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=429,
     *          description="Too many attempts",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="too_many_attempts"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *       ),
     *      @SWG\Response(
     *          response=400,
     *          description="Invalid credentials | Unexist some required params",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="invalid_credentials"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=401,
     *          description="Authentication error",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="authentication_error"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *     ),
     *     @SWG\Response(
     *          response=200,
     *          description="Successfully authenticated",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_authenticated"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(property="first_name", type="string", example="Rahul"),
     *                  @SWG\Property(property="last_name", type="string", example="Sati"),
     *                  @SWG\Property(property="email", type="string", example="email@gmail.com"),
     *                  @SWG\Property(property="phone", type="string", example="+380000000000"),
     *                  @SWG\Property(property="address", type="string", example="1037 Mclean Avenue"),
     *                  @SWG\Property(property="full_address", type="string", example="C-86, Pannu Towers, Mohali, India"),
     *                  @SWG\Property(property="full_profile_img_path", type="string", example="http://stage-ar-patient-portal.eba-5uzknmmq.us-west-2.elasticbeanstalk.com/uploads/profiles/1610450241_Sappire.png"),
     *                  @SWG\Property(property="payment_type", type="string", example="stripe"),
     *                  @SWG\Property(property="date_of_birth", type="string", example="05/05/2000"),
     *                  @SWG\Property(
     *                      property="patient_clinics",
     *                      type="array",
     *                      @SWG\items(
     *                          type="object",
     *                          @SWG\Property(property="id", type="string", example="12"),
     *                          @SWG\Property(property="name", type="string", example="Some Clinic"),
     *                          @SWG\Property(property="logoImg", type="string", example="https://armedia.s3-us-west-2.amazonaws.com/b6fe5627750df1820ad469fdc7cd1e38/admin/1525285990.jpg"),
     *                      ),
     *                  ),
     *                  @SWG\Property(property="_token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJmYWN0b3J5I..."),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function loginOTP(Request $request): JsonResponse
    {
        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        $throttles = $this->isUsingThrottlesLoginsTrait();

        if ($throttles && $lockedOut = $this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendResponse(Response::HTTP_TOO_MANY_REQUESTS, 'too_many_attempts');
        }

        $request_data = $request->all();

        #validate data
        $client_array = array(
            'smsOTP' => isset($request_data['smsOTP']) ? $request_data['smsOTP'] : '',
            'phone' => isset($request_data['phone']) ? $request_data['phone'] : ''
        );

        $testNum = config('app.test_values.test_patient_phone_num');

        $phoneNum = $client_array['phone'];
        $smsOtp = $client_array['smsOTP'];
        $patientSettingsConditions = array(
            ['phone', $phoneNum],
        );

        $isTestNumber = ($client_array['phone'] && ($client_array['phone'] == $testNum) || Str::startsWith($client_array['phone'], "+355"));

        if ((!$client_array['smsOTP'] && !$isTestNumber) || !$client_array['phone'] || count($request_data) == 0) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'missed_required_fields');
        }

        #get patient from phone
        $patientUser = User::where($patientSettingsConditions)->first();

        if (!$patientUser) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'invalid_credentials');
        }

        if (!$isTestNumber) {
            #get last patient otp
            $patientSmsVerificationCon = array(
                ['patient_user_id', $patientUser['id']],
                ['otp_status', 'pending'],
                ['otp_valid_upto', '>=', date('y-m-d H:i:s')]
            );
            $dbOTP = PatientSmsVerifications::where($patientSmsVerificationCon)->orderBy('id', 'desc')->first();
        }

        if ($isTestNumber || (isset($dbOTP['otp']) && $smsOtp == $dbOTP['otp'])) {
            #get all patient accounts
            $patientAllAccounts = DB::table('patient_accounts as pa')
                ->join('accounts as a', 'pa.account_id', '=', 'a.id')
                ->leftJoin('account_clearent_configs as acc', 'acc.account_id', '=', 'a.id')
                ->where('a.name', '<>', null)
                ->where('pa.patient_user_id', '=', $patientUser->id)
                ->select('a.id', 'a.name', 'a.storage_folder', 'a.logo', 'a.pos_gateway', 'a.database_name', 'pa.patient_id')
                ->groupby('a.id')
                ->get();

            if (isset($patientAllAccounts[0])) {
                switchDatabase($patientAllAccounts[0]->database_name);
                $patient = Patient::find($patientAllAccounts[0]->patient_id);
                $middlename = $patient->middlename;
                switchDatabase();
            }

            $ar_media_path = config('app.media_url');
            $web_session_id = '';
            foreach ($patientAllAccounts as $patientAccount) {
                if ($patientAccount->logo) {
                    $patientAccount->logoImg = $ar_media_path . $patientAccount->storage_folder . "/admin/" . $patientAccount->logo;
                }
                if ($patientAccount->pos_gateway) {
                    $patientAccount->payment_type = $patientAccount->pos_gateway;
                }
                $encrypted = new Hashids('chat_aesthetic');
                $encrypted = $encrypted->encode($patientAccount->id, $patientUser->id, floor(time() / 2));
                $web_session_id = $encrypted;

                $patientAccount->web_session_id = $web_session_id;
                unset($patientAccount->logo);
                unset($patientAccount->pos_gateway);
                unset($patientAccount->storage_folder);
                unset($patientAccount->database_name);
            }

            #expire last patient sms verification
            $userSmsVerificationCon = array(
                ['patient_user_id', $patientUser->id],
                ['otp_status', 'pending'],
            );
            PatientSmsVerifications::where($userSmsVerificationCon)->update(['otp_status' => 'expired']);

            #auth User
            if ($userToken = $this->getJWTFromUser($patientUser)) {

                $date_of_birth_strtotime = strtotime($patientUser->date_of_birth);
                $date_of_birth_american = date('m-d-Y', $date_of_birth_strtotime);
                $patientUser->date_of_birth = $date_of_birth_american;

                $patientUserData = $patientUser->toArray();

                $address_elements = [$patientUserData['address'], $patientUserData['city'], $patientUserData['country']];

                if(in_array(null, $address_elements)){
                    $fullAddress = '';
                }else{
                    $fullAddress = $patientUserData['address'] . ', ' . $patientUserData['city'] . ', ' . $patientUserData['country'];
                }

                User::query()
                    ->where('id', '=', $patientUser->id)
                    ->update([
                        'web_session_id' => $web_session_id
                    ]);

                $data = [
                    'user_id' => $patientUser->id,
                    'middle_name' => isset($middlename) ? $middlename : '',
                    'full_address' => $fullAddress,
                    'patient_clinics' => $patientAllAccounts,
                    '_token' => $userToken,
                ];

                $data = array_merge($patientUserData, $data);

                return $this->sendResponse(Response::HTTP_OK, 'successfully_authenticated', $data);
            }
        }

        #Increment the number of attempts.
        if ($throttles && !$lockedOut) {
            $this->incrementLoginAttempts($request);
        }

        return $this->sendResponse(Response::HTTP_UNAUTHORIZED, 'authentication_error');
    }

    /**
     * @SWG\Post(
     *      path="/mobile/auth/login-email",
     *      operationId="loginWithEmail",
     *      summary="Authentificate user by email",
     *      @SWG\Parameter(
     *          name="email",
     *          description="User email",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="+380000000000"
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="password",
     *          description="Password",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="r5yttr67u!RTF978oh"
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="phone",
     *          description="User phone",
     *          required=false,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="+380000000000"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=429,
     *          description="Too many attempts",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="too_many_attempts"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *       ),
     *      @SWG\Response(
     *          response=400,
     *          description="Invalid credentials (invalid email) | Unexist some required params",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="invalid_credentials"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=401,
     *          description="Authentication error - invalid password",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="authentication_error"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *     ),
     *     @SWG\Response(
     *          response=200,
     *          description="Successfully authenticated",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully_authenticated"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="object",
     *                  @SWG\Property(property="first_name", type="string", example="Rahul"),
     *                  @SWG\Property(property="last_name", type="string", example="Sati"),
     *                  @SWG\Property(property="email", type="string", example="email@gmail.com"),
     *                  @SWG\Property(property="phone", type="string", example="+380000000000"),
     *                  @SWG\Property(property="address", type="string", example="1037 Mclean Avenue"),
     *                  @SWG\Property(property="full_address", type="string", example="C-86, Pannu Towers, Mohali, India"),
     *                  @SWG\Property(property="full_profile_img_path", type="string", example="http://stage-ar-patient-portal.eba-5uzknmmq.us-west-2.elasticbeanstalk.com/uploads/profiles/1610450241_Sappire.png"),
     *                  @SWG\Property(property="payment_type", type="string", example="stripe"),
     *                  @SWG\Property(property="date_of_birth", type="string", example="05/05/2000"),
     *                  @SWG\Property(
     *                      property="patient_clinics",
     *                      type="array",
     *                      @SWG\items(
     *                          type="object",
     *                          @SWG\Property(property="id", type="string", example="12"),
     *                          @SWG\Property(property="name", type="string", example="Some Clinic"),
     *                          @SWG\Property(property="logoImg", type="string", example="https://armedia.s3-us-west-2.amazonaws.com/b6fe5627750df1820ad469fdc7cd1e38/admin/1525285990.jpg"),
     *                      ),
     *                  ),
     *                  @SWG\Property(property="_token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJmYWN0b3J5I..."),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function loginWithEmail(Request $request): JsonResponse
    {
        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.

        $throttles = $this->isUsingThrottlesLoginsTrait();

        if ($throttles && $lockedOut = $this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendResponse(Response::HTTP_TOO_MANY_REQUESTS, 'too_many_attempts');
        }

        $request_data = $request->all();

        #validate data
        $client_rules = array(
            'email' => 'required|string',
            'password' => 'required|string',
            'phone' => 'required|string',
        );

        $client_array = array(
            'email' => isset($request_data['email']) ? $request_data['email'] : '',
            'password' => isset($request_data['password']) ? $request_data['password'] : '',
            'phone' => isset($request_data['phone']) ? $request_data['phone'] : ''
        );

        $validatorMsg = $this->validatorFails($client_rules, $client_array);
        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        #get patient from email
        $patientUserEmail = $request_data['email'];

        $patientSettingsConditions = array(
            ['email', $patientUserEmail],
        );

        $patientUser = User::where($patientSettingsConditions)->first();

        if (!$patientUser) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, 'invalid_credentials');
        }

        if($request_data['phone']){
            $patientUserPhone = $request_data['phone'];
        }

        if (Auth::attempt(['email' => $patientUser->email, 'password' => $request->password, 'id' => $patientUser->id])) {

            $patientUser->update(['phone'=>$patientUserPhone]);

            #get all patient accounts
            $patientAllAccounts = DB::table('patient_accounts as pa')
                ->join('accounts as a', 'pa.account_id', '=', 'a.id')
                ->leftJoin('account_clearent_configs as acc', 'acc.account_id', '=', 'a.id')
                ->where('a.name', '<>', null)
                ->where('pa.patient_user_id', '=', $patientUser->id)
                ->select('a.id', 'a.name', 'a.storage_folder', 'a.logo', 'a.pos_gateway', 'a.database_name', 'pa.patient_id')
                ->groupby('a.id')
                ->get();

            switchDatabase($patientAllAccounts[0]->database_name);
            $patient = Patient::find($patientAllAccounts[0]->patient_id);
            $middlename = $patient->middlename;
            switchDatabase();

            $ar_media_path = config('app.media_url');

            foreach ($patientAllAccounts as $patientAccount) {
                switchDatabase($patientAccount->database_name);
                DB::purge('juvly_practice');
                $patient = Patient::find($patientAccount->patient_id);
                $patient->phoneNumber = $patientUserPhone;
                $patient->save();
                switchDatabase();

                if ($patientAccount->logo) {
                    $patientAccount->logoImg = $ar_media_path . $patientAccount->storage_folder . "/admin/" . $patientAccount->logo;
                }
                if ($patientAccount->pos_gateway) {
                    $patientAccount->payment_type = $patientAccount->pos_gateway;
                }

                $encrypted = new Hashids('chat_aesthetic');
                $encrypted = $encrypted->encode($patientAccount->id, $patientUser->id, floor(time() / 2));
                $web_session_id = $encrypted;

                $patientAccount->web_session_id = $web_session_id;

                unset($patientAccount->logo);
                unset($patientAccount->pos_gateway);
                unset($patientAccount->storage_folder);
                unset($patientAccount->database_name);
                unset($patientAccount->patient_id);
            }

            #auth User
            if ($userToken = $this->getJWTFromUser($patientUser)) {
                $date_of_birth_strtotime = strtotime($patientUser->date_of_birth);
                $date_of_birth_american = date('m-d-Y', $date_of_birth_strtotime);
                $patientUser->date_of_birth = $date_of_birth_american;

                $patientUserData = $patientUser->toArray();

                $address_elements = [$patientUserData['address'], $patientUserData['city'], $patientUserData['country']];

                if(in_array(null, $address_elements)){
                    $fullAddress = '';
                }else{
                    $fullAddress = $patientUserData['address'] . ', ' . $patientUserData['city'] . ', ' . $patientUserData['country'];
                }

                User::query()
                    ->where('id', '=', $patientUser->id)
                    ->update([
                        'web_session_id' => $web_session_id
                    ]);

                $data = [
                    'user_id' => $patientUser->id,
                    'middle_name' => $middlename,
                    'full_address' => $fullAddress,
                    'patient_clinics' => $patientAllAccounts,
                    '_token' => $userToken,
                ];

                $data = array_merge($patientUserData, $data);

                return $this->sendResponse(Response::HTTP_OK, 'successfully_authenticated', $data);
            }
        }

        #Increment the number of attempts.
        if ($throttles && !$lockedOut) {
            $this->incrementLoginAttempts($request);
        }

        return $this->sendResponse(Response::HTTP_UNAUTHORIZED, 'authentication_error');
    }

    /**
     * @SWG\Post(
     *      path="/mobile/auth/check-number",
     *      operationId="checkIfNumberExists",
     *      summary="Authentificate user by OTP",
     *      @SWG\Parameter(
     *          name="phone",
     *          description="User phone",
     *          required=true,
     *          in="query",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="+380000000000"
     *          )
     *      ),
     *     @SWG\Response(
     *          response=200,
     *          description="Patient exists | Patient doesn't exist | Patient is not activated",
     *          @SWG\items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="patient_exists"),
     *              @SWG\Property(property="data", type="boolean", example="true"),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function checkIfNumberExists(Request $request): JsonResponse
    {
        $request_data = $request->all();

        #validate data
        $client_rules = array(
            'phone' => 'required'
        );
        $client_array = array(
            'phone' => isset($request_data['phone']) ? $request_data['phone'] : ''
        );

        $validator = Validator::make($client_array, $client_rules);

        if ($validator->fails() || count($request_data) == 0) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validator->getMessageBag());
        }

        #get PatientUser by phone
        $patient_user_phone = preg_replace('/\s+/', '', $request_data['phone']);
        $patientUser = User::where('phone', $patient_user_phone)
            ->orWhere('patient_users.phone', '+' . $patient_user_phone)
            ->first();

        if (!$patientUser) {
            return $this->sendResponse(Response::HTTP_OK, 'patient_doesnt_exist', false);
        }
        if ($patientUser['status'] == 1) {
            return $this->sendResponse(Response::HTTP_OK, 'patient_not_activated', false);
        }

        return $this->sendResponse(Response::HTTP_OK, 'patient_exists', true);
    }
}
