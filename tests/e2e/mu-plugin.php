<?php
/**
 * Only for the end-to-end check (tests/e2e/run.sh), never in a real site: the stand-in ShowBits runs on a private
 * address with a self-signed certificate, so this WordPress may talk to it and download from it.
 */

add_filter(
	'http_request_args',
	static function ( array $args, string $url ): array {
		if ( str_starts_with( $url, (string) getenv( 'SHOWBITS_E2E_ORIGIN' ) ) ) {
			$args['sslverify'] = false;
		}
		return $args;
	},
	10,
	2
);
add_filter( 'showbits_allow_private_media', '__return_true' );

// WordPress' language packs are not installed here, so WordPress would lay out a right-to-left translation left to
// right: in the right-to-left locales the admin gets the direction their language pack would give it.
add_action(
	'init',
	static function (): void {
		if ( in_array( determine_locale(), array( 'ar', 'ckb', 'fa_IR', 'ug_CN' ), true ) ) {
			$GLOBALS['wp_locale']->text_direction = 'rtl';
			wp_styles()->text_direction           = 'rtl';
		}
	}
);

// wp showbits-e2e-translations: how WordPress itself reads each translation the plugin brings, from the PHP file
// (.l10n.php, what WordPress 6.5+ prefers) and from the MO file: entries, language, plural forms, and the texts the
// browser checks look for.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'showbits-e2e-translations',
		static function (): void {
			$dir     = WP_PLUGIN_DIR . '/showbits/languages';
			$strings = preg_match_all( '/^msgid "(?!")/m', (string) file_get_contents( $dir . '/showbits.pot' ) );
			$report  = array();
			foreach ( glob( $dir . '/showbits-*.po' ) as $po ) {
				$locale = substr( basename( $po, '.po' ), strlen( 'showbits-' ) );
				$read   = array();
				foreach ( array( 'php' => '.l10n.php', 'mo' => '.mo' ) as $format => $suffix ) {
					$file = WP_Translation_File::create( substr( $po, 0, -3 ) . $suffix );
					if ( ! $file || $file->error() ) {
						$read[ $format ] = null;
						continue;
					}
					$headers = $file->headers();
					$plurals = array();
					$forms   = preg_match( '/nplurals\s*=\s*(\d+)/', $headers['plural-forms'] ?? '', $m ) ? (int) $m[1] : 0;
					try {
						$rule = new Plural_Forms( rtrim( (string) preg_replace( '/^.*plural\s*=\s*/', '', $headers['plural-forms'] ?? '' ), "; \t" ) );
						for ( $n = 0; $n <= 200; $n++ ) {
							$plurals[] = $rule->get( $n );
						}
					} catch ( Exception $e ) {
						$plurals = array();
					}
					$read[ $format ] = array(
						'entries'  => $file->entries(),
						'language' => $headers['language'] ?? '',
						'plurals'  => $plurals && max( $plurals ) < $forms && min( $plurals ) >= 0 ? $plurals : null,
					);
				}
				$php               = $read['php'];
				$mo                = $read['mo'];
				$report[ $locale ] = array(
					'complete' => $php && $mo && count( $php['entries'] ) === $strings && $php['entries'] === $mo['entries'],
					'language' => $php && $mo && $locale === $php['language'] && $locale === $mo['language'],
					'plurals'  => $php && $mo && null !== $php['plurals'] && $php['plurals'] === $mo['plurals'],
					'samples'  => $php ? array_intersect_key( $php['entries'], array_flip( array( 'Connection', 'Import selected' ) ) ) : array(),
				);
			}
			WP_CLI::line( (string) wp_json_encode( array( 'strings' => $strings, 'locales' => $report ) ) );
		}
	);
}
