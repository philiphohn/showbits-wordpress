<?php
/**
 * The plugin's translations (composer i18n): collects every string of the text domain from the PHP sources and the
 * plugin header into languages/showbits.pot, brings every showbits-<locale>.po next to it up to date (new strings
 * empty, gone ones out, the order and the translators' notes of the .pot), checks each translation, and compiles each
 * .po into the files a language pack brings: showbits-<locale>.l10n.php (WordPress 6.5+, preferred) and
 * showbits-<locale>.mo (what WordPress reads when a site sets translation_file_format to mo, and what gettext tools read).
 *
 * languages/ is not part of the plugin: the WordPress.org directory delivers translations as language packs from
 * translate.wordpress.org, and the .po files here are what gets imported there. The real-WordPress run
 * (tests/e2e/run.sh) puts the compiled files where language packs land, wp-content/languages/plugins.
 *
 * Fails when a .po misses a translation, marks one fuzzy, or breaks one: placeholders, the brand names, addresses and
 * codes, HTML, menu arrows, whitespace, headers (Language, UTF-8, the locale's plural forms of translate.wordpress.org).
 * A broken translation is left out of the compiled files, so WordPress shows the English text instead of failing.
 *
 *   php bin/i18n.php               write the .pot, update the .po files, write the .l10n.php and .mo files
 *   php bin/i18n.php --check       only check that all of them are current and complete (CI, composer check)
 *   php bin/i18n.php --lint FILE…  only check these .po files, as they are (while translating)
 */

declare( strict_types=1 );

