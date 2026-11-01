<?php
/**
 * Unit tests of the plugin without a WordPress install: the plugin's classes against small stand-ins for the parts of
 * WordPress they call (options, HTTP, the database for the import log). What needs a real WordPress (sideloading,
 * redirects, REST routing, WP-Cron, ShowBits' check at the push address) runs in a real one in tests/e2e/run.sh.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'GB_IN_BYTES', 1024 ** 3 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'SHOWBITS_VERSION', '1.0.0-test' );
define( 'SHOWBITS_FILE', dirname( __DIR__ ) . '/showbits/showbits.php' );

require dirname( __DIR__ ) . '/vendor/autoload.php';

/** What the stand-ins remember; reset before every test (Stubs::reset). */
final class Stubs {
	/** @var array<string, mixed> */
	public static array $options = array();
	/** @var array<string, mixed> */
	public static array $transients = array();
	/** @var array<int, array{url: string, args: array<string, mixed>}> */
	public static array $requests = array();
	/** @var array<int, array<string, mixed>|WP_Error> Answers to hand out, in order. */
	public static array $responses = array();
	public static string $salt = 'salt-one';

	public static function reset(): void {
		self::$options    = array();
		self::$transients = array();
		self::$requests   = array();
		self::$responses  = array();
		self::$salt       = 'salt-one';
		$GLOBALS['wpdb']  = new Fake_Wpdb();
	}

	/**
	 * A response as wp_remote_request returns it.
	 *
	 * @param array<string, string> $headers Headers, lower case.
	 * @return array<string, mixed>
	 */
	public static function response( int $status, string $body = '', array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => $body,
			'headers'  => $headers,
		);
	}
}

/** WordPress' error type, as far as the plugin uses it. */
class WP_Error {
	/** @param mixed $data */
	public function __construct( private string $code = '', private string $message = '', private $data = null ) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}

	/** @return mixed */
	public function get_error_data() {
		return $this->data;
	}
}

/** The import log's table, in memory: a push id is one row; a delivery again with a new ticket changes it. */
final class Fake_Wpdb {
	public string $prefix = 'wp_';
	/** @var array<string, string> push id → ticket */
	public array $push_ids = array();

	/** @param mixed ...$args */
	public function prepare( string $query, ...$args ): string {
		return vsprintf( str_replace( array( '%i', '%s' ), array( '`%s`', "'%s'" ), $query ), $args );
	}

	public function query( string $sql ): int {
		if ( preg_match( "/^INSERT INTO \S+ \(clip_id, variant, source, push_id, ticket, .*VALUES \('[^']*', '[^']*', 'push', '([^']+)', '([0-9a-f]+)'/s", $sql, $m ) ) {
			if ( ! isset( $this->push_ids[ $m[1] ] ) ) {
				$this->push_ids[ $m[1] ] = $m[2];
				return 1;
			}
			if ( $this->push_ids[ $m[1] ] === $m[2] ) {
				return 0;
			}
			$this->push_ids[ $m[1] ] = $m[2];
			return 2;
		}
		return 0;
	}
}

// ---- WordPress functions the classes call

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function wp_salt( string $scheme = 'auth' ): string {
	return Stubs::$salt . '-' . $scheme;
}

/** @param mixed $default */
function get_option( string $name, $default = false ) {
	return Stubs::$options[ $name ] ?? $default;
}

/** @param mixed $value */
function update_option( string $name, $value, $autoload = null ): bool {
	Stubs::$options[ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	unset( Stubs::$options[ $name ] );
	return true;
}

/** @return mixed */
function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

/** @param mixed $data */
function wp_json_encode( $data ): string|false {
	return json_encode( $data );
}

/** @param mixed $thing */
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_request( string $url, array $args = array() ) {
	Stubs::$requests[] = array(
		'url'  => $url,
		'args' => $args,
	);
	return array_shift( Stubs::$responses ) ?? Stubs::response( 500 );
}

/**
 * @param array<string, mixed> $args
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_post( string $url, array $args = array() ) {
	return wp_remote_request( $url, array( 'method' => 'POST' ) + $args );
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_response_code( $response ): int|string {
	return is_array( $response ) ? $response['response']['code'] : '';
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_body( $response ): string {
	return is_array( $response ) ? $response['body'] : '';
}

/** @param array<string, mixed> $response */
function wp_remote_retrieve_header( $response, string $header ): string {
	return is_array( $response ) ? ( $response['headers'][ strtolower( $header ) ] ?? '' ) : '';
}

/** @param mixed $value */
function wp_cache_set( string $key, $value, string $group = '', int $expire = 0 ): bool {
	return true;
}

/** @return mixed */
function wp_cache_get( string $key, string $group = '' ) {
	return false;
}

function wp_cache_delete( string $key, string $group = '' ): bool {
	return true;
}

function current_time( string $type, bool $gmt = false ): string {
	return gmdate( 'Y-m-d H:i:s' );
}

/** As WordPress' wp_strip_all_tags: no tags, scripts and styles with their content. */
function wp_strip_all_tags( string $text ): string {
	return strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text ) );
}

/** As WordPress' (enough for the tests): no tags, one line, trimmed. */
function sanitize_text_field( string $str ): string {
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( $str ) ) );
}

/** As WordPress' (enough for the tests): no tags, line breaks kept, trimmed. */
function sanitize_textarea_field( string $str ): string {
	return trim( wp_strip_all_tags( $str ) );
}

function sanitize_title( string $title ): string {
	$title = strtolower( (string) iconv( 'UTF-8', 'ASCII//TRANSLIT', $title ) );
	return trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $title ), '-' );
}

/** @param mixed $value */
function apply_filters( string $hook, $value ) {
	return $value;
}

function set_url_scheme( string $url, string $scheme ): string {
	return (string) preg_replace( '#^\w+://#', $scheme . '://', $url );
}

Stubs::reset();
