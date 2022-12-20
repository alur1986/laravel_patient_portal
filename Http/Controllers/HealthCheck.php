<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * HealthCheck.
 *
 * @author Dmytro Maliar <dmytro.maliar@onix-systems.com>
 * @since  2.0
 */
final class HealthCheck extends Controller
{
    /**
     * Health Check.
     *
     * @return Response
     */
    public function index(): Response
    {
        return response('Patient Portal Healthy', Response::HTTP_OK);
    }
}
