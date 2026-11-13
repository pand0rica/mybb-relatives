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
$l['relatives_add_relative_of'] = "Add relative of %s";
$l['relatives_add_relative_of_note'] = "The relationship type is chosen from %s's perspective (e.g. 'brother' means: %s's brother). The tree automatically works out what that makes them from your own perspective (e.g. 'uncle').";
$l['relatives_edit_person_title'] = "Edit person";
$l['relatives_view_tree'] = "View family tree";
$l['relatives_usercp_menu_item'] = "Relatives & Family Tree";
$l['relatives_tree_title_for'] = "Family tree of %s";
$l['relatives_tree_reset_view'] = "Reset view";
$l['relatives_tree_zoom_in'] = "Zoom in";
$l['relatives_tree_zoom_out'] = "Zoom out";
$l['relatives_tree_hint'] = "Drag to pan, zoom with Ctrl (Mac: ⌘) + mouse wheel or the +/− buttons. Click a card to open the actions available for it.";
$l['relatives_tree_hint_popup'] = "Drag to pan, scroll to zoom. Click a card to open the actions available for it.";
$l['relatives_tree_wheel_hint'] = "Hold Ctrl (Mac: ⌘) and scroll to zoom";
$l['relatives_tree_empty_own'] = "Your family tree is still empty. Click your own card to add the first person.";
$l['relatives_tree_empty_other'] = "Nobody has been added to this family tree yet.";
$l['relatives_tree_popup'] = "Open family tree as popup";
$l['relatives_tree_link'] = "Family tree";
$l['relatives_tree_copy_link'] = "Copy link";
$l['relatives_tree_link_copied'] = "Link copied";
$l['relatives_tree_hint_read_only'] = "Drag to pan, scroll to zoom. Click a card to see its details.";
$l['relatives_tree_legend_self'] = "You";
$l['relatives_tree_node_you'] = "You";
$l['relatives_tree_node_account'] = "Account";
$l['relatives_tree_toggle_branch'] = "Collapse/expand branch";

// List
$l['relatives_col_name'] = "Name";
$l['relatives_col_relationship'] = "Relationship";
$l['relatives_col_age'] = "Age";
$l['relatives_col_status'] = "Status";
$l['relatives_col_actions'] = "Actions";
$l['relatives_no_persons_yet'] = "You haven't added any relatives yet. Add the first person using the form – or right in the family tree by clicking your own card.";
$l['relatives_no_persons_add'] = "Add the first person";
$l['relatives_no_persons_tree'] = "Add in the family tree";
$l['relatives_edit'] = "Edit";
$l['relatives_delete'] = "Delete";
$l['relatives_confirm_delete'] = 'Remove this person from your list? The person and their connections stay in the shared family tree.';
$l['relatives_cancel'] = "Cancel";
$l['relatives_save'] = "Save";

// Flash messages
$l['relatives_added'] = "Person added.";
$l['relatives_saved'] = "Changes saved.";
$l['relatives_deleted'] = "Person removed from your list.";
$l['relatives_added_existing'] = 'A person with this name already existed – they are now in your list too. Existing details were kept, your connections were added.';
$l['relatives_shared_note'] = 'Other characters with this person: %d. All details and connections are shared across the whole family tree.';