const DOMAIN    = 'showbits';
const FUNCTIONS = array( '__', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', '_e' );

/** The fields of the plugin header WordPress translates with the text domain and that are text (not names or links). */
const HEADER_FIELDS = array( 'Description' => 'Description of the plugin' );

/**
 * Plural forms as translate.wordpress.org (GlotPress, locales/locales.php) has them, for the locales whose rule is not
 * WordPress' default (nplurals=2; plural=n != 1;). A variant (de_DE_formal, de_CH_informal, nl_NL_formal) has its
 * language's rule. A new locale whose rule differs from the default goes here.
 */
const PLURAL_FORMS = array(
	'ar'    => 'nplurals=6; plural=(n == 0) ? 0 : ((n == 1) ? 1 : ((n == 2) ? 2 : ((n % 100 >= 3 && n % 100 <= 10) ? 3 : ((n % 100 >= 11 && n % 100 <= 99) ? 4 : 5))));',
	'cs_CZ' => 'nplurals=3; plural=(n == 1) ? 0 : ((n >= 2 && n <= 4) ? 1 : 2);',
	'cy'    => 'nplurals=4; plural=(n==1) ? 0 : (n==2) ? 1 : (n != 8 && n != 11) ? 2 : 3;',
	'fa_IR' => 'nplurals=2; plural=n > 1;',
	'fr_CA' => 'nplurals=2; plural=n > 1;',
	'fr_FR' => 'nplurals=2; plural=n > 1;',
	'hr'    => 'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);',
	'ja'    => 'nplurals=1; plural=0;',
	'ka_GE' => 'nplurals=1; plural=0;',
	'ko_KR' => 'nplurals=1; plural=0;',
	'lo'    => 'nplurals=1; plural=0;',
	'lv'    => 'nplurals=3; plural=(n % 10 == 0 || n % 100 >= 11 && n % 100 <= 19) ? 0 : ((n % 10 == 1 && n % 100 != 11) ? 1 : 2);',
	'pl_PL' => 'nplurals=3; plural=(n == 1) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);',
	'pt_BR' => 'nplurals=2; plural=n > 1;',
	'ro_RO' => 'nplurals=3; plural=(n == 1) ? 0 : ((n == 0 || n % 100 >= 2 && n % 100 <= 19) ? 1 : 2);',
	'ru_RU' => 'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);',
	'sk_SK' => 'nplurals=3; plural=(n == 1) ? 0 : ((n >= 2 && n <= 4) ? 1 : 2);',
	'sl_SI' => 'nplurals=4; plural=(n % 100 == 1) ? 0 : ((n % 100 == 2) ? 1 : ((n % 100 == 3 || n % 100 == 4) ? 2 : 3));',
	'sr_RS' => 'nplurals=3; plural=(n % 10 == 1 && n % 100 != 11) ? 0 : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 12 || n % 100 > 14)) ? 1 : 2);',
	'tr_TR' => 'nplurals=2; plural=n > 1;',
	'vi'    => 'nplurals=1; plural=0;',
	'zh_CN' => 'nplurals=1; plural=0;',
);
const DEFAULT_PLURAL_FORMS = 'nplurals=2; plural=n != 1;';

/** Names a translation keeps, exactly as often as the original has them (inflected forms such as ShowBitsiin count). */
const BRANDS = array( 'ShowBits', 'WordPress' );

/**
 * What a translation keeps as it is: addresses, the REST path, HTTP(S), the product names, picture ratios. Bytes, not
 * UTF-8 (no /u), so a letter of another script next to a code counts as its boundary.
 */
const VERBATIM = '~https?://(?:[A-Za-z0-9.\-/]*[A-Za-z0-9/])?|(?<![A-Za-z0-9.])/[A-Za-z0-9\-_]+(?:/[A-Za-z0-9\-_]+)+|(?<![A-Za-z0-9])(?:HTTPS?|OnPremise|ShowBits Cloud)(?![A-Za-z0-9])|(?<![0-9:])[0-9]+:[0-9]+(?![0-9:])~';

/** printf placeholders as PHP's sprintf and the library's format() (assets/library.js) read them. */
const PLACEHOLDER = '/%(?:[0-9]+\$)?[-+ 0#]*(?:\'.)?[0-9]*(?:\.[0-9]+)?[bcdeEfFgGosuxX]/';

$plugin    = dirname( __DIR__ ) . '/showbits';
$languages = dirname( __DIR__ ) . '/languages';
$check     = in_array( '--check', $argv, true );
$lint      = in_array( '--lint', $argv, true ) ? array_values( array_filter( array_slice( $argv, 1 ), static fn( string $arg ): bool => ! str_starts_with( $arg, '--' ) ) ) : array();
if ( in_array( '--lint', $argv, true ) && ! $lint ) {
	fwrite( STDERR, "php bin/i18n.php --lint FILE.po …\n" );
	exit( 2 );
}

/**
 * Every string of the text domain, with the translators' comment before it and where it is used: the plugin header's
 * text first, as WordPress' own tools put it.
 *
 * @return array<string, array{comment: string, refs: array<int, string>}>
 */
function collect( string $plugin ): array {
	$strings = array();
	$main    = (string) file_get_contents( $plugin . '/' . DOMAIN . '.php' );
	foreach ( HEADER_FIELDS as $field => $comment ) {
		// As get_file_data() reads the header.
		if ( preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':(.*)$/mi', $main, $m ) && '' !== trim( $m[1] ) ) {
			$strings[ trim( $m[1] ) ] = array(
				'comment' => $comment,
				'refs'    => array( basename( $plugin ) . '/' . DOMAIN . '.php' ),
			);
		}
	}
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin, FilesystemIterator::SKIP_DOTS ) );
	$paths = array();
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
			$strings[ $text ]['refs'][]  = substr( $path, strlen( dirname( $plugin ) ) + 1 );
			$strings[ $text ]['comment'] = $strings[ $text ]['comment'] ?? $comment;
			$comment                     = '';
		}
	}
	return $strings;
}

function po_quote( string $text ): string {
	return '"' . addcslashes( $text, "\"\\\n\t" ) . '"';
}

/**
 * One entry of a .pot or .po.
 *
 * @param array{comment: string, refs: array<int, string>} $string      The string.
 * @param array<int, string>                               $annotations The translator's own comments and flags.
 */
function entry( string $text, array $string, string $translation = '', array $annotations = array() ): string {
	$out = '';
	foreach ( $annotations as $line ) {
		if ( ! str_starts_with( $line, '#,' ) ) {
			$out .= $line . "\n";
		}
	}
	if ( '' !== $string['comment'] ) {
		$out .= '#. ' . $string['comment'] . "\n";
	}
	$out .= '#: ' . implode( ' ', array_unique( $string['refs'] ) ) . "\n";
	foreach ( $annotations as $line ) {
		if ( str_starts_with( $line, '#,' ) ) {
			$out .= $line . "\n";
		}
	}
	return $out . 'msgid ' . po_quote( $text ) . "\nmsgstr " . po_quote( $translation ) . "\n";
}

