<?php
/**
 * Creates a page built with Elementor containing a button and a heading link.
 * Run with: wp eval-file elementor.php
 */
$existing = get_page_by_path( 'elementor-page' );
if ( $existing ) {
	wp_delete_post( $existing->ID, true );
}
$id   = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Elementor page', 'post_name' => 'elementor-page' ) );
$data = array(
	array(
		'id'       => 'c0ffee1',
		'elType'   => 'container',
		'settings' => array(),
		'isInner'  => false,
		'elements' => array(
			array(
				'id'         => 'b0a7b01',
				'elType'     => 'widget',
				'widgetType' => 'button',
				'settings'   => array( 'text' => 'Contact us', 'link' => array( 'url' => home_url( '/contact/' ), 'is_external' => '', 'nofollow' => '' ) ),
				'elements'   => array(),
			),
			array(
				'id'         => 'he4d001',
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'About link', 'link' => array( 'url' => home_url( '/about/' ), 'is_external' => '', 'nofollow' => '' ) ),
				'elements'   => array(),
			),
		),
	),
);
update_post_meta( $id, '_elementor_edit_mode', 'builder' );
update_post_meta( $id, '_elementor_template_type', 'wp-page' );
update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
$ids = get_option( 'wpda_test_ids' );
$ids['elementor'] = $id;
update_option( 'wpda_test_ids', $ids );
echo "Elementor page $id\n";
