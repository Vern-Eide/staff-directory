<?php 
// Staff SMS Class to Extend Staff Admin
class Ve_Staff_SMS {
 	/**
     * @var Ve_Staff_SMS
     */
    public $background_process; // ✅ 

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Legacy WP SMS subscriber synchronization switch.
	 *
	 * @deprecated 2026-04 Legacy subscriber list sync is disabled by default.
	 *             Staff messaging now uses the "staff-sms" custom post type flow.
	 */
	private $legacy_wsms_sync_enabled = false;
	
	public function __construct() {
		/**
		 * Allow temporary re-enable for rollback/troubleshooting.
		 */
		$this->legacy_wsms_sync_enabled = (bool) apply_filters( 've_staff_enable_legacy_wsms_sync', false );
		
		// Staff SMS Ajax Functions
		add_action("wp_ajax_ve_sms_purge", array( $this, 'ajax_purgeSMSSubscriberList' ) );
		add_action("wp_ajax_nopriv_ve_sms_purge", array( $this, 'ajax_purgeSMSSubscriberList_LoggedOut' ) );
		add_action('wp_ajax_get_sms_staff_count', array( $this, 'get_sms_staff_count_ajax'));
		add_action('wp_ajax_nopriv_get_sms_staff_count', array( $this, 'get_sms_staff_count_ajax'));
		
		// Enqueue Scripts & Styles
		add_action( 'init', array( $this, 've_sms_enqueue_scripts' ), 20);
		
		// Instantiate the background sms process
		add_action( 'init', array( $this, 'init_background_process' ) );
		
		// Staff SMS ACF Fields
		add_filter( 'acf/load_field/name=sms_msg_from', [ $this, 'populate_sms_msg_from_field' ]);
		
		// Staff SMS Meta Boxes
		add_action('add_meta_boxes', function() {
			add_meta_box(
				'staff_sms_debug_log',
				__('SMS Debug Log', 've-staff-sms'),
				array($this, 'render_sms_debug_log_box'),
				'staff-sms',
				'normal',
				'default'
			);
		});
		
		// Staff SMS Filters
		add_filter( 'wp_update_term_data', array( $this, 'updatedLocationTermSMSGroup' ), 10, 3 );
		add_filter( 'wp_sms_msg', array( $this, 've_wp_sms_modify_message' ), 20);
		add_filter( 'wp_sms_shorturl', array($this, 'rebrandly_shorten_url') );
		add_filter( 'acf/load_field/name=ve_staff_sms_shorten_domain', array($this, 'acf_options_rebrandly_domains') );

		// Staff SMS Actions
		// @deprecated 2026-04: legacy WP SMS subscriber list sync disabled by default.
		if ( $this->legacy_wsms_sync_enabled ) {
			add_action( 'create_location', array( $this, 'newLocationTermSMSGroup' ), 10, 3 );
			add_action( 'pre_delete_term', array( $this, 'deletedLocationTermSMSGroup' ), 10, 2 ); 
			add_action(	'acf/save_post', array( $this, 'modifySMSSubscriberList' ), 5);
			add_action(	'acf/save_post', array( $this, 'modifyMobileNumber' ), 5);
			add_action( 'publish_staff', array( $this, 've_staff_published_welcome_sms_message' ), 10, 3 );
			add_action( 'wp_trash_post', array( $this,'trashStaffDeleteSMSSubscriber' ) );
		}
		
		// Staff SMS Admin Notices
		add_action('admin_notices', array($this, 'admin_sms_notices'));
		
		// Staff SMS MSG Post Actions
		add_action( 'acf/save_post', array( $this, 'new_sms_msg_post' ), 20);
		add_action( 'acf/save_post', array( $this, 'sms_msg_post_scheduled_check' ), 15, 1 );
		add_action( 'load-edit.php', array( $this, 'update_custom_meta_on_posts_table_load' ) );
		// OLD REMOVED 10/01/2025 add_action( 'future_to_publish', array( $this, 'send_scheduled_sms_msg' ), 10, 3);
		add_action( 'future_to_publish',   array( $this, 'send_scheduled_sms_msg' ), 10, 1 );
		add_action( 'publish_future_post', array( $this, 'send_scheduled_sms_msg_by_id' ), 10, 1 );
		add_action( 'admin_notices', array( $this, 'display_custom_message_on_edit_sms_msg_screen') );
		
		// Create Shortcodes
		add_shortcode('ve_sms_subscriber_by_number', array( $this,'veShortcodeSMSSubscriberByNumber' ) ); 
		add_shortcode('ve_sms_subscriber_by_number_group', array( $this,'veShortcodeSMSSubscriberByNumberGroup' ) ); 
		add_shortcode('ve_active_staff_sms_info_array', array( $this,'veShortcodeActiveStaffSMSInfoArray' ) ); 
		add_shortcode('ve_active_staff_sms_info_table', array( $this,'veShortcodeActiveStaffSMSInfoTable' ) ); 
		add_shortcode('ve_termed_staff_sms_info_array', array( $this,'veShortcodeTermedStaffSMSInfoArray' ) ); 
		add_shortcode('ve_termed_staff_sms_info_table', array( $this,'veShortcodeTermedStaffSMSInfoTable' ) ); 
		add_shortcode('ve_purge_sms_list', array( $this,'purgeSMSSubscriberList' ) ); 
		
	}
	
	public function ve_sms_enqueue_scripts() {
	   wp_register_script( "ve_sms_admin_ajax_script", VE_STAFF_PLUGIN_URL.'admin/js/ve-staff-sms-admin.js', array('jquery'), filemtime( plugin_dir_path(__FILE__) . '../js/ve-staff-sms-admin.js' ) );
	   wp_localize_script('ve_sms_admin_ajax_script', 'veAjax', array(
			'nonce' => wp_create_nonce('ajax-nonce'),
			'ajaxurl' => admin_url('admin-ajax.php'),
	   ));    

	   wp_enqueue_script( 'jquery' );
	   wp_enqueue_script( 've_sms_admin_ajax_script' );

	}
	
	public function init_background_process() {
        // Include the background process file
        require_once(plugin_dir_path(__FILE__) . 've-staff-admin-sms-process.php');

        // Create an instance of the background process class
        $this->background_process = new Ve_Staff_SMS_Background_Process();

    }
	
	/**
     * Populate the sms_msg_from select field with active numbers
     * from the ve_staff_sms_numbers repeater on the options page.
     */
    public function populate_sms_msg_from_field( $field ) {
		if ( function_exists( 've_sms_number_choices' ) ) {
			return ve_sms_number_choices( $field );
		}
        // Reset choices
        $field['choices'] = [];

        // Pull repeater rows from options page
        if ( have_rows( 've_staff_sms_numbers', 'option' ) ) {
            while ( have_rows( 've_staff_sms_numbers', 'option' ) ) {
                the_row();

                $label  = get_sub_field( 'label' );
                $number = get_sub_field( 'number' );
                $active = get_sub_field( 'active' );

                if ( $active && $number ) {
                    // Strip non-digits, prepend +1
                    $sanitized_number = preg_replace( '/\D/', '', $number );
                    $formatted_number = '+1' . $sanitized_number;

                    // Build label: Label (Number)
                    $choice_label = $label . ' (' . $number . ')';

                    // Set as select option
                    $field['choices'][ $formatted_number ] = $choice_label;
                }
            }
        }

        return $field;
    }
	
