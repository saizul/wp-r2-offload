<?php
/**
 * Small XML helpers for S3 responses.
 *
 * @package Saizul\R2Offload
 */

declare(strict_types=1);

namespace Saizul\R2Offload\S3;

/**
 * Parses the few S3 XML documents the plugin needs, ignoring namespaces and
 * never loading external entities.
 */
final class Xml {

	/**
	 * Parse a document.
	 *
	 * @param string $xml XML.
	 */
	public static function parse( string $xml ): ?\SimpleXMLElement {
		$xml = trim( $xml );
		if ( '' === $xml || '<' !== $xml[0] ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return false === $doc ? null : $doc;
	}

	/**
	 * Text of the first child with a local name, or "".
	 *
	 * @param \SimpleXMLElement $node Node.
	 * @param string            $name Local name.
	 */
	public static function text( \SimpleXMLElement $node, string $name ): string {
		foreach ( $node->children() as $child ) {
			if ( $child->getName() === $name ) {
				return trim( (string) $child );
			}
		}
		// Namespaced documents.
		foreach ( $node->getNamespaces( true ) as $ns ) {
			foreach ( $node->children( $ns ) as $child ) {
				if ( $child->getName() === $name ) {
					return trim( (string) $child );
				}
			}
		}

		return '';
	}

	/**
	 * Children with a local name.
	 *
	 * Elements in the default namespace (as in every S3 response) are
	 * returned once, not again for the namespace; prefixed namespaces are
	 * searched too.
	 *
	 * @param \SimpleXMLElement $node Node.
	 * @param string            $name Local name.
	 * @return \SimpleXMLElement[]
	 */
	public static function children( \SimpleXMLElement $node, string $name ): array {
		$found = array();
		$sets  = array( $node->children() );
		foreach ( $node->getNamespaces( true ) as $prefix => $ns ) {
			// Children in the default namespace are already in the plain set.
			if ( '' !== $prefix ) {
				$sets[] = $node->children( $ns );
			}
		}
		foreach ( $sets as $set ) {
			foreach ( $set as $child ) {
				if ( $child->getName() === $name ) {
					$found[] = $child;
				}
			}
		}

		return $found;
	}

	/**
	 * Error code and message of an S3 error document, or null.
	 *
	 * @param string $xml Body.
	 * @return array{code:string,message:string}|null
	 */
	public static function error( string $xml ): ?array {
		$doc = self::parse( $xml );
		if ( null === $doc || 'Error' !== $doc->getName() ) {
			return null;
		}

		return array(
			'code'    => self::text( $doc, 'Code' ),
			'message' => self::text( $doc, 'Message' ),
		);
	}

	/**
	 * Escape text for an XML element.
	 *
	 * @param string $text Text.
	 */
	public static function escape( string $text ): string {
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
