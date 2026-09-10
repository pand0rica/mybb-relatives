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
$l['relatives_tab_desc_attributes'] = "Manage attribute groups and their options (e.g. hair colour, eye colour) that can be assigned to a person.";
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
$l['relatives_acp_add_relationship_type'] = "Add relationship type";
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
$l['relatives_acp_edit_group'] = "Edit group";
$l['relatives_acp_add_option'] = "Add option";
$l['relatives_acp_edit_option'] = "Edit option";
$l['relatives_acp_col_label'] = "Label";
$l['relatives_acp_col_actions'] = "Actions";
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
$l['relatives_acp_migration_no_tables'] = "None of the legacy tables (relatives, wanted, relatives_options) were found.";
$l['relatives_acp_migration_report_summary'] = "Created %d relationship types, %d attribute groups and %d attribute options. Migrated %d entries from \"relatives\" and %d entries from \"wanted\". %d rows were skipped (invalid/incomplete data).";
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
