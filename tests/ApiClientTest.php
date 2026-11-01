<?php
/**
 * The plugin's calls to ShowBits: its own routes, its key, and the answers.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use ShowBits\WordPress\Api_Client;

final class ApiClientTest extends TestCase {
	private const API = 'https://app.showbits.ai/api/wordpress/v1';
	private Api_Client $client;

	protected function setUp(): void {
		Stubs::reset();
		$this->client = new Api_Client( self::API, 'sbwp_key' );
	}

	public function test_the_library_is_asked_for_with_the_site_key_and_the_filters_not_through_the_rest_api(): void {
		Stubs::$responses[] = Stubs::response( 200, '{"data":[{"id":"c1"}],"nextCursor":"bzoy"}' );
		$page = $this->client->library( array( 'kind' => 'quote', 'q' => 'Wohnen', 'cursor' => '', 'limit' => 24 ) );
		$this->assertSame( array( array( 'id' => 'c1' ) ), $page['data'] );
		$this->assertSame( 'bzoy', $page['nextCursor'] );
		$request = Stubs::$requests[0];
		$this->assertSame( self::API . '/library?kind=quote&q=Wohnen&limit=24', $request['url'] );
		$this->assertSame( 'Bearer sbwp_key', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'GET', $request['args']['method'] );
		$this->assertStringNotContainsString( '/api/v1/', $request['url'] );
	}

	public function test_an_import_is_asked_for_and_a_file_link_comes_with_the_push_ticket(): void {
		Stubs::$responses[] = Stubs::response( 202, '{"push":"push-1"}' );
		$this->assertSame( 'push-1', $this->client->request_import( 'q1', '1x1' ) );
		$this->assertSame( self::API . '/imports', Stubs::$requests[0]['url'] );
		$this->assertSame( '{"clip":"q1","variant":"1x1"}', Stubs::$requests[0]['args']['body'] );
		Stubs::$responses[] = Stubs::response( 202, '{"push":"push-2"}' );
		$this->client->request_import( 'c1', null );
		$this->assertSame( '{"clip":"c1"}', Stubs::$requests[1]['args']['body'] );

		$ticket             = str_repeat( 'f', 64 );
		Stubs::$responses[] = Stubs::response( 200, '{"url":"https://bucket.s3.example/clips/x.mp4?X-Amz-Signature=abc","name":"x.mp4"}' );
		$this->assertSame( 'https://bucket.s3.example/clips/x.mp4?X-Amz-Signature=abc', $this->client->file( 'push-1', $ticket, 'video' ) );
		$request = Stubs::$requests[2];
		$this->assertSame( self::API . '/pushes/push-1/files', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );
		$this->assertSame( json_encode( array( 'ticket' => $ticket, 'variant' => 'video' ) ), $request['args']['body'] );
		$this->assertSame( 0, $request['args']['redirection'] );
		// A link that is not https is no file.
		Stubs::$responses[] = Stubs::response( 200, '{"url":"http://bucket.example/x.mp4"}' );
		$this->assertInstanceOf( WP_Error::class, $this->client->file( 'push-1', $ticket, 'video' ) );
	}

	public function test_refusals_keep_showbits_reason(): void {
		Stubs::$responses[] = Stubs::response( 403, '{"error":"ticket"}' );
		$error = $this->client->file( 'push-1', str_repeat( 'f', 64 ), 'video' );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'showbits_ticket', $error->get_error_code() );
		$this->assertSame( 'ticket', Api_Client::reason( $error ) );
		Stubs::$responses[] = Stubs::response( 409, '{"error":"embargo"}' );
		$this->assertSame( 'embargo', Api_Client::reason( $this->client->request_import( 'c1', null ) ) );
	}

	public function test_answers_become_data_or_errors_with_their_reason(): void {
		$this->assertSame( array( 'ok' => true ), Api_Client::answer( Stubs::response( 200, '{"ok":true}' ) ) );
		$this->assertSame( array(), Api_Client::answer( Stubs::response( 204 ) ) );
		$unauthorized = Api_Client::answer( Stubs::response( 401, '{"error":"unauthorized"}' ) );
		$this->assertSame( 'unauthorized', Api_Client::reason( $unauthorized ) );
		$this->assertSame( 'failed', Api_Client::reason( Api_Client::answer( Stubs::response( 502, '<html>' ) ) ) );
		// A reason that is no word stays out.
		$this->assertSame( 'failed', Api_Client::reason( Api_Client::answer( Stubs::response( 400, '{"error":"<script>"}' ) ) ) );
		$this->assertSame( 'unreachable', Api_Client::reason( Api_Client::answer( new WP_Error( 'http_request_failed', 'timeout' ) ) ) );
	}

	public function test_connecting_posts_the_site_key_and_pairing_without_a_key_and_the_report_names_the_push(): void {
		Stubs::$responses[] = Stubs::response( 200, '{"siteKey":"sbwp_new"}' );
		$this->assertSame( array( 'siteKey' => 'sbwp_new' ), Api_Client::connect( 'https://app.showbits.ai', array( 'key' => 'sbsk_x', 'pairing' => 'p' ) ) );
		$this->assertSame( 'https://app.showbits.ai/api/wordpress/v1/connect', Stubs::$requests[0]['url'] );
		$this->assertSame( 'POST', Stubs::$requests[0]['args']['method'] );
		$this->assertSame( '{"key":"sbsk_x","pairing":"p"}', Stubs::$requests[0]['args']['body'] );
		$this->assertArrayNotHasKey( 'Authorization', Stubs::$requests[0]['args']['headers'] );
		// ShowBits checks this site meanwhile: more time than for the other calls.
		$this->assertGreaterThan( 20, Stubs::$requests[0]['args']['timeout'] );

		Stubs::$responses[] = Stubs::response( 204 );
		$this->assertTrue( $this->client->report( 'push-1', array( 'state' => 'failed', 'reason' => 'download' ) ) );
		$this->assertSame( self::API . '/pushes/push-1/result', Stubs::$requests[1]['url'] );
		$this->assertSame( '{"state":"failed","reason":"download"}', Stubs::$requests[1]['args']['body'] );

		Stubs::$responses[] = Stubs::response( 204 );
		$this->assertTrue( $this->client->leave() );
		$this->assertSame( 'DELETE', Stubs::$requests[2]['args']['method'] );
		$this->assertSame( self::API . '/site', Stubs::$requests[2]['url'] );
	}
}
