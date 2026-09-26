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

// Alert-Texte
$l['myalerts_relatives_delete_request'] = '{from_user} möchte <strong>{person_name}</strong> komplett aus dem Stammbaum löschen. Bitte bestätige oder lehne ab.';
$l['myalerts_relatives_delete_completed'] = 'Alle haben zugestimmt: <strong>{person_name}</strong> wurde komplett aus dem Stammbaum gelöscht.';
$l['myalerts_relatives_delete_declined_user'] = '{from_user} möchte <strong>{person_name}</strong> behalten. Die Person bleibt im Stammbaum, wurde aber aus deiner Liste entfernt.';
$l['myalerts_relatives_delete_declined_team'] = 'Das Team hat deinen Löschantrag für <strong>{person_name}</strong> verworfen. Die Person bleibt im Stammbaum, wurde aber aus deiner Liste entfernt.';
$l['myalerts_relatives_delete_declined_played'] = 'Dein Löschantrag für <strong>{person_name}</strong> wurde abgebrochen: Die Person wird inzwischen gespielt.';
