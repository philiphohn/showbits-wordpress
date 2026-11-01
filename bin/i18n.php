<?php
/**
 * The plugin's translations (composer i18n): collects every string of the text domain from the PHP sources into
 * languages/showbits.pot, then makes WordPress' PHP translation files (*.l10n.php, WordPress 6.5+) from the .po files
 * next to it. Fails when a .po misses a string or keeps one that is gone, so a new string cannot ship untranslated.
 *
 *   php bin/i18n.php          write the .pot and the .l10n.php files
 *   php bin/i18n.php --check  only check that they are current (CI)
 */

declare( strict_types=1 );

const DOMAIN    = 'showbits';
const FUNCTIONS = array( '__', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', '_e' );

$plugin    = dirname( __DIR__ ) . '/showbits';
$languages = $plugin . '/languages';
$check     = in_array( '--check', $argv, true );

/**
 * Every string of the text domain, with the translators' comment before it and where it is used.
 *
 * @return array<string, array{comment: string, refs: array<int, string>}>
 */
function collect( string $plugin ): array {
	$strings = array();
	$files   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin, FilesystemIterator::SKIP_DOTS ) );
	$paths   = array();
	foreach ( $files as $file ) {
		if ( 'php' === $file->getExtension() && ! str_ends_with( $file->getFilename(), '.l10n.php' ) ) {
			$paths[] = $file->getPathname();
		}
	}
	sort( $paths );
	foreach ( $paths as $path ) {
		$tokens  = token_get_all( (string) file_get_contents( $path ) );
		$comment = '';
		$count   = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && T_COMMENT === $token[0] && str_contains( $token[1], 'translators:' ) ) {
				$comment = trim( preg_replace( '#^/\*|\*/$|^//#', '', $token[1] ) );
				continue;
			}
			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! in_array( $token[1], FUNCTIONS, true ) ) {
				continue;
			}
			$args = array();
			for ( $j = $i + 1; $j < $count && count( $args ) < 2; $j++ ) {
				$next = $tokens[ $j ];
				if ( is_array( $next ) && T_CONSTANT_ENCAPSED_STRING === $next[0] ) {
					$args[] = $next[1];
				} elseif ( is_array( $next ) && T_WHITESPACE === $next[0] ) {
					continue;
				} elseif ( '(' !== $next && ',' !== $next ) {
					break;
				}
			}
			if ( 2 !== count( $args ) || "'" . DOMAIN . "'" !== $args[1] ) {
				continue;
			}
			$text = stripcslashes( substr( $args[0], 1, -1 ) );
			if ( "'" === $args[0][0] ) {
				$text = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), substr( $args[0], 1, -1 ) );
			}
			// Files only, no line numbers: the .pot changes with the texts, not with every edit around them.
			$strings[ $text ]['refs'][] = substr( $path, strlen( dirname( $plugin ) ) + 1 );
			$strings[ $text ]['comment'] = $strings[ $text ]['comment'] ?? $comment;
			$comment                   = '';
		}
	}
	return $strings;
}

function po_quote( string $text ): string {
	return '"' . addcslashes( $text, "\"\\\n\t" ) . '"';
}

/**
 * The .pot.
 *
 * @param array<string, array{comment: string, refs: array<int, string>}> $strings The strings.
 */
function pot( array $strings ): string {
	$out = "# Translations of the ShowBits plugin. Made by bin/i18n.php.\n"
		. "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"X-Domain: " . DOMAIN . "\\n\"\n\n";
	foreach ( $strings as $text => $string ) {
		if ( '' !== $string['comment'] ) {
			$out .= '#. ' . $string['comment'] . "\n";
		}
		$out .= '#: ' . implode( ' ', array_unique( $string['refs'] ) ) . "\n";
		$out .= 'msgid ' . po_quote( $text ) . "\nmsgstr \"\"\n\n";
	}
	return $out;
}

/**
 * A .po file's translations (msgid → msgstr), and its plural forms.
 *
 * @return array{0: array<string, string>, 1: string}
 */
function read_po( string $path ): array {
	$entries = array();
	$plural  = 'nplurals=2; plural=(n != 1);';
	$key     = null;
	$field   = null;
	$current = array(
		'msgid'  => '',
		'msgstr' => '',
	);
	$flush   = static function () use ( &$entries, &$current, &$plural ): void {
		if ( '' === $current['msgid'] ) {
			if ( preg_match( '/Plural-Forms:\s*([^\n]+)/', $current['msgstr'], $m ) ) {
				$plural = trim( $m[1] );
			}
		} else {
			$entries[ $current['msgid'] ] = $current['msgstr'];
		}
		$current = array(
			'msgid'  => '',
			'msgstr' => '',
		);
	};
	foreach ( file( $path, FILE_IGNORE_NEW_LINES ) ?: array() as $line ) {
		if ( preg_match( '/^(msgid|msgstr) "(.*)"$/', $line, $m ) ) {
			if ( 'msgid' === $m[1] && null !== $key ) {
				$flush();
			}
			$field             = $m[1];
			$key               = true;
			$current[ $field ] = stripcslashes( $m[2] );
		} elseif ( preg_match( '/^"(.*)"$/', $line, $m ) && null !== $field ) {
			$current[ $field ] .= stripcslashes( $m[1] );
		}
	}
	if ( null !== $key ) {
		$flush();
	}
	return array( $entries, $plural );
}

/**
 * WordPress' PHP translation file.
 *
 * @param array<string, string> $messages Translations.
 */
function l10n_php( string $locale, string $plural, array $messages ): string {
	return "<?php\n// Made by bin/i18n.php from showbits-{$locale}.po.\nreturn " . var_export(
		array(
			'domain'       => DOMAIN,
			'plural-forms' => $plural,
			'language'     => $locale,
			'messages'     => $messages,
		),
		true
	) . ";\n";
}

$strings  = collect( $plugin );
$problems = array();
$writes   = array( $languages . '/' . DOMAIN . '.pot' => pot( $strings ) );
foreach ( glob( $languages . '/' . DOMAIN . '-*.po' ) ?: array() as $po ) {
	$locale                = substr( basename( $po, '.po' ), strlen( DOMAIN ) + 1 );
	[ $entries, $plural ]  = read_po( $po );
	$missing               = array_diff( array_keys( $strings ), array_keys( array_filter( $entries, static fn( string $t ): bool => '' !== $t ) ) );
	$stale                 = array_diff( array_keys( $entries ), array_keys( $strings ) );
	foreach ( $missing as $text ) {
		$problems[] = basename( $po ) . ": no translation for \"{$text}\"";
	}
	foreach ( $stale as $text ) {
		$problems[] = basename( $po ) . ": \"{$text}\" is no longer used";
	}
	$messages = array();
	foreach ( array_keys( $strings ) as $text ) {
		if ( isset( $entries[ $text ] ) && '' !== $entries[ $text ] ) {
			$messages[ $text ] = $entries[ $text ];
		}
	}
	$writes[ $languages . '/' . DOMAIN . "-{$locale}.l10n.php" ] = l10n_php( $locale, $plural, $messages );
}

foreach ( $writes as $path => $content ) {
	if ( $check ) {
		if ( ! is_file( $path ) || file_get_contents( $path ) !== $content ) {
			$problems[] = basename( $path ) . ' is out of date: composer i18n';
		}
	} else {
		file_put_contents( $path, $content );
	}
}
if ( $problems ) {
	fwrite( STDERR, implode( "\n", $problems ) . "\n" );
	exit( 1 );
}
echo count( $strings ) . " strings, translations complete\n";
