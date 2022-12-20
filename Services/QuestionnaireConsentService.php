<?php


namespace App\Services;

use App\Account;
use App\Appointment;
use App\AppointmentHealthtimelineAnswer;
use App\AppointmentHealthtimelineConsent;
use App\AppointmentHealthtimelineQuestionnaire;
use App\AppointmentQuestionnair;
use App\AppointmentQuestionnairChoice;
use App\Helpers\PatientUserHelper;
use App\Helpers\UploadExternalHelper;
use App\Procedure;
use App\ProcedureHealthtimelineConsent;
use App\ProcedureTemplate;
use App\QuestionChoice;
use App\Question;
use App\ServiceConsent;
use App\ServiceProvider;
use App\ServiceQuestionnaire;
use App\Validators\QuestionnaireConsentValidator;
use DB;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class QuestionnaireConsentService
{

    public static function getConsentsByUser($patient_id)
    {
        $data = array();

        $upcoming_appointments = AppointmentService::getAppointmentsByPeriod($patient_id, [], 'upcoming');

        $service_ids = self::getServiceIdsByUpcomingAppointments($upcoming_appointments);

        $service_consents = ServiceConsent::with('consent')->whereIn('service_id', array_unique($service_ids))->get();

        $consents_infos = array();
        foreach ($service_consents as $service_consent){
            $consent_info = array();
            $consent_info['id'] = $service_consent->consent_id;
            $consent_info['title'] = $service_consent->consent->consent_name;
            $consent_info['description'] = $service_consent->consent->consent_large_description;
            $consents_infos[$service_consent->service_id][] = $consent_info;
        }

        foreach ($upcoming_appointments as $appointment) {
            if (isset($consents_infos[$appointment['service_id']])) {
                foreach ($consents_infos[$appointment['service_id']] as $consent_info) {
                    if (AppointmentHealthtimelineConsent::where('consent_id', $consent_info['id'])->where('appointment_id', $appointment['id'])->exists()) {
                        $status = "Completed";
                    }else{
                        $status = "Assigned";
                    }
                    $consent = array();
                    $consent['id'] = $consent_info['id'];
                    $consent['status'] = $status;
                    $consent['title'] = $consent_info['title'];
                    $consent['date'] = strtotime($appointment['appointment_datetime']);
                    $consent['appointment_id'] = (string)$appointment['id'];
                    $consent['service'] = $appointment['service_name'];
                    $consent['description'] = $consent_info['description'];
                    $data[] = $consent;
                }
            }
        }
        return $data;
    }

    public static function getQuestionnairesByUser($account_id, $patient_id)
    {
        $data = array();
        $appointment_additional_relations = ['appointment_healthtimeline_questionnaires'];

        $upcoming_appointments = AppointmentService::getAppointmentsByPeriod($patient_id, $appointment_additional_relations, 'upcoming');
        $service_ids = self::getServiceIdsByUpcomingAppointments($upcoming_appointments);

        $service_questionnaires = ServiceQuestionnaire::with('questionnaires', 'procedureTemplate')
            ->whereIn('service_id', array_unique($service_ids))
            ->get();

        $questionnaires_infos = array();
        foreach ($service_questionnaires as $service_questionnaire) {
            $questionnaire_info = array();
            $questionnaire_info['id'] = $service_questionnaire->questionnaire_id;
            $questionnaire_info['title'] = $service_questionnaire->questionnaires->consultation_title;
            $questionnaires_infos[$service_questionnaire->service_id][] = $questionnaire_info;
        }

        foreach ($upcoming_appointments as $appointment) {
            if (!isset($questionnaires_infos[$appointment['service_id']])) {
                continue;
            }

//            $appointment_questionnaire = $appointment['appointment_healthtimeline_questionnaires'];
//            if ($appointment_questionnaire && $template_id = $appointment_questionnaire['procedure_template_id']) {
//                $data[] = self::getProcedureTemplateQuestion($template_id, $appointment['id']);
//            }

            foreach ($questionnaires_infos[$appointment['service_id']] as $questionnaire_info) {
                $appointment_questionnair_records = AppointmentQuestionnair:: where('appointment_id', $appointment['id'])->where('service_id', $appointment['service_id'])->where('patient_id', $patient_id)->where('consultation_id', $questionnaire_info['id'])->get();
                $appointment_questionnair_choice_records = AppointmentQuestionnairChoice:: where('consultation_id', $questionnaire_info['id'])
                    ->where('appointment_id', $appointment['id'])
                    ->where('service_id', $appointment['service_id'])
                    ->where('patient_id', $patient_id)
                    ->get();

                $answer_count = ($appointment_questionnair_records->count() || $appointment_questionnair_choice_records->count());
                $status = $answer_count ? "Completed" : "Assigned";
                $questions = Question:: with('question_choices')
                    ->where('consultation_id', $questionnaire_info['id'])
                    ->where('status', 0)
                    ->select('id', 'question', 'question_type')
                    ->orderBy('order_by', 'ASC')
                    ->get()
                    ->toArray();

                if (!$questions) {
                    continue;
                }

                $questionnaire = array();
                $questionnaire['id'] = $questionnaire_info['id'];
                $questionnaire['status'] = $status;
                $questionnaire['title'] = $questionnaire_info['title'];
                $questionnaire['date'] = strtotime($appointment['appointment_datetime']);
                $questionnaire['appointment_id'] = (string)$appointment['id'];
                $questionnaire['questions'] = array();

                foreach ($questions as $question_info) {
                    $question = array();
                    $question['id'] = strval($question_info['id']);
                    $question['title'] = $question_info['question'];
                    $question['type'] = $question_info['question_type'];
                    if ($question_info['question_type'] == 'yesno') {
                        $question['type'] = 'yes_no';
                    }

                    if ($question_info['question_type'] == 'yesno') {
                        $question['yes_no'] = "undefined";
                        $question['answer'] = "";
                        $answer = $appointment_questionnair_records->where('question_id', strval($question_info['id']))->toArray();

                        if ($answer) {
                            $key = array_keys($answer)[0];

                            $arr = ['no', 'yes', 'undefined'];
                            $question['yes_no'] = $arr[$answer[$key]['answer']];

                            $comment = $answer[$key]['comment'];
                            $question['answer'] = $comment;
                        }
                    }

                    if ($question_info['question_type'] != 'yes_no') {
                        $answer = $appointment_questionnair_choice_records->where('question_id', strval($question_info['id']))->toArray();

                        if ($answer) {
                            $choices = array();
                            foreach ($answer as $ans) {
                                $choices[] = $ans['choice_id'];
                            }
                        }
                    }

                    if ($question['type'] != 'yes_no') {
                        $question['multianswers'] = false;
                        $question['variants'] = array();

                        if (count($question_info['question_choices']) < 2) {
                            continue;
                        };

                        if ($question_info['question_choices'][0]['multiple_selection'] == 1) {
                            $question['multianswers'] = true;
                        }

                        $account_storage_folder = DB::table('accounts')->where('id', $account_id)->select('storage_folder')->first();
                        $storage_folder = $account_storage_folder->storage_folder;
                        $img_url = env('MEDIA_URL') . $storage_folder . '/questionnaires/';

                        foreach ($question_info['question_choices'] as $question_choice) {
                            $choice = array();
                            $choice['id'] = strval($question_choice['id']);

                            if ($question['type'] == 'multitext') {
                                $choice['title'] = $question_choice['text'];
                            } elseif ($question['type'] == 'multiimage') {
                                $choice['image'] = $img_url . $question_choice['image'];
                            }

                            $choice['selected'] = false;

                            if (isset($choices) && in_array($choice['id'], $choices)) {
                                $choice['selected'] = true;
                            }
                            $question['variants'][] = $choice;
                        }
                    }

                    $questionnaire['questions'][] = $question;
                }
                $data[] = $questionnaire;
            }

        }

        return $data;
    }

    public static function getServiceIdsByUpcomingAppointments($upcoming_appointments)
    {
        $service_ids = array();
        foreach ($upcoming_appointments as $appointment) {
            $service_ids[] = $appointment['service_id'];
        }
        return $service_ids;
    }

    public static function savePatientUserConsent($consent_id, $image_data, $appointment_id, $account_id)
    {
        $account_data = Account::where('id', $account_id)->select('database_name', 'storage_folder', 'admin_id')->first();
        $database_name = $account_data['database_name'];

        $postdata = [];
        $postdata['api_name'] = 'upload_patient_signature';
        $postdata['upload_type'] = 'patient_signatures';
        $postdata['account']['storage_folder'] = $account_data['storage_folder'];
        $postdata['user_data']['id'] = $account_data['admin_id'];
        $postdata['image_data'] = $image_data;
        $file = file_get_contents($postdata['image_data']);
        $file_url = "data:image/png;base64,".base64_encode($file);
        $postdata['image_data'] = $file_url;
        $response = UploadExternalHelper::uploadExternalData($postdata);

        if ($response->status != 200 || empty($response->data->file_name)) {
            $response_array = array('status' => 'error', 'message' => 'Something went wrong');
            return json_encode($response_array);
        }

        switchDatabase($database_name);

        $clinicTimeZone = 'America/New_York';
        $now = convertTZ($clinicTimeZone);
        $signature_image = $response->data->file_name;
        $data['appointment_id'] = $appointment_id;
        $data['consent_id'] = $consent_id;
        $data['user_id'] = 0;
        $data['signature_image'] = $signature_image;
        $data['signed_on'] = $now;
        $data['is_signed'] = 1;
        $data['created'] = $now;

        if ($consent_id) {
            if (AppointmentHealthtimelineConsent::where('consent_id', $consent_id)->where('appointment_id', $appointment_id)->exists()) {
                $saved_consent = AppointmentHealthtimelineConsent::where('consent_id', $consent_id)->where('appointment_id', $appointment_id)->first();
                $saved_consent->signature_image = $signature_image;
                $saved_consent->modified = $now;
                $saved_consent->save();
                $affected_rows = 1;
            } else {
                $affected_rows = AppointmentHealthtimelineConsent::insert($data);
            }

            $procedure = Procedure::where('appointment_id', $appointment_id)
                ->select('id', 'appointment_id')->first();
            if ($procedure) {
                $procedureConsent = ProcedureHealthtimelineConsent::where('procedure_id', $procedure->id)
                    ->where('consent_id', $consent_id)->first();
                if ($procedureConsent) {
                    $procedureConsent->signature_image = $signature_image;
                    $procedureConsent->is_signed = 1;
                    $procedureConsent->save();
                }
            }
            return $affected_rows;
        }
    }

    public static function savePatientUserQuestionnaire($questionnaireId, $questionnaire_answers, $appointment_id, $user_id, $account_id, $database_name)
    {
        switchDatabase($database_name);

        $insert_data = array();

        $newYorkTimeZone = 'America/New_York';
        $now = convertTZ($newYorkTimeZone);
        $healthQuestinnaire = new AppointmentHealthtimelineQuestionnaire;
        if ($healthQuestinnaire->find($questionnaireId)) {
            AppointmentHealthtimelineAnswer::where('appointment_healthtimeline_questionnaire_id', $questionnaireId)->delete();
        }

        $patient = PatientUserHelper::getPatient($user_id, $account_id);
        $patient_id = $patient->patient_id;
        $appointment_detail = Appointment::where('id', $appointment_id)->with('appointment_services')->first()->toArray();
        $service_id = $appointment_detail['appointment_services'][0]['service_id'];
        $data = [];

        $healthQuestinnaire->appointment_id = $appointment_id;
        $healthQuestinnaire->user_id = 0;
        $healthQuestinnaire->save();
        $questionIds = array_column($questionnaire_answers, 'id');
        $questions = Question::whereIn('id', $questionIds)->get()->toArray();
        $multiple_choice_types = ['multitext', 'multiimage'];
        $choice_data = [];

        foreach ($questionnaire_answers as $key => $val) {
            $answer = null;
            $score = 0;
            $comment = '';

            $questions_detail_idx = array_search((int)$val->id, array_column($questions, 'id'));
            $question_type = $questions[$questions_detail_idx]['question_type'];

            if (in_array($question_type, $multiple_choice_types)) {
                if(is_array($val->variants)) {

                    foreach($val->variants as $option) {
                        if(!empty($option) && $option->selected) {
                            $choice_data[] = array(
                                'patient_id'		=> $patient_id,
                                'appointment_id'	=> $appointment_id,
                                'service_id'		=> $service_id,
                                'consultation_id' 	=> $questionnaireId,
                                'question_id' 		=> $questions[$questions_detail_idx]['id'],
                                'choice_id' 		=> $option->id,
                                'status' 			=> 0,
                                'created'			=> $now
                            );
                        }
                    }
                } else {

                    $choice_data[] = array(
                        'patient_id'		=> $patient_id,
                        'appointment_id'	=> $appointment_id,
                        'service_id'		=> $service_id,
                        'consultation_id' 	=> $questionnaireId,
                        'question_id' 		=> $questions[$questions_detail_idx]['id'],
                        'choice_id' 		=> $val->id,
                        'status' 			=> 0,
                        'created'			=> $now
                    );
                }

                $variants = array_column($val->variants, 'selected');
                $answer = isset($val->variants) ? implode(',', $variants) : null;
            } else {
                $string_answer = $val->selected;
                $string_to_numeric_answer = ['no' => 0, 'yes' => 1, 'undefined' => 2];
                $answer = $string_to_numeric_answer[$string_answer];

                if(!is_null($answer) || ($answer == 2 && isset($val->text))){
                    $comment = isset($val->text) ? $val->text : '';
                    $patient_comment = '';

                    if ($val) {
                        $patient_comment = $val->text;
                    }

                    $yes_no_record = array(
                        'patient_id' => (int)$patient_id,
                        'appointment_id' => (int)$appointment_id,
                        'service_id' => (int)$service_id,
                        'consultation_id' => (int)$questionnaireId,
                        'question_id' => $questions[$questions_detail_idx]['id'],
                        'answer' => $answer,
                        'status' => 0,
                        'comment' => trim($patient_comment),
                        'created' => $now
                    );

                    $data[] = $yes_no_record;
                }

            }

            $healthQuestinnaire_id = $healthQuestinnaire->toArray()["id"];

            $insert_data[] = array(
                'appointment_healthtimeline_questionnaire_id' => (int)$healthQuestinnaire_id,
                'question_id' => $questions[$questions_detail_idx]['id'],
                'question_type' => $question_type,
                'score' => $score,
                'answer' => $answer,
                'comment' => $comment,
                'status' => 0,
                'created' => $now
            );
        }

        AppointmentQuestionnair:: where('appointment_id', $appointment_id)->where('consultation_id', $questionnaireId)->where('patient_id', $patient_id)->where('service_id', $service_id)->delete();
        AppointmentQuestionnairChoice:: where('appointment_id', $appointment_id)->where('consultation_id', $questionnaireId)->where('patient_id', $patient_id)->where('service_id', $service_id)->delete();

        $affected_rows = 0;
        $affected_choice = 0;

        AppointmentHealthtimelineAnswer::insert($insert_data);

        $res = AppointmentQuestionnair::insert($data);
        if ($res) {
            $affected_rows += $res;
        }

        $res = AppointmentQuestionnairChoice::insert($choice_data);
        if ($res) {
            $affected_choice += $res;
        }

        return $affected_rows && $affected_choice;
    }

    public static function getProcedureTemplateQuestion($template_id, $appointment_id)
    {
        $template_data = ProcedureTemplate::with(['procedureTemplateQuestion' => function($quest) {
            $quest->with(['procedureTemplateQuestionOption'
                ,'procedureTemplatesLogic'])
                ->where('status',0)->orderby('order_by', 'ASC');
        }])
            ->where('id',$template_id)
            ->where('status',0)->first();
        $filledQuestionnair = AppointmentHealthtimelineQuestionnaire::where('procedure_template_id',$template_id)
            ->where('appointment_id',$appointment_id)->with('healthTimelineAnswer')->first();
        $filledData = [];
        $filled_questionnaire_id = 0;
        if(!empty($filledQuestionnair['healthTimelineAnswer'])){
            foreach($filledQuestionnair['healthTimelineAnswer'] as $filled){
                $filledData[$filled->question_id]['question_id'] 	= $filled->question_id;
                $filledData[$filled->question_id]['question_type'] = $filled->question_type;
                $filledData[$filled->question_id]['comment'] = $filled->comment;
                if($filled->question_type == 'Opinion Scale'){
                    $filledData[$filled->question_id]['answer'] 		= $filled->score;
                }else{
                    $filledData[$filled->question_id]['answer'] 		= $filled->answer;
                }
            }
            $filled_questionnaire_id = $filledQuestionnair->id;
        }
        if(!empty($template_data['procedureTemplateQuestion'])){
            foreach($template_data['procedureTemplateQuestion'] as $question){
                $question->answer = null;
                $question->comment = null;
                if(!empty($filledData) && $question->id == @$filledData[$question->id]['question_id'] ){
                    $question->answer = $filledData[$question->id]['answer'];
                    $question->comment = $filledData[$question->id]['comment'];
                }
                if(!empty($question['procedureTemplateQuestionOption'])){
                    foreach($question['procedureTemplateQuestionOption'] as $options){
                        $logicArray = [];
                        if(!empty($question['procedureTemplatesLogic'])){
                            foreach($question['procedureTemplatesLogic'] as $logic){

                                $logicArray[$logic->procedure_question_option_id]['option_id'] = $logic->procedure_question_option_id;
                                $logicArray[$logic->procedure_question_option_id]['jump_to_question'] = $logic->jump_to_question;
                            }
                        }
                        //if($question->question_type == 'Opinion Scale'){
                        $options->jump_to = 0;
                        if(array_key_exists($options->id,$logicArray)){
                            //echo "</br>if"; echo $options->id;
                            $options->jump_to = $logicArray[$options->id]['jump_to_question'];
                        }else{
                            //echo "</br>else"; echo $options->id;
                            if(array_key_exists(0,$logicArray)){
                                $options->jump_to = $logicArray[0]['jump_to_question'];
                            }
                        }

                        //}
                    }
                }
                //~ if($question->question_type == 'Opinion Scale'){
                //~ die;
                //~ }
            }
        }

        $template_data['filled_questionnaire_id'] = $filled_questionnaire_id;
        //echo "<pre>"; print_r($template_data->toArray()); die;
        return $template_data;
    }

}
