<?php


namespace App\Http\Controllers\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use Auth;
use Aws\Credentials\Credentials;
use Aws\Pinpoint\PinpointClient;
use Aws\Exception\AwsException;
use Aws\Pinpoint\Exception\PinpointException;
use App\Account;
use Aws\Credentials\CredentialProvider;
use Aws\Sts\StsClient;

class MChatController extends Controller
{
    public function getClient(Request $request)
    {
        $data = [
            'response' => 'ok',
        ];

        return $this->sendResponse(Response::HTTP_OK, 'successful', $data);
        $input = $request->all();

        $account_id = $input['account_id'];
        $account_data = Account::where('id', $account_id)->select('database_name')->get()->toArray();
        $database_name = $account_data[0]['database_name'];
        $user_id = Auth::user()->id;

        $credentials = $this->getCredentials();
        if (!is_object($credentials)) {
            return [
                'status' => 400,
                'message' => "Unable to verify AWS credentials",
                'data' => []
            ];
        }

//        dd($credentials);

//        $result = $stsClient->getSessionToken();
//
//        $credentials = new Credentials(
//            $result['Credentials']['AccessKeyId'],
//            $result['Credentials']['SecretAccessKey'],
//            $result['Credentials']['SessionToken']
//        );

        $client = new PinpointClient([
            'version' => 'latest',
            'region'  => config('filesystems.disks.s3.region') ?? 'us-west-2',
            'credentials' => [
                'key'    => 'AKIA3FID7JKJU7R23DXU',
                'secret' => 'AKIA3FID7JKJU7R23DXU',
            ],
        ]);
//        'credentials' => $credentials
//        $s3Client = new S3Client([
//            'version'     => 'latest',
//            'region'      => 'us-west-2',
//            'credentials' => [
//                'key'    => 'my-access-key-id',
//                'secret' => 'my-secret-access-key',
//            ],
//        ]);

//        'credentials' => $credentials
//dd($client);
//        try {
            $result = $client->createApp([
                'CreateApplicationRequest' => [
                    'Name' => 'ChatApp',
                ],
            ]);
//        } catch (PinpointException $e) {
//            echo $e->getMessage();
//        }
//        dd($result);

        $data = [
            'response' => 'ok',
        ];

        return $this->sendResponse(Response::HTTP_OK, 'successful', $data);
    }

    private function getCredentials(): Credentials
    {
        $AWS_ACCESS_KEY_ID = config('filesystems.disks.s3.key');
        $AWS_SECRET_ACCESS_KEY = config('filesystems.disks.s3.secret');
        return new Credentials($AWS_ACCESS_KEY_ID, $AWS_SECRET_ACCESS_KEY);
    }
}
