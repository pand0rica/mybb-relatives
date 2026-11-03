<?php
/**
 * relatives - MyAlerts-Anbindung (optionale Abhaengigkeit)
 *
 * Alles best effort: Fehlt MyAlerts oder passt die Version nicht, kehren die Methoden
 * still zurueck und das Plugin funktioniert ohne Benachrichtigungen weiter (offene
 * Loeschantraege stehen trotzdem unter "Meine Verwandten").
 * ExtraDetails werden ROH gespeichert -- der Formatter escaped bei der Ausgabe.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_Alerts
{
    public const CODE_DELETE_REQUEST = 'relatives_delete_request';
    public const CODE_DELETE_COMPLETED = 'relatives_delete_completed';
    public const CODE_DELETE_DECLINED = 'relatives_delete_declined';
    public const CODE_RELATION_REQUEST = 'relatives_relation_request';
    public const CODE_RELATION_ACCEPTED = 'relatives_relation_accepted';
    public const CODE_RELATION_DECLINED = 'relatives_relation_declined';

    /** Single source of truth fuer Installer, Formatter-Registrierung und Versand. */
    public static function codes(): array
    {
        return [
            self::CODE_DELETE_REQUEST,
            self::CODE_DELETE_COMPLETED,
            self::CODE_DELETE_DECLINED,
            self::CODE_RELATION_REQUEST,
            self::CODE_RELATION_ACCEPTED,
            self::CODE_RELATION_DECLINED,
        ];
    }

    public static function is_available(): bool
    {
        return class_exists('MybbStuff_MyAlerts_AlertTypeManager')
            && class_exists('MybbStuff_MyAlerts_AlertManager')
            && class_exists('MybbStuff_MyAlerts_Entity_Alert');
    }

    /** "X moechte <Person> komplett loeschen" an alle, die zustimmen muessen. */
    public static function send_delete_request(array $uids, int $request_id, string $person_name, int $from_uid = -1): int
    {
        return self::send(self::CODE_DELETE_REQUEST, $uids, [
            'person_name' => $person_name,
            'link'        => 'relatives.php?action=list#relatives_delete_requests',
        ], $request_id, $from_uid);
    }

    /** Abschluss an die antragstellende Person. */
    public static function send_delete_completed(int $uid, int $request_id, string $person_name): int
    {
        return self::send(self::CODE_DELETE_COMPLETED, [$uid], [
            'person_name' => $person_name,
            'link'        => 'relatives.php?action=list',
        ], $request_id, 0);
    }

    /**
     * Antrag geplatzt.
     *
     * @param string $reason user (abgelehnt) | team (im ACP verworfen) | played (inzwischen bespielt)
     */
    public static function send_delete_declined(int $uid, int $request_id, string $person_name, string $reason): int
    {
        return self::send(self::CODE_DELETE_DECLINED, [$uid], [
            'person_name' => $person_name,
            'reason'      => $reason,
            'link'        => 'relatives.php?action=list',
        ], $request_id, $reason === 'user' ? -1 : 0);
    }

    /** "X hat eingetragen: <Aussage>. Bitte bestaetigen." an die Spieler*in des Charakters. */
    public static function send_relation_request(int $uid, int $request_id, string $relation_text, int $from_uid): int
    {
        return self::send(self::CODE_RELATION_REQUEST, [$uid], [
            'relation_text' => $relation_text,
            'link'          => 'relatives.php?action=list#relatives_relation_requests',
        ], $request_id, $from_uid);
    }

    /** Antwort auf eine Verbindungsanfrage an die anfragende Person. */
    public static function send_relation_answer(int $uid, int $request_id, string $relation_text, bool $accepted): int
    {
        return self::send($accepted ? self::CODE_RELATION_ACCEPTED : self::CODE_RELATION_DECLINED, [$uid], [
            'relation_text' => $relation_text,
            'link'          => 'relatives.php?action=tree',
        ], $request_id, -1);
    }

    /**
     * Registriert die Alert-Typen. Best effort, still uebersprungen ohne MyAlerts.
     * Idempotent: vorhandene Typen werden nicht doppelt angelegt.
     */
    public static function register_types(): void
    {
        global $db, $cache;

        if (!class_exists('MybbStuff_MyAlerts_AlertTypeManager')
            || !class_exists('MybbStuff_MyAlerts_Entity_AlertType')) {
            return;
        }

        try {
            $manager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();
            if (!$manager) {
                $manager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
            }
            if (!$manager) {
                return;
            }

            $types = [];
            foreach (self::codes() as $code) {
                if ($manager->getByCode($code) !== null) {
                    continue;
                }
                $type = new MybbStuff_MyAlerts_Entity_AlertType();
                $type->setCode($code);
                $type->setEnabled(true);
                $type->setCanBeUserDisabled(true);
                $types[] = $type;
            }
            if ($types) {
                $manager->addTypes($types);
            }
        } catch (Throwable $e) {
            // Inkompatible MyAlerts-Version -- das Plugin bleibt ohne Alerts nutzbar.
        }
    }

    /**
     * Entfernt die Alert-Typen (nur bei uninstall, nicht bei deactivate: neu angelegte
     * Typen bekaemen neue ids, und MyAlerts verbindet Alerts per INNER JOIN mit dem Typ --
     * alle vorhandenen Alerts waeren dann unsichtbar).
     */
    public static function unregister_types(): void
    {
        global $db, $cache;

        if (!class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
            return;
        }

        try {
            $manager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();
            if (!$manager) {
                $manager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
            }
            if (!$manager) {
                return;
            }
            foreach (self::codes() as $code) {
                $type = $manager->getByCode($code);
                if ($type === null) {
                    continue;
                }
                // Erst die Alerts selbst -- MyAlerts laesst sie sonst verwaist liegen.
                $db->delete_query('alerts', 'alert_type_id = ' . (int)$type->getId());
                $manager->deleteById((int)$type->getId());
            }
        } catch (Throwable $e) {
            // Best effort, siehe register_types().
        }
    }

    /**
     * @param int $from_uid -1 = aktuelle*r Nutzer*in, 0 = ohne Absender*in
     * @return int Anzahl der an MyAlerts uebergebenen Empfaenger*innen
     */
    private static function send(string $code, array $uids, array $extra, int $object_id, int $from_uid): int
    {
        global $mybb, $db, $cache, $plugins;

        if (!self::is_available()) {
            return 0;
        }

        $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static function ($uid) {
            return $uid > 0;
        })));
        if (!$uids) {
            return 0;
        }
        if ($from_uid < 0) {
            $from_uid = (int)($mybb->user['uid'] ?? 0);
        }

        $sent = 0;
        try {
            $type_manager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();
            if (!$type_manager) {
                $type_manager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
            }
            $alert_manager = MybbStuff_MyAlerts_AlertManager::getInstance();
            if (!$alert_manager && $type_manager) {
                // MyAlerts legt seine Instanzen erst bei global_start an; im ACP bzw.
                // in fruehen Hooks (Account-Loeschung) tun wir das selbst.
                $alert_manager = MybbStuff_MyAlerts_AlertManager::createInstance($mybb, $db, $cache, $plugins, $type_manager);
            }
            if (!$type_manager || !$alert_manager) {
                return 0;
            }

            $alert_type = $type_manager->getByCode($code);
            if ($alert_type === null) {
                return 0;
            }

            foreach ($uids as $uid) {
                $alert = new MybbStuff_MyAlerts_Entity_Alert($uid, $alert_type, $object_id);
                if ($from_uid > 0) {
                    $alert->setFromUserId($from_uid);
                }
                $alert->setExtraDetails($extra);
                $alert_manager->addAlert($alert);
                $sent++;
            }

            // addAlert() reiht nur ein; MyAlerts schreibt die Warteschlange erst am Ende
            // eines Frontend-Requests. Direkt committen ist sicher (danach ist sie leer).
            $alert_manager->commit();
        } catch (Throwable $e) {
            // Inkompatible MyAlerts-Version -- nie die Seite wegen Alerts abbrechen.
        }

        return $sent;
    }
}
