<?php


namespace App\Services;


use App\AccountClearentConfig;
use App\Helpers\BookingHelper;
use App\Helpers\StripeHelper;
use App\Patient;
use App\PatientCardOnFile;

class CardService
{
    public static function authorizeCardUsingAprivaByToken($account, $input, $patientID, $type="new")
    {
        $responseArray 			= array("status" => "error", "data" => array(), "message" => "Unable to connect with payment gateway, please try again");

        if ( count($account) > 0 ) {
            $aprivaProductId 	= $account->pos_product_id;
            $aprivaClientId		= $account->pos_client_id;
            $aprivaClientSecret	= $account->pos_secret;
            $aprivaPlatformKey	= $account->pos_platform_key;
            $access_token 		= connect($aprivaProductId, $aprivaClientId, $aprivaClientSecret, $aprivaPlatformKey);
            $uniqueIdentifier   = BookingHelper::generateRandomString(6)."-".BookingHelper::generateRandomString(5)."-".BookingHelper::generateRandomString(4);

            if ( !empty($access_token) ) {
                if ( !empty($input['card_number']) && !empty($input['expiry_month']) && !empty($input['expiry_year']) && !empty($input['cvv']) && $type == "new" ) {
                    $hostTransactionData = credit_authorization($access_token, $aprivaPlatformKey, $uniqueIdentifier, $input, '0.01');

                    if ( count($hostTransactionData) ) {
                        if ( isset($hostTransactionData->Result->ResponseCode) &&  $hostTransactionData->Result->ResponseCode == 0 ) {
                            $responseArray["status"] 	= "success";
                            $responseArray["data"] 		= $hostTransactionData;
                            $responseArray["message"] 	= "Card authorized successfully";
                        } else {
                            $responseArray["message"] 	= "An error occured - ".$hostTransactionData->Result->ResponseText;
                        }
                    } else {
                        $responseArray["message"] 		= "We are unable to authorize your card, please try again";
                    }
                } else {
                    if ( $type == "saved" ) {
                        if ( $patientID ) {
                            $patient_on_file 		= array();
                            $patient_on_file 		= PatientCardOnFile::where('patient_id',$patientID)->first();

                            if ($patient_on_file) {
                                $cardToken				= $patient_on_file->card_on_file;
                                $hostTransactionData 	= credit_authorization_saved_card($access_token, $aprivaPlatformKey, $uniqueIdentifier, $cardToken, '0.01');

                                if ( count($hostTransactionData) ) {
                                    if ( isset($hostTransactionData->Result->ResponseCode) &&  $hostTransactionData->Result->ResponseCode == 0 ) {
                                        $responseArray["status"] 	= "success";
                                        $responseArray["data"] 		= $hostTransactionData;
                                        $responseArray["message"] 	= "Card authorized successfully";
                                    } else {
                                        $responseArray["message"] 	= "An error occured - ".$hostTransactionData->Result->ResponseText;
                                    }
                                } else {
                                    $responseArray["message"] 		= "We are unable to authorize your card, please try again";
                                }
                            }
                        }
                    }
                }
                disconnect($access_token);
            }
        }
        return $responseArray;
    }