/**
 * The .pot.
 *
 * @param array<string, array{comment: string, refs: array<int, string>}> $strings The strings.
 */
function pot( array $strings ): string {
	$out = "# Translations of the ShowBits plugin. Made by bin/i18n.php.\n"
		. "msgid \"\"\nmsgstr \"\"\n\"Content-Type: text/plain; charset=UTF-8\\n\"\n\"X-Domain: " . DOMAIN . "\\n\"\n";
	foreach ( $strings as $text => $string ) {
		$out .= "\n" . entry( (string) $text, $string );
	}
	return $out;
}

/**
 * A .po file as it is: its header block (kept as written), header fields, translations with the translator's own
 * comments and flags, and what is wrong with it as a file.
 *
 * @return array{head: string, header: array<string, string>, entries: array<string, array{msgstr: string, annotations: array<int, string>, fuzzy: bool}>, problems: array<int, string>}
 */
function read_po( string $path ): array {
	$name     = basename( $path );
	$content  = (string) file_get_contents( $path );
	$problems = array();
	if ( ! preg_match( '//u', $content ) ) {
		return array(
			'head'     => '',
			'header'   => array(),
			'entries'  => array(),
			'problems' => array( "{$name}: not UTF-8" ),
		);
	}
	$lines   = explode( "\n", str_replace( "\r\n", "\n", $content ) );
	$head    = null;
	$header  = array();
	$entries = array();
	$entry   = null;
	$field   = null;
	$notes   = array();
	$start   = 0;
	$flush   = static function ( int $line ) use ( &$entry, &$field, &$notes, &$head, &$header, &$entries, &$problems, &$lines, &$start, $name ): void {
		if ( null === $entry ) {
			return;
		}
		if ( null === $entry['msgstr'] ) {
			$problems[] = "{$name}:{$entry['line']}: msgid without msgstr";
		} elseif ( '' === $entry['msgid'] ) {
			if ( null !== $head ) {
				$problems[] = "{$name}:{$entry['line']}: a second header";
			} else {
				$head = implode( "\n", array_slice( $lines, 0, $line - 1 ) );
				foreach ( explode( "\n", $entry['msgstr'] ) as $pair ) {
					if ( preg_match( '/^([\w-]+):\s*(.*)$/', $pair, $m ) ) {
						$header[ $m[1] ] = trim( $m[2] );
					}
				}
			}
		} elseif ( null === $head ) {
			$problems[] = "{$name}:{$entry['line']}: entries before the header";
		} elseif ( isset( $entries[ $entry['msgid'] ] ) ) {
			$problems[] = "{$name}:{$entry['line']}: \"{$entry['msgid']}\" a second time";
		} else {
			$fuzzy                       = (bool) preg_grep( '/^#,.*\bfuzzy\b/', $notes );
			$entries[ $entry['msgid'] ] = array(
				'msgstr'      => $entry['msgstr'],
				// The translator's own comments and flags; references and extracted comments come from the .pot.
				'annotations' => array_values( preg_grep( '/^#(?:[ ,]|$)/', $notes ) ?: array() ),
				'fuzzy'       => $fuzzy,
			);
		}
		$entry = null;
		$field = null;
		$notes = array();
	};
	foreach ( $lines as $index => $raw ) {
		$number = $index + 1;
		$line   = rtrim( $raw );
		if ( '' === $line ) {
			$flush( $number );
			continue;
		}
		if ( str_starts_with( $line, '#' ) ) {
			if ( null !== $entry ) {
				$flush( $number );
			}
			if ( str_starts_with( $line, '#~' ) ) {
				$problems[] = "{$name}:{$number}: obsolete entry (#~): remove it";
			}
			$notes[] = $line;
			continue;
		}
		if ( preg_match( '/^(msgctxt|msgid_plural|msgstr\[\d+\])\s/', $line, $m ) ) {
			$problems[] = "{$name}:{$number}: {$m[1]} is not used by this plugin (bin/i18n.php knows no context or plurals)";
			continue;
		}
		if ( preg_match( '/^(msgid|msgstr) "(.*)"$/', $line, $m ) ) {
			$string = $m[2];
			$key    = $m[1];
		} elseif ( preg_match( '/^"(.*)"$/', $line, $m ) && null !== $field ) {
			$string = $m[1];
			$key    = null;
		} else {
			$problems[] = "{$name}:{$number}: not PO syntax: {$line}";
			continue;
		}
		if ( ! preg_match( '/^(?:[^"\\\\]|\\\\["\\\\ntr])*$/', $string ) ) {
			$problems[] = "{$name}:{$number}: a quote or backslash not escaped as \\\" or \\\\";
		}
		$string = stripcslashes( $string );
		if ( 'msgid' === $key ) {
			if ( null !== $entry ) {
				$flush( $number );
			}
			$entry = array(
				'msgid'  => $string,
				'msgstr' => null,
				'line'   => $number,
			);
			$field = 'msgid';
		} elseif ( 'msgstr' === $key ) {
			if ( null === $entry || null !== $entry['msgstr'] ) {
				$problems[] = "{$name}:{$number}: msgstr without msgid";
				continue;
			}
			$entry['msgstr'] = $string;
			$field           = 'msgstr';
		} else {
			$entry[ $field ] .= $string;
		}
	}
	$flush( count( $lines ) + 1 );
	if ( null === $head ) {
		$problems[] = "{$name}: no header (msgid \"\")";
	}
	return array(
		'head'     => (string) $head,
		'header'   => $header,
		'entries'  => $entries,
		'problems' => $problems,
	);
}

