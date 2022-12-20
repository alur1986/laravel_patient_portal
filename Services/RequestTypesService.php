<?php

namespace App\Services;

class RequestTypesService
{
    const WEB_REQUEST_TYPE = 0;
    const MOBILE_REQUEST_TYPE = 1;

    const REQUEST_TYPES_ARR = [
        self::WEB_REQUEST_TYPE => 'WEB_REQUEST_TYPE',
        self::MOBILE_REQUEST_TYPE => 'MOBILE_REQUEST_TYPE',
    ];
}