	// MODIFY SMS MESSAGE DURING SEND THROUGH FILTER
	public function ve_wp_sms_modify_message($message) {
		$post_id = null;

		// Try to extract and strip [PID:###] marker
		if (preg_match('/\[PID:(\d+)\]$/', $message, $matches)) {
			$post_id = (int) $matches[1];
			$message = preg_replace('/\[PID:\d+\]$/', '', $message);

			$this->log_sms_debug($post_id, "Message before processing (with marker stripped): {$message}");
		}

		// Skip if login/2FA code
		$keywords = ['one-time code', '2FA code'];
		foreach ($keywords as $keyword) {
			if (str_contains($message, $keyword)) {
				if ($post_id) {
					$this->log_sms_debug($post_id, "Bypassed modify_message: detected {$keyword} (2FA/login).");
				}
				return $message;
			}
		}

		// Start with global defaults
		$fromNameStatus      = get_field('ve_staff_sms_from_name_status', 'option');
		$fromName            = get_field('ve_staff_sms_default_from_name', 'option');
		$optOutKeyword       = get_field('ve_staff_sms_optout_keyword', 'option');
		$optOutKeywordStatus = true; // assume global keyword is enabled

		if ($post_id) {
			// Override with post_meta if available
			if (metadata_exists('post', $post_id, 'sms_from_name_status')) {
				$fromNameStatus = (bool) get_post_meta($post_id, 'sms_from_name_status', true);
			}
			if (metadata_exists('post', $post_id, 'sms_from_name')) {
				$fromName = get_post_meta($post_id, 'sms_from_name', true);
			}
			if (metadata_exists('post', $post_id, 'sms_optout_keyword_status')) {
				$optOutKeywordStatus = (bool) get_post_meta($post_id, 'sms_optout_keyword_status', true);
			}
			if (metadata_exists('post', $post_id, 'sms_optout_keyword')) {
				$optOutKeyword = get_post_meta($post_id, 'sms_optout_keyword', true);
			}

			$this->log_sms_debug(
				$post_id,
				"Modify message config → fromNameStatus={$fromNameStatus}, fromName='{$fromName}', optOutStatus={$optOutKeywordStatus}, optOutKeyword='{$optOutKeyword}'"
			);
		}

		// Apply From Name
		if ($fromNameStatus && !empty($fromName) && strpos($message, $fromName) !== 0) {
			$message = $fromName . ': ' . $message;
			if ($post_id) {
				$this->log_sms_debug($post_id, "Applied From Name prefix: {$fromName}");
			}
		}

		// Skip unsubscribe confirmations
		if (str_contains($message, 'You have been successfully') &&
			(str_contains($message, 'STOP') || str_contains($message, 'START'))) {
			if ($post_id) {
				$this->log_sms_debug($post_id, "Bypassed opt-out append: unsubscribe confirmation detected.");
			}
			return $message;
		}

		// Apply opt-out
		if ($optOutKeywordStatus && $optOutKeyword !== '') {
			$optOutPhrase = "\nReply STOP {$optOutKeyword} to Cancel";
			if (strpos($message, $optOutKeyword) !== (strlen($message) - strlen($optOutKeyword))) {
				$message .= $optOutPhrase;
				if ($post_id) {
					$this->log_sms_debug($post_id, "Applied opt-out keyword phrase: {$optOutPhrase}");
				}
			}
		} else {
			$optOutPhrase = "\nReply STOP to Cancel";
			if (!str_contains($message, 'Reply STOP to Cancel')) {
				$message .= $optOutPhrase;
				if ($post_id) {
					$this->log_sms_debug($post_id, "Applied default opt-out phrase.");
				}
			}
		}

		if ($post_id) {
			$this->log_sms_debug($post_id, "Final modified message: {$message}");
		}

		return $message;
	}

	
	/**
 	 * Get SMS Group ID by Group Name Lookup
	 */
	public function getSMSGroupID( $groupname ){
		$smsgroups = \WP_SMS\Newsletter::getGroups();
		$smsgroups = wp_filter_object_list( $smsgroups, array( 'name' => $groupname ) );
		foreach ($smsgroups as $smsgroup){
				$smsgroupid = $smsgroup->ID;
		}
		return $smsgroupid;
	}
	
	/**
	 * GET SMS Subscriber by Number
	 */
	public static function getSMSSubscriberNumber( $mobile, $print = false ) {
		global $wpdb;
		
		if ( !str_contains($mobile, '+1') ) {
			$mobile = '+1'.$mobile;
		}
		
		$db_prepare = $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}sms_subscribes` WHERE `mobile` LIKE %s;", $mobile );
		
		if($print == true){
			$result = $wpdb->get_results( $db_prepare, ARRAY_A );
		} else {
			$result = $wpdb->get_results( $db_prepare );
		}
		
		if ( $result ) {
			return $result;
		}
	}
	
	/**
	 * GET SMS Subscriber by Number & Group ID
	 */
	public static function getSMSSubscriberNumberGroup( $mobile, $group_id, $print = false ) {
		global $wpdb;
		
		if ( !str_contains($mobile, '+1') ) {
			$mobile = '+1'.$mobile;
		}
		
		$db_prepare = $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}sms_subscribes` WHERE `mobile` LIKE %s AND `group_ID` = %d;", $mobile, $group_id );
		
		if($print == true){
			$result = $wpdb->get_row( $db_prepare, ARRAY_A );
		} else {
			$result = $wpdb->get_row( $db_prepare );
		}
		
