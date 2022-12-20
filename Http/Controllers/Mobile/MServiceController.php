<?php

namespace App\Http\Controllers\Mobile;

use App\Helpers\AccountHelper;
use App\ServiceCategoryAssoc;
use App\Services\ProviderService;
use App\Validators\ServiceValidator;
use DB;
use App\Service;
use App\ServiceCategory;
use App\ServiceProvider;
use App\ServiceClinic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Session;
use App\Http\Controllers\Controller;
use Validator;

class MServiceController extends Controller
{
    const PROVIDER_ROLES = [
        1 => 'admin',
        2 => 'provider',
        3 => 'frontdesk',
        4 => 'md'
    ];

    /**
     * @SWG\Post(
     *      path="/mobile/get_services",
     *      operationId="getServicesList",
     *      summary="Get available services info",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="account_id",
     *          description="Id of patient's account",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="6818"
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="clinic_id",
     *          description="Id of patient's clinic",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="3"
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="service_type",
     *          description="What type of service: virtual or in_person",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="in_person"
     *          )
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="Services info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="array",
     *                  @SWG\Items(
     *                      type="object",
     *                      @SWG\Property(property="category_id", type="string", example="1"),
     *                      @SWG\Property(property="category_name", type="string", example="Wrinkle Treatment"),
     *                      @SWG\Property(
     *                          property="services",
     *                          type="array",
     *                          @SWG\Items(
     *                              type="object",
     *                              @SWG\Property(property="id", type="string", example="2"),
     *                              @SWG\Property(property="name", type="string", example="Botox/Dysport Current Patient"),
     *                              @SWG\Property(property="description", type="string", example="Keep your wrinkles at bay and for the everyday glow"),
     *                              @SWG\Property(property="duration", type="string", example="50"),
     *                          ),
     *                      ),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function getServicesList(Request $request): JsonResponse
    {
        $input = $request->all();

        $validatorMsg = ServiceValidator::getServicesListValidate($input);

        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];
        $service_type = $input['service_type']=='real'?'in_person':$input['service_type'];
        $clinic_id = $input['clinic_id'];

        $database_name = AccountHelper::accountDatabaseName($account_id);
        switchDatabase($database_name);

        $services_in_clinic_ids = ServiceClinic::where('clinic_id', $clinic_id)
            ->whereHas('services', function($q) use ($service_type) {
                $q->where('service_type', $service_type);
            }, '=')
            ->pluck('service_id');

        $services_with_providers_ids = ServiceProvider::whereIn('service_id', $services_in_clinic_ids)
            ->whereHas('providerClinic', function($q) use ($clinic_id) {
                $q->where('clinic_id', $clinic_id);
            }, '=')
            ->pluck('service_id');

        $services = ServiceCategoryAssoc::whereIn('service_id', $services_with_providers_ids)
            ->join('services as s', 'service_category_assocs.service_id', '=', 's.id')
            ->select('s.id', 's.name', 's.description', 's.duration', 'service_category_assocs.category_id')
            ->get()->toArray();

        $category_ids = array_column($services, 'category_id');

        $categories = ServiceCategory::select('id', 'name')->whereIn('id', $category_ids)->get()->toArray();

        $data = [];

        foreach ($categories as $category) {
            $services_by_category = [];

            foreach($services as $service){
                if($category['id'] == $service['category_id']){
                    $service['name'] = ucfirst($service['name']);
                    $service['id'] = strval($service['id']);
                    unset($service['category_id']);
                    $services_by_category[] = $service;
                }
            }

            $data[] = [
                'category_id' => strval($category['id']),
                'category_name' => ucfirst(trim($category['name'])),
                'services' => $services_by_category
            ];
        }

        switchDatabase();

        return $this->sendResponse(Response::HTTP_OK, 'successfully retrieved', $data);
    }

    /**
     * @SWG\Post(
     *      path="/mobile/get_service_providers",
     *      operationId="getServiceProviders",
     *      summary="Get available services providers",
     *      @SWG\Parameter(
     *          name="Authorization",
     *          description="User Authorization Bearer",
     *          required=true,
     *          in="header",
     *          type="string",
     *          @SWG\Schema(
     *              type="string",
     *              example="Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJ..."
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="account_id",
     *          description="Id of patient's account",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="6818"
     *          )
     *      ),
     *     @SWG\Parameter(
     *          name="clinic_id",
     *          description="Id of patient's clinic",
     *          required=true,
     *          in="body",
     *          @SWG\Schema(
     *              type="string",
     *              example="3"
     *          )
     *      ),
     *      @SWG\Parameter(
     *          name="service_id",
     *          description="Ids of requested services",
     *          required=true,
     *          in="body",
     *          @SWG\Property(
     *              property="data",
     *              type="array",
     *              @SWG\Items(
     *                      type="number",
     *                      example="1"
     *              ),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=400,
     *          description="Missed required fields",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="error"),
     *              @SWG\Property(property="message", type="string", example="missed_required_fields"),
     *              @SWG\Property(property="data", type="string", example="1610206040"),
     *          ),
     *      ),
     *      @SWG\Response(
     *          response=200,
     *          description="providers' info successfully retrieved",
     *          @SWG\Items(
     *              type="object",
     *              @SWG\Property(property="status", type="string", example="success"),
     *              @SWG\Property(property="message", type="string", example="successfully retrieved"),
     *              @SWG\Property(
     *                  property="data",
     *                  type="array",
     *                  @SWG\Items(
     *                      type="object",
     *                      @SWG\Property(property="provider_id", type="string", example="26"),
     *                      @SWG\Property(property="firstname", type="string", example="Marcus"),
     *                      @SWG\Property(property="description", type="string", example="Marcus Rathford is a licensed managing aesthetician specializing in microneedling, coolsculpting, and chemical peels."),
     *                      @SWG\Property(property="provider_position", type="string", example="provider"),
     *                      @SWG\Property(property="full_profile_img_path", type="string", example="http://stage-eva.myaestheticrecord.com/uploads/profiles/user"),
     *                  ),
     *              ),
     *          ),
     *     ),
     * )
     * @param Request $request
     * @return JsonResponse
     */
    public function getServiceProviders(Request $request): JsonResponse
    {
        $input = $request->all();

        $validatorMsg = ServiceValidator::getServiceProvidersValidate($input);

        if ($validatorMsg) {
            return $this->sendResponse(Response::HTTP_BAD_REQUEST, $validatorMsg);
        }

        $account_id = $input['account_id'];
        $service_id = $input['service_id'];
        $clinic_id = $input['clinic_id'];

        $database_name = AccountHelper::accountDatabaseName($account_id);
        switchDatabase($database_name);

        $providers = ServiceProvider::with('serviceClinic', 'service')
            ->whereHas('serviceClinic', function($q) use ($clinic_id) {
                $q->where('clinic_id', $clinic_id);}, '=')
            ->whereHas('providerClinic', function($q) use ($clinic_id) {
                $q->where('clinic_id', $clinic_id);}, '=')
            ->where('service_id', $service_id)
            ->get();

        $data = [];

        switchDatabase();

        foreach ($providers as $provider) {
            $service_type = $provider['service']['service_type'];
            $service_duration = $provider['service']['duration'];

            $data_provider_schedule = ProviderService::getProviderSchedule($provider['user_id'], $clinic_id, $service_type, $service_duration);

            if (!count($data_provider_schedule['days'])) {
                continue;
            }

            $userInfo = DB::table('users')
                ->where('id', $provider['user_id'])
                ->select('firstname', 'bio_description', 'user_image', 'user_role_id')
                ->get();

            if($userInfo[0]->user_image==""){
                $full_profile_img_path = getenv('APP_URL') . '/img/user.png';
            }else{
                $full_profile_img_path = getenv('APP_URL') . '/uploads/profiles/' . $userInfo[0]->user_image;
            }

            $data[] = [
                'provider_id' => $provider['user_id'],
                'firstname' => $userInfo[0]->firstname,
                'description' => $userInfo[0]->bio_description,
                'provider_position' => ucfirst(self::PROVIDER_ROLES[2]),
                'full_profile_img_path' => $full_profile_img_path
            ];
        }

        return $this->sendResponse(Response::HTTP_OK, 'successfully retrieved', $data);
    }

}

