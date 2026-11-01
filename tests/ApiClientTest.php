<?php
/**
 * The plugin's calls to ShowBits: paths, the token, and the answers.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use ShowBits\WordPress\Api_Client;

final class ApiClientTest extends TestCase {
	private Api_Client $client;

	protected function setUp(): void {
		Stubs::reset();
		$this->client = new Api_Client( 'https://app.showbits.ai/api', 'sbk_token', 'talk-am-abend' );
	}

	public function test_the_library_is_asked_for_with_the_token_and_the_filters(): void {
		Stubs::$responses[] = Stubs::response( 200, '{"data":[{"id":"c1"}],"nextCursor":"bzoy"}' );
		$page = $this->client->library( array( 'kind' => 'quote', 'q' => 'Wohnen', 'cursor' => '', 'limit' => 24 ) );
		$this->assertSame( array( array( 'id' => 'c1' ) ), $page['data'] );
		$this->assertSame( 'bzoy', $page['nextCursor'] );
		$request = Stubs::$requests[0];
		$this->assertSame( 'https://app.showbits.ai/api/v1/workspaces/talk-am-abend/library?kind=quote&q=Wohnen&limit=24', $request['url'] );
		$this->assertSame( 'Bearer sbk_token', $request['args']['headers']['Authorization'] );
		$this->assertSame( 'GET', $request['args']['method'] );
	}

	public function test_a_file_link_is_read_without_following_it(): void {
		Stubs::$responses[] = Stubs::response( 302, '', array( 'location' => 'https://bucket.s3.example/clips/x.mp4?X-Amz-Signature=abc' ) );
		$this->assertSame( 'https://bucket.s3.example/clips/x.mp4?X-Amz-Signature=abc', $this->client->file_location( 'c1', 'video' ) );
		$request = Stubs::$requests[0];
		$this->assertSame( 'https://app.showbits.ai/api/v1/workspaces/talk-am-abend/library/c1/file?variant=video', $request['url'] );
		// The token stays with ShowBits: the store's link is never asked with it.
		$this->assertSame( 0, $request['args']['redirection'] );
		$this->assertCount( 1, Stubs::$requests );
	}

	public function test_a_refused_file_keeps_showbits_reason(): void {
		Stubs::$responses[] = Stubs::response( 409, '{"type":"https://app.showbits.ai/problems/embargo","status":409,"title":"Conflict","reason":"embargo"}' );
		$error = $this->client->file_location( 'c1', 'video' );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'showbits_embargo', $error->get_error_code() );
		$this->assertSame( 'embargo', Api_Client::reason( $error ) );
		// A redirect elsewhere than https is no file.
		Stubs::$responses[] = Stubs::response( 302, '', array( 'location' => 'http://bucket.example/x.mp4' ) );
		$this->assertInstanceOf( WP_Error::class, $this->client->file_location( 'c1', 'video' ) );
	}

	public function test_answers_become_data_or_errors_with_their_reason(): void {
		$this->assertSame( array( 'ok' => true ), Api_Client::answer( Stubs::response( 200, '{"ok":true}' ) ) );
		$this->assertSame( array(), Api_Client::answer( Stubs::response( 204 ) ) );
		$unauthorized = Api_Client::answer( Stubs::response( 401, '{"reason":"unauthorized","detail":"Unknown, revoked or expired token"}' ) );
		$this->assertSame( 'unauthorized', Api_Client::reason( $unauthorized ) );
		$this->assertSame( 'Unknown, revoked or expired token', $unauthorized->get_error_message() );
		$this->assertSame( 'failed', Api_Client::reason( Api_Client::answer( Stubs::response( 502, '<html>' ) ) ) );
		// A reason that is no word stays out.
		$this->assertSame( 'failed', Api_Client::reason( Api_Client::answer( Stubs::response( 400, '{"reason":"<script>"}' ) ) ) );
		$this->assertSame( 'unreachable', Api_Client::reason( Api_Client::answer( new WP_Error( 'http_request_failed', 'timeout' ) ) ) );
	}

	public function test_the_exchange_posts_json_to_its_own_route_and_the_report_names_the_push(): void {
		Stubs::$responses[] = Stubs::response( 200, '{"token":"sbk_new"}' );
		$this->assertSame( array( 'token' => 'sbk_new' ), Api_Client::exchange( 'https://app.showbits.ai', array( 'code' => 'c' ) ) );
		$this->assertSame( 'https://app.showbits.ai/api/wordpress/token', Stubs::$requests[0]['url'] );
		$this->assertSame( 'POST', Stubs::$requests[0]['args']['method'] );
		$this->assertSame( '{"code":"c"}', Stubs::$requests[0]['args']['body'] );
		$this->assertArrayNotHasKey( 'Authorization', Stubs::$requests[0]['args']['headers'] );

		Stubs::$responses[] = Stubs::response( 204 );
		$this->assertTrue( $this->client->report( 'push-1', array( 'state' => 'failed', 'reason' => 'download' ) ) );
		$this->assertSame( 'https://app.showbits.ai/api/v1/wordpress/pushes/push-1/result', Stubs::$requests[1]['url'] );
		$this->assertSame( '{"state":"failed","reason":"download"}', Stubs::$requests[1]['args']['body'] );

		Stubs::$responses[] = Stubs::response( 204 );
		$this->assertTrue( $this->client->leave() );
		$this->assertSame( 'DELETE', Stubs::$requests[2]['args']['method'] );
		$this->assertSame( 'https://app.showbits.ai/api/v1/wordpress/site', Stubs::$requests[2]['url'] );
	}
}
