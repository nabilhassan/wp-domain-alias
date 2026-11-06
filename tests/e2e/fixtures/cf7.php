<?php
/**
 * Creates a Contact Form 7 form and a page that shows it.
 * Run with: wp eval-file cf7.php
 */
$form = WPCF7_ContactForm::get_template( array( 'title' => 'Alias test form' ) );
$form->save();
$existing = get_page_by_path( 'cf7-page' );
if ( $existing ) {
	wp_delete_post( $existing->ID, true );
}
$page = wp_insert_post( array(
	'post_type'    => 'page',
	'post_status'  => 'publish',
	'post_title'   => 'CF7 page',
	'post_name'    => 'cf7-page',
	'post_content' => '[contact-form-7 id="' . $form->id() . '" title="Alias test form"]',
) );
$ids = get_option( 'wpda_test_ids' );
$ids['cf7_form'] = $form->id();
$ids['cf7_page'] = $page;
update_option( 'wpda_test_ids', $ids );
echo "CF7 form {$form->id()} page $page\n";
