<?php

namespace App\Traits;
use App\Traits\TouchMd;
use App\TouchmdPatientAssoc;
use App\AccountPrefrence;
use App\Http\Controller;

trait TouchMd {
    public static function createMdPatient( $account_id, $account_prefrence, $postData, $patient_id )
    {
        // f9bf3ddd-9fd5-47c3-8a7d-35f392134738
        //check if key exists to db then check is already touchmd user or not, if not only then create there
        $token = $account_prefrence->touch_md_api_key;
        $endPoint = 'https://patientconnect-api.touchmd.com/Patients';
        $headers	 = array(
            "Authorization: Bearer $token",
            'content-type: application/json',
            'Accept: application/json'
        );

        $ch 				 = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $endPoint );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
        curl_setopt( $ch, CURLOPT_POST, 1 );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $postData ) );

        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );

        $response			 = json_decode( curl_exec( $ch ) );
        $info				 = curl_getinfo( $ch );
        curl_close ( $ch );
        if ( isset( $response->Id ) && $response->Id != '' ) {
            TouchmdPatientAssoc::create( [
                'ar_patient_id' => $patient_id,
                'touchmd_patient_id' => $response->Id
            ] );
            return true;
        }

    }
}
?>
