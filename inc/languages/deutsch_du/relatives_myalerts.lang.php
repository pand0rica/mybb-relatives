<?php
/**
 * relatives - MyAlerts-Sprachdatei (Deutsch, du-Form)
 *
 * myalerts_setting_* beschriftet die Abo-Checkboxen im UCP, myalerts_relatives_* sind
 * die Alert-Texte des Formatters (Platzhalter werden dort escaped).
 */

// UCP-Benachrichtigungseinstellungen
$l['myalerts_setting_relatives_delete_request'] = 'Jemand möchte einen NPC aus meiner Verwandtenliste komplett löschen';
$l['myalerts_setting_relatives_delete_completed'] = 'Ein von mir beantragter NPC wurde komplett gelöscht';
$l['myalerts_setting_relatives_delete_declined'] = 'Mein Löschantrag für einen NPC wurde abgelehnt';
$l['myalerts_setting_relatives_delete_withdrawn'] = 'Ein Löschantrag für einen NPC aus meiner Verwandtenliste wurde zurückgezogen';
$l['myalerts_setting_relatives_relation_request'] = 'Jemand möchte meinen Charakter als Verwandte*n eintragen';
$l['myalerts_setting_relatives_relation_accepted'] = 'Eine Verbindungsanfrage von mir wurde bestätigt';
$l['myalerts_setting_relatives_relation_declined'] = 'Eine Verbindungsanfrage von mir wurde abgelehnt';

// Alert-Texte
$l['myalerts_relatives_delete_request'] = '{from_user} möchte <strong>{person_name}</strong> komplett aus dem Stammbaum löschen. Bitte bestätige oder lehne ab.';
$l['myalerts_relatives_delete_completed'] = 'Alle haben zugestimmt: <strong>{person_name}</strong> wurde komplett aus dem Stammbaum gelöscht.';
$l['myalerts_relatives_delete_declined_user'] = '{from_user} möchte <strong>{person_name}</strong> behalten. Die Person bleibt im Stammbaum, wurde aber aus deiner Liste entfernt.';
$l['myalerts_relatives_delete_declined_team'] = 'Das Team hat deinen Löschantrag für <strong>{person_name}</strong> verworfen. Die Person bleibt im Stammbaum, wurde aber aus deiner Liste entfernt.';
$l['myalerts_relatives_delete_declined_played'] = 'Dein Löschantrag für <strong>{person_name}</strong> wurde abgebrochen: Die Person wird inzwischen gespielt.';
$l['myalerts_relatives_delete_withdrawn'] = '{from_user} hat den Löschantrag für <strong>{person_name}</strong> zurückgezogen. Du musst nichts mehr tun.';

$l['myalerts_relatives_relation_request'] = '{from_user} möchte eintragen: <strong>{relation_text}</strong>. Bitte bestätige oder lehne ab.';
$l['myalerts_relatives_relation_accepted'] = '{from_user} hat bestätigt: <strong>{relation_text}</strong>.';
$l['myalerts_relatives_relation_declined'] = '{from_user} hat abgelehnt: <strong>{relation_text}</strong>. Die Verbindung wurde nicht eingetragen.';