/** The binary operators of a plural rule, by precedence (C). */
const PRECEDENCE = array(
	'||' => 1,
	'&&' => 2,
	'==' => 3,
	'!=' => 3,
	'<'  => 4,
	'<=' => 4,
	'>'  => 4,
	'>=' => 4,
	'+'  => 5,
	'-'  => 5,
	'*'  => 6,
	'/'  => 6,
	'%'  => 6,
);

/**
 * A plural rule's value for every n up to 1000, or null when it is not one: nplurals=N; plural=EXPR; with EXPR in C
 * syntax (n, numbers, ! % * / + - < <= > >= == != && || ?: and parentheses), parsed here, never run as PHP.
 *
 * @return array{0: int, 1: array<int, int>}|null
 */
function plural_values( string $forms ): ?array {
	if ( ! preg_match( '/^\s*nplurals\s*=\s*([1-9])\s*;\s*plural\s*=\s*([^;]+);?\s*$/', $forms, $m ) ) {
		return null;
	}
	if ( ! preg_match_all( '/\s*(\d+|n|==|!=|<=|>=|&&|\|\||[<>?:()!%*\/+\-])/A', $m[2], $t ) || implode( '', $t[0] ) !== rtrim( $m[2] ) ) {
		return null;
	}
	$at   = 0;
	$tree = plural_ternary( $t[1], $at );
	if ( null === $tree || count( $t[1] ) !== $at ) {
		return null;
	}
	$values = array();
	for ( $n = 0; $n <= 1000; $n++ ) {
		$values[ $n ] = plural_evaluate( $tree, $n );
	}
	return array( (int) $m[1], $values );
}

/**
 * condition ? then : else, right-associative as in C.
 *
 * @param array<int, string> $tokens The rule's tokens.
 * @return array<int, mixed>|null
 */
function plural_ternary( array $tokens, int &$at ): ?array {
	$condition = plural_binary( $tokens, $at, 1 );
	if ( null === $condition || '?' !== ( $tokens[ $at ] ?? null ) ) {
		return $condition;
	}
	++$at;
	$then = plural_ternary( $tokens, $at );
	if ( null === $then || ':' !== ( $tokens[ $at ] ?? null ) ) {
		return null;
	}
	++$at;
	$else = plural_ternary( $tokens, $at );
	return null === $else ? null : array( '?', $condition, $then, $else );
}

