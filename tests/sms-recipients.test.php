<?php
// Standalone regression checks: php tests/sms-recipients.test.php
$hooks = array();
function add_action(...$args) {}
function add_filter($name, $callback, ...$args) { global $hooks; $hooks[$name] = $callback; }
function absint($v) { return abs((int)$v); }
function user_can(...$args) { return $args[0] !== 2; }
function get_user_meta(...$args) { return $args[0] === 2 ? array() : array(1); }
function get_current_user_id() { return 1; }
function is_wp_error($v) { return false; }
function wp_get_object_terms(...$args) { return array(1); }
function get_posts(...$args) { return array(1,2,3,4,5); }
function get_post_type($id) { return 'staff'; }
function get_post_status($id) { return $id === 4 ? 'trash' : 'publish'; }
function get_the_title($id) { return 'Person ' . $id . ' | Sales'; }
function is_email($v) { return filter_var($v, FILTER_VALIDATE_EMAIL); }
function get_field($key, $id) {
 $data = array(
  1 => array('phone'=>'6055550100', 'email'=>'alex@example.com', 'status'=>1),
  2 => array('phone'=>'+1 (605) 555-0101', 'email'=>'blake@example.com', 'status'=>1),
  3 => array('phone'=>'6055550102', 'email'=>'off@example.com', 'status'=>0),
  4 => array('phone'=>'6055550103', 'email'=>'trash@example.com', 'status'=>1),
  5 => array('phone'=>'6055550104', 'email'=>'alex@example.com', 'status'=>1),
 );
 return $data[$id][array('office_contact_info_office_cell_phone'=>'phone','office_contact_info_office_email'=>'email','office_contact_info_office_sms_status'=>'status')[$key]] ?? array();
}
function metadata_exists(...$args) { return true; }
function get_post_field(...$args) { return 1; }
function get_post_meta(...$args) { return array(); }
function wp_timezone() { return new DateTimeZone('America/Chicago'); }
require __DIR__ . '/../assets/plugins/ve-staff/admin/partials/ve-staff-admin-sms-permissions.php';
function expect($test, $label) { if (!$test) throw new Exception($label); }
$r = ve_sms_match_contacts("\xEF\xBB\xBFphone,email\r\n\"(605) 555-0100\",\r\n, BLAKE@example.com\n+16055550100,unknown@example.com,off@example.com,trash@example.com,alex@example.com,bad6055550100", 1);
expect(array_column($r['recipients'], 'id') === array(1,2), 'Only eligible unambiguous matches');
expect($r['duplicates'] === 1 && $r['skipped'] === 5, 'Deduplication and rejected contact counts');
expect(ve_sms_match_contacts('phone,email', 1)['recipients'] === array(), 'Empty template');
expect(isset(ve_sms_match_contacts(str_repeat('6055550100,', 2001), 1)['error']), 'Import limit');
expect(ve_sms_effective_ids(99) === array(), 'Explicitly empty list cannot reload filters or groups');
$_POST['acf']['field_63bf1a8ae121e']['field_63bf16abee102'] = 0;
expect($hooks['acf/update_value/key=field_63bf16f7ee103']('2030-01-01 12:00:00') === '', 'Send now clears stale date');
$_POST['acf']['field_63bf1a8ae121e']['field_63bf16abee102'] = 1;
expect($hooks['acf/validate_value/key=field_63bf16f7ee103'](true, '2000-01-01 12:00:00') !== true, 'Past date rejected');
expect($hooks['acf/validate_value/key=field_63bf16f7ee103'](true, '2099-01-01 12:00:00') === true, 'Future date accepted');
expect(ve_sms_resolve_filter_terms(array('__all'), 'department', 1) === array(1), 'All expands only permitted terms');
expect(ve_sms_resolve_filter_terms(array('__all', '999'), 'location', 1) === array(1), 'All cannot add forbidden terms');
expect(ve_sms_resolve_filter_terms(array('999'), 'location', 1) === array(), 'Unauthorized explicit term excluded');
expect(ve_sms_resolve_filter_terms(array('__all'), 'department', 2) === array(), 'All with no permission stays empty');
expect(ve_sms_resolve_filter_terms(array(), 'department', 1) === array(), 'Empty does not implicitly mean All');
expect(ve_sms_save_filter_choices(array('__all'), 99, array('name'=>'sms_msg_department')) === array(1), 'Save expands All to real IDs');
$field = ve_sms_prepare_filter_choices(array('name'=>'sms_msg_department', 'choices'=>array(1=>'Sales'), 'value'=>array(1)));
expect($field['choices']['__all'] === 'All Departments' && $field['value'] === array('__all'), 'All choice round trip');
$definition = json_decode(file_get_contents(__DIR__ . '/../assets/themes/vern-eide-staff/inc/assets/acf/json/group_63bf0f991067a.json'), true);
$fields = $hooks['acf/load_fields']($definition['fields'], $definition);
$names = array_column($fields, 'name');
expect(array_search('sms_msg_department', $names) < array_search('ve_sms_filter_preview', $names), 'Preview follows both filters');
expect(array_search('ve_sms_filter_preview', $names) < array_search('ve_sms_recipient_slot', $names), 'Preview precedes selected recipients');
echo "SMS recipient, All permissions, field layout and scheduling checks passed\n";