// Form fields
$l['relatives_field_name'] = "Name";
$l['relatives_field_gender'] = "Gender";
$l['relatives_field_relationship'] = "Relationship";
$l['relatives_field_relationship_custom'] = "Custom relationship label";
$l['relatives_field_age'] = "Age";
$l['relatives_field_birth_year'] = "Year of birth";
$l['relatives_field_job'] = "Occupation";
$l['relatives_quick_add_more_hint'] = "Add further details such as the occupation via “Edit person” after saving.";
$l['relatives_birth_year_age'] = "= {1} years (in-play year {2})";
$l['relatives_age_born'] = "{1} (born {2})";
$l['relatives_error_invalid_birth_year'] = "Please enter a valid year of birth (not after the current in-play year).";
$l['relatives_field_origin'] = "Origin";
$l['relatives_field_avatar'] = "Face claim";
$l['relatives_field_description'] = "Description of the person";
$l['relatives_field_relationship_description'] = "Your relationship";
$l['relatives_field_related_to'] = "Related to";
$l['relatives_field_related_to_hint'] = "The relationship applies to this person: 'brother' related to 'mother' becomes 'uncle' in the family tree. Without a related person it applies to you.";
$l['relatives_related_to_self'] = '– to your character –';
$l['relatives_related_to_self_named'] = '{1} (your character)';
$l['relatives_relation_sentence'] = 'Means: {1} is {2} of {3}.';
$l['relatives_relation_sentence_subject'] = 'This person';
$l['relatives_relation_sentence_self'] = 'your character';
$l['relatives_error_invalid_related_to'] = "This related person can't be chosen: it isn't in your list or would create a loop.";
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
$l['relatives_error_existing_gender_mismatch'] = "A person with this name already exists with a different gender. Please pick a matching relationship.";
$l['relatives_error_already_owner'] = "This person is already in your list.";
$l['relatives_error_invalid_age'] = "Please enter a valid age.";
$l['relatives_error_invalid_origin'] = "Please select a valid origin.";
$l['relatives_error_limit_reached'] = "You have reached the maximum number of relatives allowed.";
$l['relatives_error_username_required'] = "Please enter a username.";
$l['relatives_error_user_not_found'] = "This user could not be found.";
$l['relatives_error_generic'] = "Something went wrong. Please try again.";

// Wanted box (profile)
$l['relatives_wanted_box_title'] = "Wanted";
$l['relatives_wanted_thread_link_text'] = "To the wanted thread";
$l['relatives_wanted_more'] = "More";
$l['relatives_wanted_age'] = "{1} years";

// Link workflow
$l['relatives_pending_link_requests'] = "Pending link requests";
$l['relatives_link_request_text'] = '{1} wants to link the entry <strong>"{2}"</strong> from their family tree to your account.';
$l['relatives_link_request_text_relation'] = '{1} wants to link the entry <strong>"{2}"</strong> ({3}) to your account.';
$l['relatives_link_request_unknown_user'] = "An unknown user";
$l['relatives_link_request_view_tree'] = "View the requester's family tree";
$l['relatives_link_accept'] = "Accept";
$l['relatives_link_decline'] = "Decline";
$l['relatives_link_requested'] = "Link request sent.";
$l['relatives_link_updated'] = "Link status updated.";
$l['relatives_link_section_title'] = "Link to an account";
$l['relatives_link_section_hint'] = "Is this person played as a character of their own on the forum? Then link the entry to that account. The account receives a PM and has to agree – afterwards your family trees are connected, and clicking the card leads to that character's family tree.";
$l['relatives_link_state_confirmed'] = 'Linked with {1}.';
$l['relatives_link_state_pending'] = 'Request sent to {1} – waiting for confirmation.';
$l['relatives_link_search_user'] = "Search username …";
$l['relatives_confirm_title_named'] = 'Delete "{1}"?';
$l['relatives_link_send_request'] = "Send link request";

// PM sent for a link request (see LinkManager)
$l['relatives_pm_link_request_subject'] = "Relative link request: %s";
$l['relatives_pm_link_request_message'] = "Someone wants to link the relative entry \"%s\" to your account so your family trees connect.\n\nYou can accept or decline this request here:\n%s";

