<?php
declare(strict_types=1);

namespace Saizul\R2Offload\Tests\Unit\Signer;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Saizul\R2Offload\Http\Request;
use Saizul\R2Offload\Signer\Credentials;
use Saizul\R2Offload\Signer\SignatureV4;

/**
 * The worked examples of the Amazon S3 API reference ("Signature Calculations
 * for the Authorization Header" and "Authenticating Requests: Using Query
 * Parameters"), with their published example keys and signatures.
 */
final class S3DocumentationExamplesTest extends TestCase {

	/** @var SignatureV4 */
	private $signer;

	/** @var DateTimeImmutable */
	private $time;

	/** @var array<string,bool> */
	private $s3 = array(
		'normalize_path'        => false,
		'content_sha256_header' => true,
	);

	protected function setUp(): void {
		$this->signer = new SignatureV4( new Credentials( 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY' ), 'us-east-1', 's3' );
		$this->time   = new DateTimeImmutable( '2013-05-24T00:00:00Z' );
	}

	private function signature( Request $request ): string {
		$header = (string) $this->signer->sign( $request, $this->time, $this->s3 )->header( 'Authorization' );

		return substr( $header, strrpos( $header, '=' ) + 1 );
	}

	public function test_get_object_with_range(): void {
		$request = new Request( 'GET', 'https://examplebucket.s3.amazonaws.com/test.txt', array( 'Range' => 'bytes=0-9' ) );
		$signed  = $this->signer->sign( $request, $this->time, $this->s3 );

		self::assertSame( 'f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $this->signature( $request ) );
		self::assertSame(
			'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41',
			$signed->header( 'Authorization' )
		);
		self::assertSame( SignatureV4::EMPTY_SHA256, $signed->header( 'x-amz-content-sha256' ) );
	}

	public function test_put_object(): void {
		$request = new Request(
			'PUT',
			'https://examplebucket.s3.amazonaws.com/test%24file.text',
			array(
				'Date'                => 'Fri, 24 May 2013 00:00:00 GMT',
				'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
			),
			'Welcome to Amazon S3.'
		);

		self::assertSame( '98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd', $this->signature( $request ) );
		// The same key written with a raw "$" signs identically.
		self::assertSame( '98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd', $this->signature( $request->with_url( 'https://examplebucket.s3.amazonaws.com/test$file.text' ) ) );
	}

	public function test_get_bucket_lifecycle(): void {
		self::assertSame( 'fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543', $this->signature( new Request( 'GET', 'https://examplebucket.s3.amazonaws.com/?lifecycle' ) ) );
	}

	public function test_list_objects(): void {
		self::assertSame( '34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $this->signature( new Request( 'GET', 'https://examplebucket.s3.amazonaws.com/?max-keys=2&prefix=J' ) ) );
	}

	public function test_presigned_get(): void {
		self::assertSame(
			'https://examplebucket.s3.amazonaws.com/test.txt?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20130524T000000Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
			$this->signer->presign( 'GET', 'https://examplebucket.s3.amazonaws.com/test.txt', 86400, $this->time )
		);
	}
}
