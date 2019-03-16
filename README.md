# AwsGen - PHP classes for the [AWS SDK](https://github.com/aws/aws-sdk-php)

AwsGen generates strictly typed PHP classes that allow you to use 
Amazon Web Services with objects instead of arrays.

## Installation
`\Rtek\AwsGen` has no runtime component, so require it as a development dependency:
```
$ composer require --dev rtek/aws-gen
```

## Why Bother?

AWS has approximately 170 services with ~18,000 types. The SDK provides access 
to these services using `\ArrayAccess` and rich runtime [metadata](https://github.com/aws/aws-sdk-php/tree/master/src/data), 
but does not offer code-completion by realizing the API in PHP classes*.

`\Rtek\AwsGen` will generate PHP classes for the services and operations that 
you choose, while allowing you to use the underlying SDK at all times. 

You can treat these classes as read-only, or embed them in your project
as the basis for more sophisticated wrappers.

<sub>*If you used AwsGen for all services, there would be ~10x the number of files as the SDK</sub>

## An Example: S3 Service

### Console generation
```
$ vendor/bin/aws-gen generate

 Search Service:
 > s3

 Choose Service [Search again]:
  [0] Stop searching
  [1] Search again
  [2] s3
  [3] s3:2006-03-01
  [4] s3control
  [5] s3control:2018-08-20
 > 2

 What namespace? [App\AwsGen\]:
 > App\AwsGen\

 What output directory? [src]:
 > src

 PSR-4 namespace prefix? [App\AwsGen\]:
  [0]
  [1] App\
  [2] App\AwsGen\
 > 1

Generating: s3
==============

 Added s3:latest
 Generating...
 ...Complete

 [OK] Wrote 294 files to src/AwsGen/
```

### PHP generation
```php
<?php

namespace App;

use Rtek\AwsGen\Generator;
use Rtek\AwsGen\Writer\DirWriter;

$gen = new Generator('App\\AwsGen');  //generate classes to the 'App\AwsGen' namespace
$gen->addService('s3', '2006-03-01'); //add the s3 service, version optional
DirWriter::create('src')              //set the root directory to write the files
    ->setPsr4Prefix('App\\')          //optionally set a PSR4 prefix
    ->write($gen);                    //writes App\AwsGen\S3 to src/AwsGen/S3
```

### Usage
```php
<?php 

namespace App;

use App\AwsGen\S3 as S3;

$config = [
    'credentials' => [
        'key' => '***',
        'secret' => '***',
    ],
    'region' => 'us-east-1', 
];
//generated client extends `\Aws\S3Client` with the same config as SDK except
//for `version` which is overridden by the specified generation version
$client = new S3\S3Client($config); 
                                    
//the operation input create(...) contains required params
$input = S3\CreateBucketRequest::create($bucket = 'test'); 

//they can be set directly just like optional params
$input->Bucket($bucket)->ACL('public-read');               
              
//operation names are the same as SDK   
$output = $client->createBucket($input);

//the operation output has getters that match the SDK 
echo "Bucket created at: {$output->Location()}\n"; 

//supports fluent interface
$output = $client->putObject(
    S3\PutObjectRequest::create($bucket, $key = 'foo.txt')
        ->Body('bar baz')->ContentType('text/plain')
);   

//`\ArrayAccess` works as usual since output classes extend `\Aws\Result`
echo "Created object {$key} with ETag {$output['ETag']}\n"; 

//supports setting values via array by constructor
$input = new S3\GetObjectRequest([
    'Bucket' => $bucket,
    'Key' => $key,
]);
$output = $client->getObject($input);
echo "The object has a body of: {$output->Body()}\n";

//you can bypass AwsGen classes by passing the array argument to the client
$result = $client->getObject([
    'Bucket' => $bucket,
    'Key' => $key,
]);
echo "The object still has a body of: {$result['Body']}\n";

//`\IteratorAggregate` is implemented for iterable properties
$output = $client->listObjectsV2(S3\ListObjectsV2Request::create($bucket));
foreach ($output->Contents() as $object) {
    $client->deleteObject(S3\DeleteObjectRequest::create($bucket, $object->getKey()));
}
```

## Terms

* `Service`
    * An AWS service that has a `Client` and metadata
    * Contains multiple `Operation` and `Shape`
    * e.g. `S3`, `DynamoDb`, `Ec2`
* `Operation`
    * An AWS API call that does something
    * Has an `Input` and an `Output`
    * e.g. `DynamoDb\DynamoDbClient::putItem()`, `S3\S3Client::getObject()`
* `Shape`
    * An AWS type that can be serialized as an associative array
    * Accessors are read and write
    * e.g. `S3\ObjectList`, `Ec2\Instance`
* `Input`
    * A `Shape` that contains the input parameters for an `Operation`
    * Defines the expected `Output` for an `Operation`
    * Accessors are write-only
    * e.g. `DynamoDb\PutItemInput`, `S3\ListObjectRequest`
* `Output`
    * A `Shape` that contains the result of an `Operation`
    * Extends `\Aws\Result`
    * Accessors are read-only
    * e.g. `DynamoDb\PutItemOutput`, `S3\ListObjectOutput`
* `Client`
    * Extends the corresponding SDK client
    * Marshals an `Input` to the SDK `Operation` and returns the `Output`
    * e.g. `S3\S3Client`, `DynamoDb\DynamoDbClient`
    
## Issues / Quriks

* Paginators are not implemented
* CommandPools are not implemented
* `\Aws\Result::$data` is passed by value to `Output` classes
* `_` will be appended the PHP class name when:
    * A service contains two types with identical case-insensitive names
    * A type is a PHP keyword
* Some service names are oddly named vs the namespace: e.g. `streams.dynamodb => DynamoDbStreams`
* Some `Input` classes use the term `Request` instead of `Input` per the SDK metadata

### Acknowledgements
* Inspired by [goetas-webeservices/xsd2php](https://github.com/goetas-webservices/xsd2php)
