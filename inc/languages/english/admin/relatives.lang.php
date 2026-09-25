<?php
/**
 * Verwandtschaften & Stammbaum - ACP language file (English)
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

// Plugin name + description (also used for settinggroup title reference)
$l['relatives'] = "Relatives & Family Tree";
$l['relatives_acp_title'] = "Relatives & Family Tree";

// Tabs
$l['relatives_tab_relationship_types'] = "Relationship types";
$l['relatives_tab_desc_relationship_types'] = "Manage gender-specific relationship types (mother/father, sibling, etc.) used in the add/edit form.";
$l['relatives_tab_attributes'] = "Attribute groups";
$l['relatives_tab_desc_attributes'] = "Manage attribute groups and their options that can be assigned to a person (e.g. hair colour) or a family (e.g. blood status).";
$l['relatives_tab_origin'] = "Origin";
$l['relatives_tab_desc_origin'] = "Configure whether the \"origin\" field is a free text field or based on an existing profile field.";
$l['relatives_tab_migration'] = "Migration";
$l['relatives_tab_desc_migration'] = "Import data from the legacy relatives/wanted plugin.";

// Shared form fields
$l['relatives_acp_field_label'] = "Label";
$l['relatives_acp_field_sort_order'] = "Sort order";
$l['relatives_acp_field_is_active'] = "Active";
$l['relatives_field_gender'] = "Gender";
$l['relatives_field_origin'] = "Origin";

// Relationship types
$l['relatives_acp_actions_title'] = "Actions";
$l['relatives_acp_add_relationship_type'] = "Add relationship type";
$l['relatives_acp_add_relationship_type_desc'] = "Creates a single new relationship type, e.g. a missing gender variant for an existing concept.";
$l['relatives_acp_seed_defaults_button'] = "Create default relationship types";
$l['relatives_acp_seed_defaults_desc'] = "Creates the usual relationship types (parent, sibling, child, grandparent, grandchild, aunt/uncle, niece/nephew, cousin, spouse, ex-spouse, step- and adoptive variants) in all three gender variants each. Combinations of concept and gender that already exist are skipped -- nothing is duplicated or changed.";
$l['relatives_acp_seed_defaults_done'] = "%d relationship type(s) created.";
$l['relatives_acp_seed_defaults_nothing'] = "All default relationship types already existed, nothing was created.";
$l['relatives_acp_edit_relationship_type'] = "Edit relationship type";
$l['relatives_acp_field_concept_key'] = "Concept key";
$l['relatives_acp_field_concept_key_desc'] = "Groups the male/female/neutral variant of the same relationship (e.g. 'parent', 'sibling'). Use the same concept key for all three genders of one relationship.";
$l['relatives_acp_field_generation_offset'] = "Generation offset";
$l['relatives_acp_generation_grandparent'] = "Grandparent (-2)";
$l['relatives_acp_generation_parent'] = "Parent / aunt-uncle (-1)";
$l['relatives_acp_generation_same'] = "Same generation (0)";
$l['relatives_acp_generation_child'] = "Child (+1)";
$l['relatives_acp_generation_grandchild'] = "Grandchild (+2)";
$l['relatives_acp_relationship_types_overview'] = "Relationship types, grouped by concept";
$l['relatives_acp_col_concept_key'] = "Concept";
$l['relatives_acp_no_relationship_types'] = "No relationship types defined yet.";
$l['relatives_gender_male'] = "male";
$l['relatives_gender_female'] = "female";
$l['relatives_gender_diverse_neutral'] = "diverse";
$l['relatives_acp_missing'] = "missing";

// Attribute groups
$l['relatives_acp_add_group'] = "Add group";
$l['relatives_acp_add_group_desc'] = "Creates a new attribute group, e.g. \"hair color\" or \"eye color\".";
$l['relatives_acp_edit_group'] = "Edit group";
$l['relatives_acp_field_applies_to'] = "Applies to";
$l['relatives_acp_field_applies_to_desc'] = "Person attributes appear in a person's form, family attributes (e.g. \"blood status\") in the family form under \"Optional\". With \"Persons and families\" the group appears in both forms.";
$l['relatives_acp_applies_person'] = "Persons";
$l['relatives_acp_applies_family'] = "Families";
$l['relatives_acp_applies_both'] = "Persons and families";
$l['relatives_acp_error_text_family_only'] = "Free text is only possible if the group applies to families only. Choose \"Families\" under \"Applies to\" or set the field type to \"Preset\".";
$l['relatives_acp_field_field_type'] = "Field type";
$l['relatives_acp_field_field_type_desc'] = "Preset: users pick one of this group's options. Free text: users enter the value themselves. Free text is only available if the group applies to families only.";
$l['relatives_acp_field_type_select'] = "Preset";
$l['relatives_acp_field_type_text'] = "Free text";
$l['relatives_acp_field_is_multiple'] = "Multiple values for persons";
$l['relatives_acp_field_is_multiple_desc'] = "No: a person has exactly one value of this group (dropdown, e.g. hair colour). Yes: a person can have several values (checkboxes, e.g. abilities). Families always have exactly one value.";
$l['relatives_acp_multiple_note'] = "multiple values";
$l['relatives_acp_text_group_no_options'] = "Free text field – this group needs no options.";
$l['relatives_acp_add_option'] = "Add option";
$l['relatives_acp_edit_option'] = "Edit option";
$l['relatives_acp_col_label'] = "Label";
$l['relatives_acp_col_actions'] = 'Actions';
$l['relatives_acp_no_groups'] = "No attribute groups defined yet.";
$l['relatives_acp_no_options'] = "No options in this group yet.";

// Origin tab
$l['relatives_acp_origin_intro'] = "Choose whether members enter a free-text origin, or pick one from an existing profile field's option list.";
$l['relatives_acp_origin_mode_freetext'] = "Free text";
$l['relatives_acp_origin_mode_profilefield'] = "Profile field";
$l['relatives_acp_field_origin_fid'] = "Profile field ID (fid)";
$l['relatives_acp_field_origin_fid_desc'] = "The fid of the MyBB profile field to use as the origin option list. Only relevant if mode is set to \"Profile field\".";
$l['relatives_acp_error_invalid_fid'] = "The given profile field ID does not exist.";

// Migration tab
$l['relatives_acp_migration_intro'] = "This imports data from the legacy relatives/wanted plugin tables. Run this only once per forum.";
$l['relatives_acp_migration_table_found'] = "found";
$l['relatives_acp_migration_table_missing'] = "not found";
$l['relatives_acp_migration_run'] = "Run migration";
$l['relatives_acp_migration_confirm_rerun'] = "I understand this forum was already migrated and want to run it again anyway.";
$l['relatives_acp_migration_rerun_warning'] = "This forum has already been migrated before. Running the migration again is only safe because already-imported rows are skipped by their legacy id, but please confirm below if you really want to proceed.";
$l['relatives_acp_migration_already_done'] = "This forum has already been migrated. Tick the confirmation checkbox to run it again.";
$l['relatives_acp_migration_no_tables'] = "No usable legacy data found (needs relatives + relatives_status and/or wanted + wanted_status).";
$l['relatives_acp_migration_unmapped_error'] = "Migration aborted before any change was made: the following legacy relationship types are in use but have no mapping yet. Extend the mapping in Relatives_Migration::STATUS_BASE_MAP and try again.";
$l['relatives_acp_migration_report_title'] = "Migration report";
$l['relatives_acp_migration_report_types'] = "Relationship types created";
$l['relatives_acp_migration_report_groups'] = "Attribute groups created";
$l['relatives_acp_migration_report_options'] = "Attribute options created";
$l['relatives_acp_migration_report_persons_relatives'] = "Persons imported from \"relatives\"";
$l['relatives_acp_migration_report_persons_wanted'] = "Persons imported from \"wanted\"";
$l['relatives_acp_migration_report_links'] = "Owner assignments imported";
$l['relatives_acp_migration_report_attributes'] = "Attribute values assigned";
$l['relatives_acp_migration_report_without_relationship'] = "Assignments without relationship type";
$l['relatives_acp_migration_report_skipped_no_owner'] = "Persons skipped (no existing owner account left)";
$l['relatives_acp_migration_report_skipped_invalid'] = "Persons skipped (no name)";
$l['relatives_acp_migration_report_skipped_missing_user'] = "Assignments skipped (account deleted)";
$l['relatives_acp_migration_report_skipped_missing_person'] = "Assignments skipped (person no longer exists)";
$l['relatives_acp_migration_report_skipped_duplicate'] = "Assignments skipped (duplicate)";
$l['relatives_acp_migration_report_structure_anchored'] = "Related persons derived (family tree structure)";
$l['relatives_acp_migration_report_structure_ambiguous'] = "Related person ambiguous (stays loosely connected)";
$l['relatives_acp_migration_report_structure_unresolved'] = "No matching related person (stays loosely connected)";
$l['relatives_acp_migration_preview_stats_title'] = "Preview: what will be imported";
$l['relatives_acp_migration_preview_persons_with_owner'] = "Persons with at least one existing owner account";
$l['relatives_acp_migration_preview_persons_without_owner'] = "Persons without an existing owner account (not imported)";
$l['relatives_acp_migration_preview_links_valid'] = "Owner assignments to import";
$l['relatives_acp_migration_preview_links_invalid_status'] = "Assignments with an invalid legacy relationship type (imported without type)";
$l['relatives_acp_migration_preview_mapping_title'] = "Preview: mapping of the legacy relationship types";
$l['relatives_acp_migration_col_item'] = "Item";
$l['relatives_acp_migration_col_count'] = "Count";
$l['relatives_acp_migration_col_legacy'] = "Legacy relationship type";
$l['relatives_acp_migration_col_target'] = "New relationship type";
$l['relatives_acp_migration_col_concept'] = "Concept";
$l['relatives_acp_migration_col_links'] = "Assignments";
$l['relatives_acp_migration_no_grade'] = "without relationship type";
$l['relatives_acp_migration_unmapped_marker'] = "no mapping";
$l['relatives_acp_migration_missing_neutral_title'] = "The following concept keys have no gender-neutral label yet. Please add one so the diverse/unspecified gender filter works for them:";

// Generic ACP actions
$l['relatives_acp_edit'] = "Edit";
$l['relatives_acp_delete'] = "Delete";
$l['relatives_acp_confirm_delete'] = "Really delete this entry?";
$l['relatives_acp_saved'] = "Saved successfully.";
$l['relatives_acp_deleted'] = "Deleted successfully.";
$l['relatives_acp_not_found'] = "Entry not found.";
$l['relatives_acp_error_required_fields'] = "Please fill in all required fields.";
$l['relatives_acp_inactive'] = "inactive";

// v2.1: Verbindungen, Familien, Verstorben, Verwandtschafts-Cache
$l['relatives_acp_field_line_style'] = 'Line style in the tree';
$l['relatives_acp_field_line_style_desc'] = 'How this connection is drawn in the tree. Separated couples get their own relationship (e.g. ex-wife) with a dashed line.';
$l['relatives_acp_line_style_solid'] = 'Solid';
$l['relatives_acp_line_style_dashed'] = 'Dashed';
$l['relatives_acp_line_style_dotted'] = 'Dotted';
$l['relatives_acp_line_style_dashdot'] = 'Dash-dot';
$l['relatives_acp_recompute_button'] = 'Recalculate relations';
$l['relatives_acp_recompute_desc'] = 'Recalculates for every tree how each person relates to the character (e.g. for the job list). Needed after updating and after changing relationship types.';
$l['relatives_acp_recompute_done'] = 'Recalculated the relation for {1} assignments.';

// v2.2: gemeinsames Geflecht
$l['relatives_relation_of'] = '{1} of {2}';
$l['relatives_tab_graph'] = 'Shared network';
$l['relatives_tab_desc_graph'] = 'Merges the former per-character lists into one shared family network and lists contradictions.';
$l['relatives_acp_graph_intro'] = 'Since version 2.2 connections belong to nobody: every statement exists once in the shared network, and every played character is a node itself. The merge links persons to accounts with the same name, merges persons with identical names, removes duplicate statements and collects contradictions below. The family tree is locked on the forum until it has run.';
$l['relatives_acp_graph_merged_at'] = 'Merged: {1}';
$l['relatives_acp_graph_not_merged'] = 'Not merged yet – the family tree is locked on the forum until then.';
$l['relatives_acp_graph_preview_title'] = 'Preview (nothing is changed yet)';
$l['relatives_acp_graph_preview_account_links'] = 'Persons linked to the account with the same name';
$l['relatives_acp_graph_preview_duplicates'] = 'Persons with identical names that are merged into one';
$l['relatives_acp_graph_preview_new_nodes'] = 'Characters that get their own node';
$l['relatives_acp_graph_preview_identical'] = 'Identical statements that are merged';
$l['relatives_acp_graph_preview_conflicts'] = 'Contradicting statements (go to the conflict list)';
$l['relatives_acp_graph_duplicates_hint'] = 'If placeholder names such as "Aunt" appear here, rename those persons first – otherwise different people become one entry.';
$l['relatives_acp_graph_col_person'] = 'Person';
$l['relatives_acp_graph_col_account'] = 'Account';
$l['relatives_acp_graph_col_ids'] = 'IDs';
$l['relatives_acp_graph_col_kept'] = 'Kept statement';
$l['relatives_acp_graph_col_discarded'] = 'Discarded statement';
$l['relatives_acp_graph_col_source'] = 'Entered by';
$l['relatives_acp_graph_run'] = 'Merge network now';
$l['relatives_acp_graph_rerun'] = 'Merge again';
$l['relatives_acp_graph_conflicts_title'] = 'Conflicts';
$l['relatives_acp_graph_no_conflicts'] = 'No open conflicts.';
$l['relatives_acp_graph_accept'] = 'Use this';
$l['relatives_acp_graph_discard'] = 'Discard';
$l['relatives_acp_graph_conflict_accepted'] = 'The statement has been applied.';
$l['relatives_acp_graph_conflict_discarded'] = 'The statement has been discarded.';
$l['relatives_acp_graph_report_title'] = 'Merge result';
$l['relatives_acp_graph_report_account_links'] = 'Persons linked to accounts';
$l['relatives_acp_graph_report_persons_merged'] = 'Persons with identical names merged';
$l['relatives_acp_graph_report_account_nodes_created'] = 'New character nodes';
$l['relatives_acp_graph_report_relations_kept'] = 'Connections kept';
$l['relatives_acp_graph_report_identical_removed'] = 'Identical statements merged';
$l['relatives_acp_graph_report_conflicts'] = 'Contradictions in the conflict list';
$l['relatives_acp_graph_report_self_removed'] = 'Meaningless self-references removed';
$l['relatives_acp_graph_guesses_title'] = 'Guessed assignments from the legacy migration';
$l['relatives_acp_graph_guesses_desc'] = 'The legacy migration only guessed these connections because a list contained exactly one possible related person (e.g. "cousin" -> child of the only aunt listed). In the shared network they apply to every family tree. Reverting turns them back into the original statement from the old list.';
$l['relatives_acp_graph_col_guess'] = 'Guessed connection';
$l['relatives_acp_graph_col_original'] = 'Becomes again';
$l['relatives_acp_graph_no_guesses'] = 'No guessed assignments left.';
$l['relatives_acp_graph_guesses_button'] = 'Revert guessed assignments';
$l['relatives_acp_graph_guesses_reverted'] = '{1} guessed assignments have been reverted.';
