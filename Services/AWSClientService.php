<?php

namespace App\Services;

use Aws\Credentials\Credentials;
use Aws\DynamoDb\DynamoDbClient;
use Aws\S3\S3Client;
use Exception;

/**
 * Class AWSClientService
 * @package App\Services
 */
class AWSClientService
{

    /**
     * @return S3Client
     * @throws Exception
     */
    public function getS3Client(): S3Client
    {
        $credentials = $this->getCredentials();

        return new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region'),
            'credentials' => $credentials,
        ]);
    }

    /**
     * @return DynamoDbClient
     * @throws Exception
     */
    public function getDynamoDBClient(): DynamoDbClient
    {
        $credentials = $this->getCredentials();

        return new DynamoDbClient([
            'region' => config('filesystems.disks.s3.region'),
            'version' => 'latest',
            'http' => [
                'timeout' => 5
            ],
            'credentials' => $credentials
        ]);
    }

    /**
     * @return Credentials
     * @throws Exception
     */
    private function getCredentials(): Credentials
    {
        $AWS_ACCESS_KEY_ID = config('filesystems.disks.s3.key');
        $AWS_SECRET_ACCESS_KEY = config('filesystems.disks.s3.secret');

        $credentials = new Credentials($AWS_ACCESS_KEY_ID, $AWS_SECRET_ACCESS_KEY);

        if (!is_object($credentials)) {
            throw new Exception('Unable to verify AWS credentials!');
        }

        return $credentials;
    }

}
