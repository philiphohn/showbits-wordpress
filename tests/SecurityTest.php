<?php
/**
 * The plugin's checks: signatures on pushes, PKCE, sealed secrets, ShowBits' address.
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use ShowBits\WordPress\Crypto;
use ShowBits\WordPress\Options;
use ShowBits\WordPress\Pkce;
use ShowBits\WordPress\Signature;

final class SecurityTest extends TestCase {
	protected function setUp(): void {
		Stubs::reset();
	}

	public function test_a_signature_counts_with_the_secret_within_five_minutes(): void {
		$body   = '{"id":"p1"}';
		$header = Signature::header( 'whsec_site', $body, 1_000_000 );
		$this->assertTrue( Signature::verify( $header, 'whsec_site', $body, 1_000_000 ) );
		$this->assertTrue( Signature::verify( $header, 'whsec_site', $body, 1_000_000 + 300 ) );
		$this->assertFalse( Signature::verify( $header, 'whsec_site', $body, 1_000_000 + 301 ), 'too old' );
		$this->assertFalse( Signature::verify( $header, 'whsec_site', $body, 1_000_000 - 301 ), 'from the future' );
		$this->assertFalse( Signature::verify( $header, 'whsec_other', $body, 1_000_000 ), 'another secret' );
		$this->assertFalse( Signature::verify( $header, 'whsec_site', '{"id":"p2"}', 1_000_000 ), 'another body' );
		$this->assertFalse( Signature::verify( '', 'whsec_site', $body, 1_000_000 ) );
		$this->assertFalse( Signature::verify( $header, '', $body, 1_000_000 ), 'no secret' );
		$this->assertFalse( Signature::verify( 'v1=' . str_repeat( 'a', 64 ), 'whsec_site', $body, 1_000_000 ), 'no time' );
	}

	public function test_a_signature_counts_as_showbits_makes_it_with_two_secrets_after_a_rotation(): void {
		$body   = '{"id":"p1"}';
		$t      = 1_700_000_000;
		// lib/webhooks/sign.ts: t=…,v1=<new>,v1=<old>.
		$header = "t={$t},v1=" . hash_hmac( 'sha256', "{$t}.{$body}", 'whsec_new' ) . ',v1=' . hash_hmac( 'sha256', "{$t}.{$body}", 'whsec_old' );
		$this->assertTrue( Signature::verify( $header, 'whsec_old', $body, $t ) );
		$this->assertTrue( Signature::verify( $header, 'whsec_new', $body, $t ) );
	}

	public function test_pkce_is_s256_as_in_rfc_7636(): void {
		// RFC 7636, appendix B.
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );
		$verifier = Pkce::random();
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $verifier );
		$this->assertNotSame( $verifier, Pkce::random() );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', Pkce::challenge( $verifier ) );
	}

	public function test_sealed_secrets_open_only_with_the_same_key_and_unchanged(): void {
		$sealed = Crypto::seal( 'sbk_secret' );
		$this->assertStringStartsWith( 'sb1:', $sealed );
		$this->assertStringNotContainsString( 'sbk_secret', $sealed );
		$this->assertSame( 'sbk_secret', Crypto::open( $sealed ) );
		$this->assertNotSame( $sealed, Crypto::seal( 'sbk_secret' ), 'a fresh nonce each time' );
		$tampered    = base64_decode( substr( $sealed, 4 ) );
		$tampered[30] = chr( ord( $tampered[30] ) ^ 1 );
		$this->assertNull( Crypto::open( 'sb1:' . base64_encode( $tampered ) ) );
		$this->assertNull( Crypto::open( 'plain' ) );
		Stubs::$salt = 'salt-two';
		$this->assertNull( Crypto::open( $sealed ), 'new salts: the site connects again' );
	}

	public function test_the_connection_is_stored_sealed(): void {
		Options::save_connection(
			array(
				'app_url'      => 'https://app.showbits.ai',
				'api'          => 'https://app.showbits.ai/api/wordpress/v1',
				'site_id'      => 'site-1',
				'workspace_id' => 'ws-1',
				'key'          => 'sbwp_site-key',
				'push_secret'  => 'whsec_push',
			)
		);
		$stored = Stubs::$options[ Options::CONNECTION ];
		$this->assertStringNotContainsString( 'sbwp_site-key', serialize( $stored ) );
		$this->assertStringNotContainsString( 'whsec_push', serialize( $stored ) );
		$this->assertSame( 'sbwp_site-key', Options::connection()['key'] );
		$this->assertSame( 'whsec_push', Options::connection()['push_secret'] );
		Stubs::$salt = 'salt-two';
		$this->assertNull( Options::connection() );
		$this->assertTrue( Options::has_connection(), 'still there: the settings say "connect again"' );
	}

	public function test_showbits_address_is_https_without_extras(): void {
		$this->assertSame( 'https://app.showbits.ai', Options::app_url( ' https://App.ShowBits.ai/ ' ) );
		$this->assertSame( 'https://showbits.intern:8443/app', Options::app_url( 'https://showbits.intern:8443/app/' ) );
		$this->assertNull( Options::app_url( 'http://app.showbits.ai' ) );
		$this->assertNull( Options::app_url( 'https://user:pass@app.showbits.ai' ) );
		$this->assertNull( Options::app_url( 'https://app.showbits.ai/?next=evil' ) );
		$this->assertNull( Options::app_url( 'https://app.showbits.ai/#x' ) );
		$this->assertNull( Options::app_url( 'javascript:alert(1)' ) );
		$this->assertSame( Options::DEFAULT_APP_URL, Options::settings()['app_url'] );
		$this->assertSame( '4x5', Options::settings()['card_format'] );
		$this->assertTrue( Options::settings()['import_poster'] );
	}
}
