<?php

namespace App\Http\Controllers;

use App\Http\Requests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Hashids\Hashids;
use Session;
use Config;
use App\SurveyQuestion;
use App\Survey;
use App\SurveyAnswer;
use App\Account;
use App\PatientSurvey;
use App\Patient;
use App\SurveyQuestionChoice;
use App\AccountPrefrence;



class SurveyController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        
    }
    
    public function ShowSurvey( $key = null , Request $request )
    {
		if(!empty($key)){
			
			$account_detail 	    =  $this->GetAccountDetail($request);
			
			if(count((array)$account_detail)>0){
				
				$accountPrefrences = AccountPrefrence::where('account_id',$account_detail->id)->first();
				$allow_patients_to_manage_appt =  $accountPrefrences->allow_patients_to_manage_appt;
				$request->session()->put('allow_patients_to_manage_appt',$allow_patients_to_manage_appt);
				
				$logourl 	= $this->setAccountLogo($account_detail);
		
				$request->session()->put('logourl',$logourl);
				$request->session()->put('account',$account_detail);
				
				config(['database.connections.juvly_practice.database'=> $account_detail->database_name]);
				
				$patient_data  =  PatientSurvey :: where('survey_code',$key)->first();
				
				if(count((array)$patient_data)>0) {
					
					$request->session()->put('patient_data',$patient_data);
					
				//	$survey_exist = SurveyAnswer :: where('survey_code',$key)->get();
					
					if($patient_data->status == 'submitted') {
						
						return view('survey.thanks');
					
					} else {
						
						$appointment_id      =  $patient_data['appointment_id'];
						$patient_id    		 =  $patient_data['patient_id'];
						$survey_id   		 =  $patient_data['survey_id'];
						$current_index       =  1;
						$survey_detail		 =	Survey ::  where('id',$survey_id)->where('status',0)->where('is_published',1)->first();
						$survey_questions 	 = 	SurveyQuestion::where('survey_id',$survey_id)->where('status',0)->with('questionChoices')->orderBy('order_by', 'ASC')->get();
						$array_questions	= array();
						
						if(!empty($survey_questions)){
							foreach($survey_questions as $question){
								$question_type = $question->question_type;
								
								if( $question_type =='Multiple Choice' || $question_type =='Single Choice' ){
									
									if(count((array)$question->questionChoices) == 0){
										
										continue; /*skip the questions if choices are not available*/
									
									}else{
										$array_questions[] = $question;
									}
								}else{
									$array_questions[] = $question;
								}
							} 
						}else{
							$array_questions = [];
						}
						$total_questions = count((array)$array_questions);
						$request->session()->put('url_key',$key);
						//echo $total_questions; die;
						//echo "<pre>"; print_r($survey_questions->toArray()); die;
						return view('survey.show_survey',compact('survey_detail','appointment_id','survey_questions','key','current_index','total_questions','array_questions'))->render();
				
					}
					
				} else {
					
					return view('errors.404');	
				}	
			} else {
				return view('errors.404');
			}
		} else {
			return view('errors.404');
		}
			
	}
	
	public function SaveSurvey(Request $request)
	{
		if ($request->input() != null) {
			
			$appTimeZone = 'America/New_York';
			date_default_timezone_set($appTimeZone);
			
			//echo "<pre>"; print_r($request->input()); die("dfdfhs");
			
			$questions				= $request->input('questions');
			//$answers				= $request->input('answers');
			$url_key				= $request->session()->get('url_key');
			$account_detail			= $request->session()->get('account_detail');
			$patient_data			= $request->session()->get('patient_data');
			$insert_data 			= array();
			$total_score 			= 0;
			if( count((array)$questions) >0 ) {
				$question_count =  count((array)$questions);
				foreach ($questions as $key => $val) {
					
					//~ $arr = explode('_',$val);
					//~ $total_score = $answers[$key] + $total_score;
					$answer 	= null;
					$score 		= 0;
					$comment 	= '';
					if($val['quest_type'] == 'Multiple Choice') {
						$answer  = isset($val['answers'][0]) ? implode(',', $val['answers']) : null;	
					} else if($val['quest_type'] == 'Opinion Scale' || $val['quest_type'] == 'scale') {
						
						$val['quest_type'] ='Opinion Scale';
						
						$score 		= $val['answers'][0];
						$comment 	= $val['answers'][1];
					} else {
						$answer = isset($val['answers'][0]) ? $val['answers'][0] : null;	
					}
					
					$insert_data[] = array(
									'survey_code'		=>  $url_key,
									'question_id' 		=>  $val['question_id'],
									'question_type' 	=>  $val['quest_type'],
									'score' 			=>  $score,
									'status'			=>  0,
									'comment'			=>  $comment,
									'question_text'		=>  trim($val['quest_text']),
									'answer'			=>  $answer,
									'created'			=>  date("Y-m-d H:i:s")
							);					
				}
			}
			//echo '<pre>'; print_r($insert_data); die;
			if(count((array)$account_detail) >0){
				if(empty($url_key) ||  empty($patient_data) ){
				
					$response_array = array('status'=>'error','message'=>'Something went wrong');	
					return json_encode($response_array);
				}
				
				config(['database.connections.juvly_practice.database'=> $account_detail->database_name]);
				
				$avg_score 			= round($total_score / $question_count);
				$affected_rows      = SurveyAnswer::insert($insert_data);
				$patient_survey		= PatientSurvey :: where('survey_code',$url_key)->where('status','sent')->first();
				if(count((array)$patient_survey) > 0 ) {
					$patient_survey->status = 'submitted';
					$patient_survey->date_modified = date('Y-m-d H:i:s');
					$patient_survey->save();	
				}
				$patient 			= Patient :: where('id',$patient_data['patient_id'])->first();
				if(count((array)$patient)>0) {
					
					$current_score = $patient->satisfaction_score; 
					$total_serveys = $patient->total_serveys;
					
					$updated_serveys = $total_serveys + 1;
					//$updated_score 	=  round((($current_score*$total_serveys) + $avg_score)/ $updated_serveys);
					
					//$patient->satisfaction_score =  $updated_score;
					$patient->total_serveys  = $updated_serveys;
					$patient->save();	
				}
				
				if($affected_rows){
					$response_array = array('status'=>'success','message'=>'Survey has been saved Successfully');
				} else {
					$response_array = array('status'=>'error','message'=>'Something went wrong');	
				}		
			} else {
				$response_array = array('status'=>'error','message'=>'Something went wrong');	
			}
		}
		return json_encode($response_array);
		
	} 
		
	
	public function ShowThankyouPage($url_key = null, Request $request)
	{	
		if(!empty($url_key)){
			$account_detail 	    =  $this->GetAccountDetail($request);
			if(count((array)$account_detail)>0){
				
				$logourl 	= $this->setAccountLogo($account_detail);
				$request->session()->put('logourl',$logourl);
				$accountPrefs = AccountPrefrence::where('account_id',$account_detail->id)->first();
				config(['database.connections.juvly_practice.database'=> $account_detail->database_name]);
				
				//$patient_data  =  PatientSurvey :: where('survey_code',$url_key)->first();
				//$survey_detail =  Survey :: find( $patient_data['survey_id']);
				//$thanks_text   =  $survey_detail->thanks_message;
				$request->session()->forget('url_key');
			//	$request->session()->forget('account_detail');
				
				$request->session()->forget('patient_data');
				
				if(!empty($accountPrefs->survey_thankyou_message)){
					$thanks_text   =  $accountPrefs->survey_thankyou_message;
				}else{
					$thanks_text = 'Thank You';
				}
				
				return view('survey.thankyou',compact('thanks_text'))->render();
			} else {
				return view('errors.404');
			}
		} else {
			return view('errors.404');
		}
	}
	

	public function GetAccountDetail($request)
	{
		$account   = array();
		$hostarray = explode('.', $_SERVER['HTTP_HOST']);
		$subdomain = $hostarray[0];
		$account   = Account :: where('pportal_subdomain',$subdomain)->first();
		//$account   = Account :: where('pportal_subdomain',"customers")->first();
		if(count((array)$account)>0) {
			
			$request->session()->put('account_detail',$account);
			return $account;
		} else {
			return view('errors.404');
		}
	}
	
	 
}
