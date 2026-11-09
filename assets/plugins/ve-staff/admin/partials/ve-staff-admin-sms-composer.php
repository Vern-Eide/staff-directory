<?php
/** Staff SMS composer; preserves the existing ACF storage and send pipeline. */
function ve_sms_staff_eligible( int $id, int $user ): bool {
	return get_post_type( $id ) === 'staff' && get_post_status( $id ) === 'publish'
		&& get_field( 'office_contact_info_office_sms_status', $id )
		&& preg_match( '/^\+[1-9][0-9]{9,14}$/', ve_sms_number( (string) get_field( 'office_contact_info_office_cell_phone', $id ) ) )
		&& ve_sms_staff_allowed( $id, $user );
}

/** Match only permitted, SMS-enabled staff. Never accept arbitrary destinations. */
function ve_sms_match_contacts( string $input, int $user ): array {
	$input = preg_replace( '/^\xEF\xBB\xBF/', '', $input );
	$tokens = array();
	foreach ( preg_split( '/\r\n|\r|\n/', $input ) as $line ) {
		foreach ( str_getcsv( $line, ',', '"', '' ) as $value ) {
			$value = trim( (string) $value );
			if ( $value !== '' && ! in_array( strtolower( $value ), array( 'phone', 'email', 'contact' ), true ) ) $tokens[] = $value;
		}
	}
	if ( count( $tokens ) > 2000 ) return array( 'error' => 'Import up to 2,000 phone numbers or email addresses at a time.' );
	$index = array();
	$ids = ve_sms_filtered_ids( ve_sms_allowed_terms( $user, 'location' ), ve_sms_allowed_terms( $user, 'department' ), $user );
	foreach ( $ids as $id ) {
		if ( ! ve_sms_staff_eligible( $id, $user ) ) continue;
		$keys = array( ve_sms_number( (string) get_field( 'office_contact_info_office_cell_phone', $id ) ), strtolower( trim( (string) get_field( 'office_contact_info_office_email', $id ) ) ) );
		foreach ( array_filter( $keys ) as $key ) $index[ $key ][] = $id;
	}
	$matched = array(); $skipped = 0; $duplicates = 0;
	foreach ( $tokens as $token ) {
		$key = is_email( $token ) ? strtolower( $token ) : ( preg_match( '/^\+?[0-9().\s-]+$/', $token ) ? ve_sms_number( $token ) : '' );
		$matches = array_unique( $index[ $key ] ?? array() );
		// Ambiguous contacts are excluded rather than choosing the wrong employee.
		if ( count( $matches ) !== 1 ) { $skipped++; continue; }
		$id = reset( $matches );
		if ( isset( $matched[ $id ] ) ) { $duplicates++; continue; }
		$matched[ $id ] = array( 'id' => $id, 'text' => get_the_title( $id ) );
	}
	return array( 'recipients' => array_values( $matched ), 'skipped' => $skipped, 'duplicates' => $duplicates );
}
function ve_sms_ajax_import(): void {
	check_ajax_referer( 'ajax-nonce', 'nonce' );
	if ( ! current_user_can( 'edit_posts' ) ) wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
	$input = $_POST['contacts'] ?? '';
	if ( ! is_string( $input ) || strlen( $input ) > 200000 ) wp_send_json_error( array( 'message' => 'Choose a CSV or paste list smaller than 200 KB.' ), 400 );
	$result = ve_sms_match_contacts( wp_unslash( $input ), get_current_user_id() );
	if ( isset( $result['error'] ) ) wp_send_json_error( array( 'message' => $result['error'] ), 400 );
	wp_send_json_success( $result );
}
add_action( 'wp_ajax_ve_sms_import_recipients', 've_sms_ajax_import' );

