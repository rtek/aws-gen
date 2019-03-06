# AwsGen - PHP classes for [AWS SDK](https://github.com/aws/aws-sdk-php)

AwsGen generates typed PHP classes that allow you to use Amazon Web Services 
with objects instead of associative arrays.

## Installation
AwsGen has no runtime component, so require it as a development dependency:
```
//todo publish on packagist $ composer require --dev rtek/aws-gen
```

## Why Bother?

AWS has approximately 170 services with ~18,000 types. The SDK provides access 
to these services using `\ArrayAccess` and rich runtime [metadata](https://github.com/aws/aws-sdk-php/tree/master/src/data), 
but not offer code-completion by realizing the API in PHP classes.

AwsGen will generate PHP classes for the services and operations that 
you choose, while allowing you to use the underlying SDK at all times. 

You can treat these classes as read-only, or embed them in your project
as the basis for more sophisticated wrappers.

## An Example: S3 Service
### Generation
```php
<?php

$gen = new \Rtek\AwsGen\Generator('Gen'); //generate classes to the 'Gen' namespace
$gen->addService('s3', '2006-03-01'); //add the s3 service, version optional
\Rtek\AwsGen\Writer\DirWriter::create('/path/to/src/Gen', true)->write($gen);
```
### Usage
```php
<?php 

$config = [
    'credentials' => [
        'key' => '***',
        'secret' => '***',
    ],
    'region' => 'us-east-1', 
];

//generated client extends `\Aws\S3Client` with the same config as SDK except
//for `version` which is overridden by the specified generation version
$client = new \Gen\S3\S3Client($config); 

//the operation input create(...) contains required params
$input = \Gen\S3\CreateBucketRequest::create($bucket = 'test') 
    ->Bucket($bucket) //or you can set them directly
    ->ACL('public-read'); //just like you would optional params
    
$output = $client->createBucket($input); //operation names are the same as SDK
echo "Bucket created at: {$output->Location()}\n"; //the operation output has getters that match the SDK 

$input = \Gen\S3\PutObjectRequest::create($bucket, $key = 'foo.txt')
    ->Body('bar baz')
    ->ContentType('text/plain');

$output = $client->putObject($input);
echo "Created object {$key} with ETag {$output->ETag()}\n"; //you can also use `$output['ETag']`

$input = \Gen\S3\GetObjectRequest::create($bucket, $key);
$output = $client->getObject($input);
echo "The object has a body of: {$output->Body()}\n";

//or just ignore AwsGen classes
$result = $client->getObject([
    'Bucket' => $bucket,
    'Key' => $key,
]);

echo "The object still has a body of: {$result['Body']}\n";

//`\IteratorAggregate` is implemented for iterable properties
$output = $client->listObjectsV2(\Gen\S3\ListObjectsV2Request::create($bucket));
foreach ($output->Contents() as $object) {
    $client->deleteObject(\Gen\S3\DeleteObjectRequest::create($bucket, $object->getKey()));
}
```

## Terms

* `Service`
    * An AWS service that has a `Client` and metadata
    * Contains multiple `Operation` and corresponding `Shape`
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
    
## Quirks / Issues

* Paginators are not implemented
* CommandPools are not implemented
* An underscore will be appended to a PHP class name when:
    * A service contains two types with identical case-insensitive names
    * A service contains a type with a PHP keyword as the name
* Some service names are oddly named vs the namespace: e.g. `streams.dynamodb => DynamoDbStreams`
* Some input classes use the term `Request` instead of `Input` per the SDK metadata