    public static function authorizeCardUsingStripeByToken($account, $customerTokenID, $email, $appointmentType, $isFreeVirtualService, $bookAppointMent)
    {
        $responseArray = array("status" => "error", "data" => array(), "message" => "Unable to connect with payment gateway, please try again");

        if (count((array)$account) > 0) {
            $dbname = $account->database_name;
            $stripeUserID = BookingHelper::getAccountStripeConfig($account, $bookAppointMent);

            if ($appointmentType && $appointmentType == 'virtual' && $isFreeVirtualService == "false") {
                $serviceAmount = BookingHelper::getServiceAmount($dbname, $bookAppointMent);
                $serviceAmount = $serviceAmount->price;
            } else {
                $serviceAmount = 0;
            }

            if (strlen($stripeUserID) > 0) {

                $createCustomerArr = array(
                    "email" => $email,
                    "source" => $customerTokenID,
                );

                $createStripeCustomerResponse = callStripe('customers', $createCustomerArr);

                if ($createStripeCustomerResponse && isset($createStripeCustomerResponse->id) && !empty($createStripeCustomerResponse->id)) {
                    $customerTokenID = $createStripeCustomerResponse->id;
                    $accountName = !empty($account->name) ? $account->name : 'Aesthetic Record';
                    $accountName = substr(strtoupper(str_replace(' ', '', $accountName)), 0, 20);
                    $accountName = cleanString($accountName);
                    $accountName = preg_replace('/[^A-Za-z0-9\-]/', '', $accountName);

                    $accountCurrency = $account->stripe_currency;
                    $currencyMinAmnt = StripeHelper::stripeMinimumAmount($accountCurrency);

                    if ($appointmentType && $appointmentType == 'in_person') {
                        $chargeArr = array(
                            "amount" => $currencyMinAmnt,
                            "capture" => 'false',
                            "customer" => $customerTokenID,
                            "currency" => $accountCurrency,
                            "statement_descriptor_suffix" => strtoupper($accountName),
                            "description" => $email . ' booked an appointment',
                            "on_behalf_of" => $stripeUserID,
                            "transfer_data" => array(
                                "destination" => $stripeUserID,
                            ),
                        );
                    } else if ($appointmentType && $appointmentType == 'virtual' && $isFreeVirtualService == "true") {
                        $chargeArr = array(
                            "amount" => $currencyMinAmnt,
                            "capture" => 'false',
                            "customer" => $customerTokenID,
                            "currency" => $accountCurrency,
                            "statement_descriptor_suffix" => strtoupper($accountName),
                            "description" => $email . ' booked a free virtual appointment',
                            "on_behalf_of" => $stripeUserID,
                            "transfer_data" => array(
                                "destination" => $stripeUserID,
                            ),
                        );
                    } else if ($appointmentType && $appointmentType == 'virtual' && $isFreeVirtualService == "false") {
                        $amnt = (float)$serviceAmount;
                        $finalAmnt = $amnt * 100;

                        $chargeArr = array(
                            "amount" => $finalAmnt,
                            "capture" => 'true',
                            "customer" => $customerTokenID,
                            "currency" => $accountCurrency,
                            "statement_descriptor_suffix" => strtoupper($accountName),
                            "description" => $email . ' booked a virtual appointment',
                            "on_behalf_of" => $stripeUserID,
                            "transfer_data" => array(
                                "destination" => $stripeUserID,
                            ),
                        );
                    }
                    $chargeCustomerResponse = callStripe('charges', $chargeArr);

                    if ($chargeCustomerResponse) {
                        $responseArray["status"] = "success";
                        $responseArray["data"] = $chargeCustomerResponse;
                        $responseArray["message"] = "Card authorized successfully";
                    } else {
                        $responseArray["message"] = "An error occured - " . $chargeCustomerResponse;
                    }
                    return $responseArray;
                }
            }
        }
        return array("status" => "error", "data" => array(), "message" => "Unable to process: Stripe connection not found");
    }

