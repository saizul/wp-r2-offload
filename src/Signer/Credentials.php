<?php
/**
 * Access keys.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\Signer;

use InvalidArgumentException;

/**
 * Access key id, secret access key and an optional session token.
 */
final class Credentials {

	/**
	 * Access key id.
	 *
	 * @var string
	 */
	private $access_key_id;

	/**
	 * Secret access key.
	 *
	 * @var string
	 */
	private $secret_access_key;

	/**
	 * Session token (temporary credentials), or empty.
	 *
	 * @var string
	 */
	private $session_token;

	/**
	 * Constructor.
	 *
	 * @param string $access_key_id Access key id.
	 * @param string $secret_access_key Secret access key.
	 * @param string $session_token Session token.
	 * @throws InvalidArgumentException When a key is empty.
	 */
	public function __construct( string $access_key_id, string $secret_access_key, string $session_token = '' ) {
		if ( '' === trim( $access_key_id ) || '' === trim( $secret_access_key ) ) {
			throw new InvalidArgumentException( 'Access key id and secret access key are required.' );
		}
		$this->access_key_id     = trim( $access_key_id );
		$this->secret_access_key = trim( $secret_access_key );
		$this->session_token     = trim( $session_token );
	}

	/**
	 * Access key id.
	 */
	public function access_key_id(): string {
		return $this->access_key_id;
	}

	/**
	 * Secret access key.
	 */
	public function secret_access_key(): string {
		return $this->secret_access_key;
	}

	/**
	 * Session token, or empty.
	 */
	public function session_token(): string {
		return $this->session_token;
	}

	/**
	 * Keep secrets out of var_dump() and print_r().
	 *
	 * @return array<string,string>
	 */
	public function __debugInfo(): array {
		return array(
			'access_key_id'     => $this->access_key_id,
			'secret_access_key' => '***',
			'session_token'     => '' === $this->session_token ? '' : '***',
		);
	}
}