		if ( $result ) {
			return $result;
		}
	}
	
	public function veShortcodeSMSSubscriberByNumber ( $atts ){
		extract(shortcode_atts(array(
		  'mobile' => 0,
	   ), $atts));
		
		if($mobile != 0){
			$print = true;
			$data = $this->getSMSSubscriberNumber( $mobile, $print );
			return ("<pre>".print_r($data,true)."</pre>");
		} else{
			return 'Mobile Number Not Provided';
		}
		
	}
	
	public function veShortcodeSMSSubscriberByNumberGroup ( $atts ){
		extract(shortcode_atts(array(
		  'mobile' => 0,
		  'group_id' => 0,
	   ), $atts));
		
		if($mobile != 0 && $group_id !=0){
			$print = true;
			$data = $this->getSMSSubscriberNumberGroup( $mobile, $group_id, $print );
			return ("<pre>".print_r($data,true)."</pre>");
		} else{
			return 'Mobile Number Not Provided';
		}
		
	}
	
	/**
	 * New Location Term - create SMS Subscriber Group
	 */

	public function newLocationTermSMSGroup( $term_id, $tt_id ){
		$location_name = get_term( $term_id )->name;
			\WP_SMS\Newsletter::addGroup($location_name);
	}
	

	/**
	 * Updated Location Term - update SMS Subscriber Group by Name
	 */
	public function updatedLocationTermSMSGroup( $update_data, $term_id, $taxonomy ) {

		if( 'location' !== $taxonomy ) {
			return $update_data;
		}
		
		// Current Term
		$term = get_term( $term_id, $taxonomy );
		$termname = $term->name;
		

		// Current Location Name from Term
		$location_name = $termname;
		$smsgroupid = $this->getSMSGroupID($location_name);
		
		// Updated Location
		$updated_location_name = $update_data["name"];
		
		if(!empty($smsgroupid)){
			// Update SMS Group Name
			\WP_SMS\Newsletter::updateGroup($smsgroupid, $updated_location_name);
		}else{
			// Add SMS Group Name since missing
			\WP_SMS\Newsletter::addGroup($updated_location_name);
		}
		
		//write_log("Updated Data Debug".var_dump($updated_location_name));
		
		return $update_data;
	}
	

	/**
	 * Deleted Location Term - Delete Subscriber Group
	 */
	public function deletedLocationTermSMSGroup( $term_id, $taxonomy ) {
		if( 'location' == $taxonomy ) {
			// Location Term
			$term = get_term( $term_id, $taxonomy );
			$termname = $term->name;
			
			// Get Location SMS Group ID
			$smsgroupid = $this->getSMSGroupID($termname);
			
			// Delete SMS Group
			\WP_SMS\Newsletter::deleteGroup($smsgroupid);
		}
	}
	


	/**
	 * Staff Profile Modified - Add to SMS Subscriber Lists
	 */
    
	public function modifySMSSubscriberList($post_id)
	{
		
		// Only runs on staff posts
		if ( get_post_type() == 'staff' ) {
		   
			// CURRENT POST VALUES BEFORE UPDATE
		    $current_name = get_field('first_name') . ' ' . get_field('last_name');
			$current_smsstatus = get_field('office_contact_info_office_sms_status');
		    $current_mobilephone = get_field('office_contact_info_office_cell_phone');
		    $current_mobilephone = preg_replace('/\D+/', '', $current_mobilephone);
		    $current_location = get_field('primary_location');
		    $current_location_id = $current_location->term_id;
			$current_location_name = $current_location->name;
			
			// NEW POST VALUES BEING UPDATED
			$new_name = $_POST['acf']['field_6039297eabbcd'] . ' ' . $_POST['acf']['field_603929a6abbce'];
			$new_smsstatus = $_POST['acf']['field_6050e1f967a48']['field_62470a1c96fb4'];
		    $new_mobilephone = $_POST['acf']['field_6050e1f967a48']['field_6050e33067a4c'];
		    $new_mobilephone = preg_replace('/\D+/', '', $new_mobilephone);
		    $new_location_id = $_POST['acf']['field_6047ac10e2653'];
			$new_location_term = get_term( $new_location_id, 'location' );
			$new_location_name = $new_location_term->name;
			
			
			// Delete All Old Numbers if Number Has Changed or changed to opted out.
			if ($current_mobilephone != $new_mobilephone){
				\WP_SMS\Newsletter::deleteSubscriberByNumber('+1'.$current_mobilephone, null);
			} 
			
			/** SMS STATUS CHANGE */
			// If SMS Communication Is Enabled Add to Subscribers otherwise Delete Records
			if ($new_smsstatus == "1"){				
				// Delete Subscriber from Old Location Subscriber Group if changed
				if ($current_location_id != $new_location_id){
					if (isset($current_mobilephone)) {
						$current_location_group_id = $this->getSMSGroupID($current_location_name);
					   // Delete only the subscriber from the list with the old location id
						\WP_SMS\Newsletter::deleteSubscriberByNumber('+1'.$current_mobilephone, $current_location_group_id);
					}
				}

				// Add Subscriber to Primary Location Subscriber Group
				if ($new_name and $new_mobilephone) {
					$new_location_group_id = $this->getSMSGroupID($new_location_name);
					// Function Parms (name, mobile, group_id, status, key)
					\WP_SMS\Newsletter::addSubscriber($new_name, '+1'.$new_mobilephone, $new_location_group_id, '1');
				}
				
			} else {
				// Delete subscriptions by mobile number since status has changed.
				if ($current_mobilephone == $new_mobilephone){
					\WP_SMS\Newsletter::deleteSubscriberByNumber('+1'.$current_mobilephone, null);
				}
			}
			
			/** END SMS STATUS **/
			
		}
	}
	
	public function modifyMobileNumber($post_id)
	{
		
		// Only runs on staff posts
		if ( get_post_type() == 'staff' ) {
			
			// CURRENT POST VALUES BEFORE UPDATE
		    $current_name = get_field('first_name') . ' ' . get_field('last_name');
			$current_smsstatus = get_field('office_contact_info_office_sms_status');
		    $current_mobilephone = get_field('office_contact_info_office_cell_phone');
		    $current_mobilephone = preg_replace('/\D+/', '', $current_mobilephone);
		    $current_location = get_field('primary_location');
		    $current_location_id = $current_location->term_id;
			$current_location_name = $current_location->name;
			
			// NEW POST VALUES BEING UPDATED
			$new_name = $_POST['acf']['field_6039297eabbcd'] . ' ' . $_POST['acf']['field_603929a6abbce'];
			$new_smsstatus = $_POST['acf']['field_6050e1f967a48']['field_62470a1c96fb4'];
		    $new_mobilephone = $_POST['acf']['field_6050e1f967a48']['field_6050e33067a4c'];
		    $new_mobilephone = preg_replace('/\D+/', '', $new_mobilephone);
		    $new_location_id = $_POST['acf']['field_6047ac10e2653'];
			$new_location_term = get_term( $new_location_id, 'location' );
			$new_location_name = $new_location_term->name;
			
			/** WELCOME MESSAGE **/
			// If the mobile number was just added or welcome message was not sent previously
			$currentmobilevalid = $this->validPhoneNumber($current_mobilephone);
			$newmobilevalid = $this->validPhoneNumber($new_mobilephone);
			if(!metadata_exists('post', $post_id, 'staff_welcome_sms_sent') || get_post_meta($post_id, 'staff_welcome_sms_sent', true) == 0){
				$welcomemsgsent == false;
			} else {
				$welcomemsgsent == true;
			}
			if(!$welcomemsgsent){
				if (
					(!$currentmobilevalid && $newmobilevalid) &&
					(!metadata_exists('post', $post_id, 'staff_welcome_sms_sent') || get_post_meta($post_id, 'staff_welcome_sms_sent', true) == 0)
				) {
					update_post_meta($post_id, 'staff_welcome_sms_sent', 1);
					$this->ve_staff_welcome_sms_message($post_id, $post, $new_mobilephone);
				} elseif ($current_mobilephone != $new_mobilephone && $currentmobilevalid && $newmobilevalid) {
					// Changes the Welcome SMS Sent to false so it will resend.
					update_post_meta($post_id, 'staff_welcome_sms_sent', 1);
					$this->ve_staff_welcome_sms_message($post_id, $post, $new_mobilephone);
				} elseif ($current_mobilephone = $new_mobilephone && $currentmobilevalid && $newmobilevalid) {
					// If the mobile was set but a welcome was never sent, it will send it.
					update_post_meta($post_id, 'staff_welcome_sms_sent', 1);
					$this->ve_staff_welcome_sms_message($post_id, $post, $current_mobilephone);
				} elseif ($current_mobilephone && $currentmobilevalid && !$newmobilevalid){
					// If the mobile was set but a welcome was never sent, it will send it.
					update_post_meta($post_id, 'staff_welcome_sms_sent', 1);
					$this->ve_staff_welcome_sms_message($post_id, $post, $current_mobilephone);
				}
				/** END WELCOME **/
			}
		}
	}

	/*
	 * Staff Profile Trashed - Delete SMS Subscriber
	 */
	
	public function trashStaffDeleteSMSSubscriber( $post_id ){
		// Only runs on staff posts
		if ( get_post_type() == 'staff' ) {
			$mobilephone = get_field('office_contact_info_office_cell_phone');
			if(isset($mobilephone)){
				// Delete all records of subscribers number
				$mobilephone = preg_replace('/\D+/', '', $mobilephone);
				\WP_SMS\Newsletter::deleteSubscriberByNumber('+1'.$mobilephone, null);
			}
			update_post_meta( $post_id, 'staff_welcome_sms_sent', 0, 1 );
		}
	}
	
	public function getActiveStaffSMSInfo(){
		
		$staff = array();
		
		// Post Args
		$args = array(  
			'post_type' => 'staff',
			'post_status' => 'publish',
			'posts_per_page' => -1, 
			'orderby' => 'ID', 
			'order' => 'ASC',
			'meta_query' => [
				'relation' => 'AND',
				[
				  'key' => 'office_contact_info_office_cell_phone',
				  'compare' => 'EXISTS',
				],
				[
				  'key' => 'office_contact_info_office_cell_phone',
				  'compare' => '!=',
				  'value' => ''
				]
      		]
		);

		// Post Query
		$staff_query = new WP_Query( $args );

		if ( $staff_query->have_posts() ) :

			// Loop
			while ( $staff_query->have_posts() ) : $staff_query->the_post();
				// Staff Info
				$staff_name = get_field('first_name') . ' ' . get_field('last_name');
		
				// Cell Info
				$cell_number = 	get_field('office_contact_info_office_cell_phone');
				$cell_number = preg_replace('/\D+/', '', $cell_number);
		
				// Location Info
				$primary_location = get_field('primary_location');
		    	$primary_location_id = $primary_location->term_id;
				$primary_location_name = $primary_location->name;
		
				// Add to Staff Array
				$staff[]=array(
						'id' => get_the_ID(),
						'name' => $staff_name,
						'sms_status' => get_field('office_contact_info_office_sms_status'),
						'cell_number' => $cell_number,
						'location_id' => $primary_location_id,
						'location_name' => $primary_location_name,
						);
			endwhile;
			// End of the loop

			wp_reset_postdata();

		else :
		
				return 'No Results';

		endif;
		
		return $staff;
	}
	
	public function veShortcodeActiveStaffSMSInfoArray(){
		$activeStaff = $this->getActiveStaffSMSInfo();
		return ("<pre>".print_r($activeStaff,true)."</pre>");
	}
	
	public function veShortcodeActiveStaffSMSInfoTable(){
		$activeStaff = $this->getActiveStaffSMSInfo();
		
		$tableData = '<style>
			#veActiveSMS {
			  font-family: Arial, Helvetica, sans-serif;
			  border-collapse: collapse;
			  width: 100%;
			}

			#veActiveSMS td, #veActiveSMS th {
			  border: 1px solid #ddd;
			  padding: 8px;
			}

			#veActiveSMS tr:nth-child(even){background-color: #f2f2f2;}

			#veActiveSMS tr:hover {background-color: #ddd;}

			#veActiveSMS th {
			  padding-top: 12px;
			  padding-bottom: 12px;
			  text-align: left;
			  background-color: #345ca6;
			  color: white;
			}
			</style>';
		
		$tableData .= '<table id="veActiveSMS" style="width:100%">
			<tr>
				<th style="width:10%">ID</th>
				<th style="width:20%">Name</th>
				<th style="width:10%">SMS Status</th>
				<th style="width:20%">Number</th>
				<th style="width:10%">Location ID</th>
				<th style="width:30%">Location Name</th>
			</tr>';

		foreach ($activeStaff as $staff):
		$tableData .= '<tr>
					 <td>' . $staff['id'] . '</td>
					 <td>' . $staff['name'] . '</td>
					 <td>' . $staff['sms_status'] . '</td>
					 <td>' . $staff['cell_number'] . '</td>
					 <td>' . $staff['location_id'] . '</td>
					 <td>' . $staff['location_name'] . '</td>
				</tr>';
		endforeach;

		$tableData .= '</table>';
		
		return $tableData;
	}
	
	public function getTermedStaffSMSInfo(){
		
		$staff = array();
		
		// Post Args
		$args = array(  
			'post_type' => 'staff',
			'post_status' => 'trash',
			'posts_per_page' => -1, 
			'orderby' => 'ID', 
			'order' => 'ASC',
			'meta_query' => [
				'relation' => 'AND',
				[
				  'key' => 'office_contact_info_office_cell_phone',
				  'compare' => 'EXISTS',
				],
				[
				  'key' => 'office_contact_info_office_cell_phone',
				  'compare' => '!=',
				  'value' => ''
				]
      		]
		);

		// Post Query
		$staff_query = new WP_Query( $args );

		if ( $staff_query->have_posts() ) :

			// Loop
			while ( $staff_query->have_posts() ) : $staff_query->the_post();
				// Staff Info
				$staff_name = get_field('first_name') . ' ' . get_field('last_name');
		
				// Cell Info
				$cell_number = 	get_field('office_contact_info_office_cell_phone');
				$cell_number = preg_replace('/\D+/', '', $cell_number);
		
				// Location Info
				$primary_location = get_field('primary_location');
		    	$primary_location_id = $primary_location->term_id;
				$primary_location_name = $primary_location->name;
		
				// Add to Staff Array
				$staff[]=array(
						'id' => get_the_ID(),
						'name' => $staff_name,
						'sms_status' => get_field('office_contact_info_office_sms_status'),
						'cell_number' => $cell_number,
						'location_id' => $primary_location_id,
						'location_name' => $primary_location_name,
						);
			endwhile;
			// End of the loop

			wp_reset_postdata();

		else :
		
				return 'No Results';

		endif;
		
		return $staff;
	}
	
	public function veShortcodeTermedStaffSMSInfoArray(){
		$termedStaff = $this->getTermedStaffSMSInfo();
		return ("<pre>".print_r($termedStaff,true)."</pre>");
	}
	
	public function veShortcodeTermedStaffSMSInfoTable(){
		$termedStaff = $this->getTermedStaffSMSInfo();
		
		$tableData = '<style>
			#veTermedSMS {
			  font-family: Arial, Helvetica, sans-serif;
			  border-collapse: collapse;
			  width: 100%;
			}

			#veTermedSMS td, #veTermedSMS th {
			  border: 1px solid #ddd;
			  padding: 8px;
			}

			#veTermedSMS tr:nth-child(even){background-color: #f2f2f2;}

			#veTermedSMS tr:hover {background-color: #ddd;}

			#veTermedSMS th {
			  padding-top: 12px;
			  padding-bottom: 12px;
			  text-align: left;
			  background-color: #345ca6;
			  color: white;
			}
			</style>';
		
		$tableData .= '<table id="veTermedSMS" style="width:100%">
			<tr>
				<th style="width:10%">Status</th>
				<th style="width:10%">ID</th>
				<th style="width:20%">Name</th>
				<th style="width:20%">Number</th>
				<th style="width:40%">Location Name</th>
			</tr>';

		foreach ($termedStaff as $staff):
		$tableData .= '<tr>
					 <td>Termed</td>
					 <td>' . $staff['id'] . '</td>
					 <td>' . $staff['name'] . '</td>
					 <td>' . $staff['cell_number'] . '</td>
					 <td>' . $staff['location_name'] . '</td>
				</tr>';
		endforeach;

		$tableData .= '</table>';
		
		return $tableData;
	}
	
	public function purgeSMSSubscriberList(){
		
		$activeStaff = $this->getActiveStaffSMSInfo();
		$subscribersAdded = 0;
		$subscribersDeleted = 0;
		
		foreach ($activeStaff as $staff):
			// Checks SMS Status (Either 1 is Enabled or 0 Disabled)
			if ( $staff['sms_status'] == 1 ) {

				$name = $staff['name'];
				$smsstatus = $staff['sms_status'];
				$mobile = $staff['cell_number'];
				if ( !str_contains($mobile, '+1') ) {
					$mobile = '+1'.$mobile;
				}
				$location = get_field('primary_location');
				$location_id = $staff['location_id'];
				$location_name = $staff['location_name'];
				
				// Get Primary SMS Group by Primary Location
				$primary_group_id = $this->getSMSGroupID($location_name);
				
				// Check if the subscriber is subsribed to their primary location
				$primary_subscripton = $this->getSMSSubscriberNumberGroup($mobile, $primary_group_id);
				if(!$primary_subscripton){
					// Add subscriber to primary location group (name, mobile, group_id, status, key)
					\WP_SMS\Newsletter::addSubscriber($name, $mobile, $primary_group_id, '1');
					$subscribersAdded++;
				}
				
				// Get Subscriber Info by Number
				$subscriber_subscriptions = $this->getSMSSubscriberNumber($mobile);
				
				if($subscriber_subscriptions){
					foreach ( $subscriber_subscriptions as $subscription ) {
						
					}
				}
 
			} elseif ($staff['sms_status'] == 0){
				// Delete the Subscriber from subscriptions
				\WP_SMS\Newsletter::deleteSubscriberByNumber($mobile);
				$subscribersDeleted++;
			}
		endforeach;
		
		$results = 'Subscribers Added: '.$subscribersAdded.' | Subscribers Deleted: '.$subscribersDeleted;
		return $results;
		
	}
	
	public function ajax_purgeSMSSubscriberList(){
		if ( isset($_REQUEST) ) {
			// Start Time
			$start_time = microtime(true);
			
			// Get SMS Purge Function
			$purge_results = $this->purgeSMSSubscriberList();
			
			// End Time
			$end_time = microtime(true);
			$execution_time = ($end_time - $start_time);
			$execution_time = round($execution_time, 2);
			$execution_time_line = '<br><i> Execution Time: ('.$execution_time.' secs)</i>';
			
			$purge_results .= $execution_time_line;
			
			// Ajax Response
			if(empty($purge_results)) {
				  $result['type'] = "error";
				  $result['purge_data'] = $purge_results;
			} else {
				  $result['type'] = "success";
				  $result['purge_data'] = $purge_results;
			}

			$result = json_encode($result);
			echo $result;

			die();
		}
	}
	
	public function ajax_purgeSMSSubscriberList_LoggedOut(){
		echo "You must be logged in to purge list.";
   		die();
	}
	
	public function ve_staff_published_welcome_sms_message($post_id, $post){
		if (get_post_meta($post_id, '_previous_status', true) !== 'publish') {
        	$this->ve_staff_welcome_sms_message($post_id, $post);
    	}
		// Update the previous status to the current one for future checks
    	update_post_meta($post_id, '_previous_status', $post->post_status);
	}
	
	// SMS WELCOME MESSAGE
	public function ve_staff_welcome_sms_message($post_id, $post, $mobile = null) {
		
		// If this is a revision, don't send the sms.
		if ( wp_is_post_revision( $post_id ) && get_post_meta( $post_id, 'staff_welcome_sms_sent', true ) == 1 )
			return;
		
		// Sends out welcome message to new staff on creation if welcome status enabled.
		if ( $post->post_type == 'staff' && $post->post_status == 'publish' && get_field('ve_staff_sms_welcome_message_status', 'option') && (!metadata_exists('post', $post_id, 'staff_welcome_sms_sent') || get_post_meta( $post_id, 'staff_welcome_sms_sent', true ) == 0 ) ) {
			$smsstatus = get_field('office_contact_info_office_sms_status');
			if($smsstatus == 1){
				if( is_null($mobile) ){
					$mobile = get_field('office_contact_info_office_cell_phone');
					if ( !str_contains($mobile, '+1') ) {
						$mobile = '+1'.$mobile;
					}
				}
				if($this->validPhoneNumber($mobile)){
					$firstName = get_field('first_name');
					$company = get_field('ve_staff_sms_company_name', 'option');

					if( get_the_date("Y-m-d h:i:s") < date("Y-m-d h:i:s", strtotime("today")) ){
						$defaultMsg = get_field('ve_staff_sms_new_sub_welcome_msg', 'option');
					} else {
						$defaultMsg = get_field('ve_staff_sms_default_welcome_msg', 'option');
					}

					if(isset($defaultMsg)){
						$message = str_replace(array('{{First_Name}}', '{{Company_Name}}'), array($firstName, $company), $defaultMsg);
					}

					$sms_to = array($mobile);
					$sms_msg = $message;
					$welcomeImg = get_field('ve_staff_sms_welcome_image', 'option');

					if(isset($welcomeImg)){
						$sms_urls = [$welcomeImg];
						wp_sms_send( $sms_to, $sms_msg, false, null, $sms_urls);
					} else {
						wp_sms_send( $sms_to, $sms_msg);
					}

					// Set Custom Post Meta that Welcome Msg was sent
					$dateTime = current_time('m/d/Y h:iA');
					if( get_post_meta( $post_id, 'staff_welcome_sms_sent', true ) == 0 ){
						update_post_meta( $post_id, 'staff_welcome_sms_sent', 1 );
						update_post_meta( $post_id, 'staff_welcome_sms_sent_datetime', $dateTime );
					} else {
						add_metadata( 'post', $post_id, 'staff_welcome_sms_sent', 1, true );
						add_metadata( 'post', $post_id, 'staff_welcome_sms_sent_datetime', $dateTime, true );
					}
				} else {
					// Invalid phone number, set fields to indicate SMS was not sent
					update_post_meta( $post_id, 'staff_welcome_sms_sent', 0 );
					delete_post_meta( $post_id, 'staff_welcome_sms_sent_datetime' );
				}	
			}
		}
	}
	
	public function validPhoneNumber($number) {
		// Remove all non-numeric characters from the phone number
		$numericPhoneNumber = preg_replace('/\D/', '', $number);

		// Remove leading '1' if it exists (for North American numbering plan)
		if (substr($numericPhoneNumber, 0, 1) == '1') {
			$numericPhoneNumber = substr($numericPhoneNumber, 1);
		}

		// Check if the length is exactly 10 digits
		return strlen($numericPhoneNumber) == 10;
	}
	
	
	//////////////////////////////////////////////////////
	// FUNCTIONS WHEN A NEW SMS MESSAGE POST IS CREATED //
	//////////////////////////////////////////////////////
	public function sms_msg_post_scheduled_check( $post_id ) {
		$post_status = get_post_status( $post_id );

		// Check if it's an autosave, post revision, or if the post is saved as auto-draft or in the trash
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || wp_is_post_revision( $post_id ) || in_array( $post_status, array( 'auto-draft', 'trash' ) ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );

		// Check if the post type is 'staff-sms'
		if ( $post_type == 'staff-sms' ) {
			$scheduled_date = get_field( 'scheduled_message_sms_msg_scheduled_date', $post_id );

			// Check if the scheduled date is not empty
			if ( ! empty( $scheduled_date ) && in_array( $post_status, array( 'publish', 'future' ), true ) ) {
				$scheduled_timestamp = strtotime( $scheduled_date );
				$current_timestamp   = current_time( 'timestamp' );
				$time_diff           = $scheduled_timestamp - $current_timestamp;
				$time_in_future      = $time_diff > 0;

				// Check if the scheduled date is in the future and the post status is not already 'future'
				if ( $time_in_future && ( get_post_status( $post_id ) !== 'future' || get_post_field( 'post_date', $post_id ) !== date( 'Y-m-d H:i:s', $scheduled_timestamp ) ) ) {
					// Set the post status to 'future' and schedule the post
					wp_update_post( array(
						'ID'            => $post_id,
						'post_status'   => 'future',
						'post_date'     => date( 'Y-m-d H:i:s', $scheduled_timestamp ),
						'post_date_gmt' => get_gmt_from_date( date( 'Y-m-d H:i:s', $scheduled_timestamp ) ),
					) );
				}
			}

			// Add custom field logic when the post is saved as a draft
			if ( $post_status === 'draft' ) {
				// Update the custom field 'staff_sms_msg_sent' to 'false'
				update_post_meta( $post_id, 'staff_sms_msg_sent', '' );

				// Clear the value for 'staff_sms_msg_sent_datetime'
				update_post_meta( $post_id, 'staff_sms_msg_sent_datetime', '' );
			}
		}
	}
	
	// NEW STAFF SMS POST ADDED
	public function new_sms_msg_post($post_id){
		$post_type   = get_post_type($post_id);
		$post_status = get_post_status($post_id);

		// Bail on autosave or revision
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}
		if (wp_is_post_revision($post_id)) {
			return;
		}

		if ($post_type === 'staff-sms') {
			if ( $post_status === 'publish' && function_exists( 've_staff_sms_validate_post_access' ) && ! ve_staff_sms_validate_post_access( (int) $post_id ) ) {
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
				update_post_meta( $post_id, 'staff_sms_permission_error', 'The message was not sent because its number or recipients are outside the author permissions.' );
				return;
			}
			// Ensure the sent meta exists
			if (!metadata_exists('post', $post_id, 'staff_sms_msg_sent')) {
				update_post_meta($post_id, 'staff_sms_msg_sent', 0);
			}
			if (!metadata_exists('post', $post_id, 'staff_sms_msg_sent_datetime')) {
				update_post_meta($post_id, 'staff_sms_msg_sent_datetime', '');
			}
			
			// --- Shorten URLs in the message text before queuing/sending ---
			$sms_msg = (string) get_field('sms_msg_text', $post_id);

			// Find all URLs
			preg_match_all('~https?://[^\s]+~', $sms_msg, $matches);
			$urls = $matches[0] ?? [];

			if (!empty($urls)) {
				foreach ($urls as $url) {
					$short_url = $this->rebrandly_shorten_url($url);

					// Only replace if the returned value is different
					if ($short_url && $short_url !== $url) {
						$this->log_sms_debug($post_id, "Shortened URL: {$url} → {$short_url}");
						$sms_msg = str_replace($url, $short_url, $sms_msg);
					} else {
						$this->log_sms_debug($post_id, "URL left unchanged: {$url}");
					}
				}

				// Update ACF field with modified message text
				update_field('sms_msg_text', $sms_msg, $post_id);
			}

			// --- Save From Name + Opt-Out settings into post_meta ---
			$sms_from             = get_field('sms_msg_from', $post_id);
			$fromNameStatus       = 0;
			$fromName             = '';
			$optOutKeywordStatus  = 0;
			$optOutKeyword        = '';

			if (!empty($sms_from)) {
				$normalize_number = function($num) {
					return preg_replace('/\D+/', '', (string) $num);
				};
				$sms_from_normalized = $normalize_number($sms_from);

				$rows = get_field('ve_staff_sms_numbers', 'option');
				if ($rows && is_array($rows)) {
					foreach ($rows as $row) {
						if (!empty($row['number'])) {
							$row_number_normalized = $normalize_number($row['number']);
							if ($row_number_normalized === $sms_from_normalized) {
								$fromNameStatus      = isset($row['from_name_status']) ? (int) $row['from_name_status'] : 0;
								$fromName            = isset($row['from_name']) ? trim($row['from_name']) : '';
								$optOutKeywordStatus = isset($row['optout_keyword_status']) ? (int) $row['optout_keyword_status'] : 0;
								$optOutKeyword       = isset($row['optout_keyword']) ? trim($row['optout_keyword']) : '';
								break;
							}
						}
					}
				}
			}

			update_post_meta($post_id, 'sms_from_name_status', $fromNameStatus);
			update_post_meta($post_id, 'sms_from_name', $fromName);
			update_post_meta($post_id, 'sms_optout_keyword_status', $optOutKeywordStatus);
			update_post_meta($post_id, 'sms_optout_keyword', $optOutKeyword);

			// --- Handle scheduled vs immediate ---
			$scheduled_date = get_field('scheduled_message_sms_msg_scheduled_date', $post_id);
			if (in_array($post_status, array('publish', 'future'), true) && !empty($scheduled_date) && strtotime($scheduled_date) > current_time('timestamp')) {
				// Mark as scheduled
				update_post_meta($post_id, 'staff_sms_msg_scheduled', 1);
				update_post_meta($post_id, 'staff_sms_msg_scheduled_datetime', $scheduled_date);
				update_post_meta($post_id, 'staff_sms_msg_queued', 0);
				update_post_meta($post_id, 'staff_sms_msg_sent', 0);
				return; // don't send now
			}

			// Only act when published & not already sent
			if ($post_status === 'publish' && (int) get_post_meta($post_id, 'staff_sms_msg_sent', true) === 0) {
				// Mark as queued
				update_post_meta($post_id, 'staff_sms_msg_queued', 1);
				update_post_meta($post_id, 'staff_sms_msg_scheduled', 0);
				update_post_meta($post_id, 'staff_sms_msg_sent', 0);

				// Queue background process
				$this->background_process = new Ve_Staff_SMS_Background_Process();
				$this->background_process->push_to_queue(array(
					'post_id'     => $post_id,
					'staff_array' => $this->sms_msg_get_staff_contact_info($post_id),
				));

				$this->background_process->save()->dispatch();
			}
		}
	}

	/////////////////////////////////
	// SCHEDULED MESSAGE FUNCTIONS //
	/////////////////////////////////
	
	public function send_scheduled_sms_msg($post) {
		if (!$post || $post->post_type !== 'staff-sms') { return; }

		$post_id = (int) $post->ID;
		$this->log_sms_debug($post_id, "schedule trigger fired (future_to_publish) for post {$post_id}");

		// Prevent double send
		$already = (int) get_post_meta($post_id, 'staff_sms_msg_sent', true);
		$this->log_sms_debug($post_id, "already_sent flag = {$already}");
		if ($already === 1) {
			$this->log_sms_debug($post_id, "skipped because already sent");
			return;
		}

		// Build recipients
		$staff_array = $this->sms_msg_get_staff_contact_info($post_id);
		$count = is_array($staff_array) ? count($staff_array) : 0;
		$this->log_sms_debug($post_id, "recipients count = {$count}");

		if ($count === 0) {
			update_post_meta($post_id, 'staff_sms_msg_sent', 0);
			update_post_meta($post_id, 'staff_sms_msg_sent_datetime', date('Y-m-d H:i A', current_time('timestamp', 0)));
			$this->log_sms_debug($post_id, "aborted because no recipients found");
			return;
		}

		// Send immediately
		$this->log_sms_debug($post_id, "calling send_sms_msg_post()");
		$this->send_sms_msg_post($post_id, $staff_array);

		// Update flags
		update_post_meta($post_id, 'staff_sms_msg_scheduled', 0);
		update_post_meta($post_id, 'staff_sms_msg_queued', 0);
		update_post_meta($post_id, 'staff_sms_msg_sent', 1);
		update_post_meta($post_id, 'staff_sms_msg_sent_datetime', date('Y-m-d H:i A', current_time('timestamp', 0)));
		$this->log_sms_debug($post_id, "completed send, flags updated to sent=1");
	}

	public function send_scheduled_sms_msg_by_id($post_id) {
		$post = get_post($post_id);
		if ($post && $post->post_type === 'staff-sms') {
			$this->log_sms_debug($post_id, "schedule trigger fired (publish_future_post)");
			$this->send_scheduled_sms_msg($post);
		}
	}
	
	public function sms_msg_get_staff_contact_info($post_id) {
			if ( function_exists( 've_staff_sms_recipient_rows' ) ) {
				return ve_staff_sms_recipient_rows( (int) $post_id );
			}
			$locations = get_field('sms_msg_location', $post_id); 
			if ($locations) {
			  if (!is_array($locations)) {
				$locations = array($locations);
			  }
			}
	
			$departments = get_field('sms_msg_department', $post_id);
			if ($departments) {
			  if (!is_array($departments)) {
				$departments = array($departments);
			  }
			}

			$args = array(
				'post_status' => 'publish',
				'post_type' => 'staff',
				'posts_per_page' => -1,
				'tax_query' => array(
					'relation' => 'AND',
					array(
						'taxonomy' => 'location',
						'field' => 'term_id',
						'terms' => $locations,
						'operator' => 'IN'
					),
					array(
						'taxonomy' => 'department',
						'field' => 'term_id',
						'terms' => $departments,
						'operator' => 'IN'
					),
				),
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key' => 'office_contact_info_office_sms_status',
						'value' => 1,
						'compare' => '=',
					),
					array(
						'key' => 'office_contact_info_office_cell_phone',
						'compare' => 'EXISTS',
					),
					array(
						'key' => 'office_contact_info_office_cell_phone',
						'value' => '',
						'compare' => '!=',
					),
				)
			);
			$staff = new WP_Query($args);

			$staff_array = array();

			if ( $staff->have_posts() ) {
				while ( $staff->have_posts() ){ 
					$staff->the_post();
					$mobile = get_field('office_contact_info_office_cell_phone');
					if ( !str_contains($mobile, '+1') ) {
						$mobile = '+1'.$mobile;
					}
					$staff_array[] = array(
							'ID' 			=> $staff->post->ID,
							'post_title' 	=> $staff->post->post_title,
							'mobile' 		=> $mobile,
					);
				}
			}
		
			// Return the Staff Contact Info Array
			return $staff_array;
	}
	
	public function get_staff_data_by_location_department($locations, $departments) {
		$args = array(
			'post_status' => 'publish',
			'post_type' => 'staff',
			'posts_per_page' => -1,
			'tax_query' => array(
				'relation' => 'AND',
				array(
					'taxonomy' => 'location',
					'field' => 'term_id',
					'terms' => $locations,
					'operator' => 'IN'
				),
				array(
					'taxonomy' => 'department',
					'field' => 'term_id',
					'terms' => $departments,
					'operator' => 'IN'
				),
			),
			'meta_query' => array(
				'relation' => 'AND',
				array(
					'key' => 'office_contact_info_office_sms_status',
					'value' => 1,
					'compare' => '=',
				),
				array(
					'key' => 'office_contact_info_office_cell_phone',
					'compare' => 'EXISTS',
				),
				array(
					'key' => 'office_contact_info_office_cell_phone',
					'value' => '',
					'compare' => '!=',
				),
			)
		);

		$staff_query = new WP_Query($args);

		$staff_data = array();

		if ($staff_query->have_posts()) {
			while ($staff_query->have_posts()) {
				$staff_query->the_post();
				$mobile = get_field('office_contact_info_office_cell_phone');
				if (!str_contains($mobile, '+1')) {
					$mobile = '+1' . $mobile;
				}
				$staff_data[] = array(
					'ID' => get_the_ID(),
					'post_title' => get_the_title(),
					'mobile' => $mobile,
				);
			}
		}

		// Reset post data
		wp_reset_postdata();

		// Return the staff data
		return $staff_data;
	}

	
	public function get_sms_staff_count_ajax() {
		// Verify nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ajax-nonce')) {
			die('Permission check failed');
		}

		// Get locations and departments from the AJAX request
		$locations = $_POST['locations'];
		$departments = $_POST['departments'];

		// Call the new function to get staff data
		$staff_data = $this->get_staff_data_by_location_department($locations, $departments);

		// Process and sort the post titles
		$post_titles = wp_list_pluck($staff_data, 'post_title');
		$processed_titles = array_map(function ($title) {
			// Remove everything after the "|"
			$parts = explode('|', $title);
			return trim($parts[0]); // Get the first part and trim any extra whitespace
		}, $post_titles);

		// Sort the processed titles alphabetically
		sort($processed_titles);

		// Prepare the response object
		$response = new stdClass();
		$response->count = count($processed_titles);
		$response->staffTitles = $processed_titles;

		// Return the response as JSON
		echo json_encode($response);

		wp_die();
	}


	// SEND SMS MESSAGE FROM POST
	public function send_sms_msg_post($post_id, $staff_array){
		if ( function_exists( 've_staff_sms_validate_post_access' ) && ! ve_staff_sms_validate_post_access( (int) $post_id ) ) {
			$this->log_sms_debug( $post_id, 'Send denied: the sending number or recipient group is outside the author permissions.' );
			update_post_meta( $post_id, 'staff_sms_permission_error', 'Send denied because permissions changed or the recipient group contains inaccessible staff.' );
			return;
		}
		$sms_from              = trim((string) get_field('sms_msg_from', $post_id));
		$sms_msg               = (string) get_field('sms_msg_text', $post_id);
		$sms_img               = get_field('sms_msg_image', $post_id);
		$sms_recipients_number = get_field('sms_recipients_number', $post_id); // ACF may return string

		$this->log_sms_debug($post_id, "Starting send_sms_msg_post. From={$sms_from}");

		if ($sms_img) {
			$size        = 'large';
			$sms_img_url = esc_url($sms_img['sizes'][$size]);
			$this->log_sms_debug($post_id, "Image attached: {$sms_img_url}");
		}

		// Hidden marker for filter context
		$sms_msg_with_marker = $sms_msg . " [PID:$post_id]";

		// Build & sanitize recipients (strings only, strip empties/dupes)
		$sms_to = array_map(function($v){ return is_object($v) ? (string) $v->mobile : (string) $v['mobile']; }, (array) $staff_array);
		$sms_to = array_values(array_unique(array_filter($sms_to, function($v){ return $v !== ''; })));

		// Decide mode: post field primary; fallback to actual count
		$mode = null;
		if ($sms_recipients_number !== null && $sms_recipients_number !== '') {
			if (is_numeric($sms_recipients_number)) {
				$mode = ((int)$sms_recipients_number === 1) ? 'single' : 'bulk';
			} else {
				$val  = strtolower(trim((string)$sms_recipients_number));
				$mode = ($val === 'single' || $val === '1') ? 'single' : 'bulk';
			}
		} else {
			$mode = (count($sms_to) === 1) ? 'single' : 'bulk';
		}

		$this->log_sms_debug($post_id, "Mode={$mode}, recipients=" . count($sms_to));

		if ($mode === 'single') {
			$recipient = $sms_to[0] ?? '';
			if ($recipient === '') {
				$this->log_sms_debug($post_id, "Aborted single send: no recipient found");
				return;
			}
			$this->log_sms_debug($post_id, "Sending SINGLE to {$recipient}");
			if (isset($sms_img_url)) {
				wp_sms_send($recipient, $sms_msg_with_marker, false, $sms_from, [$sms_img_url]);
			} else {
				wp_sms_send($recipient, $sms_msg_with_marker, false, $sms_from);
			}
		} else {
			if (empty($sms_to)) {
				$this->log_sms_debug($post_id, "Aborted bulk send: no recipients found");
				return;
			}
			$this->log_sms_debug($post_id, "Sending BULK to " . implode(',', $sms_to));
			if (isset($sms_img_url)) {
				wp_sms_send($sms_to, $sms_msg_with_marker, false, $sms_from, [$sms_img_url]);
			} else {
				wp_sms_send($sms_to, $sms_msg_with_marker, false, $sms_from);
			}
		}

		// Mark sent now that we actually called wp_sms_send
		$dateTime = date('Y-m-d H:i A', current_time('timestamp', 0));
		update_post_meta($post_id, 'staff_sms_msg_sent', 1);
		update_post_meta($post_id, 'staff_sms_msg_sent_datetime', $dateTime);
		$this->log_sms_debug($post_id, "Marked as sent at {$dateTime}");

		// Optional: keep the recipient count fresh for UI/debug
		update_post_meta($post_id, 'sms_recipients_number', count($sms_to));
		$this->log_sms_debug($post_id, "Recipient count updated to " . count($sms_to));
	}
	
	//////////////////////////////////////
	//// POST ADMIN NOTICES & DISPLAY ////
	//////////////////////////////////////
	
	public function display_custom_message_on_edit_sms_msg_screen() {
        global $post;
		$screen = get_current_screen();
        // Ensure that we are on the correct post type's edit screen
        if ( $screen->base === 'post' && $screen->action !== 'add' && isset( $post->post_type ) && $post->post_type === 'staff-sms' ) {
            // Get the meta fields
            $message_sent = get_post_meta( $post->ID, 'staff_sms_msg_sent', true );
            $message_sent_datetime = get_post_meta( $post->ID, 'staff_sms_msg_sent_datetime', true );

            // If the message was sent (staff_sms_msg_sent is 1), display the custom message
            if ( $message_sent === '1' && !empty( $message_sent_datetime ) ) {
                echo '<div class="notice notice-success" style="margin-top: 20px;">
                        <p><strong>This message was successfully sent on ' . esc_html( $message_sent_datetime ) . '.</strong><br> Modifications to this message are no longer allowed. To modify, a new message will need to be created.</p>
                      </div>';
                ?>
                <style>
                    /* Hide the Update and Move to Trash buttons */
                    #delete-action, #publishing-action { display: none !important; }
                    /* Hide the Edit links for post status, visibility, and date */
                    .edit-post-status, .edit-visibility, .edit-timestamp { display: none !important; }
                </style>
                <script type="text/javascript">
                    jQuery(document).ready(function($) {
                        // Disable all input fields and select elements to prevent modifications
                        $(':input').attr('disabled', 'disabled');
                        $('select').attr('disabled', 'disabled');
                        
                        // Remove the Edit links for status, visibility, and published date
                        $('.edit-post-status').remove();
                        $('.edit-visibility').remove();
                        $('.edit-timestamp').remove();
						$('[data-name="scheduled_message"]').hide();
						
						$('#submitpost > #major-publishing-actions').remove();
                        
                        // Keep the submit buttons enabled to allow other actions if necessary
                        $(':submit').removeAttr('disabled');
                    });
                </script>
                <?php
            }
        }
    }
	
	public function send_sms_msg_admin_notice_already_sent() {
		// Admin Notice that the Message Has Already Been Sent
		echo '<div class="notice notice-error is-dismissible">
      			<p>MESSAGE ERROR: This message cannot be modified as it has already been sent.</p>
      		  </div>'; 
	}
	
	public function admin_sms_notices() {
		global $post;

		if (!is_admin()) {
			return;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || !isset($screen->post_type) || $screen->post_type !== 'staff-sms') {
			return;
		}

		if (!$post || $post->post_type !== 'staff-sms') {
			return;
		}

		$post_id = $post->ID;

		$sent       = (int) get_post_meta($post_id, 'staff_sms_msg_sent', true);
		$sent_time  = get_post_meta($post_id, 'staff_sms_msg_sent_datetime', true);
		$queued     = (int) get_post_meta($post_id, 'staff_sms_msg_queued', true);
		$scheduled  = (int) get_post_meta($post_id, 'staff_sms_msg_scheduled', true);
		$sched_time = get_post_meta($post_id, 'staff_sms_msg_scheduled_datetime', true);

		if ($sent === 1) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>Message Sent:</strong> ' . esc_html($sent_time) . '</p></div>';
		} elseif ($scheduled === 1 && !empty($sched_time)) {
			echo '<div class="notice notice-info is-dismissible"><p><strong>Message Scheduled:</strong> ' . esc_html($sched_time) . '</p></div>';
		} elseif ($queued === 1) {
			echo '<div class="notice notice-warning is-dismissible"><p><strong>Sending Messages:</strong> Processing in the background. Check back later for results.</p></div>';
		}
	}
	
	//////////////////////////
	//// SUBSCRIBER MSGS /////
	//////////////////////////
	
	public function sms_send_unsubscribe_msg($sms_to) {
		if ( !str_contains($sms_to, '+1') ) {
			$sms_to = '+1'.$sms_to;
		}
		$optOutKeyword = get_field('ve_staff_sms_optout_keyword', 'option');
		$programName = get_field('ve_staff_sms_program_name', 'option');

		// Send the SMS to let the receiver know they have been successfully unsubscribed
		$sms_msg = 'You have been successfully unsubscribed from '.$programName.'. To subscribe again TXT START '.$optOutKeyword.'.';
		wp_sms_send( $sms_to, $sms_msg);
	}
	
	public function sms_send_subscribed_msg($sms_to) {
		if ( !str_contains($sms_to, '+1') ) {
			$sms_to = '+1'.$sms_to;
		}
		$optOutKeyword = get_field('ve_staff_sms_optout_keyword', 'option');
		$programName = get_field('ve_staff_sms_program_name', 'option');

		// Send the SMS to let the receiver know they have been successfully unsubscribed
		$sms_msg = 'You have been successfully subscribed to '.$programName.'. To unsubscribe TXT STOP '.$optOutKeyword.'.';
		wp_sms_send( $sms_to, $sms_msg);
	}
	
	//////////////////////////
	//// HELPER FUNCTIONS ////
	//////////////////////////

	/**
	 * Append a debug entry to a staff-sms post.
	 */
	private function log_sms_debug($post_id, $message) {
		$timestamp = date('Y-m-d H:i:s', current_time('timestamp', 0));
		$entry = "[{$timestamp}] {$message}";

		$logs = get_post_meta($post_id, 'staff_sms_debug_log', true);
		if (!is_array($logs)) {
			$logs = [];
		}
		$logs[] = $entry;

		update_post_meta($post_id, 'staff_sms_debug_log', $logs);
	}
	
	public function render_sms_debug_log_box($post) {
		$logs = get_post_meta($post->ID, 'staff_sms_debug_log', true);
		if (!is_array($logs) || empty($logs)) {
			echo '<p>No debug log entries for this SMS.</p>';
			return;
		}

		echo '<div style="max-height:300px; overflow:auto; background:#f8f8f8; border:1px solid #ddd; padding:8px;">';
		foreach ($logs as $line) {
			echo '<div style="font-family:monospace; margin-bottom:4px;">' . esc_html($line) . '</div>';
		}
		echo '</div>';
	}
	
	public function update_custom_meta_on_posts_table_load() {
		// Check if we are on the 'staff-sms' post type admin page
		$screen = get_current_screen();
		if ( $screen && $screen->post_type === 'staff-sms' ) {
			// Query all 'staff-sms' posts with the status 'draft'
			$args = array(
				'post_type'      => 'staff-sms',
				'post_status'    => 'draft',
				'posts_per_page' => -1, // Get all draft posts
			);

			$query = new WP_Query( $args );

			// Loop through the posts and update meta fields
			if ( $query->have_posts() ) {
				while ( $query->have_posts() ) {
					$query->the_post();
					$post_id = get_the_ID();

					// Update the custom meta fields for each post
					update_post_meta( $post_id, 'staff_sms_msg_sent', '' );
					update_post_meta( $post_id, 'staff_sms_msg_sent_datetime', '' );
				}
			}

			// Reset post data
			wp_reset_postdata();
		}
	}
	
	///////////////////////////////
	//// REBRANDLY SHORT LINKS ////
	///////////////////////////////
	
	/*
	 * SET REBRANDLY API KEY
	 */
	public function rebrandly_api_key(){
		$apikey = get_field('ve_staff_sms_shorten_access_token', 'option');
		return $apikey;
	}
	
	/*
	 * REBRANDLY DOMAIN
	 */
	public function rebrandly_domain_id(){
		$domain = get_field('ve_staff_sms_shorten_domain', 'option');
		return $domain['value'];
	}
	
	public function rebrandly_domain_name(){
		$domain = get_field('ve_staff_sms_shorten_domain', 'option');
		return $domain['label'];
	}
	
	/*
	 * GETS CUSTOM DOMAINS FROM REBRAND.LY 
	 */
	
	public function rebrandly_domains(){
		$apikey = $this->rebrandly_api_key();
		
		if( $apikey == '' || empty($apikey)){
			return;
		}
		
		$url = "https://api.rebrandly.com/v1/domains?orderBy=fullName&orderDir=asc&limit=100&active=true";
		
		$result = wp_remote_get(
		  $url, 
		  array(
			'headers' => array(
			  'apikey' => $apikey
			)
		  )
		);

		return json_decode($result['body']);
	}
	
	/*
	 * SET DOMAIN OPTIONS IN OPTIONS SELECT
	 */
	
	public function acf_options_rebrandly_domains($field){
		$apikey = $this->rebrandly_api_key();
		$short_url_status    = get_field('ve_staff_sms_shorten_url', 'option');
		
		if ($apikey != '' && $short_url_status == 1) {
			// reset choices
			$field['choices'] = array();

			$domains = $this->rebrandly_domains();

			foreach ($domains as $domain) :
				$field['choices'][ $domain->id ] = $domain->fullName;
			endforeach;

			return $field;
		} else {
			return $field;
		}
        
    }

	 public function check_rebrandly_domain( $rebrandly_id ) {
		$options = get_option('sbrb_settings');
		$url = 'https://api.rebrandly.com/v1/domains/' . $rebrandly_id;

		$result = wp_remote_get(
		  $url, 
		  array(
			'headers' => array(
			  'apikey' => $this->rebrandly_api_key()
			)
		  )
		);

		return json_decode($result['body']);
	  }
	

	
	/**
	  * Check a Rebrandly account API Key.
	  * 
	  * @return boolean
	  */
	public function check_rebrandly_account() {
		$apiKey = $this->rebrandly_api_key();
		// If API Key option not set, do nothing
		if( $apiKey == '' )
		  return;

		$url = 'https://api.rebrandly.com/v1/account';

		$result = wp_remote_get(
		  $url, 
		  array(
			'headers' => array(
			  'apikey' => $apiKey
			)
		  )
		);

		$response = ( $result['response']['code'] == 200 ) ? true : false;

		return $response;
	}
	
	/**
	  * Create a new Rebrandly link.
	  *
	  * @param WP_Post $post The post object.
	  */
	public function create_rebrandly_link( $longurl ) {

		$url = 'https://api.rebrandly.com/v1/links';
		$title = 'SMS Sent by '.get_bloginfo( 'name' ).' at '.current_time("Y-m-d h:ia");
		$description = '';

		$result = wp_remote_post(
		  $url, 
		  array(
			'body' => json_encode(array(
			  'title' => $title,
			  'slashtag' => '',
			  'destination' => $longurl,
			  'domain' => array(
				  			'id' => $this->rebrandly_domain_id()
				  				)
			)),
			'headers' => array(
			  'apikey' => $this->rebrandly_api_key(),
			  'Content-Type' => 'application/json'
			)
		  )
		);

		if ( is_wp_error($result) ) {
		  var_dump( $result->get_error_message() ); die;
		} else {
		  return json_decode($result['body'], true);
		}
	}
	
	public function rebrandly_shorten_url($longUrl = ''){
		$short_url_status    = get_field('ve_staff_sms_shorten_url', 'option');
		$short_url_api_token = get_field('ve_staff_sms_shorten_access_token', 'option');

		if ($short_url_status == '1' && !empty($short_url_api_token)) {
			$longUrlParse  = parse_url($longUrl);
			$longUrlDomain = $longUrlParse['host'] ?? '';

			// Skip if already your Rebrandly domain
			if ($longUrlDomain === $this->rebrandly_domain_name()) {
				return $longUrl;
			}

			$rebrandly = $this->create_rebrandly_link($longUrl);

			if (is_wp_error($rebrandly)) {
				return $longUrl;
			}

			if (!empty($rebrandly['shortUrl'])) {
				// Ensure https:// prefix
				$shortUrl = $rebrandly['shortUrl'];
				if (stripos($shortUrl, 'http') !== 0) {
					$shortUrl = 'https://' . ltrim($shortUrl, '/');
				}
				return $shortUrl;
			}
		}

		return $longUrl;
	}


}
