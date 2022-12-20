<?php

namespace App\Traits;

trait Clearent {

    public static function curlRequest( $endPoint, $headers, $postData, $type = 'GET',$is_file=false ) {
        
        $ch 				 = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $endPoint );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

        switch ( $type ) {
            case 'GET':
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
            break;

            case 'POST':
            
            if($is_file){
                curl_setopt( $ch, CURLOPT_POST, 1 );
                curl_setopt( $ch, CURLOPT_POSTFIELDS,  $postData  );
            }
            else {
                if(!empty($postData) && isset($postData)){
                     
                    curl_setopt( $ch, CURLOPT_POST, 1 );
                    curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $postData ) );
                }else{
                    curl_setopt( $ch, CURLOPT_POST, 1 );
                   /* curl_setopt( $ch, CURLOPT_POST, 0 );
                    curl_setopt( $ch, CURLOPT_POSTFIELDS, "" );*/
                }
            }
            
            
            break;

            case 'PUT':
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PUT' );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $postData ) );
            break;

            case "DELETE":
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                if(!empty($postData)){
                    curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $postData ) );
                }
            break;

            default:
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
        }
        
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
       
        $response			 = json_decode( curl_exec( $ch ) );
        $info				 = curl_getinfo( $ch );
        curl_close ( $ch );
        return ['result' => $response, 'info' => $info];
    }

    public static function errorCode( $code ) {
        switch ( $code ) {
            case '401':
            $slug = 'Unauthorized';
            break;

            case '403':
            $slug = 'Forbidden';
            break;

            case '405':
            $slug = 'Http Method not supported';
            break;

            case '500':
            $slug = 'Server Error';
            break;
        }
        return $slug ?? 'Server error';

    }

    public static function validateCardToken( $endPoint, $headers, $postData, $method ) {

        $response_data = self::curlRequest( $endPoint, $headers, $postData, $method );

        if ( !empty( $response_data['result'] ) && isset( $response_data['result'] ) ) {
            $clearent_array = json_decode( json_encode( $response_data['result'] ), true );
            if ( !empty( $response_data['result'] ) && isset( $response_data['result'] ) && $clearent_array['status'] == 'success' ) {
                $response = [];
                $response =  json_decode( json_encode( $response_data['result'] ), true );
                //In This case only authorise the card and save token to db
                if ( !empty( $response['payload']['tokenResponse']['status'] ) && $response['payload']['tokenResponse']['status'] == 'Active' ) {
                    return ['status' => 200, 'message' => 'Success', 'data' => $response];
                } else {
                    return ['status' => 400, 'message' => 'card_authorize_failed', 'data' => $response];
                }
            } else {
                return ['status' => 400, 'message' => 'card_authorize_failed', 'data' =>  $clearent_array['payload']['error']['error-message'] ];
            }
        } else {
            return ['status' => 400, 'message' => 'card_authorize_failed', 'data' =>  self::errorCode( $response_data['info']['http_code'] ) ];
        }
    }

    public static function createToken_BkUp__withTempToken( $token, $api_key ) {
        $endPoint = config( 'constants.ClEARENT_PAYMENT_URL' ).'tokens';
        $headers = 	[
            'content-type: application/json',
            'api-key: '.$api_key,
        ];
        if(empty($token)){
            return ['status' => 400, 'message' => 'clearent_token_required','data' => []];
        }
       
        try {
            $cipher ="AES-256-CBC";
            $key = 'd41d8cd98f00b204e9800998ecf8427e';
            $chiperRaw = base64_decode($token);
            $iv = hex2bin('f0b53b2da041fca49ef0b9839060b345');
            $jsOriginalPlaintext = openssl_decrypt($chiperRaw, $cipher, $key, OPENSSL_RAW_DATA, $iv);
           
            if($jsOriginalPlaintext){
                $array = json_decode($jsOriginalPlaintext);
                $postData = [
                    'card' => $array->card,
                    'csc' => $array->csc,
                    'exp-date' => $array->exp_date
                ];
                $new_post_data = [];
                if(!empty($array->zip) && isset($array->zip)){
                    $new_post_data['avs-zip'] = $array->zip;
                    $postData = array_merge($postData,$new_post_data);
                }
            }else{
                return ['status' => 400, 'message' => 'invalid_payload','data' => []];
            }
        } catch(Exception $e) {
            return ['status' => 400, 'message' => $e->getMessage(),'data' => []];
        }

        $response_data = self::curlRequest( $endPoint, $headers, $postData, 'POST' );
       
        if ( !empty( $response_data['result'] ) && isset( $response_data['result'] ) ) {
            $clearent_array = json_decode( json_encode( $response_data['result'] ), true );
            if ( !empty( $response_data['result'] ) && isset( $response_data['result'] ) && $clearent_array['status'] == 'success' ) {
                $response = [];
                $response =  json_decode( json_encode( $response_data['result'] ), true );
                //In This case only authorise the card and save token to db
                if ( !empty( $response['payload']['tokenResponse']['status'] ) && $response['payload']['tokenResponse']['status'] == 'Active' ) {
                    $response['payload']['tokenResponse']['clearent_email_id'] = $array->clearent_email_id ?? '';
                    return ['status' => 200, 'message' => 'Success', 'data' => $response];
                } else {
                    return ['status' => 400, 'message' => 'card_authorize_failed', 'data' => $response];
                }
            } else {
                return ['status' => 400, 'message' => 'card_authorize_failed', 'data' =>  $clearent_array['payload']['error']['error-message'] ];
            }
        } else {
            return ['status' => 400, 'message' => 'card_authorize_failed', 'data' =>  self::errorCode( $response_data['info']['http_code'] ) ];
        }
    }
    
    public static function createToken( $token, $api_key ) {
        if(!empty($token) && !empty($api_key)){
            return ['status' => 200, 'message' => 'Success', 'data' => $token];
        }
        else{
            return ['status' => 400, 'message' => 'card_authorize_failed', 'data' => []];
        }
    }

    /*public static function createToken( $input, $api_key ) {
        $endPoint = env('ClEARENT_PAYMENT_URL').'tokens';
        $headers = 	[
            'content-type: application/json',
            'api-key: '.$api_key,
        ];
       
        try {
            //~ $cipher ="AES-256-CBC";
            //~ $key = 'd41d8cd98f00b204e9800998ecf8427e';
            //~ $chiperRaw = base64_decode($token);
            //~ $iv = hex2bin('f0b53b2da041fca49ef0b9839060b345');
            //~ $jsOriginalPlaintext = openssl_decrypt($chiperRaw, $cipher, $key, OPENSSL_RAW_DATA, $iv);
           
  
                $postData = [
                    'card' => $input['card_number'],
                    'csc' => $input['cvv'],
                    'exp-date' => $input['expiry_month'].$input['expiry_year']
                ];
                $new_post_data = [];
                if(!empty($input['pincode']) && isset($input['pincode'])){
                    $new_post_data['avs-zip'] = $input['pincode'];
                    $postData = array_merge($postData,$new_post_data);
                }
            
        } catch(Exception $e) {
            return ['status' => 400, 'message' => $e->getMessage(),'data' => []];
        }

        $response_data = self::curlRequest( $endPoint, $headers, $postData, 'POST' );
       
        if ( !empty( $response_data['result'] ) && isset( $response_data['result'] ) ) {
            $clearent_array = json_decode( json_encode( $response_data['result'] ), true );
            if ( !empty( $response_data['result'] ) && isset( $response_data['result'] ) && $clearent_array['status'] == 'success' ) {
                $response = [];
                $response =  json_decode( json_encode( $response_data['result'] ), true );
                //In This case only authorise the card and save token to db
                if ( !empty( $response['payload']['tokenResponse']['status'] ) && $response['payload']['tokenResponse']['status'] == 'Active' ) {
                    $response['payload']['tokenResponse']['clearent_email_id'] = $input['email'] ?? '';
                    return ['status' => 200, 'message' => 'Success', 'data' => $response];
                } else {
                    return ['status' => 400, 'message' => 'card_authorize_failed', 'data' => $response];
                }
            } else {
                return ['status' => 400, 'message' => 'card_authorize_failed', 'data' =>  $clearent_array['payload']['error']['error-message'] ];
            }
        } else {
            return ['status' => 400, 'message' => 'card_authorize_failed', 'data' =>  self::errorCode( $response_data['info']['http_code'] ) ];
        }
    }*/

    public static function canVoidTransaction( $endPoint, $headers, $apriva_transaction_data) {

        $response_data = self::curlRequest( $endPoint, $headers, [], 'GET' );
        
        if ( !empty( $response_data['result'] ) && isset( $response_data['result'] ) ) {
            $clearent_array = json_decode( json_encode( $response_data['result'] ), true );
            if ( !empty( $response_data['result'] ) && isset( $response_data['result'] ) && $clearent_array['status'] == 'success' ) {
                $response = [];
                $response =  json_decode( json_encode( $response_data['result'] ), true );
                //In This case only authorise the card and save token to db
                $decode_data = json_decode( $apriva_transaction_data["apriva_transaction_data"],true);
                if(!empty($decode_data['transaction'])){
                    $batch_string_id = $decode_data['transaction']['batch-string-id'];
                }else{
                    $batch_string_id = $decode_data['batch-string-id'];
                }
               
                if ( !empty($batch_string_id) && $batch_string_id == $response['payload']['batch']['id'] &&  !empty( $response['payload']['batch']['status'] ) && $response['payload']['batch']['status'] == 'OPEN' ) {
                    return ['type' => 'open', 'status' => 200, 'message' => 'Success', 'data' => $response];
                }
                else if ( !empty($batch_string_id) && $batch_string_id == $response['payload']['batch']['id'] &&  !empty( $response['payload']['batch']['status'] ) && $response['payload']['batch']['status'] == 'CLOSED' ) {
                    return ['type' => 'closed','status' => 200, 'message' => 'Success', 'data' => $response];
                }
            } else {
                return ['status' => 400, 'message' => 'card_authorize_failed', 'data' =>  $clearent_array['payload']['error']['error-message'] ];
            }
        } else {
            return ['type' => ($response_data['info']['http_code'] == 204) ? 'closed' : '','status' => ($response_data['info']['http_code'] == 204) ? 204 : 400, 'message' => 'card_authorize_failed', 'data' =>  self::errorCode( $response_data['info']['http_code'] ) ];
        }
    }



    public static function authoriseCard( $token, $api_key, $zip=null ) {
        if(empty($token)){
            return ['status' => 400, 'message' => 'clearent_token_required','data' => []];
        }
       
        $headers = 	[
            'content-type: application/json',
            'accept: application/json',
            'mobilejwt: '.$token,
            'api-key: '.$api_key
        ];
        $endPoint = env('ClEARENT_PAYMENT_URL').'mobile/transactions/auth';
        $postData = array(
            "type" => "AUTH",
            "amount" => "0.00",
            "create-token" => true,
            "card-inquiry" => true,
            'software-type' => env('CLEARENT_SOFTWARE_TYPE') ?? 'Aesthetic Record',
            'software-type-version' => env('CLEARENT_SOFTWARE_VERSION') ?? 'One',
            "billing" => ["zip" => $zip ?? '']
        );

        $response_data = [];
        $response_data = Clearent::curlRequest($endPoint,$headers,$postData,'POST');
        #dd($response_data);
        if(!empty($response_data["result"]) && isset($response_data["result"]) ){
            $clearent_array = json_decode(json_encode($response_data["result"]), true);
            if(!empty($response_data["result"]) && isset($response_data["result"]) && $clearent_array["status"] == "success"){
                return ["status" => 200, "message" => "success", "data" => $clearent_array ];
            }
            else if(!empty($response_data["result"]) && isset($response_data["result"]) && $clearent_array["status"] == "fail"){
                $response =  json_decode(json_encode($response_data["result"]), true);
                if(!empty($response["payload"]["transaction"]) && $response["payload"]["transaction"]["result"] == "DECLINED"){
                    return   ["status" => 400, "message" => $response["payload"]["transaction"]["display-message"], 
                        "data" => ["resultCode" => $response["payload"]["transaction"]["result-code"], "errorMessage" => $response["payload"]["transaction"]["display-message"]] ];
                }
                else{
                    if(!empty($response["payload"]["transaction"]['result']) &&  ($response["payload"]["transaction"]['result'] != 'APPROVED')){
                        return ["status" => 400, "message" => $clearent_array["payload"]["transaction"]["display-message"], "data" => $response["payload"]["transaction"]["display-message"] ];
                    }
                    else  if(!empty($response["payload"]["error"]) &&  isset($response["payload"]["error"])){
                        return  ["status" => 400, "message" => $response["payload"]["error"]["error-message"],  "data" => ["resultCode" => $response["payload"]["error"]["result-code"], "errorMessage" => $response["payload"]["error"]["error-message"] ] ];
                    }
                }
            }
            else{
                return ["status" => 400, "message" => $clearent_array["payload"]["error"]["error-message"], "data" => $clearent_array["payload"]["error"]["error-message"] ];
            }
        }else{
            return ["status" => 400, "message" => Clearent::errorCode($response_data["info"]["http_code"]), "data" => Clearent::errorCode($response_data["info"]["http_code"]) ];
        }
    }
}
?>
