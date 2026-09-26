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

// Alert texts
$l['myalerts_relatives_delete_request'] = '{from_user} wants to delete <strong>{person_name}</strong> from the family tree completely. Please confirm or decline.';
$l['myalerts_relatives_delete_completed'] = 'Everyone agreed: <strong>{person_name}</strong> was deleted from the family tree completely.';
$l['myalerts_relatives_delete_declined_user'] = '{from_user} wants to keep <strong>{person_name}</strong>. The person stays in the family tree but was removed from your list.';
$l['myalerts_relatives_delete_declined_team'] = 'The team discarded your deletion request for <strong>{person_name}</strong>. The person stays in the family tree but was removed from your list.';
$l['myalerts_relatives_delete_declined_played'] = 'Your deletion request for <strong>{person_name}</strong> was cancelled: the person is now played by an account.';
