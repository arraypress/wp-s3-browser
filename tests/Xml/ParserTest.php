<?php
declare( strict_types=1 );

namespace ArrayPress\S3\Tests\Xml;

use ArrayPress\S3\Responses\ErrorResponse;
use ArrayPress\S3\Xml\Parser;
use PHPUnit\Framework\TestCase;

/**
 * XML parsing mechanics.
 *
 * These were spread across five traits mixed into the API client, so the only
 * way to reach them was to construct a client and issue a request. The
 * collection handling in particular has a real bug surface: SimpleXML gives no
 * signal whether it produced one element or many.
 */
final class ParserTest extends TestCase {

	public function test_empty_body_is_an_error(): void {
		$this->assertInstanceOf( ErrorResponse::class, Parser::parse( '' ) );
		$this->assertInstanceOf( ErrorResponse::class, Parser::parse( "  \n " ) );
	}

	public function test_malformed_xml_is_an_error(): void {
		$result = Parser::parse( '<Root><Unclosed></Root>' );

		$this->assertInstanceOf( ErrorResponse::class, $result );
		$this->assertSame( 'xml_parse_error', $result->get_error_code() );
	}

	public function test_parse_error_reports_the_offending_line(): void {
		$result = Parser::parse( '<Root><Unclosed></Root>' );
		$errors = $result->get_error_data()["errors"];

		$this->assertNotEmpty( $errors );
		$this->assertMatchesRegularExpression( '/line \d+, column \d+/', $errors[0] );
	}

	public function test_text_node_collapses_to_a_value(): void {
		$this->assertSame( [ 'Name' => [ 'value' => 'my-bucket' ] ], Parser::parse( '<R><Name>my-bucket</Name></R>' ) );
	}

	public function test_attributes_are_preserved_alongside_text(): void {
		$parsed = Parser::parse( '<R><Item id="7">hello</Item></R>' );

		$this->assertSame( '7', $parsed['Item']['@attributes']['id'] );
		$this->assertSame( 'hello', $parsed['Item']['@text'] );
	}

	/**
	 * A listing with exactly one object is the case that breaks naive parsers:
	 * SimpleXML returns the same shape as a single non-repeating element.
	 */
	public function test_single_repeated_element_is_not_a_list(): void {
		$parsed = Parser::parse( '<R><Contents><Key>a.txt</Key></Contents></R>' );

		$this->assertArrayHasKey( 'Key', $parsed['Contents'] );
		$this->assertArrayNotHasKey( 0, $parsed['Contents'] );
	}

	public function test_repeated_elements_become_a_list(): void {
		$parsed = Parser::parse(
			'<R><Contents><Key>a.txt</Key></Contents><Contents><Key>b.txt</Key></Contents></R>'
		);

		$this->assertCount( 2, $parsed['Contents'] );
		$this->assertSame( 'a.txt', $parsed['Contents'][0]['Key']['value'] );
		$this->assertSame( 'b.txt', $parsed['Contents'][1]['Key']['value'] );
	}

	public function test_three_repeated_elements_keep_appending(): void {
		$parsed = Parser::parse( '<R><K><V>1</V></K><K><V>2</V></K><K><V>3</V></K></R>' );

		$this->assertCount( 3, $parsed['K'] );
	}

	public function test_find_reaches_a_nested_key(): void {
		$parsed = Parser::parse( '<R><A><B><Target>found</Target></B></A></R>' );

		$this->assertSame( [ 'value' => 'found' ], Parser::find( $parsed, 'Target' ) );
		$this->assertNull( Parser::find( $parsed, 'Missing' ) );
	}

	/**
	 * External entity resolution is the classic XXE vector. The parse options
	 * must leave entity substitution off, so the entity never expands.
	 */
	public function test_external_entities_are_not_resolved(): void {
		$xml = '<?xml version="1.0"?>'
			. '<!DOCTYPE r [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
			. '<r><v>&xxe;</v></r>';

		$parsed = Parser::parse( $xml );

		if ( $parsed instanceof ErrorResponse ) {
			$this->assertSame( 'xml_parse_error', $parsed->get_error_code() );

			return;
		}

		$this->assertStringNotContainsString( 'root:', (string) ( $parsed['v']['value'] ?? '' ) );
	}

	/**
	 * A billion-laughs document must not expand. Same defence as above: with
	 * substitution off, the nested entities stay unexpanded references.
	 */
	public function test_entity_expansion_does_not_blow_up(): void {
		$xml = '<?xml version="1.0"?><!DOCTYPE r ['
			. '<!ENTITY a "aaaaaaaaaa">'
			. '<!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
			. '<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">'
			. ']><r><v>&c;</v></r>';

		$parsed = Parser::parse( $xml );
		$value  = $parsed instanceof ErrorResponse ? '' : (string) ( $parsed['v']['value'] ?? '' );

		$this->assertLessThan( 1000, strlen( $value ) );
	}

	public function test_namespaced_children_are_prefixed(): void {
		$parsed = Parser::parse(
			'<R xmlns:s3="http://s3.example/"><s3:Owner><s3:ID>7</s3:ID></s3:Owner></R>'
		);

		$this->assertNotNull( Parser::find( $parsed, 's3:Owner' ) );
	}
}
