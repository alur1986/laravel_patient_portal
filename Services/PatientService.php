<?php

namespace App\Services;

use App\EgiftCardRedemption;
use App\PatientCardOnFile;

class PatientService
{
    /**
     * Get get Card Vouchers
     * @param string | null $patient_id
     * @return mixed
     */
    static public function getCardVouchers($patient_id)
    {
        return EgiftCardRedemption::where('patient_id', $patient_id)
            ->with(['eGiftCardVoucher' => function ($voucher) {
                $voucher->where('is_expired', 0)->where('balance', '>', 0);
            }])
            ->whereHas('eGiftCardVoucher', function ($gift_vochar) {
                $gift_vochar->where('is_expired', 0)->where('balance', '>', 0);
            })
            ->groupBy('egift_card_voucher_id')->get();
    }

    static public function saveCard($account, $input, $patient_id, $stripeUserID="",$gatewayType = null)
    {
        if ( $account && $input ) {
            $dbname	= $account->database_name;

            switchDatabase($dbname);
            $patient_on_file 	= PatientCardOnFile::where('patient_id', $patient_id)->where('stripe_user_id', $stripeUserID)->first();


            if(!empty($gatewayType) && $gatewayType == "clearent"){
                if(!empty($input["payload"]["tokenResponse"]) && isset($input["payload"]["tokenResponse"])){
                    $card_number		= $input["payload"]["tokenResponse"]["card-type"] . ' ending ' . $input["payload"]["tokenResponse"]["last-four-digits"];
                    $card_on_file		= $input["payload"]["tokenResponse"]["token-id"];
                    $card_expiry_date   = $input["payload"]["tokenResponse"]["exp-date"];
                }
                else if(!empty($input["payload"]["transaction"]) && isset($input["payload"]["transaction"])){
                    $card_number		= $input["payload"]["transaction"]["card-type"] . ' ending ' . $input["payload"]["transaction"]["last-four"];
                    $card_on_file		= $input["payload"]["transaction"]["id"];
                    $card_expiry_date   = $input["payload"]["transaction"]["exp-date"];
                }

            } else {
                $card_number = $input['payment_method_details']->card->brand . ' ending ' . $input['payment_method_details']->card->last4;
                $card_on_file = $input['customer'];
                $card_expiry_date = $input['payment_method_details']->card->exp_month . '/' . $input['payment_method_details']->card->exp_year;
            }

            if (!$patient_on_file) {
                $PatientCardOnFile 					= new PatientCardOnFile;
                $PatientCardOnFile->patient_id  	= $patient_id;
                $PatientCardOnFile->card_on_file    = $card_on_file;
                $PatientCardOnFile->status    		= 0;
                $PatientCardOnFile->card_number    	= $card_number;
                $PatientCardOnFile->stripe_user_id  = $stripeUserID;
                $PatientCardOnFile->card_expiry_date  = $card_expiry_date;
                if ($PatientCardOnFile->save()) {
                    return true;
                } else {
                    return false;
                }

            } else {
                $update_arr	= array(
                    'card_on_file'	=> $card_on_file,
                    'card_number'	=> $card_number,
                    'stripe_user_id'=> $stripeUserID,
                    'card_expiry_date'  => $card_expiry_date ?? null
                );

                $status    = PatientCardOnFile::where('id', $patient_on_file->id)->update($update_arr);

                if ( $status ) {
                    return true;
                } else {
                    return false;
                }

            }
        } else {
            return false;
        }
    }
}

