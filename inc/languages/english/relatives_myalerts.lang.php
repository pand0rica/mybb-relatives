<?php
/**
 * relatives - MyAlerts language file (English)
 *
 * myalerts_setting_* labels the subscription checkboxes in the UCP, myalerts_relatives_*
 * are the formatter's alert texts (placeholders are escaped there).
 */

// UCP alert settings
$l['myalerts_setting_relatives_delete_request'] = 'Someone wants to delete an NPC from my relatives list completely';
$l['myalerts_setting_relatives_delete_completed'] = 'An NPC I requested to delete was deleted completely';
$l['myalerts_setting_relatives_delete_declined'] = 'My deletion request for an NPC was declined';
$l['myalerts_setting_relatives_delete_withdrawn'] = 'A deletion request for an NPC on my relatives list was withdrawn';
$l['myalerts_setting_relatives_relation_request'] = 'Someone wants to add my character as a relative';
$l['myalerts_setting_relatives_relation_accepted'] = 'A connection request of mine was confirmed';
$l['myalerts_setting_relatives_relation_declined'] = 'A connection request of mine was declined';

// Alert texts
$l['myalerts_relatives_delete_request'] = '{from_user} wants to delete <strong>{person_name}</strong> from the family tree completely. Please confirm or decline.';
$l['myalerts_relatives_delete_completed'] = 'Everyone agreed: <strong>{person_name}</strong> was deleted from the family tree completely.';
$l['myalerts_relatives_delete_declined_user'] = '{from_user} wants to keep <strong>{person_name}</strong>. The person stays in the family tree but was removed from your list.';
$l['myalerts_relatives_delete_declined_team'] = 'The team discarded your deletion request for <strong>{person_name}</strong>. The person stays in the family tree but was removed from your list.';
$l['myalerts_relatives_delete_declined_played'] = 'Your deletion request for <strong>{person_name}</strong> was cancelled: the person is now played by an account.';
$l['myalerts_relatives_delete_withdrawn'] = '{from_user} withdrew the deletion request for <strong>{person_name}</strong>. Nothing left for you to do.';

$l['myalerts_relatives_relation_request'] = '{from_user} wants to add: <strong>{relation_text}</strong>. Please confirm or decline.';
$l['myalerts_relatives_relation_accepted'] = '{from_user} confirmed: <strong>{relation_text}</strong>.';
$l['myalerts_relatives_relation_declined'] = '{from_user} declined: <strong>{relation_text}</strong>. The connection was not added.';
