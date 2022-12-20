<?php

namespace App\Services;

use App\Account;
use App\Patient;
use App\PatientAccount;
use App\User;
use DB;

class PatientUserAccountService
{
    /**
     * Add patientUser to account
     * @param User $patient_user
     * @param Account $account
     * @return bool
     */
    static public function addPatientUserToAccount(User $patient_user, Account $account, $isTestNumber = false): bool
    {
        #connect account db
        config(['database.connections.juvly_practice.database' => $account->database_name]);
        DB::purge('juvly_practice');
        $patientUserArr = $patient_user->toArray();

        #create new Patient
        $patient = new Patient;
        unset($patient->{'0'});
        $patient->user_id = 0;
        $patient->firstname = htmlentities(addslashes(trim(preg_replace('/\s+/', ' ', $patientUserArr['first_name']))), ENT_QUOTES, 'utf-8');
        $patient->lastname = htmlentities(addslashes(trim(preg_replace('/\s+/', ' ', $patientUserArr['last_name']))), ENT_QUOTES, 'utf-8');
        $patient->email = $patientUserArr['email'];
        $patient->gender = 2;
        $patient->phoneNumber = $patient_user['phone'];
        $patient->status = $isTestNumber? 0:1;
        $patient->access_portal = 1;

        if (!$patient->save()) {
            return false;
        }

        $patient_id = $patient->id;

        #create new PatientAccount
        $patient_account = new PatientAccount;
        $patient_account->patient_id = $patient_id;
        $patient_account->patient_user_id = $patient_user->id;
        $patient_account->account_id = $account->id;

        #connect main db
        config(['database.connections.juvly_practice.database' => env('DB_DATABASE')]);

        return $patient_account->save();
    }

}