// v2.1: Verbindungen, Familien, Verstorben, Verwandtschafts-Cache
$l['relatives_relation_of'] = '{1} of {2}';
$l['relatives_relation_of_word'] = 'of';
$l['relatives_connections'] = 'Connections';
$l['relatives_deceased'] = 'Deceased';
$l['relatives_tree_node_family'] = 'Family';
$l['relatives_tree_legend_family'] = 'Family';
$l['relatives_tree_legend_deceased'] = 'Deceased';
$l['relatives_tree_legend_line_solid'] = 'Blood relation / married';
$l['relatives_tree_legend_line_dashed'] = 'Adopted / foster / separated';
$l['relatives_tree_legend_line_dotted'] = 'Step / in-law / engaged';
$l['relatives_tree_legend_line_secret'] = 'Secret in-play connection';
$l['relatives_tree_legend_non_kin'] = 'Friends, acquaintances & co. are only shown in the list, not in the family tree.';
$l['relatives_field_is_deceased'] = 'Deceased';
$l['relatives_field_death_note'] = 'Year or date of death';
$l['relatives_field_relations'] = 'Connections';
$l['relatives_field_relations_hint'] = 'The relationship always refers to the selected person or family: "Brother" with "Delia" means "brother of Delia". You can add several connections, e.g. birth parents and adoptive parents. The main connection decides where the person is placed in the tree.';
$l['relatives_form_section_optional'] = 'Optional';
$l['relatives_relation_anchor'] = 'Related to (person or family)';
$l['relatives_relation_anchor_choose'] = '– choose a person –';
$l['relatives_error_anchor_required'] = 'Please choose a related person for every connection. A played character cannot be connected to itself.';
$l['relatives_incoming_below'] = 'More connections are stored with the other person – you find them below under "Connections added by others".';
$l['relatives_relation_add'] = 'Add another connection';
$l['relatives_relation_remove'] = 'Remove';
$l['relatives_relation_primary'] = 'Main connection';
$l['relatives_relation_secret'] = 'Secret in-play (faded line)';
$l['relatives_relation_secret_desc'] = 'The line is drawn faded in the family tree, e.g. for a parentage the characters do not know about. The connection stays visible to everyone.';
$l['relatives_relation_family_as_parent'] = 'Show family as parents';
$l['relatives_relation_family_hint'] = 'A family needs no relationship type. If checked, the family box takes the place of the parents in the tree; otherwise the person simply belongs to the family.';
$l['relatives_relation_sentence_family'] = 'Means: {1} belongs to {3}.';
$l['relatives_relation_sentence_family_parent'] = 'Means: {1} belongs to {3}, the box takes the place of the parents.';
$l['relatives_field_families'] = 'Family';
$l['relatives_field_families_hint'] = 'Does the person belong to a family from the family overview? Then pick it here. No relationship degree is needed for that.';
$l['relatives_field_families_none'] = 'There are no families yet. You can create one under “Families”.';
$l['relatives_family_row_add'] = 'Assign family';
$l['relatives_family_row_family'] = 'Family';
$l['relatives_family_row_choose'] = '– choose a family –';
$l['relatives_family_member'] = 'Member';
$l['relatives_anchor_group_persons'] = 'Persons';
$l['relatives_families_title'] = 'Families';
$l['relatives_families_hint'] = 'Family boxes take the place of the parents in the tree, so you can group siblings without adding individual ancestors. Families are shared across all trees on the forum. Assign people in the person form under “Family” or on the family page via “Add to this family”.';
$l['relatives_families_empty'] = 'No families have been created yet.';
$l['relatives_families_filter_welcomes'] = 'New members';
$l['relatives_families_filter_welcomes_yes'] = 'allowed';
$l['relatives_families_filter_welcomes_no'] = 'not allowed';
$l['relatives_families_filter_all'] = '– all –';
$l['relatives_families_filter_submit'] = 'Filter';
$l['relatives_families_filter_reset'] = 'Reset filter';
$l['relatives_families_filter_no_match'] = 'No family matches this filter.';
$l['relatives_family_list_title'] = 'Families';
$l['relatives_family_list_open'] = 'Open public family list';
$l['relatives_family_list_intro'] = 'All families of the forum with their members. Use the filter to find, for example, families that welcome new members.';
$l['relatives_families_members_played'] = '{1} persons, {2} of them played';
$l['relatives_family_open_page'] = 'Open family page';
$l['relatives_family_back'] = 'Back to the family overview';
$l['relatives_family_group_played'] = 'Played characters';
$l['relatives_family_group_wanted'] = 'Wanted';
$l['relatives_family_group_npc'] = 'Other family members';
$l['relatives_family_member_tree'] = 'Family tree';
$l['relatives_family_no_members'] = 'Nobody belongs to this family yet.';
$l['relatives_family_join_title'] = 'Add to this family';
$l['relatives_family_join_hint'] = 'Pick your character or a person from your list. Further connections (e.g. parents, siblings) are added in the person form as usual.';
$l['relatives_family_join_person'] = 'Person';
$l['relatives_family_join_submit'] = 'Add';
$l['relatives_family_join_closed_hint'] = 'This family takes no further played characters. You can still assign NPCs.';
$l['relatives_family_join_exists'] = 'This person already belongs to the family.';
$l['relatives_family_joined'] = '{1} now belongs to the {2} family.';
$l['relatives_tree_family_members'] = 'Members';
$l['relatives_family_add'] = 'Add family';
$l['relatives_family_added'] = 'The family has been created.';
$l['relatives_family_deleted'] = 'The family has been deleted.';
$l['relatives_family_confirm_delete'] = 'Really delete this family? It is removed from every tree; the persons are kept.';
$l['relatives_family_edit_title'] = 'Edit family';
$l['relatives_family_form_hint'] = 'If a family with this name already exists, it is reused instead of created twice. The person who created it, the staff and every played character belonging to the family can edit it; only the creator and the staff can delete it.';
$l['relatives_field_family_name'] = 'Name (e.g. "Potter")';
$l['relatives_field_family_color'] = 'Colour';
$l['relatives_field_family_description'] = 'Description';
$l['relatives_field_family_welcomes'] = 'This family welcomes new members';
$l['relatives_field_family_welcomes_hint'] = 'Without the tick, no further characters can be added to the family. New players can then only take over NPCs that already appear in one of the family’s trees.';
$l['relatives_family_closed_badge'] = 'No new members';
$l['relatives_family_attribute_none'] = '– not specified –';
$l['relatives_error_family_closed'] = 'The family {1} is currently not accepting new members. {2} can only join by taking over an NPC that already appears in its tree.';
$l['relatives_error_family_name_required'] = 'Please enter a name for the family.';
$l['relatives_error_invalid_family'] = 'This family does not exist (anymore).';