// Apply to local PHP, JSON and database definitions alike, retaining field keys.
add_filter( 'acf/load_field_group', static function ( $group ) {
	if ( ( $group['key'] ?? '' ) === 'group_63bf0f991067a' ) $group['title'] = 'Compose a staff message';
	return $group;
} );
add_filter( 'acf/load_fields', static function ( $fields, $parent ) {
	if ( ( $parent['key'] ?? '' ) !== 'group_63bf0f991067a' ) return $fields;
	$compose = array(); $recipients = array(); $schedule = array(); $section = 'compose';
	foreach ( $fields as $field ) {
		if ( $field['key'] === 'field_63bf17cbc4e50' ) { $field['label'] = '1. Compose'; $field['endpoint'] = 0; }
		if ( $field['key'] === 'field_63bf17a7c4e4f' ) { $section = 'recipients'; $field['label'] = '2. Recipients'; $field['endpoint'] = 0; }
		if ( $field['name'] === 'scheduled_message' ) { $schedule[] = $field; continue; }
		if ( $field['type'] === 'acfe_column' || in_array( $field['name'], array( 'sms_recipients', 'sms_recipients_number' ), true ) ) continue;
		if ( $field['key'] === 'field_6570b12c69c6c' ) $field['message'] = 'Choose locations and departments, then replace the recipient list with matching staff. You can remove names or add individual staff afterward. Filters do not change the list until you apply them.';
		if ( $section === 'compose' ) $compose[] = $field; else $recipients[] = $field;
	}
	$recipients[] = array( 'key' => 'field_ve_sms_recipient_slot', 'name' => 've_sms_recipient_slot', 'label' => 'Selected recipients', 'type' => 'message', 'message' => '<div id="ve-sms-recipient-slot"></div>', 'esc_html' => 0 );
	$tab = array( 'key' => 'field_ve_sms_delivery_tab', 'name' => '', 'label' => '3. Delivery', 'type' => 'tab', 'placement' => 'top', 'endpoint' => 0 );
	return array_merge( $compose, $recipients, array( $tab ), $schedule );
}, 20, 2 );
add_action( 'admin_enqueue_scripts', static function () {
	$screen = get_current_screen();
	if ( ! $screen || $screen->post_type !== 'staff-sms' ) return;
	wp_enqueue_style( 've-sms-composer', VE_STAFF_PLUGIN_URL . 'admin/css/ve-staff-sms-composer.css', array(), filemtime( __DIR__ . '/../css/ve-staff-sms-composer.css' ) );
} );
// A stale hidden date must never turn “Send Now” into a scheduled message.
add_filter( 'acf/update_value/key=field_63bf16f7ee103', static function ( $value ) {
	if ( ! isset( $_POST['acf']['field_63bf1a8ae121e'] ) ) return $value;
	return empty( $_POST['acf']['field_63bf1a8ae121e']['field_63bf16abee102'] ) ? '' : $value;
} );
add_filter( 'acf/validate_value/key=field_63bf16f7ee103', static function ( $valid, $value ) {
	if ( empty( $_POST['acf']['field_63bf1a8ae121e']['field_63bf16abee102'] ) ) return $valid;
	$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $value, wp_timezone() );
	if ( ! $date || $date->getTimestamp() <= time() ) return 'Choose a future delivery date and time in the site timezone.';
	return $valid;
}, 10, 2 );

add_filter( 'acf/validate_value/key=field_63bf12f1f1157', static function ( $valid ) {
	if ( isset( $_POST['ve_sms_recipients_nonce'] ) && empty( $_POST[ VE_SMS_RECIPIENTS ] ) && ( isset( $_POST['publish'] ) || ( $_POST['post_status'] ?? '' ) === 'future' ) ) return 'Add at least one recipient in step 2 before sending or scheduling.';
	return $valid;
} );
// Explicitly switching an existing scheduled post to Send Now must reset WP's
// future publication date as well as the ACF date. Draft saves remain drafts.
add_filter( 'wp_insert_post_data', static function ( $data ) {
	if ( ( $data['post_type'] ?? '' ) !== 'staff-sms' || ( $data['post_status'] ?? '' ) !== 'future' ) return $data;
	$group = $_POST['acf']['field_63bf1a8ae121e'] ?? null;
	if ( ! is_array( $group ) || ! array_key_exists( 'field_63bf16abee102', $group ) || ! empty( $group['field_63bf16abee102'] ) ) return $data;
	if ( ! isset( $_POST['ve_sms_recipients_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ve_sms_recipients_nonce'] ) ), 've_sms_recipients' ) ) return $data;
	if ( ! current_user_can( 'publish_posts' ) || ( ! isset( $_POST['publish'] ) && ! isset( $_POST['save'] ) ) ) return $data;
	$data['post_status'] = 'publish';
	$data['post_date'] = current_time( 'mysql' );
	$data['post_date_gmt'] = current_time( 'mysql', true );
	return $data;
} );
