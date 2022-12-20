<?php

namespace App\Traits;

trait Zoho {
	
	public static function createZohoModule($module, $accessToken, $postData) {
		$ch 		= curl_init();

		curl_setopt($ch, CURLOPT_URL, 'https://www.zohoapis.com/crm/v2/'.$module);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		
		$postData 	= json_encode($postData);

		$post 		= '{
					"data": '.$postData.',
					"trigger": [
						"approval",
						"workflow",
						"blueprint"
					]
		}';
		
		curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

		$headers 	= array();
		$headers[] 	= 'Authorization: Zoho-oauthtoken '.$accessToken;
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result 	= curl_exec($ch);
		$status 	= curl_getinfo($ch);

		if ( $module == "Products" ) {
			return $result;
		} else {
			return $status;
		}
	}
	
	public static function findOrCreateARProduct($productName, $accessToken) {
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, 'https://www.zohoapis.com/crm/v2/Products/search?criteria=((Product_Name:equals:'.urlencode($productName).'))');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');


		$headers 	= array();
		$headers[] 	= 'Authorization: Zoho-oauthtoken '.$accessToken;
		//$headers[] 	= 'Content-Type: application/x-www-form-urlencoded;charset=utf-8';
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		
		$result 	= curl_exec($ch);
		
		curl_close($ch);
		
		$proJson	= array();
		$proJson	= json_decode($result, true);
		$productID	= 0;
		$needToSave	= false;
		
		if (isset($proJson)) {
			foreach ($proJson as $product){
				if (isset($product)) {
					foreach ($product as $eachProduct) {
						$productID = $eachProduct['id'];
					}
				} else {
					$needToSave = true;
				}
				break;
			}
		} else {
			$needToSave = true;
		}
		
		if ($needToSave && $productID == 0) { // need to create this product at ZOHO
			$finalProductArray		= array(
				array(
					"Product_Name"	=> $productName					
				)
			);

			$response 		= self::createZohoModule("Products", $accessToken, $finalProductArray);
			$createProJson	= json_decode($response, true);
			
			if (isset($createProJson)) {
				foreach ($createProJson as $createProduct){
					if (isset($createProduct)) {
						foreach ($createProduct as $eachCreateProduct) {
							$productID = $eachCreateProduct['details']['id'];
						}
					}
					break;
				}
			}
		}
		
		return $productID;
	}
}

?>
