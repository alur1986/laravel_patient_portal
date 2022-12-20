<?php


namespace App\Services;

use App\Helpers\AccountHelper;
use App\PatientInsurance;

class PatientInsuranceService
{

    public static function getPatientInsurance($account_id, $patient_id)
    {
        $data = array();

        $database_name = AccountHelper::accountDatabaseName($account_id);
        switchDatabase($database_name);

        $clinicTimeZone = 'America/New_York';
        $now = convertTZ($clinicTimeZone);

        $patient_insurance = PatientInsurance::where('patient_id', $patient_id)->first();

        if ($patient_insurance) {
            $data['insurance_provider'] = $patient_insurance->insurance_provider;
            $data['policy_id'] = $patient_insurance->policy_id;
            $data['policy_group'] = $patient_insurance->policy_group;
            $data['phone'] = $patient_insurance->phone;
            $data['name_of_insured'] = $patient_insurance->name_of_insured;
            $data['relationship'] = $patient_insurance->relationship;
            $data['employer'] = $patient_insurance->employer;
            $data['prescription_card'] = $patient_insurance->prescription_card;
            $data['carrier'] = $patient_insurance->carrier;
        }else {
            $insert['patient_id'] = $patient_id;
            $insert['created'] = $now;
            $insert['modified'] = $now;
            $affected_rows = PatientInsurance::insert($insert);

            if($affected_rows){
                $data['insurance_provider'] = '';
                $data['policy_id'] = '';
                $data['policy_group'] = '';
                $data['phone'] = '';
                $data['name_of_insured'] = '';
                $data['relationship'] = '';
                $data['employer'] = '';
                $data['prescription_card'] = '';
                $data['carrier'] = '';
            }
        }

        switchDatabase();
        return $data;
    }

    public static function updatePatientInsurance($input, $account_id, $patient_id)
    {
        $data = array();

        $database_name = AccountHelper::accountDatabaseName($account_id);
        switchDatabase($database_name);

        $clinicTimeZone = 'America/New_York';
        $now = convertTZ($clinicTimeZone);

        $patient_insurance = PatientInsurance::where('patient_id', $patient_id)->first();

        if ($patient_insurance) {
            $patient_insurance->insurance_provider = $input['insurance_provider'];
            $patient_insurance->policy_id = $input['policy_id'];
            $patient_insurance->policy_group = $input['policy_group'];
            $patient_insurance->phone = $input['phone'];
            $patient_insurance->name_of_insured = $input['name_of_insured'];
            $patient_insurance->relationship = $input['relationship'];
            $patient_insurance->employer = $input['employer'];
            $patient_insurance->prescription_card = $input['prescription_card'];
            $patient_insurance->carrier = $input['carrier'];
            $patient_insurance->modified = $now;

            $patient_insurance->save();
            $data = $input;
//            array_shift($data);
            $data['id'] = strval($patient_insurance->id);
        }else {
            array_shift($input);
            $input['patient_id'] = $patient_id;
            $input['created'] = $now;

            $insurance_id = PatientInsurance::insertGetId($input);

            if($insurance_id){
                $data = $input;
                $data['id'] = strval($insurance_id);
            }
        }

        switchDatabase();
        return $data;
    }
}
