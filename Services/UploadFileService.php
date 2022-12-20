<?php

namespace App\Services;


use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Mpdf\Mpdf;

class UploadFileService
{

    private $file;
    private $folderPath;
    private $fileName;
    private $allowedAllMime = ['pdf']; // You can add other mime there. Must also have values from an $allowedDocMime
    private $allowedDocMime = ['pdf']; // You can add other mime there
    private $fileExtension;
    private $replaceSymbols = [" ", "+", "%", "@", "(", ")", "!", "#", "^", "&", "*", "=", "?", "~", "|", "{", "}", "[", "]"];

    public function __construct($fileFullPath, $folderPath, $uploadURI = false)
    {
        if (!$uploadURI && !file_exists($fileFullPath)) {
            throw new \Exception('File not found!');
        }

        $this->folderPath = $folderPath;
        $this->file = $fileFullPath;
        $pathInfo = pathinfo($fileFullPath);
        $this->fileExtension = $pathInfo['extension'];
        $this->fileName = $pathInfo['filename'];
        $file_type = strtolower($this->fileExtension);

        if (!in_array($file_type, $this->allowedAllMime)) {
            throw new \Exception('Allowed type not found!');
        }
    }

    public function getS3Client(): S3Client
    {
        $credentials = $this->getCredentials();
        if (!is_object($credentials)) {
            throw new \Exception('Unable to verify AWS credentials!');
        }

        return new S3Client([
            'version' => 'latest',
            'region' => config('filesystems.disks.s3.region') ?? 'us-west-2',
            'credentials' => $credentials,
        ]);
    }

    public function uploadFileToS3($upload_type = '', $overrideUploadImage = null): array
    {
        $originalName = $this->getFileOriginalName($overrideUploadImage);
        $uploadFile = $this->removeSpecialChars($originalName);
        $keyName = "{$this->folderPath}/{$uploadFile}";
        $bucket = config("filesystems.disks.s3.bucket");
        $data['file_name'] = '';

        try {
            $s3 = $this->getS3Client();
            $s3->putObject([
                'Bucket' => $bucket,
                'Key' => $keyName,
                'SourceFile' => $this->file,
                'ContentType' => 'pdf',
                'ACL' => 'public-read',
            ]);
            $data['file_name'] = $uploadFile;

            return $data;
        } catch (\Exception $e) {
            throw new \Exception("{$e->getMessage()} : {$e->getLine()}");
        }
    }

    public function getFileOriginalName($overrideUploadImage = null): string
    {
        if (isset($overrideUploadImage) && !empty($overrideUploadImage)) {
            return $overrideUploadImage;
        } else {
            return trim(str_replace(" ", "_", "{$this->fileName}.{$this->fileExtension}"));
        }
    }

    private function getCredentials(): Credentials
    {
        $AWS_ACCESS_KEY_ID = config('filesystems.disks.s3.key');
        $AWS_SECRET_ACCESS_KEY = config('filesystems.disks.s3.secret');
        return new Credentials($AWS_ACCESS_KEY_ID, $AWS_SECRET_ACCESS_KEY);
    }

    public function removeSpecialChars($originalName)
    {
        return str_replace($this->replaceSymbols, "_", $originalName);
    }

    public static function deleteLocalTemporaryDir($dirPath)
    {
        if (!is_dir($dirPath)) {
            throw new \InvalidArgumentException("$dirPath must be a directory");
        }
        if (substr($dirPath, strlen($dirPath) - 1, 1) != '/') {
            $dirPath .= '/';
        }
        $files = glob($dirPath . '*', GLOB_MARK);
        foreach ($files as $file) {
            if (is_dir($file)) {
                self::deleteLocalTemporaryDir($file);
            } else {
                unlink($file);
            }
        }
        rmdir($dirPath);
    }

    public function uploadToS3ByURI(Mpdf $pdf): array
    {
        $bucket = config("filesystems.disks.s3.bucket");
        $originalName = $this->getFileOriginalName();
        $uploadFile = $this->removeSpecialChars($originalName);

        $aws_file = "s3://{$bucket}/{$this->folderPath}/{$uploadFile}";

        try {
            $s3 = $this->getS3Client();
            $s3->registerStreamWrapper();
            $pdf->Output($aws_file);
            $data['file_name'] = $uploadFile;

            return $data;
        } catch (\Exception $exception) {
            throw new \Exception("{$exception->getMessage()} : {$exception->getLine()}");
        }
    }

}