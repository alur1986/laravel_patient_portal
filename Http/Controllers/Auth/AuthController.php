<?php

namespace App\Http\Controllers\Auth;

use App\Account;
use App\AccountPrefrence;
use App\Http\Controllers\Controller;
use App\Patient;
use App\PatientAccount;
use App\User;
use Auth;
use Hashids\Hashids;
use Illuminate\Foundation\Auth\AuthenticatesAndRegistersUsers;
use Illuminate\Foundation\Auth\ThrottlesLogins;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Session;
use Validator;

class AuthController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Registration & Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users, as well as the
    | authentication of existing users. By default, this controller uses
    | a simple trait to add these behaviors. Why don't you explore it?
    |
    */

    use AuthenticatesAndRegistersUsers, ThrottlesLogins;

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
        $this->middleware($this->guestMiddleware(), ['except' => 'logout']);
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array  $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
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
     * @param  array  $data
     * @return User
     */
    protected function create(array $data)
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
        ]);
    }
    
     /**
     * Overriding default behaviour for registration 
     **/
    //~ public function register(Request $request)
    //~ {
		//~ return redirect('/login');
    //~ }
    //~ 
    
    
    public function register(Request $request)
    {
		
		$input 				= $request->input();
		$account_detail 	= $request->session()->get('account_detail');
		$patient_id 		= $request->session()->get('patient_id');
		if ( null != $input ) {
			
			$client_rules = array(
									'firstname' 					=> 'required',
									'lastname' 	 					=> 'required',
									'email'  						=> 'required|email',
								//	'email'  						=> 'required|email',
									'password'  					=> 'required',
									'password_confirmation'  		=> 'required|same:password'
									);
			$client_array = array(
									'firstname' 					=> $input['firstname'],
									'lastname'  					=> $input['lastname'],
									'email'  						=> $input['email'],
									'password'  					=> $input['password'],
									'password_confirmation'  		=> $input['password_confirmation']
									);
				
			$validator = Validator::make($client_array, $client_rules);
			
			if($input['key']!= null) {
			
				if ( $validator->fails() ) {
					return Redirect::to('/register?key='.$input['key'])->withInput()->withErrors($validator);
				}
				 
				config(['database.connections.juvly_practice.database'=> $account_detail->database_name]);
				
				 $patient = Patient :: where('id',$patient_id)->where('status',0)->first();
				 
				 if(count((array)$patient)>0) {
					 if($patient->gender == null){
						$gender  = 0;
					 } else {
						$gender  = $patient->gender;
					 }
					 $patient_user 						= new User;
					 $patient_user->firstname 			= $input['firstname'];
					 $patient_user->lastname  			= $input['lastname'];
					 $patient_user->email	 			= $input['email'];
					 $patient_user->password 			= bcrypt($input['password']);
					 $patient_user->status 				= 1;
					 $patient_user->phone 				= $patient->phoneNumber;
					 $patient_user->address 			= $patient->address_line_1;
					 $patient_user->city 				= $patient->city;
					 $patient_user->state 				= $patient->state;
					 $patient_user->pincode 			= $patient->pincode;
					 $patient_user->country 			= $patient->country;
					 $patient_user->date_of_birth 		= $patient->date_of_birth;
					 $patient_user->gender 				= $gender;
					 
					 $image = $this->getImage($patient,$account_detail,$patient_user->id);
					 
					 $patient_user->profile_pic 		= $image;
				
					if($patient_user->save()) {
					
						$patient_account 					= new PatientAccount ;
						$patient_account->patient_id 		= $patient_id ;
						$patient_account->patient_user_id 	= $patient_user->id ;
						$patient_account->account_id        = $account_detail->id ;
						if($patient_account->save()){
							
							$email = $patient->email;
							//~ Auth::login($patient_user);
							//~ return redirect($this->redirectPath());
							$response = app('App\Http\Controllers\PatientController')->sendActivationLink($request, $patient_user->id, $email);
							$request->session()->put('account_detail',$account_detail);
							$logourl 	= $this->setAccountLogo($account_detail);
							$request->session()->put('logourl',$logourl);
							$request->session()->put('patient_register_email',$email);
							return Redirect::to('/register/success');
						} else { 
							
							Session::put('error', 'Something went Wrong,Please try Again');
							return Redirect::to('/register?key='.$input['key']);
						}	
						
					} else {
							Session::put('error', 'Something went Wrong,Please try Again');
							return Redirect::to('/register?key='.$input['key']);
					}
				} else {
					Session::put('error', 'Sorry, No Customer found with the details you provided');
					return Redirect::to('/register?key='.$input['key'])->withInput()->withErrors($validator);
						
				}
				
			} else {
				
				if ( $validator->fails() ) {
					return Redirect::to('/register')->withInput()->withErrors($validator);
				}
				 
				config(['database.connections.juvly_practice.database'=> $account_detail->database_name]);
				
				 $patient = Patient :: where('firstname',$input['firstname'])->where('email',$input['email'])->where('status',0)->first();
				 if(count((array)$patient)>0) {
					
					 if($patient->gender == null){
						$gender  = 0;
					 } else {
						$gender  = $patient->gender;
					 }
					
					 $patient_user 						= new User;
					 $patient_user->firstname 			= $input['firstname'];
					 $patient_user->lastname  			= $input['lastname'];
					 $patient_user->email	 			= $input['email'];
					 $patient_user->password 			= bcrypt($input['password']);
					 $patient_user->status 				= 0;
					 $patient_user->phone 				= $patient->phoneNumber;
					 $patient_user->address 			= $patient->address_line_1;
					 $patient_user->city 				= $patient->city;
					 $patient_user->state 				= $patient->state;
					 $patient_user->pincode 			= $patient->pincode;
					 $patient_user->country 			= $patient->country;
					 $patient_user->gender 				= $gender;
					 $patient_user->date_of_birth 		= !empty($patient->date_of_birth) ? $patient->date_of_birth : "";
					 
					 $image = $this->getImage($patient,$account_detail,$patient_user->id);
					 
					 $patient_user->profile_pic 		= @$image;
					 
					 if($patient_user->save()) {
						
						$patient_account 					= new PatientAccount ;
						$patient_account->patient_id 		= $patient->id ;
						$patient_account->patient_user_id 	= $patient_user->id ;
						$patient_account->account_id        = $account_detail->id ;
						$patient_account->access_portal     = 1 ;
						if($patient_account->save()){
							
							$patient->access_portal = 1;
							
							if($patient->save()){
								Auth::login($patient_user);
								return redirect($this->redirectPath());
							} else {
								$patient_user->delete();
								$patient_account->delete();
								Session::put('error', 'Something went Wrong,Please try Again');
								return Redirect::to('/register');
							}
						} else { 
							$patient_user->delete();
							Session::put('error', 'Something went Wrong,Please try Again');
							return Redirect::to('/register');
						}	
						
					} else {
							Session::put('error', 'Something went Wrong,Please try Again');
							return Redirect::to('/register');
					}
				} else {
					Session::put('error', 'Sorry, No Customer found with the details you provided');
					return Redirect::to('/register')->withInput();
						
				}
				
			}	
		}
		
    }

    public function showRegistrationForm(Request $request)
    {
        if (property_exists($this, 'registerView')) {
            return view($this->registerView);
        }

        $key = $request->input('key');
        if (!empty($key)) {
            $decodedKey = $this->decodeKey($key);
            $user_data = $this->getAccountDetail($decodedKey);
            if (!is_null($user_data)) {
                $accountPreference = AccountPrefrence::query()
                    ->where('account_id', $decodedKey['account_id'])
                    ->first();
                session()->put('allow_patients_to_manage_appt', $accountPreference->allow_patients_to_manage_appt);

                return view('auth.register', compact('user_data', 'key'));
            } else {
                return view('errors.503');
            }
        } else {
            $hostArray = explode('.', $_SERVER['HTTP_HOST']);
            $subdomain = $hostArray[0];
            $account = Account::query()->where('pportal_subdomain', $subdomain)->first();
            if (!is_null($account)) {
                $accountPreference = AccountPrefrence::query()->where('account_id', $account->id)->first();
                session()->put('allow_patients_to_manage_appt', $accountPreference->allow_patients_to_manage_appt);
                session()->put('account_detail', $account);
                $logoUrl = $this->setAccountLogo($account);
                session()->put('logourl', $logoUrl);

                return view('auth.register');
            } else {
                return view('errors.503');
            }
        }

    }
    
    public function showLoginForm(Request $request)
    {
        $view = property_exists($this, 'loginView')
                    ? $this->loginView : 'auth.authenticate';

		$hostarray = explode('.', $_SERVER['HTTP_HOST']);
		$subdomain = $hostarray[0];
		$account = Account :: where('pportal_subdomain',$subdomain)->first();
		//$account = Account :: where('pportal_subdomain',"customers")->first();
		if(count((array)$account)>0) {
			
			$request->session()->put('account_detail',$account);
			
			$logourl 	= $this->setAccountLogo($account);
			$request->session()->put('logourl',$logourl);
			$account_preference =   AccountPrefrence::where('account_id',$account->id)->first();
			$request->session()->put('is_membership_enable',$account_preference->is_membership_enable);
			$request->session()->put('account_preference',$account_preference);
			$this->setSessionAppointmentSettingForPatient($request);
			$request->session()->put('patient_sign_up',$account_preference->patient_sign_up);
			return view('auth.login');
		}else {
			return view('errors.503');
		}
    }

    public function getAccountDetail($key)
    {
        $accountDetail = Account::query()
            ->where('id', $key['account_id'])
            ->first();
        if (!is_null($accountDetail)) {
            session()->put('account_detail', $accountDetail);
            $logoUrl = $this->setAccountLogo($accountDetail);
            session()->put('logourl', $logoUrl);
            session()->put('patient_id', $key['patient_id']);
            config(['database.connections.juvly_practice.database' => $accountDetail->database_name]);
            return Patient::query()
                ->where('id', $key['patient_id'])
                ->where('status', 0)
                ->first();
        }

        return null;
    }
	
	public function decodeKey($string)
    {
		
		$secret_key = "juvly12345";

		//$string = "2:1" where 2=account_id and 1=patient_id
				
		$hashids    = new Hashids($secret_key, 30);
		$string_array  = explode(":",$string);
		$account_id    = $hashids->decode($string_array[0]);
		$patient_id    = $hashids->decode($string_array[1]);
		$key = array('account_id'=> $account_id[0],'patient_id'=>$patient_id[0]);

		return $key;

	}
	
	public function logout(Request $request)
    {
		
		$request->session()->forget('database');
		$request->session()->forget('patient_id');
		$request->session()->forget('account_detail');	
		$request->session()->forget('logourl');	
		$request->session()->forget('patient_is_monthly_membership');
		$request->session()->forget('is_membership_enable');
        Auth::guard($this->getGuard())->logout();
        
        Session::put('selectedDB', '');
		$bookinArray = Session::get('bookAppointMent');
		
		unset($bookinArray['selClinic']);
		unset($bookinArray['selService']);
		unset($bookinArray['selDoc']);
		unset($bookinArray['selTimeZone']);
		unset($bookinArray['selDate']);
		unset($bookinArray['selTime']);
		unset($bookinArray['formData']);
		
		Session::put('bookAppointMent', $bookinArray);

        return redirect(property_exists($this, 'redirectAfterLogout') ? $this->redirectAfterLogout : '/');
    }
    
    public function getImage($patient,$account_detail,$user_id)
	{
        $url = rtrim(config('constants.urls.media.bucket'), '/') . "/{$account_detail->storage_folder}/patientpics/{$patient->user_image}";
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.11 (KHTML, like Gecko) Chrome/23.0.1271.1 Safari/537.11');
		$res = curl_exec($ch);
		$rescode = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
		curl_close($ch) ;
		if($rescode==200)
		{
			$destinationPath	= public_path('uploads/profiles');
			$imageArray 		= explode('.', $patient->user_image);
			$imageLastIndex		= count($imageArray) - 1;
			$image_extension 	= $imageArray[$imageLastIndex];
			$imagename			= $user_id.strtotime(date('Y-m-d H:i:s')).'.'.strtolower($image_extension);
			$imagewithpath      = $destinationPath . "/".$imagename;
			file_put_contents($imagewithpath, $res);
			return $imagename;
		}else{
			return $imagename = '';
		}
		
	}
	
	public function login(Request $request)
    {
        if($this->checkJuvlyDomainName()) {
            $account_id = $this->getAccountsDetails();
        } else {
            $account_id = $this->getNotInactiveAccountsDetails();
        }
        $this->validateLogin($request);
        $input = $request->all();
        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        $throttles = $this->isUsingThrottlesLoginsTrait();

        if ($throttles && $lockedOut = $this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            return $this->sendLockoutResponse($request);
        }
		$userObj = \DB::table('patient_users')
		->join('patient_accounts','patient_accounts.patient_user_id','=','patient_users.id')
		->where('patient_users.email',$input['email'])
		->where('patient_accounts.account_id',$account_id)
		->select('patient_users.id','patient_users.email','patient_accounts.id as patient_account_id','patient_accounts.account_id as account_id')
		->first();
		
        //~ $credentials = $this->getCredentials($request);
		//~ $obj = Auth::guard($this->getGuard())->attempt($credentials, $request->has('remember'));
		
		if ($userObj && Auth::attempt(['email' => $userObj->email, 'password' => $request->password, 'id' => $userObj->id])) {
            $encrypted = new Hashids('chat_aesthetic');
            $encrypted = $encrypted->encode($account_id, $userObj->id, time() / 2);
		    User::query()
                ->where('id', '=', $userObj->id)
                ->update([
		        'web_session_id' => $encrypted
            ]);
            return $this->handleUserWasAuthenticated($request, $throttles);
        }

        // If the login attempt was unsuccessful we will increment the number of attempts
        // to login and redirect the user back to the login form. Of course, when this
        // user surpasses their maximum number of attempts they will get locked out.
        if ($throttles && ! $lockedOut) {
            $this->incrementLoginAttempts($request);
        }

        return $this->sendFailedLoginResponse($request);
    }
	
}
