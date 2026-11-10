<?php
/**
 * What ShowBits pushes and asks while connecting, and what an import puts into the media library.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use ShowBits\WordPress\Connection;
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
		// An animated card's video, picked here (1.1.0); a variant ShowBits does not make is turned away.
		$animated = self::push( array( 'id' => '0b5f8c5e-1111-4000-8000-000000000003', 'variant' => 'motion-9x16', 'from' => 'site' ) );
		$this->assertSame( 202, Push_Endpoint::handle( $animated, Signature::header( 'whsec_site', $animated ), self::CONNECTION )['status'] );
		$unknown = self::push( array( 'id' => '0b5f8c5e-1111-4000-8000-000000000004', 'variant' => 'motion-1x1', 'from' => 'site' ) );
		$this->assertSame( 422, Push_Endpoint::handle( $unknown, Signature::header( 'whsec_site', $unknown ), self::CONNECTION )['status'] );
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
		$waiting   = array( Connection::pairing_id( $pairing ) => $pairing );
		$lookup    = static fn( ?string $id ): ?string => null === $id ? null : ( $waiting[ $id ] ?? null );
		$check     = (string) json_encode( array( 'type' => 'site.verify', 'challenge' => $challenge, 'pairingId' => Connection::pairing_id( $pairing ) ) );
		$answer    = Push_Endpoint::handle( $check, '', null, $lookup );
		$this->assertSame( 200, $answer['status'] );
		// As ShowBits computes it (lib/wordpress-verify.ts): base64url(HMAC-SHA256(pairing, "showbits-site-verify:" + challenge)).
		$this->assertSame( rtrim( strtr( base64_encode( hash_hmac( 'sha256', 'showbits-site-verify:' . $challenge, $pairing, true ) ), '+/', '-_' ), '=' ), $answer['proof'] );
		// The id is the value's hash, as ShowBits sends it (lib/wordpress-verify.ts pairingId).
		$this->assertSame( rtrim( strtr( base64_encode( hash( 'sha256', $pairing, true ) ), '+/', '-_' ), '=' ), Connection::pairing_id( $pairing ) );
		// Not connecting right now, or another attempt's id: no proof at all; a malformed check neither.
		$this->assertSame( 404, Push_Endpoint::handle( $check, '', self::CONNECTION, null )['status'] );
		$this->assertArrayNotHasKey( 'proof', Push_Endpoint::handle( $check, '', self::CONNECTION, null ) );
		$other = (string) json_encode( array( 'type' => 'site.verify', 'challenge' => $challenge, 'pairingId' => Connection::pairing_id( str_repeat( 'q', 43 ) ) ) );
		$this->assertSame( 404, Push_Endpoint::handle( $other, '', null, $lookup )['status'] );
		foreach ( array( array( 'challenge' => 'x' ), array( 'pairingId' => 'x' ), array( 'pairingId' => 123 ) ) as $odd ) {
			$malformed = (string) json_encode( array_replace( array( 'type' => 'site.verify', 'challenge' => $challenge, 'pairingId' => Connection::pairing_id( $pairing ) ), $odd ) );
			$this->assertSame( 422, Push_Endpoint::handle( $malformed, '', null, $lookup )['status'], (string) json_encode( $odd ) );
		}
		$this->assertSame( array(), $GLOBALS['wpdb']->push_ids, 'a check queues nothing' );
	}

	public function test_two_attempts_at_once_each_prove_their_own_pairing_value(): void {
		$first   = str_repeat( 'a', 43 );
		$second  = str_repeat( 'b', 43 );
		$waiting = array(
			Connection::pairing_id( $first )  => $first,
			Connection::pairing_id( $second ) => $second,
		);
		$lookup  = static fn( ?string $id ): ?string => null === $id ? null : ( $waiting[ $id ] ?? null );
		foreach ( array( $first, $second ) as $pairing ) {
			$check = (string) json_encode( array( 'type' => 'site.verify', 'challenge' => str_repeat( 'c', 43 ), 'pairingId' => Connection::pairing_id( $pairing ) ) );
			$this->assertSame( Push_Endpoint::proof( $pairing, str_repeat( 'c', 43 ) ), Push_Endpoint::handle( $check, '', null, $lookup )['proof'] );
		}
		// A ShowBits that names no value (before pairingId) gets the latest attempt's, as plugins up to 1.0.0 answered.
		$latest = static fn( ?string $id ): ?string => null === $id ? $second : ( $waiting[ $id ] ?? null );
		$check  = (string) json_encode( array( 'type' => 'site.verify', 'challenge' => str_repeat( 'c', 43 ) ) );
		$this->assertSame( Push_Endpoint::proof( $second, str_repeat( 'c', 43 ) ), Push_Endpoint::handle( $check, '', null, $latest )['proof'] );
		$this->assertSame( 404, Push_Endpoint::handle( $check, '', null, static fn( ?string $id ): ?string => null )['status'] );
		// A stored value under someone else's id proves nothing.
		$swapped = static fn( ?string $id ): ?string => $second;
		$check   = (string) json_encode( array( 'type' => 'site.verify', 'challenge' => str_repeat( 'c', 43 ), 'pairingId' => Connection::pairing_id( $first ) ) );
		$this->assertSame( 404, Push_Endpoint::handle( $check, '', null, $swapped )['status'] );
	}

	public function test_a_download_cut_short_is_no_file(): void {
		$file = (string) tempnam( sys_get_temp_dir(), 'showbits-test' );
		try {
			file_put_contents( $file, str_repeat( 'x', 1000 ) );
			$this->assertTrue( Importer::complete( $file, Stubs::response( 200, '', array( 'content-length' => '1000' ) ), 5000 ) );
			$this->assertTrue( Importer::complete( $file, Stubs::response( 200 ), 5000 ), 'without a length, the limit decides' );
			// WordPress stops writing at the limit and still answers 200.
			$this->assertFalse( Importer::complete( $file, Stubs::response( 200 ), 1000 ) );
			// A connection that broke off.
			$this->assertFalse( Importer::complete( $file, Stubs::response( 200, '', array( 'content-length' => '4000' ) ), 5000 ) );
			file_put_contents( $file, '' );
			$this->assertFalse( Importer::complete( $file, Stubs::response( 200 ), 5000 ) );
		} finally {
			unlink( $file );
		}
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

		// An animated card is a video: named by its ratio, the quote as its caption, no alternative text.
		$this->assertSame( 'warum-wohnen-so-teuer-ist-9x16.mp4', Importer::file_name( $card['title'], 'motion-9x16' ) );
		$this->assertNull( Importer::alt_text( $card, 'motion-4x5' ) );
		$this->assertSame( array( 'Warum Wohnen so teuer ist', 'Lokales wird geteilt.' ), array_values( array_intersect_key( Importer::attachment_post( $card, 'motion-4x5', 1 ), array_flip( array( 'post_title', 'post_excerpt' ) ) ) ) );
	}

	public function test_an_animated_default_falls_back_to_the_picture_of_its_ratio(): void {
		update_option( 'showbits_settings', array( 'card_format' => 'motion-9x16' ) );
		$animated = array( 'kind' => 'quote', 'files' => array( array( 'variant' => '9x16' ), array( 'variant' => 'motion-9x16' ) ) );
		$this->assertSame( 'motion-9x16', Importer::card_default( $animated ) );
		$this->assertSame( '9x16', Importer::card_default( array( 'kind' => 'quote', 'files' => array( array( 'variant' => '9x16' ) ) ) ) );
		update_option( 'showbits_settings', array( 'card_format' => '1x1' ) );
		$this->assertSame( '1x1', Importer::card_default( $animated ) );
		update_option( 'showbits_settings', array( 'card_format' => 'motion-16x9' ) );
		$this->assertSame( '4x5', Importer::card_default( $animated ) );
		update_option( 'showbits_settings', array() );
	}

	public function test_texts_from_showbits_arrive_as_plain_text(): void {
		$clip = array(
			'id'      => 'c1',
			'kind'    => 'highlight',
			'title'   => 'Warum <img src=x onerror=alert(1)>Wohnen',
			'caption' => "Erste Zeile<script>alert(1)</script>\nZweite Zeile",
			'show'    => array( 'name' => '<b>Talk</b> am Abend' ),
			'episode' => array( 'title' => 'Folge <i>141</i>' ),
		);
		$post = Importer::attachment_post( $clip, 'video', 3 );
		$this->assertSame( 'Warum Wohnen', $post['post_title'] );
		$this->assertSame( "Erste Zeile\nZweite Zeile", $post['post_excerpt'] );
		$this->assertSame( 'Talk am Abend · Folge 141', $post['post_content'] );
		$card = array( 'kind' => 'quote', 'caption' => 'Gesagt<svg onload=alert(1)>', 'quote' => array( 'name' => '<a href="https://x">Lena</a>' ) ) + $clip;
		$this->assertSame( '“Gesagt” – Lena', Importer::alt_text( $card, '4x5' ) );
	}

	public function test_failures_reach_showbits_as_known_reasons_only(): void {
		$this->assertSame( 'embargo', Importer::reason_of( new WP_Error( 'showbits_embargo', '', array( 'reason' => 'embargo' ) ) ) );
		$this->assertSame( 'download', Importer::reason_of( new WP_Error( 'showbits_download', '', array( 'reason' => 'download' ) ) ) );
		$this->assertSame( 'failed', Importer::reason_of( new WP_Error( 'x', '', array( 'reason' => 'something else' ) ) ) );
		$this->assertSame( 'failed', Importer::reason_of( new WP_Error( 'x', '' ) ) );
	}

	public function test_every_reason_the_import_log_keeps_has_its_own_translatable_message(): void {
		$generic  = Importer::message_of( 'failed' );
		$messages = array();
		// What reason_of() lets through, and what Imports::give_up_interrupted() writes for a run that never finished.
		foreach ( array( 'embargo', 'notReleased', 'noFile', 'download', 'media', 'invalid', 'unauthorized', 'unreachable', 'ticket', 'inactive', 'limit', 'interrupted' ) as $reason ) {
			$messages[ $reason ] = Importer::message_of( $reason );
			$this->assertNotSame( $generic, $messages[ $reason ], $reason );
		}
		$this->assertCount( count( $messages ), array_unique( $messages ) );
		$this->assertSame( 'The import failed.', $generic );
		$this->assertSame( $generic, Importer::message_of( 'something else' ) );
	}
}