    public static function authorizeCardUsingClearentByToken($account, $input, $patientID, $type="new", $appointmentType, $isFreeVirtualService, $bookAppointMent){
        $responseArray 			= array("status" => "error", "data" => array(), "message" => "Unable to connect with payment gateway, please try again");

        $ip = null;
        if(array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }

        if ( count((array)$account) > 0 ) {
            $dbname					= $account->database_name;


            $stripeConnectionType	= $account->stripe_connection;
            if($stripeConnectionType == 'global'){
                $clinicID 			= 0;
            } else {
                $clinicID 			= $input['selClinic'];
            }
            $stipeCon				= [
                ['account_id', $account->id],
                ['clinic_id', $clinicID]
            ];
            $accountClearentConfig	= (array)AccountClearentConfig::where($stipeCon)->first();
            if(count($accountClearentConfig) > 0){
                $accountClearentConfig	= $accountClearentConfig->toArray();
            }else{
                $accountClearentConfig	= array();
            }

            if ( empty($accountClearentConfig) ) {
                $responseArray 			= array("status" => "error", "data" => array(), "message" => "Unable to process: Clearent connection not found");
            }

            if ( empty($accountClearentConfig['apikey']) ) {
                $responseArray 			= array("status" => "error", "data" => array(), "message" => "Unable to process: Clearent api key not found");
            }


            $stripeUserID	= $accountClearentConfig['merchant_id'];
            $platformFee	= $accountClearentConfig['platform_fee'];

            $input					= $input['formData'];

            if ($appointmentType && $appointmentType == 'virtual' && $isFreeVirtualService == "false") {
                $serviceAmount 		= BookingHelper::getServiceAmount($dbname, $bookAppointMent);

                $serviceAmount 		= $serviceAmount->price;
            } else {
                $serviceAmount 		= 0;
            }

            if ( strlen($stripeUserID) > 0 ) {
                if ( !empty($input['card_number']) && !empty($input['expiry_month']) && !empty($input['expiry_year']) && !empty($input['cvv']) && $type == "new" ) {

                    $input['expiry_year'] = substr($input['expiry_year'],-2);
                    $result_set = Clearent::createToken($input,$accountClearentConfig['apikey']);

                    if ( count($result_set) ) {
                        if(!empty($result_set["status"]) && $result_set["status"] == 200){
                            $clearent_array = $result_set["data"];
                            $customerTokenID = $clearent_array["payload"]["tokenResponse"]["token-id"];
                            $cardExpiryDate  = $clearent_array["payload"]["tokenResponse"]["exp-date"];
                            $input['clearent_email_id']  = $clearent_array["payload"]["tokenResponse"]["clearent_email_id"];

                            $accountName			= !empty($account->name) ? $account->name : 'Aesthetic Record';
                            $accountName			= substr(strtoupper(str_replace(' ', '', $accountName)), 0, 20);
                            $accountName			= cleanString($accountName);
                            $accountName			= preg_replace('/[^A-Za-z0-9\-]/', '', $accountName);

                            if ($isFreeVirtualService == "false") {
                                $headers = 	[
                                    'content-type: application/json',
                                    'accept: application/json',
                                    'api-key: '.$accountClearentConfig['apikey']
                                ];
                                $endPoint = env('ClEARENT_PAYMENT_URL').'transactions/sale';
                                $invoice_number 		= 'AR00'.$account->id.'0'.$patientID.'0'.time();
                                $postData = array(
                                    "type" => 'SALE',
                                    "exp-date" => $cardExpiryDate,
                                    "amount" => number_format((float)$serviceAmount, 2, '.', ''),
                                    "card" => $customerTokenID,
                                    "description" => strtoupper($accountName),
                                    "order-id" => 0,
                                    "invoice"  => $invoice_number ?? '',
                                    "email-address" => $input['clearent_email_id'] ?? $input['patient_email'],
                                    "customer-id" => self::getClearentCustomerData($patientID) ?? '',
                                    'software-type' => env('CLEARENT_SOFTWARE_TYPE'),
                                    'software-type-version' => env('CLEARENT_SOFTWARE_VERSION'),
                                    "client-ip" => isset($ip) ?? null
                                );

                                $response_data = Clearent::curlRequest($endPoint,$headers,$postData,'POST');

                                $clearent_array = json_decode(json_encode($response_data["result"]), true);
                            }

                            if(!empty($clearent_array) && isset($clearent_array) ){

                                $responseArray["status"] 	= "success";
                                $responseArray["data"] 		= $clearent_array;
                                if(isset($invoice_number)){
                                    $responseArray["data"]['platformFee'] 		= $platformFee;
                                    $responseArray["data"]['invoice_number'] 		= $invoice_number;
                                }
                                $responseArray["message"] 	= "Card authorized successfully";
                            } else {
                                $responseArray["message"] 	= "An error occured - " . $clearent_array;
                            }
                        } else {
                            $responseArray["message"] 	= "An error occured - " . $result_set["message"];
                        }
                    } else {
                        $responseArray["message"] 		= "We are unable to authorize your card, please try again";
                    }
                } else {
                    if ( $type == "saved" ) {
                        if ( $patientID ) {
                            $patient_on_file 		= PatientCardOnFile::where('patient_id',$patientID)->first();

                            if ($patient_on_file) {
                                /// TO DO IN FUTURE
                            }
                        }
                    }
                }
            } else {
                $responseArray 			= array("status" => "error", "data" => array(), "message" => "Unable to process: Stripe connection not found");
            }
        }
        return $responseArray;
    }

    private static function getClearentCustomerData($id,$type=null){
        $patients 	= Patient::where('id', $id)->first();

        if($type == 'email'){
            return $patients->email ?? '';
        }else{
            $name_data = [];
            if(!empty($patients->id)){
                $name_data[] = $patients->id;
            }

            if(!empty($patients->firstname)){
                $name_data[] = $patients->firstname;
            }

            if(!empty($patients->lastname)){
                $name_data[] = $patients->lastname;
            }

            $name = implode(' ', $name_data);
            return $name;
        }
    }

}