/**
 * Binary operators from a precedence on (precedence climbing).
 *
 * @param array<int, string> $tokens The rule's tokens.
 * @return array<int, mixed>|null
 */
function plural_binary( array $tokens, int &$at, int $min ): ?array {
	$left = plural_unary( $tokens, $at );
	while ( null !== $left && ( PRECEDENCE[ $tokens[ $at ] ?? '' ] ?? 0 ) >= $min ) {
		$operator = $tokens[ $at++ ];
		$right    = plural_binary( $tokens, $at, PRECEDENCE[ $operator ] + 1 );
		$left     = null === $right ? null : array( $operator, $left, $right );
	}
	return $left;
}

/**
 * n, a number, !operand or (expression).
 *
 * @param array<int, string> $tokens The rule's tokens.
 * @return array<int, mixed>|null
 */
function plural_unary( array $tokens, int &$at ): ?array {
	$token = $tokens[ $at++ ] ?? null;
	if ( '!' === $token ) {
		$operand = plural_unary( $tokens, $at );
		return null === $operand ? null : array( '!', $operand );
	}
	if ( '(' === $token ) {
		$inner = plural_ternary( $tokens, $at );
		if ( null === $inner || ')' !== ( $tokens[ $at ] ?? null ) ) {
			return null;
		}
		++$at;
		return $inner;
	}
	if ( 'n' === $token ) {
		return array( 'n' );
	}
	return null !== $token && ctype_digit( $token ) ? array( '#', (int) $token ) : null;
}

/**
 * A parsed plural rule's value for n.
 *
 * @param array<int, mixed> $node The rule, parsed.
 */
function plural_evaluate( array $node, int $n ): int {
	switch ( $node[0] ) {
		case 'n':
			return $n;
		case '#':
			return $node[1];
		case '!':
			return (int) ! plural_evaluate( $node[1], $n );
		case '?':
			return plural_evaluate( $node[1], $n ) ? plural_evaluate( $node[2], $n ) : plural_evaluate( $node[3], $n );
	}
	$a = plural_evaluate( $node[1], $n );
	$b = plural_evaluate( $node[2], $n );
	return match ( $node[0] ) {
		'||' => (int) ( $a || $b ),
		'&&' => (int) ( $a && $b ),
		'==' => (int) ( $a === $b ),
		'!=' => (int) ( $a !== $b ),
		'<'  => (int) ( $a < $b ),
		'<=' => (int) ( $a <= $b ),
		'>'  => (int) ( $a > $b ),
		'>=' => (int) ( $a >= $b ),
		'+'  => $a + $b,
		'-'  => $a - $b,
		'*'  => $a * $b,
		'/'  => 0 === $b ? 0 : intdiv( $a, $b ),
		'%'  => 0 === $b ? 0 : $a % $b,
	};
}

/** The plural forms translate.wordpress.org has for a locale. */
function plural_forms_of( string $locale ): string {
	return PLURAL_FORMS[ $locale ] ?? PLURAL_FORMS[ (string) preg_replace( '/_(?:in)?formal$/', '', $locale ) ] ?? DEFAULT_PLURAL_FORMS;
}

/**
 * What is wrong with a .po's header for its locale.
 *
 * @param array<string, string> $header The header fields.
 * @return array<int, string>
 */
function header_problems( string $name, string $locale, array $header ): array {
	$problems = array();
	if ( ! preg_match( '/^text\/plain;\s*charset=UTF-8$/i', $header['Content-Type'] ?? '' ) ) {
		$problems[] = "{$name}: Content-Type must be text/plain; charset=UTF-8";
	}
	if ( ( $header['Language'] ?? '' ) !== $locale ) {
		$problems[] = "{$name}: Language must be {$locale}, the locale in the file name";
	}
	$forms  = $header['Plural-Forms'] ?? '';
	$values = plural_values( $forms );
	$wanted = plural_values( plural_forms_of( $locale ) );
	if ( null === $values ) {
		$problems[] = "{$name}: Plural-Forms \"{$forms}\" is not a plural rule";
	} elseif ( $values !== $wanted ) {
		$problems[] = "{$name}: Plural-Forms must be " . plural_forms_of( $locale ) . ' (translate.wordpress.org)';
	} elseif ( max( $values[1] ) >= $values[0] || min( $values[1] ) < 0 ) {
		$problems[] = "{$name}: Plural-Forms gives forms beyond nplurals";
	}
	return $problems;
}

