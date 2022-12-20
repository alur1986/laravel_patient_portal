<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\EmailHelper;
use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Support\Facades\Password;
use Session;
use Auth;
use App\Http\Requests;
use Illuminate\Http\Request;
use App\Account;
use App\PasswordReset;
use App\User;
use App\Clinic;

class PasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior. You're free to
    | explore this trait and override any methods you wish to tweak.
    |
    */

    use ResetsPasswords;

    /**
     * Create a new password controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    public function showLinkRequestForm()
    {

		$hostarray = explode('.', $_SERVER['HTTP_HOST']);
		$subdomain = $hostarray[0];
		$account = Account :: where('pportal_subdomain',$subdomain)->first();

		if($account) {
			Session::put('account_detail',$account);
			$logourl 	= $this->setAccountLogo($account);

			Session::put('logourl',$logourl);

			if (property_exists($this, 'linkRequestView')) {
				return view($this->linkRequestView);
			}

			if (view()->exists('auth.passwords.email')) {
				return view('auth.passwords.email');
			}
			return view('auth.password');

		}else {
			return view('errors.404');
		}

    }

    public function sendResetLinkEmail(Request $request)
    {


        $this->validateSendResetLinkEmail($request);


		$response = $this->sendPasswordResetLinkEmail($request);

        //~ $broker = $this->getBroker();
				//~ //echo "<pre>"; print_r($broker); die("cghssh");
//~
//~
        //~ $response = Password::broker($broker)->sendResetLink(
            //~ $this->getSendResetLinkEmailCredentials($request),
            //~ $this->resetEmailBuilder()
        //~ );
        switch ($response) {
            case Password::RESET_LINK_SENT:
                return $this->getSendResetLinkEmailSuccessResponse($response);
            case Password::INVALID_USER:
            default:
                return $this->getSendResetLinkEmailFailureResponse($response);
        }
    }



    public function sendPasswordResetLinkEmail($request)
    {
		$token = str_random(64);

		PasswordReset::where('email', $request->input('email'))->delete();

		$password_reset 		= new PasswordReset();
		$password_reset->email	= $request->input('email');
		$password_reset->token	= $token;
		$password_reset->save();

		$link 				= url('password/reset', $token).'?email='.urlencode($request->input('email'));
		$account 			= $request->session()->get('account_detail');

		$sender	 			= $this->getSenderEmail();
		config(['database.connections.juvly_practice.database'=> $account->database_name ]);
		$clinic 						= Clinic::where('status',0)->first();
		$noReply = getenv('MAIL_FROM_EMAIL');
		$subject 			= "Your Password Reset Link";
		$mail_body			= "Click link to setup your new password: <a href='".$link."'>" . $link ." </a>";
		$email_content 		= $this->getEmailTemplate($mail_body,$account,$clinic,$subject);


        $response_data =  EmailHelper::sendEmail($noReply, $request->input('email'), $sender, $email_content, $subject);
		if($response_data){
			return "passwords.sent";
		} else {
			return false;
		}
	}

   public function showResetForm(Request $request, $token = null)
   {
        $hostarray = explode('.', $_SERVER['HTTP_HOST']);
		$subdomain = $hostarray[0];
		$account = Account :: where('pportal_subdomain',$subdomain)->first();
		if($account) {

			Session::put('account_detail',$account);
			$logourl 	= $this->setAccountLogo($account);
			Session::put('logourl',$logourl);
		// function body
			if (is_null($token)) {
				return $this->getEmail();
			}

			$email = $request->input('email');

			if (property_exists($this, 'resetView')) {
				return view($this->resetView)->with(compact('token', 'email'));
			}

			if (view()->exists('auth.passwords.reset')) {
				return view('auth.passwords.reset')->with(compact('token', 'email'));
			}

			return view('auth.reset')->with(compact('token', 'email'));
		} else {
			return view('errors.404');
		}
    }

    public function reset(Request $request)
    {
		$input = $request->all();
        $this->validate(
            $request,
            $this->getResetValidationRules(),
            $this->getResetValidationMessages(),
            $this->getResetValidationCustomAttributes()
        );
		$account_id = $this->getNotInactiveAccountsDetails();
        $credentials = $this->getResetCredentials($request);

        $broker = $this->getBroker();

		$userObj = \DB::table('patient_users')
		->join('patient_accounts','patient_accounts.patient_user_id','=','patient_users.id')
		->where('patient_users.email',$input['email'])
		->where('patient_accounts.account_id',$account_id)
		->select('patient_users.id','patient_users.email','patient_accounts.id as patient_account_id','patient_accounts.account_id as account_id')
		->first();

        $response = Password::broker($broker)->reset($credentials, function ($user, $password) use($userObj) {
			if($userObj){
				$user = User::find($userObj->id);
			}else{
				$user = null;
			}
            $this->resetPassword($user, $password);
        });

        switch ($response) {
            case Password::PASSWORD_RESET:
				User::where('email',$input['email'])->update(['status' =>0]);
				Session::put('success', 'Your password has been updated, please login to continue');
                return $this->getResetSuccessResponse($response);
            default:
                return $this->getResetFailureResponse($request, $response);
        }
    }

}
