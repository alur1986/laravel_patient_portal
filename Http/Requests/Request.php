<?php

namespace App\Http\Requests;

use App\Model\MedicalHistory\SocialHistory;
use Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Session;
use Route;
use App\Account;
use Illuminate\Foundation\Http\FormRequest;
use App\Helpers\PatientUserHelper;
use App\Services\RequestTypesService;
use App\Http\Controllers\Controller;
use Illuminate\Routing\Controller as RoutingController;

abstract class Request extends FormRequest
{
    const MOBILE_PREFIX = 'mobile';

    private $request_type = RequestTypesService::WEB_REQUEST_TYPE;

    public function __construct()
    {
        parent::__construct();
        $request_type = $this->getRequestTypeFromPrefix();
        $this->__setRequestType($request_type);

        if ($this->__getRequestType() !== RequestTypesService::WEB_REQUEST_TYPE) {
            return;
        }

        $referer = $this->headers->get('referer');
        if ($referer) {
            if (strpos($referer, 'password/reset')) {
                Session::put('success', 'Your password has been updated, please login to continue');
                \Illuminate\Support\Facades\Auth::logout();
            }
        }
//original//
//        $this->middleware('auth');
//        $user = Auth::user();
//        $this->session()->put('user', $user);
//        $this->checkAccountStatus($this);

//      $this->middleware('auth');
        $user = Auth::user();
        Session::put('user', $user);
        $controller = new Controller();
        $controller->checkAccountStatus($this);
    }

    public function validate()
    {
        $instance = $this->getValidatorInstance();

        if ($this->__getRequestType() == RequestTypesService::WEB_REQUEST_TYPE && !$this->passesAuthorization()) {
//check            $this->failedAuthorization();
        } elseif (!$instance->passes()) {
            $this->failedValidation($instance);
        }
    }

    protected function getRequestTypeFromPrefix(): int
    {
        $request_type = RequestTypesService::WEB_REQUEST_TYPE;
        $request_uri = Route::getFacadeRoot()->current()->uri();
        $prefix = explode('/', $request_uri)[0];

        if ($prefix && $prefix == self::MOBILE_PREFIX) {
            $request_type = RequestTypesService::MOBILE_REQUEST_TYPE;
        }

        return $request_type;
    }

    public function response(array $errors)
    {
        if ($this->__getRequestType() == RequestTypesService::MOBILE_REQUEST_TYPE) {
            return new JsonResponse($errors, 422);
        }

        return $this->redirector->to($this->getRedirectUrl())
            ->withInput($this->except($this->dontFlash))
            ->withErrors($errors, $this->errorBag);
    }

    protected function __setRequestType($request_type): bool
    {
        $is_valid_type = isset(RequestTypesService::REQUEST_TYPES_ARR[$request_type]);
        if ($is_valid_type) {
            $this->request_type = $request_type;
        }

        return $is_valid_type;
    }

    protected function getWebAccountId()
    {
        $account_detail = $this->session()->get('account_detail');
        return $account_detail->id;
    }

    protected function getMobileAccountId()
    {
        $input = $this->input();
        return $input['account_id'];
    }

    protected function getWebPatientId()
    {
        return $this->session()->get('patient_id');
    }

    protected function getMobileDatabaseName()
    {
        $account_id = $this->getMobileAccountId();
        $account_data = Account::where('id', $account_id)->select('database_name')->get()->toArray();
        return $account_data[0]['database_name'];
    }

    protected function getWebDatabaseName()
    {
        return $this->session()->get('database');
    }

    protected function getMobilePatientId()
    {
        $account_id = $this->getMobileAccountId();
        $user_id = Auth::user()->id;

        $patient = PatientUserHelper::getPatient($user_id, $account_id);

        return $patient->patient_id;
    }

    public function __getRequestType(): int
    {
        return $this->request_type;
    }

    public function getPatientId()
    {
        $patient_id = 0;

        if ($this->request_type == RequestTypesService::WEB_REQUEST_TYPE) {
            $patient_id = $this->getWebPatientId();
        } else if ($this->request_type == RequestTypesService::MOBILE_REQUEST_TYPE) {
            $patient_id = $this->getMobilePatientId();
        }

        return $patient_id;
    }

    public function getDatabaseName()
    {
        $database_name = 'juvly_master';

        if ($this->request_type == RequestTypesService::WEB_REQUEST_TYPE) {
            $database_name = $this->getWebDatabaseName();
        } else if ($this->request_type == RequestTypesService::MOBILE_REQUEST_TYPE) {
            $database_name = $this->getMobileDatabaseName();
        }

        return $database_name;
    }

    public function getAccountId(): int
    {
        $patient_id = 0;

        if ($this->request_type == RequestTypesService::WEB_REQUEST_TYPE) {
            $patient_id = $this->getWebAccountId();
        } else if ($this->request_type == RequestTypesService::MOBILE_REQUEST_TYPE) {
            $patient_id = $this->getMobileAccountId();
        }

        return $patient_id;
    }
}