// v2.2: gemeinsames Geflecht
$l['relatives_graph_not_merged'] = 'The family tree is currently being converted into a shared network. Please try again later.';
$l['relatives_error_relation_conflict'] = 'This person and {1} are already connected as "{2}". Adjust the existing connection instead of adding a contradicting one.';
$l['relatives_tree_show_more'] = 'Show more relatives';
$l['relatives_tree_expand_all'] = 'Expand all';
$l['relatives_tree_collapse_all'] = 'Default view';
$l['relatives_tree_truncated'] = 'This family tree is very large – only the closest relatives are shown.';
$l['relatives_tree_derived'] = 'derived (not entered, recognised through shared parents)';
$l['relatives_tree_node_character'] = 'Character';
$l['relatives_tree_placeholder_parent'] = 'Parent of %s';
$l['relatives_tree_placeholder_grandparent'] = 'Grandparent of %s';
$l['relatives_tree_placeholder_great_grandparent'] = 'Great-grandparent of %s';
$l['relatives_tree_placeholder_ancestor'] = 'Ancestor of %s';
$l['relatives_tree_placeholder_partner'] = 'Partner of %s';
$l['relatives_tree_placeholder_and'] = ' and ';
$l['relatives_tree_placeholder_badge'] = 'Placeholder';
$l['relatives_tree_placeholder_note'] = 'This person has not been entered. The placeholder only shows who the connection runs through and disappears once the real person is added.';
$l['relatives_tree_placeholder_add'] = 'Add this person';
$l['relatives_tree_adopt'] = 'Add to my list';
$l['relatives_tree_adopt_note'] = 'This person was added by another player. Add them to your list to be able to edit them – the details stay shared with everyone.';
$l['relatives_adopted'] = 'The person is now in your list too.';