/**
 * How often each match of a pattern occurs in a text.
 *
 * @return array<string, int>
 */
function occurrences( string $pattern, string $text ): array {
	preg_match_all( $pattern, $text, $m );
	$counts = array_count_values( $m[0] );
	ksort( $counts, SORT_STRING );
	return $counts;
}

/**
 * What is wrong with one translation of a text.
 *
 * @return array<int, string>
 */
function translation_problems( string $text, string $translation ): array {
	$problems = array();
	if ( occurrences( PLACEHOLDER, $text ) !== occurrences( PLACEHOLDER, $translation ) ) {
		$problems[] = 'placeholders differ (keep ' . ( implode( ' ', array_keys( occurrences( PLACEHOLDER, $text ) ) ) ?: 'none' ) . ', each as often, in any order)';
	} elseif ( str_contains( (string) preg_replace( PLACEHOLDER, '', $translation ), '%' ) ) {
		$problems[] = 'a % that is not a placeholder';
	}
	foreach ( BRANDS as $brand ) {
		if ( substr_count( $text, $brand ) !== substr_count( $translation, $brand ) ) {
			$problems[] = "\"{$brand}\" " . substr_count( $text, $brand ) . ' time(s), as in the original';
		}
	}
	foreach ( occurrences( VERBATIM, $text ) as $token => $count ) {
		if ( substr_count( $translation, (string) $token ) < $count ) {
			$problems[] = "\"{$token}\" must stay as it is";
		}
	}
	if ( occurrences( '/<[^>]*>/', $text ) !== occurrences( '/<[^>]*>/', $translation ) ) {
		$problems[] = 'HTML differs';
	}
	if ( substr_count( $text, '→' ) + substr_count( $text, '←' ) !== substr_count( $translation, '→' ) + substr_count( $translation, '←' ) ) {
		$problems[] = 'menu arrows differ (→, or ← in right-to-left scripts)';
	}
	foreach ( array( '/^[ \t\n]/', '/[ \t\n]$/' ) as $edge ) {
		if ( preg_match( $edge, $text ) !== preg_match( $edge, $translation ) ) {
			$problems[] = 'leading or trailing whitespace differs';
		}
	}
	if ( substr_count( $text, "\n" ) !== substr_count( $translation, "\n" ) ) {
		$problems[] = 'line breaks differ';
	}
	return $problems;
}

/**
 * A .po brought up to date with the .pot: its header as written, then every string in the .pot's order with the
 * .pot's notes, its translation (empty when new) and the translator's own comments and flags.
 *
 * @param array{head: string, entries: array<string, array{msgstr: string, annotations: array<int, string>, fuzzy: bool}>} $po The .po.
 * @param array<string, array{comment: string, refs: array<int, string>}> $strings The strings.
 */
