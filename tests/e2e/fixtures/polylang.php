<?php
/**
 * Configures Polylang: English (default, no prefix) and Spanish under /es/.
 * Run with: wp eval-file polylang.php
 */
$model = PLL()->model;
$add   = function ( $args ) use ( $model ) {
	if ( $model->get_language( $args['slug'] ) ) {
		return;
	}
	if ( isset( $model->languages ) && method_exists( $model->languages, 'add' ) ) {
		$model->languages->add( $args );
	} else {
		$model->add_language( $args );
	}
};
$add( array( 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'rtl' => false, 'term_group' => 0 ) );
$add( array( 'name' => 'Español', 'slug' => 'es', 'locale' => 'es_ES', 'rtl' => false, 'term_group' => 1 ) );

$options = get_option( 'polylang' );
$options['force_lang']   = 1; // Language from the directory name.
$options['hide_default'] = 1; // No /en/ prefix.
$options['rewrite']      = 1; // /es/ rather than /language/es/.
$options['default_lang'] = 'en';
update_option( 'polylang', $options );

$ids = get_option( 'wpda_test_ids' );
foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'numberposts' => -1, 'post_status' => 'any' ) ) as $p ) {
	if ( ! pll_get_post_language( $p->ID ) ) {
		pll_set_post_language( $p->ID, 'en' );
	}
}
foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) ) as $t ) {
	if ( ! pll_get_term_language( $t->term_id ) ) {
		pll_set_term_language( $t->term_id, 'en' );
	}
}

$switcher = '<!-- wp:polylang/language-switcher {"dropdown":false} /-->';
$es       = get_page_by_path( 'sobre-nosotros' );
if ( ! $es ) {
	$es_id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Sobre nosotros',
		'post_name'    => 'sobre-nosotros',
		'post_content' => '<!-- wp:paragraph --><p>Página en español. <a id="es-contact" href="' . esc_url( home_url( '/es/' ) ) . '">Inicio</a></p><!-- /wp:paragraph -->' . $switcher,
	) );
	pll_set_post_language( $es_id, 'es' );
	pll_save_post_translations( array( 'en' => $ids['about'], 'es' => $es_id ) );
} else {
	$es_id = $es->ID;
}
$about = get_post( $ids['about'] );
if ( false === strpos( $about->post_content, 'polylang/language-switcher' ) ) {
	wp_update_post( array( 'ID' => $about->ID, 'post_content' => $about->post_content . $switcher ) );
}
$ids['es_about'] = $es_id;
update_option( 'wpda_test_ids', $ids );
flush_rewrite_rules();
echo "Polylang ready es_about=$es_id\n";
