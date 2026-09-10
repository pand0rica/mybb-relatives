<?php
/**
 * Verwandtschaften & Stammbaum - Frontend language file (English)
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

$l['relatives'] = "Relatives & Family Tree";
$l['relatives_disabled'] = "This feature is currently disabled.";

// Navigation / titles
$l['relatives_my_persons_title'] = "My relatives";
$l['relatives_add_person'] = "Add person";
$l['relatives_edit_person_title'] = "Edit person";
$l['relatives_view_tree'] = "View family tree";
$l['relatives_usercp_menu_item'] = "Relatives & Family Tree";
$l['relatives_tree_title_for'] = "Family tree of %s";
$l['relatives_tree_reset_view'] = "Reset view";

// List
$l['relatives_col_name'] = "Name";
$l['relatives_col_relationship'] = "Relationship";
$l['relatives_col_age'] = "Age";
$l['relatives_col_status'] = "Status";
$l['relatives_col_actions'] = "Actions";
$l['relatives_no_persons_yet'] = "You haven't added any relatives yet.";
$l['relatives_edit'] = "Edit";
$l['relatives_delete'] = "Delete";
$l['relatives_confirm_delete'] = "Really delete this person?";
$l['relatives_cancel'] = "Cancel";
$l['relatives_save'] = "Save";

// Flash messages
$l['relatives_added'] = "Person added.";
$l['relatives_saved'] = "Changes saved.";
$l['relatives_deleted'] = "Person deleted.";

// Form fields
$l['relatives_field_name'] = "Name";
$l['relatives_field_gender'] = "Gender";
$l['relatives_field_relationship'] = "Relationship";
$l['relatives_field_relationship_custom'] = "Custom relationship label";
$l['relatives_field_age'] = "Age";
$l['relatives_field_origin'] = "Origin";
$l['relatives_field_avatar'] = "Avatar (image URL)";
$l['relatives_field_description'] = "Description";
$l['relatives_field_is_wanted'] = "Mark as wanted";
$l['relatives_field_wanted_thread_link'] = "Link to the wanted thread";
$l['relatives_field_attributes'] = "Attributes";
$l['relatives_field_link_username'] = "Username to link this person with";

$l['relatives_gender_any'] = "any";
$l['relatives_gender_male'] = "male";
$l['relatives_gender_female'] = "female";
$l['relatives_gender_diverse'] = "diverse";
$l['relatives_relationship_other'] = "Other connection";
$l['relatives_relationship_unspecified'] = "unspecified";
$l['relatives_relationship_missing_neutral'] = "No gender-neutral label exists for this relationship yet. Please ask a team member.";
$l['relatives_origin_none'] = "- none -";

$l['relatives_badge_wanted'] = "Wanted";
$l['relatives_badge_link_requested'] = "Link requested";
$l['relatives_badge_link_confirmed'] = "Linked";

// Validation errors
$l['relatives_error_name_required'] = "Please enter a name.";
$l['relatives_error_custom_label_required'] = "Please enter a custom relationship label, or pick a relationship from the list.";
$l['relatives_error_invalid_relationship_type'] = "The selected relationship is invalid.";
$l['relatives_error_relationship_gender_mismatch'] = "The selected relationship does not match the selected gender.";
$l['relatives_error_invalid_age'] = "Please enter a valid age.";
$l['relatives_error_invalid_origin'] = "Please select a valid origin.";
$l['relatives_error_limit_reached'] = "You have reached the maximum number of relatives allowed.";
$l['relatives_error_username_required'] = "Please enter a username.";
$l['relatives_error_user_not_found'] = "This user could not be found.";
$l['relatives_error_generic'] = "Something went wrong. Please try again.";

// Wanted box (profile)
$l['relatives_wanted_box_title'] = "Wanted";
$l['relatives_wanted_thread_link_text'] = "To the wanted thread";

// Link workflow
$l['relatives_pending_link_requests'] = "Pending link requests";
$l['relatives_link_request_text'] = "Someone wants to link a relative entry to your account.";
$l['relatives_link_accept'] = "Accept";
$l['relatives_link_decline'] = "Decline";
$l['relatives_link_requested'] = "Link request sent.";
$l['relatives_link_updated'] = "Link status updated.";
$l['relatives_link_pending'] = "Link request pending confirmation.";
$l['relatives_link_confirmed_with'] = "Linked with account:";
$l['relatives_link_send_request'] = "Send link request";

// PM sent for a link request (see LinkManager)
$l['relatives_pm_link_request_subject'] = "Relative link request: %s";
$l['relatives_pm_link_request_message'] = "Someone wants to link the relative entry \"%s\" to your account so your family trees connect.\n\nYou can accept or decline this request here:\n%s";
