<?php
/** Staff SMS authorization and recipient selection. */
const VE_SMS_LOCATIONS = 've_staff_sms_locations';
const VE_SMS_DEPARTMENTS = 've_staff_sms_departments';
const VE_SMS_NUMBERS = 've_staff_sms_numbers_allowed';
const VE_SMS_RECIPIENTS = 've_staff_sms_recipient_ids';
const VE_SMS_NUMBER_TAX = 'sms-sending-number';
const VE_SMS_GROUP_TAX = 'sms-recipient-group';

function ve_sms_unrestricted( int $user_id ): bool {
	return user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'edit_others_posts' );
}
function ve_sms_allowed_terms( int $user_id, string $taxonomy ): array {
	$keys = array( 'location' => VE_SMS_LOCATIONS, 'department' => VE_SMS_DEPARTMENTS, VE_SMS_NUMBER_TAX => VE_SMS_NUMBERS );
	$ids = array_values( array_filter( array_map( 'absint', (array) get_user_meta( $user_id, $keys[ $taxonomy ], true ) ) ) );
	if ( $ids || ! ve_sms_unrestricted( $user_id ) ) return $ids;
	$all = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids' ) );
	return is_wp_error( $all ) ? array() : array_map( 'absint', $all );
}
function ve_sms_taxonomy_labels( string $singular, string $plural, string $short_name ): array {
	return array(
		'name'                       => $plural,
		'singular_name'              => $singular,
		'menu_name'                  => $plural,
		'all_items'                  => 'All ' . $plural,
		'edit_item'                  => 'Edit ' . $singular,
		'view_item'                  => 'View ' . $singular,
		'update_item'                => 'Update ' . $singular,
		'add_new_item'               => 'Add ' . $short_name,
		'new_item_name'              => 'New ' . $singular . ' Name',
		'parent_item'                => 'Parent ' . $singular,
		'parent_item_colon'          => 'Parent ' . $singular . ':',
		'search_items'               => 'Search ' . $plural,
		'popular_items'              => 'Popular ' . $plural,
		'separate_items_with_commas' => 'Separate ' . strtolower( $plural ) . ' with commas',
		'add_or_remove_items'        => 'Add or remove ' . strtolower( $plural ),
		'choose_from_most_used'      => 'Choose from the most used ' . strtolower( $plural ),
		'not_found'                  => 'No ' . strtolower( $plural ) . ' found.',
		'no_terms'                   => 'No ' . strtolower( $plural ),
		'items_list_navigation'      => $plural . ' list navigation',
		'items_list'                 => $plural . ' list',
		'back_to_items'              => 'Back to ' . $plural,
	);
}
function ve_sms_register_taxonomies(): void {
	register_taxonomy( VE_SMS_NUMBER_TAX, 'staff-sms', array(
		'labels'            => ve_sms_taxonomy_labels( 'Sending Number', 'Sending Numbers', 'Number' ),
		'public'            => false,
		'show_ui'           => true,
		'show_admin_column' => true,
		'meta_box_cb'       => false,
		'capabilities'      => array( 'manage_terms' => 'manage_options', 'edit_terms' => 'manage_options', 'delete_terms' => 'manage_options', 'assign_terms' => 'edit_posts' ),
	) );
	register_taxonomy( VE_SMS_GROUP_TAX, 'staff-sms', array(
		'labels'            => ve_sms_taxonomy_labels( 'Recipient Group', 'Recipient Groups', 'Recipient Group' ),
		'public'            => false,
		'show_ui'           => true,
		'show_admin_column' => true,
		'meta_box_cb'       => false,
	) );
}
add_action( 'init', 've_sms_register_taxonomies', 8 );
function ve_sms_number( string $value ): string {
	$digits = preg_replace( '/\D+/', '', $value );
	if ( strlen( $digits ) === 10 ) $digits = '1' . $digits;
	return $digits ? '+' . $digits : '';
}
function ve_sms_migrate_numbers(): void {
	if ( get_option( 've_sms_number_terms_migrated' ) || ! function_exists( 'get_field' ) ) return;
	foreach ( (array) get_field( 've_staff_sms_numbers', 'option' ) as $row ) {
		$number = ve_sms_number( (string) ( $row['number'] ?? '' ) );
		if ( ! $number ) continue;
		$found = get_terms( array( 'taxonomy' => VE_SMS_NUMBER_TAX, 'hide_empty' => false, 'meta_key' => 'sms_number', 'meta_value' => $number ) );
		$id = $found && ! is_wp_error( $found ) ? (int) $found[0]->term_id : 0;
		if ( ! $id ) { $created = wp_insert_term( (string) ( $row['label'] ?: $number ), VE_SMS_NUMBER_TAX ); $id = is_wp_error( $created ) ? 0 : (int) $created['term_id']; }
		if ( ! $id ) continue;
		update_term_meta( $id, 'sms_number', $number ); update_term_meta( $id, 'sms_active', ! empty( $row['active'] ) ? 1 : 0 );
		foreach ( array( 'from_name_status', 'from_name', 'optout_keyword_status', 'optout_keyword' ) as $key ) update_term_meta( $id, 'sms_' . $key, $row[ $key ] ?? '' );
	}
	update_option( 've_sms_number_terms_migrated', 1, false );
}
add_action( 'admin_init', 've_sms_migrate_numbers' );
function ve_sms_user_fields( WP_User $user ): void {
	if ( ! current_user_can( 'edit_users' ) ) return;
	wp_nonce_field( 've_sms_permissions', 've_sms_permissions_nonce' );
	echo '<h2>SMS Permissions</h2><p>High-access roles with no selection have access to all values. Other roles with no selection have no recipient access.</p><table class="form-table">';
	foreach ( array( VE_SMS_LOCATIONS => array( 'Locations', 'location' ), VE_SMS_DEPARTMENTS => array( 'Departments', 'department' ), VE_SMS_NUMBERS => array( 'Sending Numbers', VE_SMS_NUMBER_TAX ) ) as $key => $definition ) {
		$selected = array_map( 'absint', (array) get_user_meta( $user->ID, $key, true ) ); $terms = get_terms( array( 'taxonomy' => $definition[1], 'hide_empty' => false ) );
		echo '<tr><th>' . esc_html( $definition[0] ) . '</th><td>';
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $term->term_id ) . '" ' . checked( in_array( (int) $term->term_id, $selected, true ), true, false ) . '> ' . esc_html( $term->name ) . '</label>';
		echo '</td></tr>';
	}
	echo '</table>';
}
add_action( 'show_user_profile', 've_sms_user_fields' ); add_action( 'edit_user_profile', 've_sms_user_fields' );
function ve_sms_save_user_fields( int $user_id ): void {
	if ( ! current_user_can( 'edit_user', $user_id ) || ! isset( $_POST['ve_sms_permissions_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ve_sms_permissions_nonce'] ) ), 've_sms_permissions' ) ) return;
	foreach ( array( VE_SMS_LOCATIONS, VE_SMS_DEPARTMENTS, VE_SMS_NUMBERS ) as $key ) update_user_meta( $user_id, $key, isset( $_POST[ $key ] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST[ $key ] ) ) ) ) : array() );
}
add_action( 'personal_options_update', 've_sms_save_user_fields' ); add_action( 'edit_user_profile_update', 've_sms_save_user_fields' );
function ve_sms_filter_field( array $field ): array {
	$taxonomy = ( $field['name'] ?? '' ) === 'sms_msg_location' ? 'location' : ( ( $field['name'] ?? '' ) === 'sms_msg_department' ? 'department' : '' );
	if ( ! $taxonomy ) return $field;
	$field['allow_terms'] = ve_sms_allowed_terms( get_current_user_id(), $taxonomy ) ?: array( 0 );
	$field['field_type'] = 'select'; $field['ui'] = 1; $field['multiple'] = 1; $field['allow_null'] = 1; $field['required'] = 0; $field['wrapper']['class'] = ''; $field['wrapper']['width'] = '50'; $field['placeholder'] = 'Search ' . $taxonomy . 's';
	return $field;
}
add_filter( 'acf/load_field/name=sms_msg_location', 've_sms_filter_field', 20 ); add_filter( 'acf/load_field/name=sms_msg_department', 've_sms_filter_field', 20 );
function ve_sms_number_choices( array $field ): array {
	$field['choices'] = array();
	foreach ( ve_sms_allowed_terms( get_current_user_id(), VE_SMS_NUMBER_TAX ) as $id ) { $term = get_term( $id ); $number = ve_sms_number( (string) get_term_meta( $id, 'sms_number', true ) ); if ( $term && ! is_wp_error( $term ) && $number && get_term_meta( $id, 'sms_active', true ) ) $field['choices'][ $number ] = $term->name . ' (' . $number . ')'; }
	$field['required'] = 1; return $field;
}
add_filter( 'acf/load_field/name=sms_msg_from', 've_sms_number_choices', 30 );
function ve_sms_staff_allowed( int $staff_id, int $user_id ): bool {
	$locations = wp_get_object_terms( $staff_id, 'location', array( 'fields' => 'ids' ) ); $departments = wp_get_object_terms( $staff_id, 'department', array( 'fields' => 'ids' ) );
	return ! is_wp_error( $locations ) && ! is_wp_error( $departments ) && (bool) array_intersect( $locations, ve_sms_allowed_terms( $user_id, 'location' ) ) && (bool) array_intersect( $departments, ve_sms_allowed_terms( $user_id, 'department' ) );
}
/** Expand the explicit All choice only within the author's permitted terms. */
function ve_sms_resolve_filter_terms( array $values, string $taxonomy, int $user_id ): array {
	$allowed = ve_sms_allowed_terms( $user_id, $taxonomy );
	return in_array( '__all', $values, true ) ? $allowed : array_values( array_intersect( array_map( 'absint', $values ), $allowed ) );
}
function ve_sms_prepare_filter_choices( array $field ): array {
	$taxonomy = $field['name'] === 'sms_msg_location' ? 'location' : 'department';
	$field['choices'] = array( '__all' => $taxonomy === 'location' ? 'All Locations' : 'All Departments' ) + (array) ( $field['choices'] ?? array() );
	$allowed = ve_sms_allowed_terms( get_current_user_id(), $taxonomy );
	$selected = array_map( 'absint', (array) ( $field['value'] ?? array() ) );
	if ( $allowed && ! array_diff( $allowed, $selected ) ) $field['value'] = array( '__all' );
	return $field;
}
function ve_sms_save_filter_choices( $value, $post_id, array $field ): array {
	return ve_sms_resolve_filter_terms( (array) $value, $field['name'] === 'sms_msg_location' ? 'location' : 'department', get_current_user_id() );
}
foreach ( array( 'sms_msg_location', 'sms_msg_department' ) as $filter_name ) {
	add_filter( 'acf/load_field/name=' . $filter_name, 've_sms_prepare_filter_choices', 40 );
	add_filter( 'acf/prepare_field/name=' . $filter_name, 've_sms_prepare_filter_choices', 30 );
}
// Expand before ACF dispatches type-specific handlers that can save taxonomy terms.
add_filter( 'acf/update_value', static function ( $value, $post_id, $field ) {
	return in_array( $field['name'] ?? '', array( 'sms_msg_location', 'sms_msg_department' ), true ) ? ve_sms_save_filter_choices( $value, $post_id, $field ) : $value;
}, 5, 3 );
function ve_sms_filtered_ids( array $locations, array $departments, int $user_id ): array {
	$locations = ve_sms_resolve_filter_terms( $locations, 'location', $user_id ); $departments = ve_sms_resolve_filter_terms( $departments, 'department', $user_id );
	if ( ! $locations || ! $departments ) return array();
	return array_map( 'absint', get_posts( array( 'post_type' => 'staff', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'tax_query' => array( 'relation' => 'AND', array( 'taxonomy' => 'location', 'field' => 'term_id', 'terms' => $locations ), array( 'taxonomy' => 'department', 'field' => 'term_id', 'terms' => $departments ) ), 'meta_query' => array( array( 'key' => 'office_contact_info_office_sms_status', 'value' => 1 ), array( 'key' => 'office_contact_info_office_cell_phone', 'value' => '', 'compare' => '!=' ) ) ) ) );
}
function ve_sms_effective_ids( int $post_id ): array {
	$user_id = (int) get_post_field( 'post_author', $post_id ); $ids = array_map( 'absint', (array) get_post_meta( $post_id, VE_SMS_RECIPIENTS, true ) );
	if ( ! metadata_exists( 'post', $post_id, VE_SMS_RECIPIENTS ) ) $ids = ve_sms_filtered_ids( (array) get_field( 'sms_msg_location', $post_id ), (array) get_field( 'sms_msg_department', $post_id ), $user_id );
	$groups = metadata_exists( 'post', $post_id, VE_SMS_RECIPIENTS ) ? array() : wp_get_object_terms( $post_id, VE_SMS_GROUP_TAX, array( 'fields' => 'ids' ) ); foreach ( is_wp_error( $groups ) ? array() : $groups as $group ) $ids = array_merge( $ids, (array) get_term_meta( $group, 'recipient_ids', true ) );
	return array_values( array_filter( array_unique( array_map( 'absint', $ids ) ), static fn( int $id ): bool => ve_sms_staff_eligible( $id, $user_id ) ) );
}
function ve_staff_sms_recipient_rows( int $post_id ): array {
	$rows = array(); foreach ( ve_sms_effective_ids( $post_id ) as $id ) { $mobile = ve_sms_number( (string) get_field( 'office_contact_info_office_cell_phone', $id ) ); if ( $mobile ) $rows[] = array( 'ID' => $id, 'post_title' => get_the_title( $id ), 'mobile' => $mobile ); } return $rows;
}
function ve_sms_recipient_select( array $ids, string $name, string $id ): string {
	$options = '';
	foreach ( $ids as $staff_id ) {
		$options .= '<option selected value="' . esc_attr( $staff_id ) . '">' . esc_html( get_the_title( $staff_id ) ) . '</option>';
	}
	return '<select class="ve-sms-recipients" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '[]" multiple style="width:100%">' . $options . '</select>';
}
function ve_sms_recipients_box( WP_Post $post ): void {
	wp_nonce_field( 've_sms_recipients', 've_sms_recipients_nonce' );
	echo '<button type="button" class="button ve-sms-load-recipients" data-recipient-target="#ve-sms-recipients">Set recipients from Filters</button><p>';
	echo ve_sms_recipient_select( ve_sms_effective_ids( $post->ID ), VE_SMS_RECIPIENTS, 've-sms-recipients' );
	echo '</p><p class="description">Load the permitted filters, then remove or search for individual staff.</p>';
	echo '<p><label for="ve-sms-save-recipient-group"><strong>Save as Recipient Group</strong></label><br><input class="widefat" id="ve-sms-save-recipient-group" name="ve_sms_new_recipient_group" type="text" value="" placeholder="Recipient group name"></p>';
}
add_action( 'add_meta_boxes', static function (): void { add_meta_box( 've-sms-recipients-box', 'Recipients', 've_sms_recipients_box', 'staff-sms', 'normal', 'high' ); } );
function ve_sms_save_recipients( int $post_id ): void {
	if ( ! isset( $_POST['ve_sms_recipients_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ve_sms_recipients_nonce'] ) ), 've_sms_recipients' ) || ! current_user_can( 'edit_post', $post_id ) ) return;
	$ids = isset( $_POST[ VE_SMS_RECIPIENTS ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ VE_SMS_RECIPIENTS ] ) ) : array();
	$ids = array_values( array_filter( $ids, static fn( int $id ): bool => ve_sms_staff_eligible( $id, get_current_user_id() ) ) );
	update_post_meta( $post_id, VE_SMS_RECIPIENTS, $ids );
	$group_name = sanitize_text_field( wp_unslash( $_POST['ve_sms_new_recipient_group'] ?? '' ) );
	if ( '' === $group_name || ! $ids ) return;
	$term = term_exists( $group_name, VE_SMS_GROUP_TAX );
	if ( ! $term ) $term = wp_insert_term( $group_name, VE_SMS_GROUP_TAX );
	if ( is_wp_error( $term ) ) return;
	$term_id = (int) ( is_array( $term ) ? $term['term_id'] : $term );
	update_term_meta( $term_id, 'recipient_ids', $ids );
	wp_set_object_terms( $post_id, array( $term_id ), VE_SMS_GROUP_TAX, true );
}
add_action( 'save_post_staff-sms', 've_sms_save_recipients' );
function ve_sms_ajax_load(): void {
	check_ajax_referer( 'ajax-nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	$ids = ve_sms_filtered_ids( (array) ( $_POST['locations'] ?? array() ), (array) ( $_POST['departments'] ?? array() ), get_current_user_id() );
	$ids = array_values( array_filter( $ids, static fn( int $id ): bool => ve_sms_staff_eligible( $id, get_current_user_id() ) ) );
	wp_send_json_success( array_map( static fn( int $id ): array => array( 'id' => $id, 'text' => get_the_title( $id ) ), $ids ) );
}
add_action( 'wp_ajax_ve_sms_load_recipients', 've_sms_ajax_load' );
function ve_sms_ajax_search(): void {
	check_ajax_referer( 'ajax-nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	$permitted = ve_sms_filtered_ids( ve_sms_allowed_terms( get_current_user_id(), 'location' ), ve_sms_allowed_terms( get_current_user_id(), 'department' ), get_current_user_id() );
	$posts = get_posts( array( 'post__in' => $permitted ?: array( 0 ), 'post_type' => 'staff', 'post_status' => 'publish', 'posts_per_page' => 20, 's' => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ) ) );
	$results = array();
	foreach ( $posts as $post ) if ( ve_sms_staff_eligible( (int) $post->ID, get_current_user_id() ) ) $results[] = array( 'id' => $post->ID, 'text' => $post->post_title );
	wp_send_json( array( 'results' => $results ) );
}
add_action( 'wp_ajax_ve_sms_search_recipients', 've_sms_ajax_search' );
function ve_sms_remove_trashed_recipient( int $post_id ): void {
	if ( get_post_type( $post_id ) !== 'staff' ) return;
	$groups = get_terms( array( 'taxonomy' => VE_SMS_GROUP_TAX, 'hide_empty' => false ) );
	foreach ( is_wp_error( $groups ) ? array() : $groups as $group ) update_term_meta( $group->term_id, 'recipient_ids', array_values( array_diff( array_map( 'absint', (array) get_term_meta( $group->term_id, 'recipient_ids', true ) ), array( $post_id ) ) ) );
}
add_action( 'wp_trash_post', 've_sms_remove_trashed_recipient' ); add_action( 'before_delete_post', 've_sms_remove_trashed_recipient' );
function ve_staff_sms_validate_post_access( int $post_id ): bool {
	$user_id = (int) get_post_field( 'post_author', $post_id );
	$allowed = array_map( static fn( int $id ): string => ve_sms_number( (string) get_term_meta( $id, 'sms_number', true ) ), ve_sms_allowed_terms( $user_id, VE_SMS_NUMBER_TAX ) );
	$groups = wp_get_object_terms( $post_id, VE_SMS_GROUP_TAX, array( 'fields' => 'ids' ) );
	foreach ( is_wp_error( $groups ) ? array() : $groups as $group_id ) {
		foreach ( array_map( 'absint', (array) get_term_meta( $group_id, 'recipient_ids', true ) ) as $staff_id ) {
			if ( ! ve_sms_staff_allowed( $staff_id, $user_id ) ) return false;
		}
	}
	return in_array( ve_sms_number( (string) get_field( 'sms_msg_from', $post_id ) ), $allowed, true ) && (bool) ve_sms_effective_ids( $post_id );
}

function ve_sms_twilio_credentials(): array {
	$credentials = apply_filters( 've_staff_sms_twilio_credentials', array(
		'account_sid' => (string) get_option( 'wpsms_gateway_username', '' ),
		'auth_token'  => (string) get_option( 'wpsms_gateway_password', '' ),
	) );
	if ( empty( $credentials['account_sid'] ) || empty( $credentials['auth_token'] ) ) {
		return array();
	}
	return array( 'account_sid' => (string) $credentials['account_sid'], 'auth_token' => (string) $credentials['auth_token'] );
}
function ve_sms_twilio_numbers(): array|WP_Error {
	$cached = get_transient( 've_sms_twilio_numbers' );
	if ( is_array( $cached ) ) return $cached;
	$credentials = ve_sms_twilio_credentials();
	if ( ! $credentials ) return new WP_Error( 've_sms_twilio_credentials', 'Twilio credentials are unavailable. Configure the Twilio gateway in WP SMS before adding a Sending Number.' );
	$url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $credentials['account_sid'] ) . '/IncomingPhoneNumbers.json?PageSize=1000';
	$response = null;
	for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
		$response = wp_remote_get( $url, array( 'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $credentials['account_sid'] . ':' . $credentials['auth_token'] ) ), 'timeout' => 15 ) );
		if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) break;
		error_log( wp_json_encode( array( 'event' => 've_sms_twilio_numbers_retry', 'attempt' => $attempt, 'status' => is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response ), 'error' => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ) ) ) );
	}
	if ( is_wp_error( $response ) ) return new WP_Error( 've_sms_twilio_request', 'Twilio number lookup failed after three attempts: ' . $response->get_error_message() );
	$status = wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	if ( 200 !== $status ) return new WP_Error( 've_sms_twilio_response', 'Twilio number lookup failed after three attempts. HTTP ' . $status . ': ' . $body );
	$data = json_decode( $body, true );
	if ( ! is_array( $data ) || ! isset( $data['incoming_phone_numbers'] ) || ! is_array( $data['incoming_phone_numbers'] ) ) return new WP_Error( 've_sms_twilio_payload', 'Twilio returned an invalid incoming phone numbers response.' );
	$numbers = array();
	foreach ( $data['incoming_phone_numbers'] as $entry ) {
		$number = ve_sms_number( (string) ( $entry['phone_number'] ?? '' ) );
		if ( ! $number || empty( $entry['capabilities']['sms'] ) ) continue;
		$label = sanitize_text_field( (string) ( $entry['friendly_name'] ?? $number ) );
		$numbers[ $number ] = $label . ' (' . $number . ')';
	}
	set_transient( 've_sms_twilio_numbers', $numbers, 5 * MINUTE_IN_SECONDS );
	return $numbers;
}
function ve_sms_number_select( string $selected ): string {
	$numbers = ve_sms_twilio_numbers();
	if ( is_wp_error( $numbers ) ) return '<p class="notice notice-error inline"><strong>Unable to load Twilio numbers.</strong> ' . esc_html( $numbers->get_error_message() ) . '</p>';
	$options = '<option value="">Select a Twilio number</option>';
	foreach ( $numbers as $number => $label ) $options .= '<option value="' . esc_attr( $number ) . '" ' . selected( $selected, $number, false ) . '>' . esc_html( $label ) . '</option>';
	return '<select name="sms_number" id="sms_number" required>' . $options . '</select><p class="description">Only SMS-capable incoming numbers from the connected Twilio account are available.</p>';
}
function ve_sms_group_term_checkboxes( string $taxonomy, array $selected ): string {
	$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'include' => ve_sms_allowed_terms( get_current_user_id(), $taxonomy ) ?: array( 0 ) ) );
	$html = '<fieldset class="ve-sms-group-filter" data-filter="' . esc_attr( $taxonomy ) . '">';
	foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) $html .= '<label style="display:block"><input type="checkbox" name="group_' . esc_attr( $taxonomy ) . '[]" value="' . esc_attr( $term->term_id ) . '" ' . checked( in_array( (int) $term->term_id, $selected, true ), true, false ) . '> ' . esc_html( $term->name ) . '</label>';
	return $html . '</fieldset>';
}
function ve_sms_group_fields( array $ids, array $locations, array $departments, bool $table ): void {
	$fields = array(
		'Locations'   => ve_sms_group_term_checkboxes( 'location', $locations ),
		'Departments' => ve_sms_group_term_checkboxes( 'department', $departments ),
		'Recipients'  => '<button type="button" class="button ve-sms-load-recipients" data-recipient-target="#ve-sms-group-recipients">Replace list with matching staff</button><p>' . ve_sms_recipient_select( $ids, 'recipient_ids', 've-sms-group-recipients' ) . '</p><p class="description">Choose locations and departments, load matching recipients, then remove or search for individual staff.</p>',
	);
	foreach ( $fields as $label => $control ) {
		if ( $table ) echo '<tr class="form-field"><th><label>' . esc_html( $label ) . '</label></th><td>' . $control . '</td></tr>';
		else echo '<div class="form-field"><label>' . esc_html( $label ) . '</label>' . $control . '</div>';
	}
}
function ve_sms_term_permission_fields( string $taxonomy ): void {
	if ( VE_SMS_NUMBER_TAX === $taxonomy ) {
		echo '<div class="form-field"><label for="sms_number">Twilio phone number</label>' . ve_sms_number_select( '' ) . '</div><div class="form-field"><label><input type="checkbox" name="sms_active" value="1" checked> Active</label></div>';
		return;
	}
	ve_sms_group_fields( array(), array(), array(), false );
}
add_action( VE_SMS_NUMBER_TAX . '_add_form_fields', static function (): void { ve_sms_term_permission_fields( VE_SMS_NUMBER_TAX ); } );
add_action( VE_SMS_GROUP_TAX . '_add_form_fields', static function (): void { ve_sms_term_permission_fields( VE_SMS_GROUP_TAX ); } );
function ve_sms_edit_term_fields( WP_Term $term ): void {
	if ( $term->taxonomy === VE_SMS_NUMBER_TAX ) {
		echo '<tr class="form-field"><th><label for="sms_number">Twilio phone number</label></th><td>' . ve_sms_number_select( (string) get_term_meta( $term->term_id, 'sms_number', true ) ) . '<label><input type="checkbox" name="sms_active" value="1" ' . checked( get_term_meta( $term->term_id, 'sms_active', true ), 1, false ) . '> Active</label></td></tr>';
		return;
	}
	ve_sms_group_fields( array_map( 'absint', (array) get_term_meta( $term->term_id, 'recipient_ids', true ) ), array_map( 'absint', (array) get_term_meta( $term->term_id, 'location_ids', true ) ), array_map( 'absint', (array) get_term_meta( $term->term_id, 'department_ids', true ) ), true );
}
add_action( VE_SMS_NUMBER_TAX . '_edit_form_fields', 've_sms_edit_term_fields' );
add_action( VE_SMS_GROUP_TAX . '_edit_form_fields', 've_sms_edit_term_fields' );
function ve_sms_save_term_fields( int $term_id, int $tt_id, string $taxonomy ): void {
	if ( $taxonomy === VE_SMS_NUMBER_TAX ) {
		$number = ve_sms_number( sanitize_text_field( wp_unslash( $_POST['sms_number'] ?? '' ) ) );
		$available = ve_sms_twilio_numbers();
		if ( is_wp_error( $available ) || ! isset( $available[ $number ] ) ) return;
		update_term_meta( $term_id, 'sms_number', $number );
		update_term_meta( $term_id, 'sms_active', isset( $_POST['sms_active'] ) ? 1 : 0 );
		return;
	}
	if ( $taxonomy !== VE_SMS_GROUP_TAX ) return;
	$locations = array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['group_location'] ?? array() ) ) ) );
	$departments = array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['group_department'] ?? array() ) ) ) );
	$manual = array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['recipient_ids'] ?? array() ) ) ) );
	$ids = array_values( array_filter( array_unique( $manual ), static fn( int $id ): bool => ve_sms_staff_eligible( $id, get_current_user_id() ) ) );
	update_term_meta( $term_id, 'location_ids', $locations );
	update_term_meta( $term_id, 'department_ids', $departments );
	update_term_meta( $term_id, 'recipient_ids', $ids );
}
add_action( 'created_term', 've_sms_save_term_fields', 10, 3 );
add_action( 'edited_term', 've_sms_save_term_fields', 10, 3 );

require_once __DIR__ . '/ve-staff-admin-sms-composer.php';