function merged_po( array $po, array $strings ): string {
	$out = rtrim( $po['head'] ) . "\n";
	foreach ( $strings as $text => $string ) {
		$entry = $po['entries'][ (string) $text ] ?? null;
		$out  .= "\n" . entry( (string) $text, $string, $entry['msgstr'] ?? '', $entry['annotations'] ?? array() );
	}
	return $out;
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

/**
 * A GNU MO file (little-endian, originals sorted, no hash table), the header as the translation of the empty string.
 *
 * @param array<string, string> $header   The .po's header fields.
 * @param array<string, string> $messages Translations.
 */
function mo( array $header, array $messages ): string {
	$head = '';
	foreach ( $header as $key => $value ) {
		$head .= "{$key}: {$value}\n";
	}
	$pairs = array( array( '', $head ) );
	foreach ( $messages as $original => $translation ) {
		$pairs[] = array( (string) $original, $translation );
	}
	usort( $pairs, static fn( array $a, array $b ): int => strcmp( $a[0], $b[0] ) );
	$count  = count( $pairs );
	$offset = 28 + 16 * $count;
	$tables = array(
		'',
		'',
	);
	$data   = '';
	foreach ( array( 0, 1 ) as $side ) {
		foreach ( $pairs as $pair ) {
			$tables[ $side ] .= pack( 'VV', strlen( $pair[ $side ] ), $offset + strlen( $data ) );
			$data            .= $pair[ $side ] . "\0";
		}
	}
	return pack( 'V7', 0x950412de, 0, $count, 28, 28 + 8 * $count, 0, $offset ) . $tables[0] . $tables[1] . $data;
}

$strings  = collect( $plugin );
$problems = array();
$notes    = array();
$known    = array();
$writes   = array( $languages . '/' . DOMAIN . '.pot' => pot( $strings ) );
$files    = $lint ?: ( glob( $languages . '/' . DOMAIN . '-*.po' ) ?: array() );
foreach ( $files as $path ) {
	$name = basename( $path );
	if ( ! is_file( $path ) || ! preg_match( '/^' . DOMAIN . '-([a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?)\.po$/', $name, $m ) ) {
		$problems[] = "{$name}: not a translation of the plugin (" . DOMAIN . '-<locale>.po)';
		continue;
	}
	$locale   = $m[1];
	$po       = read_po( $path );
	$problems = array_merge( $problems, $po['problems'], $po['head'] ? header_problems( $name, $locale, $po['header'] ) : array() );
	$messages = array();
	foreach ( array_keys( $strings ) as $text ) {
		$text  = (string) $text;
		$entry = $po['entries'][ $text ] ?? null;
		if ( null === $entry || '' === $entry['msgstr'] ) {
			$problems[] = "{$name}: no translation for \"{$text}\"";
			continue;
		}
		if ( $entry['fuzzy'] ) {
			$problems[] = "{$name}: \"{$text}\" is marked fuzzy: check the translation, then remove the flag";
			continue;
		}
		$wrong = translation_problems( $text, $entry['msgstr'] );
		foreach ( $wrong as $problem ) {
			$problems[] = "{$name}: \"{$text}\": {$problem}";
		}
		if ( ! $wrong ) {
			$messages[ $text ] = $entry['msgstr'];
		}
	}
	foreach ( array_diff( array_keys( $po['entries'] ), array_map( 'strval', array_keys( $strings ) ) ) as $text ) {
		if ( $lint || $check ) {
			$problems[] = "{$name}: \"{$text}\" is no longer used: composer i18n removes it";
		} elseif ( '' !== $po['entries'][ $text ]['msgstr'] ) {
			$notes[] = "{$name}: removed the translation of \"{$text}\", no longer used";
		}
	}
	$compiled                = array( $languages . '/' . DOMAIN . "-{$locale}.l10n.php", $languages . '/' . DOMAIN . "-{$locale}.mo" );
	$known[ $compiled[0] ]   = true;
	$known[ $compiled[1] ]   = true;
	// A .po that does not read cleanly is not rewritten (nothing of it may get lost) and keeps its compiled files.
	if ( $lint || $po['problems'] ) {
		continue;
	}
	$writes[ $path ]        = merged_po( $po, $strings );
	$writes[ $compiled[0] ] = l10n_php( $locale, $po['header']['Plural-Forms'] ?? DEFAULT_PLURAL_FORMS, $messages );
	$writes[ $compiled[1] ] = mo( $po['header'], $messages );
}

if ( ! $lint ) {
	// Compiled files without their .po would ship translations nobody can update.
	foreach ( array_merge( glob( $languages . '/' . DOMAIN . '-*.l10n.php' ) ?: array(), glob( $languages . '/' . DOMAIN . '-*.mo' ) ?: array() ) as $file ) {
		if ( ! isset( $known[ $file ] ) ) {
			$problems[] = basename( $file ) . ' has no .po next to it';
		}
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
}
if ( $notes ) {
	echo implode( "\n", $notes ) . "\n";
}
if ( $problems ) {
	fwrite( STDERR, implode( "\n", $problems ) . "\n" );
	exit( 1 );
}
echo $lint
	? count( $files ) . ' file(s), ' . count( $strings ) . " strings each, translations complete\n"
	: count( $strings ) . ' strings, ' . count( $files ) . " locales, translations complete\n";