// Deletion requests for NPCs
$l['relatives_confirm_delete_npc'] = 'Delete this person from the family tree completely? Everyone else who has them in their list must agree first. Only then are they deleted together with all connections. If nobody else has them in their list, they are deleted right away.';
$l['relatives_deleted_completely'] = 'The person was deleted from the family tree completely.';
$l['relatives_delete_requested_one'] = 'Deletion requested. One more person has to agree, then the NPC is deleted completely and you will be notified.';
$l['relatives_delete_requested'] = 'Deletion requested. {1} more people have to agree, then the NPC is deleted completely and you will be notified.';
$l['relatives_error_delete_pending'] = 'There is already a deletion request for this person. You can find open requests under "My relatives".';
$l['relatives_error_delete_vote'] = 'This deletion request is no longer open or does not concern you.';
$l['relatives_pending_delete_requests'] = 'Open deletion requests';
$l['relatives_pending_delete_requests_hint'] = 'These people are in your list as well. They are only deleted completely once everyone has agreed. If you decline, the person is kept.';
$l['relatives_delete_request_text'] = '{1} wants to delete <strong>{2}</strong> from the family tree completely.';
$l['relatives_delete_request_date'] = 'Requested on {1}';
$l['relatives_delete_vote_confirm'] = 'Agree to delete';
$l['relatives_delete_vote_decline'] = 'Keep';
$l['relatives_delete_vote_confirm_message'] = 'Agree that this person is deleted from the family tree together with all connections? This cannot be undone.';
$l['relatives_delete_vote_confirmed'] = 'Thanks, your approval was saved. The person is deleted once everyone has agreed.';
$l['relatives_delete_vote_completed'] = 'Everyone agreed, the person was deleted from the family tree completely.';
$l['relatives_delete_vote_declined'] = 'The person is kept. The deletion request was declined.';
$l['relatives_badge_delete_requested'] = 'Deletion requested ({1}/{2} approvals)';
$l['relatives_badge_delete_vote_open'] = 'Deletion request open';
$l['relatives_delete_withdraw'] = 'Withdraw request';
$l['relatives_delete_withdrawn'] = 'Deletion request withdrawn. The person stays in your list.';
$l['relatives_error_delete_withdraw'] = 'This deletion request is no longer open or is not yours.';
$l['relatives_nav_open_delete_requests'] = 'Open deletion requests: {1}';
$l['relatives_nav_open_requests'] = 'Open requests for you: {1}';
$l['relatives_relation_statement'] = '{1} is {2} of {3}';
$l['relatives_relation_statement_family'] = '{1} belongs to the {2} family';
$l['relatives_relation_requests_title'] = 'Open connection requests';
$l['relatives_relation_requests_hint'] = 'Connections to a played character only count once its player confirms them. Until then they do not appear in any family tree.';
$l['relatives_relation_request_incoming'] = '{1} wants to add: <strong>{2}</strong>';
$l['relatives_relation_request_outgoing'] = 'Your request <strong>{1}</strong> is waiting for {2}';
$l['relatives_relation_request_change'] = 'Change';
$l['relatives_relation_request_accept'] = 'Confirm';
$l['relatives_relation_request_decline'] = 'Decline';
$l['relatives_relation_request_cancel'] = 'Withdraw';
$l['relatives_relation_request_accepted'] = 'The connection was confirmed and added.';
$l['relatives_relation_request_declined'] = 'The connection was declined.';
$l['relatives_relation_request_cancelled'] = 'The request was withdrawn.';
$l['relatives_relation_request_conflict'] = 'Something else has been entered between the two persons in the meantime, so the request was discarded.';
$l['relatives_relation_requests_created'] = '{1} connection(s) to played characters are waiting for their player to confirm.';
$l['relatives_relation_deleted'] = 'The connection was removed.';
$l['relatives_incoming_title'] = 'Connections added by others';
$l['relatives_incoming_hint'] = 'These connections were added at the other person. You can change or remove them here. If a change affects a played character, its player has to confirm it first.';
$l['relatives_incoming_pending'] = 'Still waiting for confirmation:';
$l['relatives_incoming_change'] = 'Change';
$l['relatives_incoming_delete'] = 'Remove';
$l['relatives_incoming_confirm_delete'] = 'Really remove this connection? The persons stay.';
