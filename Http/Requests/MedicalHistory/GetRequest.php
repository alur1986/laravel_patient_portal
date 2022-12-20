<?php

namespace App\Http\Requests\MedicalHistory;

use App\Helpers\MedicalHistoryHelper;
use App\Http\Requests\Request;
use App\Model\MedicalHistory\NewMedicalHistory;
use App\Services\RequestTypesService;
use Illuminate\Http\Response;

class GetRequest extends Request
{
    public function rules()
    {
       if ($this->__getRequestType() == RequestTypesService::MOBILE_REQUEST_TYPE) {
           return [
               'account_id' => 'required',
           ];
       }
       return [];
    }

    public function getMedicalHistoryRes($patient_id)
    {
        $res = null;
        if ($this->__getRequestType() == RequestTypesService::WEB_REQUEST_TYPE) {
            $res = $this->getWebMedicalHistory($patient_id);
        } else if ($this->__getRequestType() == RequestTypesService::MOBILE_REQUEST_TYPE) {
            $res = $this->getMobileMedicalHistory($patient_id);
            if(!$res) {
                $res = new \stdClass();
                return sendResponse(Response::HTTP_OK, 'No data found', $res);
            }

            return sendResponse(Response::HTTP_OK, 'Medical history delivered successfully', $res);
        }

        return $res;
    }

    protected function getWebMedicalHistory($patient_id)
    {
        $accountId = $this->getAccountId();
        $patientId = $this->getPatientId();

        $medical_history = NewMedicalHistory::with(
            [
                'social_history',
                'allergy' => function($query) {
                    $query->with(['allergy_drug', 'allergy_food', 'allergy_environment']);
                },
                'current_medication' => function($query) {
                    $query->with(['prescription_medication', 'over_medication', 'vitamin']);
                },
                'family_health_history' => function($query) {
                    $query->with(['medical_issues']);
                },
                'current_medical_history' => function($query) {
                    $query->with(['ongoing_condition']);
                },
                'past_medical_history' => function($query) {
                    $query->with(['surgery', 'implant', 'vaccine']);
                },
            ])
            ->where('patient_id', $patient_id)
            ->first();

        /**
         * NOTE: CommitID: b0288c90 Username: David Kykharchyk
         *
         * It is not clear why he added this.
         * But it is obvious that it is looping the application,
         * since the current method is called in getMedicalHistoryRes.
         * And then he calls the getMedicalHistoryRes again.
         *
         * Commented out before clarifying the circumstances
         */
        //$this->getMedicalHistoryRes($patient_id);

        return view('app.medical_history.ajax.medical_history_popup', compact('medical_history', 'accountId', 'patientId'))->render();
    }

    protected function getMobileMedicalHistory($patient_id)
    {
        $medical_history = NewMedicalHistory::withAllRelations()
            ->where('patient_id', $patient_id)
            ->first();

        if($medical_history){
            $medical_history = MedicalHistoryHelper::objectConvertDatesToTimestamp($medical_history);
        }

        return $medical_history;
    }

}
