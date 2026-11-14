# AWS Signature Version 4 test suite

The directories in this folder are the Signature Version 4 test cases
(`context.json`, `request.txt`, `header-canonical-request.txt`,
`header-string-to-sign.txt`, `header-signature.txt`) from the AWS
`aws-c-auth` project:
<https://github.com/awslabs/aws-c-auth/tree/main/tests/aws-signing-test-suite/v4>

They are published by Amazon.com, Inc. under the Apache License 2.0 and are
used unchanged, only as test data. They are not part of the plugin zip.

`tests/Unit/Signer/AwsTestSuiteTest.php` runs every case.
