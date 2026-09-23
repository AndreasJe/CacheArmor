<?php
/**
 * Package lint: the shipped files against the WordPress.org plugin review
 * team's "Common issues" list, plus this plugin's own review history.
 *
 * https://developer.wordpress.org/plugins/wordpress-org/common-issues/
 *
 * Run: php tests/test-package.php
 *
 * @package CacheArmor
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 'This file can only be run from the command line.' );
}

$root = dirname( __DIR__ );
$GLOBALS['ca_checks']   = 0;
$GLOBALS['ca_failures'] = 0;

function check( $label, $ok, $detail = '' ) {
	$GLOBALS['ca_checks']++;
	if ( ! $ok ) {
		$GLOBALS['ca_failures']++;
	}
	printf( "  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' === $detail ? '' : "  ($detail)" );
}

// The ship list lives in build.sh; read it from there so the two cannot drift.
preg_match( '/^SHIP=\( (.+) \)$/m', (string) file_get_contents( "$root/build.sh" ), $m );
$ship = str_replace( array( '"', '$SLUG' ), array( '', 'cachearmor' ), preg_split( '/\s+/', trim( $m[1] ?? '' ) ) );
$php  = array_values( array_filter( $ship, function ( $f ) { return '.php' === substr( $f, -4 ); } ) );
$src  = array();
foreach ( $php as $file ) {
	$src[ $file ] = (string) file_get_contents( "$root/$file" );
}

/** Files whose source matches a pattern. */
function offenders( array $src, $pattern ) {
	$hits = array();
	foreach ( $src as $file => $code ) {
		if ( preg_match_all( $pattern, $code, $found, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $found[0] as $hit ) {
				$hits[] = $file . ':' . ( substr_count( substr( $code, 0, $hit[1] ), "\n" ) + 1 );
			}
		}
	}
	return $hits;
}

echo "\nShipped files\n";
check( 'ship list read from build.sh', count( $ship ) >= 5, implode( ' ', $ship ) );
foreach ( $ship as $file ) {
	check( "$file exists", is_file( "$root/$file" ) );
}
$allowed = array( 'php', 'js', 'css', 'txt' );
check( 'only functional file types', ! array_filter( $ship, function ( $f ) use ( $allowed ) { return ! in_array( pathinfo( $f, PATHINFO_EXTENSION ), $allowed, true ); } ) );
check( 'no tests, dev tools or dotfiles shipped', ! array_filter( $ship, function ( $f ) { return 0 === strpos( $f, 'tests' ) || '.' === $f[0] || false !== strpos( $f, 'phpcs' ) || false !== strpos( $f, 'build' ); } ) );

