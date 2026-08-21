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
	 * A document type declaration is refused before parsing.
	 *
	 * This is the whole defence, and it has to be, because the parse options
	 * are not one. LIBXML_NOENT governs *external* entity substitution;
	 * general entities declared in the internal subset are expanded by libxml
	 * regardless of it, and newer versions apply no limit while doing so.
	 *
	 * That difference is invisible locally: libxml 2.9.13 refuses these
	 * documents on its own, so a parser relying on the library's behaviour
	 * looks safe on one machine and expands a megabyte on another. CI running
	 * a newer libxml is what surfaced it.
	 */
	public function test_a_doctype_is_refused(): void {
		$result = Parser::parse( '<?xml version="1.0"?><!DOCTYPE r><r><v>x</v></r>' );

		$this->assertInstanceOf( ErrorResponse::class, $result );
		$this->assertSame( 'xml_doctype_refused', $result->get_error_code() );
	}

	public function test_a_doctype_is_refused_whatever_its_case(): void {
		foreach ( [ '<!DOCTYPE', '<!doctype', '<!DocType' ] as $spelling ) {
			$this->assertInstanceOf(
				ErrorResponse::class,
				Parser::parse( '<?xml version="1.0"?>' . $spelling . ' r><r><v>x</v></r>' ),
				"Not refused: {$spelling}"
			);
		}
	}

	/**
	 * External entity resolution is the classic XXE vector: an entity pointing
	 * at a local file, expanded into the response the caller reads.
	 */
	public function test_an_external_entity_never_reaches_the_output(): void {
		$xml = '<?xml version="1.0"?>'
			. '<!DOCTYPE r [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
			. '<r><v>&xxe;</v></r>';

		$result = Parser::parse( $xml );

		$this->assertInstanceOf( ErrorResponse::class, $result );
	}

	/**
	 * A billion-laughs document. Six levels reach a million characters if
	 * every entity is substituted, and nine reach a gigabyte.
	 */
	public function test_an_entity_bomb_never_expands(): void {
		$xml = '<?xml version="1.0"?><!DOCTYPE r ['
			. '<!ENTITY a "aaaaaaaaaa">'
			. '<!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
			. '<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">'
			. '<!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;&c;&c;">'
			. '<!ENTITY e "&d;&d;&d;&d;&d;&d;&d;&d;&d;&d;">'
			. '<!ENTITY f "&e;&e;&e;&e;&e;&e;&e;&e;&e;&e;">'
			. ']><r><v>&f;</v></r>';

		$result = Parser::parse( $xml );

		$this->assertInstanceOf( ErrorResponse::class, $result );
		$this->assertSame( 'xml_doctype_refused', $result->get_error_code() );
	}

	/**
	 * Rejecting a DOCTYPE must not reject anything a provider actually sends.
	 */
	public function test_real_provider_payloads_still_parse(): void {
		foreach ( glob( __DIR__ . '/../fixtures/*.xml' ) as $fixture ) {
			$this->assertIsArray(
				Parser::parse( (string) file_get_contents( $fixture ) ),
				'Fixture rejected: ' . basename( $fixture )
			);
		}
	}

	public function test_namespaced_children_are_prefixed(): void {
		$parsed = Parser::parse(
			'<R xmlns:s3="http://s3.example/"><s3:Owner><s3:ID>7</s3:ID></s3:Owner></R>'
		);

		$this->assertNotNull( Parser::find( $parsed, 's3:Owner' ) );
	}
}
