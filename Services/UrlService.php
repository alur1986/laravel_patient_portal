<?php
namespace App\Services;

class UrlService {
    static public function checkS3UrlValid($full_url=null): bool
    {
        $file_headers = @get_headers($full_url);
        if($file_headers && strpos($file_headers[0], '200 OK')){
            return true;
        }else{
            return false;
        }
    }
}