echo "\nSecurity\n";
foreach ( $src as $file => $code ) {
	// The guard must be the first statement after the opening tag and header comments.
	$first = '';
	foreach ( token_get_all( $code ) as $token ) {
		if ( '' === $first && is_array( $token ) && in_array( $token[0], array( T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true ) ) {
			continue; // Skip only what precedes the first statement.
		}
		$first .= is_array( $token ) ? $token[1] : $token;
		if ( strlen( $first ) >= 30 ) {
			break;
		}
	}
	check( "$file blocks direct access first", 0 === strpos( $first, "if ( ! defined( 'ABSPATH' )" ), $first );
}
$hits = offenders( $src, '/<<<\s*[\'"]?[A-Za-z_]/' );
check( 'no HEREDOC or NOWDOC', ! $hits, implode( ', ', $hits ) );
$hits = offenders( $src, '/(?<!wp_)\bjson_encode\s*\(/' );
check( 'wp_json_encode, never json_encode', ! $hits, implode( ', ', $hits ) );
$hits = offenders( $src, '/\b(_e|_ex)\s*\(/' );
check( 'no unescaped _e() or _ex()', ! $hits, implode( ', ', $hits ) );
$hits = offenders( $src, '/foreach\s*\(\s*(array_keys\s*\(\s*)?\$_(COOKIE|GET|POST|REQUEST|SERVER)\b/' );
check( 'never loops over a whole superglobal', ! $hits, implode( ', ', $hits ) );
$hits = offenders( $src, '/\b(move_uploaded_file|ALLOW_UNFILTERED_UPLOADS)\b/' );
check( 'no raw upload handling', ! $hits, implode( ', ', $hits ) );
$hits = offenders( $src, '/\beval\s*\(|\bbase64_decode\s*\(|\b(shell_exec|passthru|proc_open|popen|system|exec)\s*\(/' );
check( 'no code execution or obfuscation', ! $hits, implode( ', ', $hits ) );

echo "\nCompatibility\n";
$hits = offenders( $src, '/<\?(?!php\b|xml)/' );
check( 'no short open tags', ! $hits, implode( ', ', $hits ) );
$hits = offenders( $src, '/\b(ini_set|error_reporting|date_default_timezone_set|set_time_limit)\s*\(/' );
check( 'no global PHP settings changed', ! $hits, implode( ', ', $hits ) );
$hits = offenders( $src, '/\bcurl_[a-z_]+\s*\(|file_get_contents\s*\(\s*[\'"]https?:/' );
check( 'no remote calls outside the HTTP API', ! $hits, implode( ', ', $hits ) );
// The plugin declares PHP 7.4; the development PHP is newer, so lint cannot catch these.
$hits = offenders( $src, '/\?->|\bmatch\s*\(|#\[|\benum\s+[A-Z]|\breadonly\s|\b(str_contains|str_starts_with|str_ends_with|array_is_list)\s*\(|\):\s*(static|mixed|never)\b/' );
check( 'no PHP 8-only syntax or functions', ! $hits, implode( ', ', $hits ) );
$globals = array();
foreach ( $src as $file => $code ) {
	// Top-level declarations only: class names, define() names, functions outside classes.
	preg_match_all( '/^(?:final\s+)?class\s+(\w+)|^function\s+(\w+)|define\(\s*\'(\w+)\'/m', $code, $d, PREG_SET_ORDER );
	foreach ( $d as $decl ) {
		$name = end( $decl );
		if ( 0 !== stripos( $name, 'cachearmor' ) ) {
			$globals[] = "$file:$name";
		}
	}
	// Hooks the plugin defines.
	preg_match_all( "/(?:apply_filters|do_action)\(\s*'(\w+)'/", $code, $h );
	foreach ( $h[1] as $hook ) {
		if ( 0 !== strpos( $hook, 'cachearmor_' ) && ! in_array( $hook, array( 'rest_pre_dispatch', 'rest_post_dispatch' ), true ) ) {
			$globals[] = "$file:$hook";
		}
	}
}
check( 'every global class, function, constant and hook is prefixed', ! $globals, implode( ', ', $globals ) );

echo "\nFile locations (the first review's rejection)\n";
$hits = offenders( $src, '/WP_CONTENT_DIR|[\'"]\/?wp-content\//' );
check( 'no hard-coded wp-content paths', ! $hits, implode( ', ', $hits ) );
check( 'cache resolved with wp_upload_dir()', false !== strpos( $src['cachearmor.php'], 'wp_upload_dir( null, false )' ) );
check( 'cache folder named after the slug', false !== strpos( $src['cachearmor.php'], "'/cachearmor'" ) );
$hits = offenders( $src, '/file_put_contents\s*\([^;]*<\?/' );
check( 'nothing written contains a PHP tag', ! $hits, implode( ', ', $hits ) );

echo "\nPlugin standards\n";
$main   = $src['cachearmor.php'];
$readme = (string) file_get_contents( "$root/readme.txt" );
$h      = function ( $name ) use ( $main ) { return preg_match( '/^ \* ' . preg_quote( $name, '/' ) . ':\s*(.+)$/m', $main, $x ) ? trim( $x[1] ) : null; };
$r      = function ( $name ) use ( $readme ) { return preg_match( '/^' . preg_quote( $name, '/' ) . ':\s*(.+)$/m', $readme, $x ) ? trim( $x[1] ) : null; };
preg_match( "/define\( 'CACHEARMOR_VERSION', '([^']+)' \)/", $main, $const );
check( 'main file matches the slug', is_file( "$root/cachearmor.php" ) && 'cachearmor' === $h( 'Text Domain' ) );
check( 'required headers present', $h( 'Plugin Name' ) && $h( 'Version' ) && $h( 'License' ) && $h( 'Requires PHP' ) && $h( 'Requires at least' ) );
check( 'licence identical in header and readme', $h( 'License' ) === $r( 'License' ), $h( 'License' ) . ' / ' . $r( 'License' ) );
check( 'licence URI identical', $h( 'License URI' ) === $r( 'License URI' ) );
check( 'stable tag = version = constant', $r( 'Stable tag' ) === $h( 'Version' ) && $h( 'Version' ) === ( $const[1] ?? null ), $r( 'Stable tag' ) . ' / ' . $h( 'Version' ) . ' / ' . ( $const[1] ?? '?' ) );
check( 'requirements identical in header and readme', $h( 'Requires PHP' ) === $r( 'Requires PHP' ) && $h( 'Requires at least' ) === $r( 'Requires at least' ) );
check( 'readme has Description, Installation, FAQ and Changelog', 4 === preg_match_all( '/^== (Description|Installation|Frequently Asked Questions|Changelog) ==$/m', $readme ) );
check( 'readme short description under 150 characters', preg_match( '/^License URI:.*\n\n(.+)$/m', $readme, $sd ) && strlen( $sd[1] ) <= 150, isset( $sd[1] ) ? strlen( $sd[1] ) . ' chars' : 'missing' );

echo "\nInternationalization\n";
$bad = array();
$map = array( '__' => 2, 'esc_html__' => 2, 'esc_attr__' => 2, 'esc_html_e' => 2, 'esc_attr_e' => 2, '_x' => 3, '_n' => 4 );
foreach ( $src as $file => $code ) {
	$t = token_get_all( $code );
	for ( $i = 0, $n = count( $t ); $i < $n; $i++ ) {
		if ( ! is_array( $t[ $i ] ) || T_STRING !== $t[ $i ][0] || ! isset( $map[ $t[ $i ][1] ] ) ) {
			continue;
		}
		for ( $j = $i + 1; $j < $n && is_array( $t[ $j ] ) && T_WHITESPACE === $t[ $j ][0]; $j++ );
		if ( '(' !== ( $t[ $j ] ?? '' ) ) {
			continue;
		}
		$depth = 0; $arg = 1; $args = array();
		for ( $k = $j; $k < $n; $k++ ) {
			if ( '(' === $t[ $k ] ) { $depth++; continue; }
			if ( ')' === $t[ $k ] ) { if ( 0 === --$depth ) { break; } continue; }
			if ( 1 === $depth && ',' === $t[ $k ] ) { $arg++; continue; }
			if ( 1 === $depth && ! ( is_array( $t[ $k ] ) && T_WHITESPACE === $t[ $k ][0] ) ) {
				$args[ $arg ][] = $t[ $k ];
			}
		}
		$domain = $args[ $map[ $t[ $i ][1] ] ] ?? array();
		$first  = $args[1] ?? array();
		$literal = function ( $a ) { return 1 === count( $a ) && is_array( $a[0] ) && T_CONSTANT_ENCAPSED_STRING === $a[0][0]; };
		if ( ! $literal( $domain ) || "'cachearmor'" !== $domain[0][1] || ! $literal( $first ) ) {
			$bad[] = $file . ':' . $t[ $i ][2] . ' ' . $t[ $i ][1] . '()';
		}
	}
}
check( 'every translated string and text domain is a literal, domain "cachearmor"', ! $bad, implode( ', ', $bad ) );

printf( "\n%d checks, %d failed\n", $GLOBALS['ca_checks'], $GLOBALS['ca_failures'] );
exit( $GLOBALS['ca_failures'] ? 1 : 0 );
