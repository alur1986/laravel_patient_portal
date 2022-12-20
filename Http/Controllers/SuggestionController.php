<?php

namespace App\Http\Controllers;

use App\Http\Requests\Suggestion\MedicineNamesRequest;
use Aws\Credentials\Credentials;
use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Http\Response;

class SuggestionController extends Controller
{
    private const LIMIT = 5;

    public function medicineNames(MedicineNamesRequest $request)
    {
        $limit = $request->has('limit') ? $request->get('limit') : self::LIMIT;
        $query = $request->get('q');
        $tableName = 'medicine_names';
        $params = [
            'TableName' => $tableName,
            'Limit' => (int) $limit,
            'ScanFilter' => [
                'name' => [
                    'AttributeValueList' => [
                        ['S' => $query]
                    ],
                    'ComparisonOperator' => 'CONTAINS'
                ],
            ]
        ];

        $AWS_ACCESS_KEY_ID = config('filesystems.disks.s3.key');
        $AWS_SECRET_ACCESS_KEY = config('filesystems.disks.s3.secret');
        $credentials = new Credentials($AWS_ACCESS_KEY_ID, $AWS_SECRET_ACCESS_KEY);
        $dynamoClient = new DynamoDbClient([
            'region' => config('filesystems.disks.s3.region') ?? 'us-west-2',
            'version' => 'latest',
            'http' => [
                'timeout' => 5
            ],
            'credentials' => $credentials
        ]);
        $response = $dynamoClient->scan($params);
        $items = $response->get('Items');
        $result = [];

        foreach ($items as $key => $data) {
            foreach ($data as $k => $item) {
                $result['items'][$key][$k] = $item['S'];
            }
        }

        return response()->json($result, Response::HTTP_OK);
    }

    public static function allMedicineNames()
    {
        $tableName = 'medicine_names';
        $params = [
            'TableName' => $tableName,
            'ScanFilter' => []
        ];

        $AWS_ACCESS_KEY_ID = config('filesystems.disks.s3.key');
        $AWS_SECRET_ACCESS_KEY = config('filesystems.disks.s3.secret');
        $credentials = new Credentials($AWS_ACCESS_KEY_ID, $AWS_SECRET_ACCESS_KEY);
        $dynamoClient = new DynamoDbClient([
            'region' => config('filesystems.disks.s3.region') ?? 'us-west-2',
            'version' => 'latest',
            'http' => [
                'timeout' => 5
            ],
            'credentials' => $credentials
        ]);
        $response = $dynamoClient->scan($params);
        $items = $response->get('Items');

        $result = [];

        foreach ($items as $key => $data) {
            foreach ($data as $k => $item) {
                $result['items'][$key][$k] = $item['S'];
            }
        }

        if(!empty($result)){
            $result = array_unique(array_column($result['items'], 'name'));
            $result = array_splice($result, 0, count($result));
        }

        return $result;
    }
}
