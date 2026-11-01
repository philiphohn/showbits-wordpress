<?php
/**
 * What ShowBits pushes and asks while connecting, and what an import puts into the media library.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use ShowBits\WordPress\Importer;
use ShowBits\WordPress\Push_Endpoint;
use ShowBits\WordPress\Signature;

final class PushTest extends TestCase {
	private const CONNECTION = array(
		'site_id'      => 'site-1',
		'workspace_id' => 'ws-1',
		'push_secret'  => 'whsec_site',
		'connected_by' => 7,
	);

	protected function setUp(): void {
		Stubs::reset();
	}

	/** A push as worker/wordpress.ts sends it. */
	private static function push( array $over = array() ): string {
		return (string) json_encode(
			array_replace_recursive(
				array(
					'id'         => '0b5f8c5e-1111-4000-8000-000000000001',
					'type'       => 'clip.push',
					'occurredAt' => '2026-11-01T12:00:00Z',
					'workspace'  => array(
						'id'   => 'ws-1',
						'slug' => 'talk-am-abend',
					),
					'site'       => array( 'id' => 'site-1' ),
					'object'     => array(
						'type' => 'clip',
						'id'   => '5b0b0000-0000-4000-8006-000000000001',
						'kind' => 'highlight',
					),
					'from'       => 'showbits',
					'variant'    => null,
					'ticket'     => str_repeat( 'a', 64 ),
				),
				$over
			)
		);
	}

	public function test_a_signed_push_for_this_site_is_taken_once_and_a_new_delivery_brings_its_new_ticket(): void {
		$body = self::push();
		$this->assertSame( array( 'status' => 202, 'queued' => true ), Push_Endpoint::handle( $body, Signature::header( 'whsec_site', $body ), self::CONNECTION ) );
		// ShowBits delivers at least once: the same push again is answered, not queued twice.
		$this->assertSame( array( 'status' => 202, 'queued' => false ), Push_Endpoint::handle( $body, Signature::header( 'whsec_site', $body ), self::CONNECTION ) );
		// A retry brings a new ticket (the old one no longer opens anything in ShowBits): it replaces it.
		$again = self::push( array( 'ticket' => str_repeat( 'b', 64 ) ) );
		$this->assertSame( array( 'status' => 202, 'queued' => true ), Push_Endpoint::handle( $again, Signature::header( 'whsec_site', $again ), self::CONNECTION ) );
		$this->assertSame( str_repeat( 'b', 64 ), $GLOBALS['wpdb']->push_ids['0b5f8c5e-1111-4000-8000-000000000001'] );
		$card = self::push( array( 'id' => '0b5f8c5e-1111-4000-8000-000000000002', 'variant' => '1x1', 'from' => 'site' ) );
		$this->assertSame( 202, Push_Endpoint::handle( $card, Signature::header( 'whsec_site', $card ), self::CONNECTION )['status'] );
	}

	public function test_anything_else_is_turned_away(): void {
		$body = self::push();
		$this->assertSame( 401, Push_Endpoint::handle( $body, Signature::header( 'whsec_other', $body ), self::CONNECTION )['status'] );
		$this->assertSame( 401, Push_Endpoint::handle( $body, '', self::CONNECTION )['status'] );
		$this->assertSame( 401, Push_Endpoint::handle( $body, Signature::header( 'whsec_site', $body, time() - 600 ), self::CONNECTION )['status'] );
		$big = str_repeat( ' ', 9000 ) . $body;
		$this->assertSame( 413, Push_Endpoint::handle( $big, Signature::header( 'whsec_site', $big ), self::CONNECTION )['status'] );
		// Not connected: ShowBits stops trying.
		$this->assertSame( 410, Push_Endpoint::handle( $body, Signature::header( 'whsec_site', $body ), null )['status'] );
		foreach ( array( array( 'site' => array( 'id' => 'site-2' ) ), array( 'workspace' => array( 'id' => 'ws-2' ) ) ) as $other ) {
			$foreign = self::push( $other );
			$this->assertSame( 410, Push_Endpoint::handle( $foreign, Signature::header( 'whsec_site', $foreign ), self::CONNECTION )['status'] );
		}
		foreach ( array( array( 'type' => 'webhook.test' ), array( 'object' => array( 'id' => '../../etc' ) ), array( 'id' => '' ), array( 'ticket' => 'short' ), array( 'ticket' => null ), array( 'variant' => 'video' ) ) as $odd ) {
			$invalid = self::push( $odd );
			$this->assertSame( 422, Push_Endpoint::handle( $invalid, Signature::header( 'whsec_site', $invalid ), self::CONNECTION )['status'], (string) json_encode( $odd ) );
		}
		$this->assertSame( array(), $GLOBALS['wpdb']->push_ids, 'nothing queued' );
	}

	public function test_while_connecting_the_site_proves_its_pairing_value_and_nothing_else(): void {
		$pairing   = str_repeat( 'p', 43 );
		$challenge = str_repeat( 'c', 43 );
		$check     = (string) json_encode( array( 'type' => 'site.verify', 'challenge' => $challenge ) );
		$answer    = Push_Endpoint::handle( $check, '', null, $pairing );
		$this->assertSame( 200, $answer['status'] );
		// As ShowBits computes it (lib/wordpress-verify.ts): base64url(HMAC-SHA256(pairing, "showbits-site-verify:" + challenge)).
		$this->assertSame( rtrim( strtr( base64_encode( hash_hmac( 'sha256', 'showbits-site-verify:' . $challenge, $pairing, true ) ), '+/', '-_' ), '=' ), $answer['proof'] );
		// Not connecting right now: no proof at all; a malformed challenge neither.
		$this->assertSame( 404, Push_Endpoint::handle( $check, '', self::CONNECTION, null )['status'] );
		$this->assertArrayNotHasKey( 'proof', Push_Endpoint::handle( $check, '', self::CONNECTION, null ) );
		$odd = (string) json_encode( array( 'type' => 'site.verify', 'challenge' => 'x' ) );
		$this->assertSame( 422, Push_Endpoint::handle( $odd, '', null, $pairing )['status'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->push_ids, 'a check queues nothing' );
	}

	public function test_a_video_and_its_poster_and_a_quote_card_get_their_texts(): void {
		$clip = array(
			'id'      => 'c1',
			'kind'    => 'highlight',
			'title'   => 'Warum Wohnen so teuer ist',
			'caption' => 'Die Mieten steigen schneller als die Löhne.',
			'show'    => array( 'name' => 'Talk am Abend' ),
			'episode' => array( 'title' => 'Folge 141' ),
		);
		$this->assertSame(
			array(
				'post_title'   => 'Warum Wohnen so teuer ist',
				'post_excerpt' => 'Die Mieten steigen schneller als die Löhne.',
				'post_content' => 'Talk am Abend · Folge 141',
				'post_author'  => 3,
			),
			Importer::attachment_post( $clip, 'video', 3 )
		);
		$this->assertSame( 'Warum Wohnen so teuer ist (thumbnail)', Importer::attachment_post( $clip, '16x9', 3 )['post_title'] );
		$this->assertNull( Importer::alt_text( $clip, 'video' ) );
		$this->assertSame( 'warum-wohnen-so-teuer-ist.mp4', Importer::file_name( $clip['title'], 'video' ) );
		$this->assertSame( 'warum-wohnen-so-teuer-ist-9x16.jpg', Importer::file_name( $clip['title'], '9x16' ) );
		$this->assertSame( 'showbits-clip-4x5.jpg', Importer::file_name( '!!!', '4x5' ) );

		$card = array( 'kind' => 'quote', 'caption' => 'Lokales wird geteilt.', 'quote' => array( 'name' => 'Lena Hoffmann' ) ) + $clip;
		$this->assertSame( '“Lokales wird geteilt.” – Lena Hoffmann', Importer::alt_text( $card, '4x5' ) );
		$this->assertSame( '“Lokales wird geteilt.”', Importer::alt_text( array( 'quote' => array( 'name' => null ) ) + $card, '4x5' ) );
		$this->assertSame( 'Lokales wird geteilt.', Importer::attachment_post( $card, '4x5', 1 )['post_excerpt'] );
	}

	public function test_failures_reach_showbits_as_known_reasons_only(): void {
		$this->assertSame( 'embargo', Importer::reason_of( new WP_Error( 'showbits_embargo', '', array( 'reason' => 'embargo' ) ) ) );
		$this->assertSame( 'download', Importer::reason_of( new WP_Error( 'showbits_download', '', array( 'reason' => 'download' ) ) ) );
		$this->assertSame( 'failed', Importer::reason_of( new WP_Error( 'x', '', array( 'reason' => 'something else' ) ) ) );
		$this->assertSame( 'failed', Importer::reason_of( new WP_Error( 'x', '' ) ) );
	}
}
