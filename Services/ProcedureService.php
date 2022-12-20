<?php
namespace App\Services;

use App\ProcedureHealthtimelineAnswer;
use App\ProcedureHealthtimelineQuestionnaire;
use App\SignatureConsent;
use App\ProcedureAnswer;
use DateTimeZone;
use DateTime;
use Config;

class ProcedureService {
    public static function exportProcedureHTML($data)
    {
        $prescriber = "";
        $patient = "";
        $other = "";
        $other_value = "";
        if($data["ship_to"] == 'prescriber')
        {
            $prescriber = "background:#000000;";
        }
        elseif($data["ship_to"] == 'patient')
        {
            $patient = "background:#000000;";
        }
        else{
            $other = "background:#000000;";
            $other_value = $data["ship_to"];
        }
        $male = "";
        $female = "";

        if($data['patient']['gender'] == 0)
        {
            $male = "background:#000000;";
        }elseif($data['patient']['gender'] == 1)
        {
            $female = "background:#000000;";
        }
        $prescription_yes = "";
        $prescription_no = "";
        if(isset($data["patient"]["prescription_card"])){
            $prescription_card = $data["patient"]["prescription_card"];
            if($prescription_card == 'yes'){
                $prescription_yes = "background:#000000;";
            }
            if($prescription_card == 'no'){
                $prescription_no = "background:#000000;";
            }
        }
        $is_patient_eligible_for = "";
        if(isset($data["patient"]["is_patient_eligible_for_medicare"])){
            $is_patient_eligible_for_medicare = $data["patient"]["is_patient_eligible_for_medicare"];

            if($is_patient_eligible_for_medicare == 1){
                $is_patient_eligible_for = "background:#000000;";
            }
            if($is_patient_eligible_for_medicare == 0){
                $is_patient_eligible_for = "background:#000000;";
            }
        }
        $self_relationship = "";
        $other_relationship = "";
        if($data["patient"]["relationship"] != ""){
            if($data["patient"]["relationship"] == 'self'){
                $self_relationship = "background:#000000;";
            }else{
                $other_relationship = "background:#000000;";
            }
        }
        $is_patient_new_to_therapy = "";
        if($data["is_patient_new_to_therapy"] == 1){
            $is_patient_new_to_therapy = "background:#000000;";
        }

        $is_patient_restarting_therapy = "";
        if($data["is_patient_restarting_therapy"] == 1){
            $is_patient_restarting_therapy = "background:#000000;";
        }

        $is_patient_currently_on_therapy = "";
        if($data["is_patient_currently_on_therapy"] == 1){
            $is_patient_currently_on_therapy = "background:#000000;";
        }

        $weight_type_kg = "";
        $weight_type_lb = "";
        if($data["weight_type"] == "kg"){
            $weight_type_kg = "background:#000000;";
        }
        if($data["weight_type"] == "lb"){
            $weight_type_lb = "background:#000000;";
        }

        $height_type_in = "";
        $height_type_cm = "";
        if($data["height_type"] == "in"){
            $height_type_in = "background:#000000;";
        }
        if($data["height_type"] == "cm"){
            $height_type_cm = "background:#000000;";
        }
        $other_therapies_tried_failed = "";
        if($data["other_therapies_tried_failed"] !="" ){
            $other_therapies_tried_failed = "background:#000000;";
        }

        $preferred_method_of_contact_email = "";
        $preferred_method_of_contact_phone = "";
        $preferred_method_of_contact_fax = "";
        if($data["preferred_method_of_contact"] !="" ){
            $preferred_method = $data["preferred_method_of_contact"];
            if($preferred_method =="email"){
                $preferred_method_of_contact_email = "background:#000000;";
            }
            if($preferred_method =="phone"){
                $preferred_method_of_contact_phone = "background:#000000;";
            }
            if($preferred_method =="fax"){
                $preferred_method_of_contact_fax = "background:#000000;";
            }
        }
        $to_timezone	=  env("DEFAULT_TIMEZONE");
        $to_timezone = "America/New_York";
        //~ $from_timezone	=  config("constants.default.timezone");
        $from_timezone	=   env("DEFAULT_TIMEZONE");
        $from_timezone = "America/New_York";
        $dateFormat = config("constants.default.date_format");
        $date_needed = "";
        if( (!empty($data["date_needed"])) && ($data["date_needed"] != '0000-00-00') ){
            //~ $date_needed = date('m/d/Y',strtotime(@$data["date_needed"]));
            $date_needed = $data["date_needed"];
            $date_needed = self::convertTZ($date_needed, $from_timezone, $to_timezone, 'date');
            $date_needed = self::getDateFormatWithOutTimeZone($date_needed, $dateFormat, true);
        }

        $date_of_birth = "";
        if( (!empty($data["patient"]["date_of_birth"])) && ($data["patient"]["date_of_birth"] != '0000-00-00') ){
            //~ $date_of_birth = date('m/d/Y',strtotime(@$data["patient"]["date_of_birth"]));
            $date_of_birth = $data["patient"]["date_of_birth"];
            $date_of_birth = self::convertTZ($date_of_birth, $from_timezone, $to_timezone, 'date');
            $date_of_birth = self::getDateFormatWithOutTimeZone($date_of_birth, $dateFormat, true);
        }

        $start_date = "";
        if( (!empty($data["start_date"])) && ($data["start_date"] != '0000-00-00') ){
            //~ $start_date = date('m/d/Y',strtotime(@$data["start_date"]));
            $start_date = $data["start_date"];
            $start_date = self::convertTZ($start_date, $from_timezone, $to_timezone, 'date');
            $start_date = self::getDateFormatWithOutTimeZone($start_date, $dateFormat, true);
        }

        $date_of_diagnosis = "";
        if( (!empty($data["date_of_diagnosis"])) && ($data["date_of_diagnosis"] != '0000-00-00') ){
            //~ $date_of_diagnosis = date('m/d/Y',strtotime(@$data["date_of_diagnosis"]));
            $date_of_diagnosis = $data["date_of_diagnosis"];
            $date_of_diagnosis = self::convertTZ($date_of_diagnosis, $from_timezone, $to_timezone, 'date');
            $date_of_diagnosis = self::getDateFormatWithOutTimeZone($date_of_diagnosis, $dateFormat, true);
        }

        $date_of_last_weight = "";
        if( (!empty($data["date_of_last_weight"])) && ($data["date_of_last_weight"] != '0000-00-00') ){
            $date_of_last_weight = $data["date_of_last_weight"];
            $date_of_last_weight = self::getDateFormatWithOutTimeZone($date_of_last_weight, $dateFormat, true);
        }

        $last_date_of_height = "";
        if( (!empty($data["last_date_of_height"])) && ($data["last_date_of_height"] != '0000-00-00') ){
            $last_date_of_height = $data["last_date_of_height"];
            $last_date_of_height = self::getDateFormatWithOutTimeZone($last_date_of_height, $dateFormat, true);
        }
        /*date_of_last_weight, last_date_of_height needs to put in new html will provided by prince when price has done his new changes */
        $html ='<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <link rel="shortcut icon" href="data:image/x-icon;," type="image/x-icon">
    <title>Aesthetic Record - Telemedicine</title>
	<script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
  </head>
  <body>

	<style>
		body, table{
			font-family:"lato",arial;
			font-size:12px;
		}
	</style>

            <table border="0" cellpadding="0" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="25%">
						<div style="padding: 5px 0px;text-align:center;"><img src="https://test.aestheticrecord.com/backend/images/logo.png" style="height:30px;"></div>
					 </td>
					  <td valign="top" width="75%" style="font-size:16px;text-align:right;padding-top:10px;">
							<b>Prescription / Pharmacy Intake Form</b>
					  </td>
				  </tr>
			   </tbody>
            </table>
            
            <table border="0" cellpadding="5" cellspacing="0" width="100%" style="margin:20px 0px;">
			   <tbody>
				  <tr>
					 <td valign="top" width="100%" style="background:#047ec3;color:#ffffff;font-size:13px;padding:5px 10px;">
						 PHARMACY INFORMATION
					 </td>
				  </tr>
				</tbody>
			</table>
            
            <table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="100%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="50">Pharmacy:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["pharmacy"]["name"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
				</tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="50%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="100">Pharmacy Fax:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["pharmacy"]["fax"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="50%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="110">Pharmacy Phone:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["pharmacy"]["phone"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
				</tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="30%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="100">Date Needed:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.$date_needed.'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="70%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="340"><span>Ship To:</span>
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$prescriber.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Prescriber’s Office
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$patient.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Patient’s Home
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$other.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Other:
								 </td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.$other_value.'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			<tbody>
			 <tr>
				<td valign="top" width="25%">
					<table border="0" cellpadding="5" cellspacing="0" width="100%">
					<tr>
					<td valign="top" width="25">Country:</td>
					<td valign="top" style="border-bottom:1px solid #000000;">'.@$data["pharmacy"]["country"].'</td>
				 </tr>
				 </table>
				</td>
				<td valign="top" width="75%">
					<table border="0" cellpadding="5" cellspacing="0" width="100%">
						 <tr>
							<td valign="top" width="40">Address:</td>
							<td valign="top" style="border-bottom:1px solid #000000;">'.@$data["pharmacy"]["address"].'</td>
						 </tr>
				 </table>
				</td>
			 </tr>
			</tbody>
	 </table>

			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="40">City:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["pharmacy"]["city"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="40">State:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["pharmacy"]["state"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="70">Zip code:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["pharmacy"]["zipcode"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>

			<table border="0" cellpadding="5" cellspacing="0" width="100%" style="margin:20px 0px;">
			   <tbody>
				  <tr>
					 <td valign="top" width="100%" style="background:#047ec3;color:#ffffff;font-size:13px;padding:5px 10px;">
						 PATIENT INFORMATION
					 </td>
				  </tr>
				</tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="80">First name:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["firstname"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="80">Last name:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["lastname"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="40">DOB:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.$date_of_birth.'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="25%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="80">
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$male.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Male
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$female.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Female
								 </td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="75%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="40">Address:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["address_line_1"].' '.@$data["patient"]["address_line_2"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="40">City:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["city"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="40">State:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["state"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="70">Zip code:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["pincode"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="120">Phone # (Daytime):</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["phoneNumber"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="120">Phone # (Evening):</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["phoneNumber_2"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="50%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="120">E-mail Address:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["email"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="50%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="100">Case Manager:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["case_manager"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="100%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="410">Insurance provider (Please include copy of front and back of card):</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["insurance_provider"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="40">ID #:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["policy_id"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="100">Policy/Group #:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["policy_group"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="60">Phone #:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["phone"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="150">
								 <span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$is_patient_eligible_for.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Patient is eligible for Medicare
									</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="110">Name of Insured:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["name_of_insured"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="50">Employer:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["employer"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="100%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="240">Relationship to Patient:
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$self_relationship.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Self
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$other_relationship.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Other:
								 </td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["relationship"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="100%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="240">Prescription Card:
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px;'.$prescription_yes.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Yes
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$prescription_no.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									No Carrier:
								 </td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["carrier"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>

			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="100%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="500">Will there be access to anaphylactic medications and oxygen at the administration site?</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["patient"]["will_there_be_access_to"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%" style="margin:20px 0px 10px;">
			   <tbody>
				  <tr>
					 <td valign="top" width="100%" style="background:#047ec3;color:#ffffff;font-size:13px;padding:5px 10px;">
						 CLINICAL ASSESSMENT
					 </td>
				  </tr>
				</tbody>
			</table>
			
			
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="80">
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px;'.$is_patient_new_to_therapy.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Patient is new to therapy
								 </td>
							  </tr>
						</table>
					 </td>
					<td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="80">
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$is_patient_restarting_therapy.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Patient is restarting therapy
								 </td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="80">
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$is_patient_currently_on_therapy.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Patient is currently on therapy
								 </td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="25%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="70">Start date:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.$start_date.'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="75%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="270">Primary Diagnosis Code and Condition (ICD-10):</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["primary_diagnosis_code"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="30%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="110">Date of Diagnosis:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.$date_of_diagnosis.'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="70%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="180">Other Diagnosis/Conditions:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["other_diagnosis_conditions"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="50%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="100">Current Weight:</td>
								 <td valign="top" width="40" style="border-bottom:1px solid #000000;">'.@$data["current_weight"].'</td>
								 <td valign="top" width="80">
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$weight_type_lb.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									lb
									
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$weight_type_kg.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Kg
								 </td>
								 <td valign="top" width="30">Date:</td>
								 <td valign="top" width="70" style="border-bottom:1px solid #000000;">'.$date_of_last_weight.'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="50%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="100">Current Height:</td>
								 <td valign="top" width="40" style="border-bottom:1px solid #000000;">'.@$data["current_height"].'</td>
								 <td valign="top" width="80">
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$height_type_in.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									in
									
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$height_type_cm.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									cm
								 </td>
								 <td valign="top" width="30">Date:</td>
								 <td valign="top" width="70" style="border-bottom:1px solid #000000;">'.$last_date_of_height.'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="100%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="270">
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px;'.$other_therapies_tried_failed.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Other Therapies Tried & Failed (Please List):
								 </td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["other_therapies_tried_failed"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="100%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="60">
									Allergies:
								 </td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["allergies"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<div style="page-break-before:always;">&nbsp;</div>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%" style="margin:20px 0px;">
			   <tbody>
				  <tr>
					 <td valign="top" width="100%" style="background:#047ec3;color:#ffffff;font-size:13px;padding:5px 10px;">
						 PRESCRIPTION INFORMATION
					 </td>
				  </tr>
				</tbody>
			</table>
			
			<table border="0" cellpadding="10" cellspacing="0" width="100%" style="font-family:Lato,sans-serif;text-align:left;margin-bottom:20px;">
                    
                    <thead style="background:#daedf7;color:#667680;font-weight:normal;font-size:12px;text-align:left;">
                        <tr>
                            <th style="background: #daedf7;font-weight:normal;font-size:12px;text-align:left;width:15%;">Medication</th>
                            <th style="background: #daedf7;font-weight:normal;font-size:12px;text-align:left;width:10%;">Form</th>
                            <th style="background: #daedf7;font-weight:normal;font-size:12px;text-align:left;width:10%;">Strength</th>
                            <th style="background: #daedf7;font-weight:normal;font-size:12px;text-align:left;width:10%;">Quantity</th>
                            <th style="background: #daedf7;font-weight:normal;font-size:12px;text-align:left;width:30%;">Directions/Frequency</th>
                            <th style="background: #daedf7;font-weight:normal;font-size:12px;text-align:left;width:10%;">Dose</th>
                            <th style="background: #daedf7;font-weight:normal;font-size:12px;text-align:left;width:15%;">Refills</th>
                        </tr>
                    </thead>';
        if(!empty($data['procedure_prescription'])){
            foreach($data['procedure_prescription'] as $prescription){
                $html .='<tbody>
								<tr>
								<td style="font-weight:normal;font-size:12px;border-bottom:1px solid #dddddd;">
								'.@$prescription['medicine_name'].'</td>
								<td style="font-weight:normal;font-size:12px;border-bottom:1px solid #dddddd;">
								'.@$prescription['form'].'</td>
								<td style="font-weight:normal;font-size:12px;border-bottom:1px solid #dddddd;">
								'.@$prescription['strength'].'</td>
								<td style="font-weight:normal;font-size:12px;border-bottom:1px solid #dddddd;">
								'.@$prescription['quantity'].'</td>
								<td style="font-weight:normal;font-size:12px;border-bottom:1px solid #dddddd;">
								'.@$prescription['frequency'].'</td>
								<td style="font-weight:normal;font-size:12px;border-bottom:1px solid #dddddd;">
								'.@$prescription['dosage'].'</td>
								<td style="font-weight:normal;font-size:12px;border-bottom:1px solid #dddddd;">
								'.@$prescription['refills'].'</td>
								</tr>
							  </tbody>';
            }
        }
        $html .= '<tfoot>
						<tr>
						<td colspan="10" style="font-weight:normal;font-size:12px;border:1px solid #dddddd;border-top:none">
							I authorize, by my signature below, the dispensing of appropriate needles and syringes, in a sufficient quantity, required for the administration of injectable products by patient or
caregiver. Authorization for supplies runs concurrently with the number of refills or time frame specified for the drug.
						</td>
						</tr>
                      </tfoot>
                  </table>
                  
             <table border="0" cellpadding="5" cellspacing="0" width="100%" style="margin:20px 0px;">
			   <tbody>
				  <tr>
					 <td valign="top" width="100%" style="background:#047ec3;color:#ffffff;font-size:13px;padding:5px 10px;">
						 PRESCRIBER INFORMATION
					 </td>
				  </tr>
				</tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="30%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="80">First name:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["provider"]["firstname"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="30%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="80">Last name:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["provider"]["lastname"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="40%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="100">Practice/facility:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["business_name"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="50%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="60">Address:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["prescriber_address"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="20">City:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["prescriber_city"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="20">State:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["prescriber_state"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="70">Zip code:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["prescriber_zipcode"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="100">Office contact:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["office_contact"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="20">Phone:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["prescriber_phone"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="30">Fax:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["prescriber_fax"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="50%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="50">Email:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["provider"]["email_id"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="50%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="110">Best time to call:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["best_time_to_call"].'</td>
							  </tr>
						</table>
					 </td>
					
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="50%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="80">
									 Preferred method of contact:
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$preferred_method_of_contact_email.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Email
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$preferred_method_of_contact_phone.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Phone
									<span style="width:5px;height:5px;border:1px solid #000000;display:inline-block;margin: 2px; '.$preferred_method_of_contact_fax.'
									vertical-align: top;overflow:hidden;box-shadow:0px 0px 0px 1px #ffffff inset;-webkit-box-shadow:0px 0px 0px 1px #ffffff inset;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
									Fax
								 </td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="50%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="100">State license #:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["provider"]["state_license"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="50">DEA #:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["provider"]["dea"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="50">NPI #:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["provider"]["npi"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" width="110">Medicaid UPIN #:</td>
								 <td valign="top" style="border-bottom:1px solid #000000;">'.@$data["provider"]["medicaid_upin"].'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
			   </tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%">
			   <tbody>
				  <tr>
						<td valign="top" width="100%" style="font-size:12px;">
						In order for a brand name product to be dispensed, the prescriber must handwrite “Brand Necessary” or “Brand Medically Necessary” or your state specific required language after their
signature. I certify that the above therapy is medically necessary and that the information above is accurate to the best of my knowledge. Prescriber’s signature required on one of the lines below.
					</td>
				  </tr>
				</tbody>
			</table>
			
			<table border="0" cellpadding="5" cellspacing="0" width="100%" style="margin-bottom:20px;">
			   <tbody>
				  <tr>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" style="border-bottom:1px solid #000000;height:15px;text-align:center;">'.@$data["substitution_permitted"].'</td>
							  </tr>
						</table>
					 </td>
					 <td valign="top" width="33%">
						 <table border="0" cellpadding="5" cellspacing="0" width="100%">
							  <tr>
								 <td valign="top" style="border-bottom:1px solid #000000;height:15px;text-align:center;">'.date('m/d/Y').'</td>
							  </tr>
						</table>
					 </td>
				  </tr>
				  <tr>
					<td valign="top" width="33%" style="font-size:10px;text-align:center;padding:0px;">
						Substitution permitted
					</td>
					<td valign="top" width="33%" style="font-size:10px;text-align:center;padding:0px;">
						Date
					</td>
				  </tr>
			   </tbody>
			</table>
			';


        if(!empty($data["signature_url"]) && isset($data["signature_url"])){
            $html .='<table border="0" cellpadding="5" cellspacing="0" width="100%">
					<tbody>
					 <tr>
						<td valign="top" width="100%">
							<table border="0" cellpadding="2" cellspacing="0" width="100%">
								 <tr>
									<td valign="top" style="text-align:right;">
									<img style="height:50px;" src="'.$data["signature_url"].'"/>
									</td>
								 </tr>
								 <tr>
									<td valign="top" style="font-size:13px;color:#404040; text-align:right;">('.$data["provider"]["full_name"].')</td>
								 </tr>
						 </table>
						</td>
					 </tr>
				 </tbody>
			 </table>';
        }
        $html .='</body>
</html>
';

        return $html;

    }

    public static function convertTZ($time, $fromTimezone, $toTimezone, $type='time'){
        if ( !empty($fromTimezone) ) {
            if ( $fromTimezone == "UTC" ) {
                $fromTimezone = "America/New_York";
            }
        }
        $toTimezone = trim($toTimezone);
        $fromTimezone = trim($fromTimezone);
        if($type == 'time'){
            $time 		= date('Y-m-d')." ".$time;
        }

        $date 		= new DateTime($time, new DateTimeZone($fromTimezone));
        $date->setTimezone(new \DateTimeZone($toTimezone));
        if($type == 'time'){
            return $date->format('H:i:s');
        }else{
            return $date->format('Y-m-d H:i:s');
        }
    }

    /* get database date in selected date format */
    public static function getDateFormatWithOutTimeZone($value,$date_format, $doFormatting = false) {
        $return = "0000-00-00";
        if (!is_null($value) && !empty($value && $value != '0000-00-00')){
            if($doFormatting){
                //~ $new_date = $value;
                $new_date = date($date_format,strtotime($value));
            }
            $return = $new_date;
        }
        return $return;
    }

}
