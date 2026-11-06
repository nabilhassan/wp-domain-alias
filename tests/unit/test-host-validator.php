<?php
use WP_Domain_Alias\Host_Validator as V;

group( 'Host_Validator::normalize_input' );
$accept = array(
	'alias-one.example'          => 'alias-one.example',
	'Alias-One.EXAMPLE'          => 'alias-one.example',
	'  alias-one.example  '      => 'alias-one.example',
	'https://alias-one.example/' => 'alias-one.example',
	'HTTPS://Alias-One.example'  => 'alias-one.example',
	'alias-one.example.'         => 'alias-one.example',
	'www.alias-two.example'      => 'www.alias-two.example',
	'xn--bcher-kva.example'      => 'xn--bcher-kva.example',
	'a.b.c.d.example'            => 'a.b.c.d.example',
	'local-dev.test'             => 'local-dev.test',
);
foreach ( $accept as $in => $out ) {
	$r = V::normalize_input( $in );
	same( $r['host'], $out, "accepts '$in'" );
	same( $r['error'], '', "no error for '$in'" );
}
$r = V::normalize_input( 'http://alias-one.example' );
ok( 'alias-one.example' === $r['host'] && 1 === count( $r['warnings'] ), 'http:// accepted with an HTTPS warning' );

$reject = array(
	''                                 => 'empty',
	'   '                              => 'blank',
	'*.alias.example'                  => 'wildcard',
	'alias.*.example'                  => 'inner wildcard',
	'alias.example:8080'               => 'port',
	'alias.example:443'                => 'default port is still a port',
	'https://alias.example:443/'       => 'port inside URL',
	'alias.example/path'               => 'path',
	'https://alias.example/path'       => 'URL path',
	'alias.example?x=1'                => 'query',
	'alias.example#x'                  => 'fragment',
	'user@alias.example'               => 'userinfo',
	'https://user:pw@alias.example'    => 'URL userinfo',
	'alias example.com'                => 'space',
	"alias.example\r\nX-Injected: 1"   => 'CRLF',
	"alias.example\0"                  => 'NUL byte',
	'alias_example.com'                => 'underscore',
	'-alias.example'                   => 'leading hyphen',
	'alias-.example'                   => 'trailing hyphen in label',
	'alias..example'                   => 'empty label',
	'localhost'                        => 'single label',
	'192.168.1.10'                     => 'IPv4',
	'[::1]'                            => 'IPv6',
	'10.0.0.1.example.123'             => 'numeric TLD',
	'ftp://alias.example'              => 'non-http scheme',
	'javascript://alias.example'       => 'javascript scheme',
	'alias\\example.com'               => 'backslash',
	str_repeat( 'a', 64 ) . '.example' => 'label over 63 characters',
	str_repeat( 'abcdefghi.', 26 ) . 'example' => 'name over 253 characters',
	'<script>.example'                 => 'markup',
);
foreach ( $reject as $in => $why ) {
	$r = V::normalize_input( $in );
	ok( '' === $r['host'] && '' !== $r['error'], "rejects $why" );
}
$r = V::normalize_input( array( 'alias.example' ) );
ok( '' !== $r['error'], 'rejects non-string input' );

$r = V::normalize_input( 'bücher.example' );
if ( function_exists( 'idn_to_ascii' ) ) {
	same( $r['host'], 'xn--bcher-kva.example', 'converts IDN to punycode' );
} else {
	ok( '' === $r['host'] && false !== strpos( $r['error'], 'punycode' ), 'without intl, IDN input asks for punycode' );
}

group( 'Host_Validator::request_host' );
same( V::request_host( 'alias-one.example', true ), 'alias-one.example', 'plain host' );
same( V::request_host( 'ALIAS-ONE.EXAMPLE', true ), 'alias-one.example', 'case-insensitive' );
same( V::request_host( 'alias-one.example:443', true ), 'alias-one.example', 'default HTTPS port' );
same( V::request_host( 'alias-one.example:80', false ), 'alias-one.example', 'default HTTP port' );
foreach ( array( 'alias-one.example:80' => true, 'alias-one.example:8443' => true, 'alias-one.example.' => true, 'user@alias-one.example' => true, "alias-one.example\r\nX: y" => true, 'alias-one.example/x' => true, '127.0.0.1' => true, '' => true, 'alias-one.example:443' => false ) as $h => $ssl ) {
	same( V::request_host( $h, $ssl ), '', 'rejects ' . json_encode( $h ) . ( $ssl ? ' over HTTPS' : ' over HTTP' ) );
}
same( V::request_host( null, true ), '', 'rejects missing header' );

group( 'Host_Validator helpers' );
same( V::www_counterpart( 'example.com' ), 'www.example.com', 'adds www' );
same( V::www_counterpart( 'www.example.com' ), 'example.com', 'removes www' );
ok( V::is_valid_host( 'alias.example' ) && ! V::is_valid_host( 'Alias.example' ) && ! V::is_valid_host( 5 ), 'is_valid_host requires lowercase strings' );
