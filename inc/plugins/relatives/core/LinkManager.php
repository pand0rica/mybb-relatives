<?php
/**
 * relatives - LinkManager
 *
 * Verknuepfungs-/Bestaetigungs-Workflow zwischen einer relatives_persons-Zeile und
 * einem echten Account (linked_uid). Siehe PROJECT_CONTEXT.md "Konto-Verknuepfung &
 * Bestaetigungs-Workflow" und die globale CLAUDE.md "Sending PMs from a plugin" --
 * PMs werden bewusst NICHT ueber send_pm() verschickt, sondern nativ ueber
 * PMDataHandler + manuelles Feuern von private_do_send_end, damit Account-Switcher/
 * MyAlerts/Tapatalk reagieren.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_LinkManager
{
    /** link_status-Werte auf relatives_persons. */
    public const STATUS_NONE      = 0;
    public const STATUS_REQUESTED = 1;
    public const STATUS_CONFIRMED = 2;

    /**
     * Stellt eine Verknuepfungsanfrage: setzt linked_uid/link_status=1 und verschickt
     * eine PM an den Ziel-Account. Nur der Owner der Person darf anfragen.
     */
    public static function request_link(int $person_id, int $requester_uid, int $target_uid): bool
    {
        global $db;

        $person = Relatives_Database::get_person($person_id);
        if ($person === null) {
            return false;
        }
        if ((int)$person['owner_uid'] !== $requester_uid) {
            return false; // nur der Owner der Person darf verknuepfen
        }
        if ($target_uid <= 0 || $target_uid === $requester_uid) {
            return false;
        }

        $target = $db->fetch_array($db->simple_select('users', 'uid, username', "uid = " . (int)$target_uid, ['limit' => 1]));
        if (!$target) {
            return false;
        }

        Relatives_Database::update_person($person_id, [
            'linked_uid'        => $target_uid,
            'link_status'       => self::STATUS_REQUESTED,
            'link_requested_by' => $requester_uid,
            'link_confirmed_at' => null,
        ]);

        self::send_link_request_pm((string)$target['username'], (string)$person['name'], $requester_uid);

        return true;
    }

    /**
     * Nimmt eine Verknuepfungsanfrage an -- nur der referenzierte linked_uid-Account
     * selbst darf das. Setzt link_status=2, link_confirmed_at, und erzwingt is_wanted=0
     * (Rolle ist jetzt besetzt).
     */
    public static function accept_link(int $person_id, int $acting_uid): bool
    {
        $person = Relatives_Database::get_person($person_id);
        if ($person === null) {
            return false;
        }
        if ((int)$person['link_status'] !== self::STATUS_REQUESTED) {
            return false;
        }
        if (empty($person['linked_uid']) || (int)$person['linked_uid'] !== $acting_uid) {
            return false;
        }

        Relatives_Database::update_person($person_id, [
            'link_status'       => self::STATUS_CONFIRMED,
            'link_confirmed_at' => TIME_NOW,
            'is_wanted'         => 0,
        ]);

        return true;
    }

    /**
     * Lehnt eine Verknuepfungsanfrage ab -- nur der referenzierte linked_uid-Account
     * selbst darf das. Setzt linked_uid/link_status zurueck auf "keine".
     */
    public static function decline_link(int $person_id, int $acting_uid): bool
    {
        $person = Relatives_Database::get_person($person_id);
        if ($person === null) {
            return false;
        }
        if ((int)$person['link_status'] !== self::STATUS_REQUESTED) {
            return false;
        }
        if (empty($person['linked_uid']) || (int)$person['linked_uid'] !== $acting_uid) {
            return false;
        }

        Relatives_Database::update_person($person_id, [
            'linked_uid'        => null,
            'link_status'       => self::STATUS_NONE,
            'link_requested_by' => null,
            'link_confirmed_at' => null,
        ]);

        return true;
    }

    /**
     * Sendet die native PM ueber PMDataHandler und feuert private_do_send_end selbst,
     * damit Notification-Plugins reagieren (siehe globale CLAUDE.md). Fehler beim
     * PM-Versand duerfen den Verknuepfungsvorgang selbst nicht abbrechen -- der
     * link_status wurde bereits gesetzt, ein PM-Fehlschlag ist kein Grund, den ganzen
     * Request rueckgaengig zu machen.
     */
    private static function send_link_request_pm(string $target_username, string $person_name, int $requester_uid): void
    {
        global $mybb, $lang, $session, $plugins, $pm, $pmhandler, $pminfo;

        if ($target_username === '') {
            return;
        }

        $lang->load('relatives');

        require_once MYBB_ROOT . 'inc/datahandlers/pm.php';

        $subject = sprintf($lang->relatives_pm_link_request_subject, $person_name);
        $message = sprintf(
            $lang->relatives_pm_link_request_message,
            $person_name,
            $mybb->settings['bburl'] . '/relatives.php?action=list'
        );

        $pm = [
            'subject' => $subject,
            'message' => $message,
            'icon'    => -1,
            'fromid'  => $requester_uid,
            'do'      => '',
            'pmid'    => 0,
            'to'      => [$target_username],
            'bcc'     => [],
            'options' => [
                'signature'     => 0,
                'disablesmilies' => 0,
                'savecopy'      => 0,
                'readreceipt'   => 0,
            ],
            'saveasdraft' => 0,
        ];
        if (is_object($session)) {
            $pm['ipaddress'] = $session->packedip;
        }

        $pmhandler = new PMDataHandler();
        $pmhandler->admin_override = true;
        $pmhandler->set_data($pm);
        if ($pmhandler->validate_pm()) {
            $pminfo = $pmhandler->insert_pm();
            // A broken listener on private_do_send_end (e.g. a third-party alert plugin)
            // must not turn the requester's link-request action into a fatal error --
            // the PM itself is already inserted at this point.
            try {
                $plugins->run_hooks('private_do_send_end');
            } catch (\Throwable $e) {
                // Intentionally swallowed: see comment above.
            }
        }
    }
}
