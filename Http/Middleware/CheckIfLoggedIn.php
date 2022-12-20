<?php

namespace App\Http\Middleware;

use Closure;
use Auth;
use App\User;
use App\Model\Account;
use App\Model\UserPrivilege;
use App\Model\ResponseTime;
use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Support\Facades\DB;
use Config;
use Illuminate\Http\Request;
use App\Model\WorkspaceUser;
use App\Model\Workspace;
class CheckIfLoggedIn {

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next) {
		$headers = apache_request_headers();
	
		$access_token  = $headers['access-token'] ?? $headers['access_token'] ?? $headers['Access-Token'] ?? "";
			
		//~ $headers = $request->headers->all();
        //~ $access_token  = $headers['access-token'][0] ?? $headers['access_token'][0] ?? "";
        
        if(!empty($access_token)){
        
						$workspace_user = WorkspaceUser::with('activeWorkspace')->where("web_session_id", $access_token)->first();
						#$user = User::where("web_session_id", $access_token)->first();
            $dev_team_static_token = 'b513ebd99c00007f703139023b88735f0b865462_dev_token';
					
					
						/*if($access_token == $dev_team_static_token){
							$user = User::find(env("TOKEN_DEV_LOCAL_ID"));
						}
						if (is_null($user)) {
							return app(Controller::class)->sendResponse(400, 'invalid_token');
						}*/
						if($access_token == $dev_team_static_token){
							$workspace_user = WorkspaceUser::find(env("TOKEN_DEV_LOCAL_ID"));
						}
            if (is_null($workspace_user)) {
                return app(Controller::class)->sendResponse(400, 'invalid_token');
            }else{
							if(empty($workspace_user->activeWorkspace)){
								return app(Controller::class)->sendResponse(400, 'invalid_token');
							}else{
								$user_id = $workspace_user->activeWorkspace->user_id;
								$account_id = $workspace_user->activeWorkspace->account_id;
								$user = User::where("id", $user_id)->first();
							}
						}
            $get_route = $request->path();
            
            
            $account_data = Account::where('id', $user->account_id)->first();
            if (!is_null($account_data)) {
				$action_list = array('api/upgrade-account-to-stripe','api/sign-tos-agreement','api/upgrade-plan','api/login','api/user/logout','api/dashboard/header-notifications', 'api/subscription-details', 'api/switch-workspace');
				if($account_data->status == 'inactive' && !in_array($get_route, $action_list)) {
					$update_user = $user->update(['web_session_id' => '']);
					return app(Controller::class)->sendResponse(400, 'invalid_token');
				}
			}
			
            
            if($get_route != "api/dashboard/header-notifications"){
				$login_time = round(abs(strtotime(date('Y-m-d H:i:s')) - strtotime($workspace_user->web_last_activity)) / 60);
				#$login_time = round(abs(strtotime(date('Y-m-d H:i:s')) - strtotime($user->web_last_activity)) / 60);
				#check if last activity more than 3 hours
				if (0) { //$login_time > 180 && $access_token != $dev_team_static_token
					return app(Controller::class)->sendResponse(400, 'session_timeout');
				} else {
					try {
						/*$user_id = $user->id;
						$account_id = $user->account_id;
						$workspace_user_data = Workspace::where('user_id', $user_id)->where('account_id', $account_id)->first();
						$workspace_user = WorkspaceUser::where("id", $workspace_user_data->workspace_user_id)->first();
						$all_users = Workspace::where("workspace_user_id", $workspace_user_data->workspace_user_id)->pluck('user_id')->toArray();
						DB::transaction(function () use ($user,$all_users,$workspace_user) {
							User::whereIn('id',$all_users)->update(['web_last_activity' => now(), 'modified' => now()]);
							$workspace_user->update(['web_last_activity' => now(), 'modified' => now()]);
						});*/
						$all_users = app(Controller::class)->getActiveWorkspaceUserIds($user_id,$account_id);
						DB::transaction(function () use ($user,$all_users,$workspace_user) {
							User::whereIn('id',$all_users)->update(['web_last_activity' => now(), 'modified' => now()]);
							$workspace_user->update(['web_last_activity' => now(), 'modified' => now()]);
						});
					} catch (\Exception $e) {
						return app(Controller::class)->sendResponse(500, 'server_error'.$e->getMessage());
					}
				}
			}

            #check for storage data limit exhausted 
            //$account_data = Account::with(['accountSubscription','accountPreference'])
            $account_data = Account::with(['accountPreference'])
                            ->where('id', $user->account_id)->first();
            if (!is_null($account_data)) {
				Config::set("constants.default.account_id", $account_data->id);
				if(!is_null($account_data->accountSubscription)){
					$storage_limit = ($account_data->accountSubscription->storage_limit > 0) ? ($account_data->accountSubscription->storage_limit / 1000) : 0;
					$storage_used = $account_data->accountSubscription->storage_used;
					/* check for storage limit reached */
					//~ if ($storage_used >= $storage_limit) {
						//~ if ($account_data->account_type == 'trial') {
							//~ return app(Controller::class)->sendResponse(400, 'storage_limit_reached_upgrade_account');
						//~ } else {
							//~ if ($account_data->accountSubscription->refill_data_status != 1 && in_array($account_data->accountSubscription->plan_code, config('constants.plan_code_array'))) {
								//~ return app(Controller::class)->sendResponse(400, 'storage_limit_reached_buy_storage');
							//~ }
						//~ }
					//~ }
				}
				
				$account_data = $this->getAccountDateTimeFormat($account_data);
				
				#get account currency symbol
				$account_data->currency_symbol = app(Controller::class)->getCurrencySymbol($account_data->stripe_currency);
				
				#get user privileges
				$user_privileges = array(); //$this->userPrivileges($user->id, $user->account_id);
            }else{
				return app(Controller::class)->sendResponse(400, 'session_timeout');
			}
        } else {
            return app(Controller::class)->sendResponse(400, 'token_not_found');
        }
	
		Config::set("constants.default.account_storage_folder", $account_data->storage_folder);
		Config::set("constants.default.account_database", $account_data->database_name);
		if($account_data->database_host != "" &&  	$user->id == env("DEMO_USER_ID")){
			Config::set("constants.default.account_database_host", $account_data->database_host);
		}else{
			Config::set("constants.default.account_database_host", env("DB_HOST"));
		}
		Config::set("constants.default.client_replacement_text", $account_data->accountPreference->client_replacement_text);

				/*$workspace_user_id  = $workspace_user->id;
				$work_space_data = Workspace::with(["account" => function($q){$q->select('id','name','logo','storage_folder');}])->where(['workspace_user_id' => $workspace_user_id,'is_active' => 1])->get()->toArray();
				$work_space_data =  app(Controller::class)->overrideWorkspaceData($work_space_data);*/

        $request->merge(['user_data' => $user->toArray()]);
        $request->merge(['account' => $account_data]);
				$request->merge(['user_privileges' => $user_privileges]);

			//	$request->merge(['workspaces' => $work_space_data ?? []]);
        
				Config::set("constants.default.timezone", $workspace_user->timezone);
			
        return $next($request);
    }
    
    private function getAccountDateTimeFormat($account_data){
		#get account date format
		$date_format = !empty($account_data->accountPreference->date_format) ? $account_data->accountPreference->date_format : "";
		$account_data->date_format = app(Controller::class)->getDateFormat($date_format);
		
		#set globaly account date format
		Config::set("constants.default.date_format", $account_data->date_format);
		
		#get account time format
		$time_format = !empty($account_data->accountPreference->time_format) ? $account_data->accountPreference->time_format : "";
		$account_data->time_format = app(Controller::class)->getTimeFormat($time_format);
		
		#set globaly account time format
		Config::set("constants.default.time_format", $account_data->time_format);
		
		return $account_data;
	}
	
	private function userPrivileges($user_id, $account_id){
		$privilege = new UserPrivilege();
		$user_privileges = $privilege->getUserPrivileges($user_id, $account_id);
        return $user_privileges;
	}
	
	public function terminate($request, $response)
    {
		$default_database 	= env('DB_DATABASE');
		app(Controller::class)->switchDatabase($default_database);
		$request_data 	= $request->all();
		if(!empty($request_data['user_data'])){
			$userId			= $request_data['user_data']['id'];
			$account_id 	= $request_data['user_data']['account_id'];
			$headers 		= apache_request_headers();
			$access_token 	= $headers['access-token'] ?? $headers['access_token'] ?? $headers['Access-Token'] ?? ""; 
			$duration		= microtime(true) - LARAVEL_START;
			$url 			= $request->path();
			if($url != "api/dashboard/header-notifications" && $url != "api/clock_data"){
				$responseTime = array(
						'url' => "$url",
						'user_id' => 0,
//						'account_id' => $account_id,
						'response_time' => $duration,
					);
				$responseTime =  ResponseTime::create($responseTime); 
			}
		}
		//~ var_dump($responseTime);
		//~ die;
        // Store the session data...
    }
    
}
