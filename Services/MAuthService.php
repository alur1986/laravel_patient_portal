<?php

namespace App\Services;

use App\Account;
use Illuminate\Http\Response;


class MAuthService
{
    /**
     * Save new user and send confirmation email in transaction
     * @param
     * @return bool
     */
    public static function savePatientUserAndSendActivationEmail($patient_user, $email): bool
    {
        if ($patient_user->save()) {
            $email_sent = app('App\Http\Controllers\Mobile\MPatientUserController')->sendActivationLink($patient_user->id, $email)->status() == Response::HTTP_OK;
//            if($email_sent){
                #gets testAccount by id
                $testAccount = Account::where('id', 1300)->first();

                PatientUserAccountService::addPatientUserToAccount($patient_user, $testAccount);

                return true;
//            }
        }
        return false;
    }

}

