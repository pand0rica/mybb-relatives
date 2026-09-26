<?php
/**
 * relatives - MyAlerts-Formatter
 *
 * Eine Instanz je Alert-Typ. Jeder Wert aus den ExtraDetails wird mit
 * htmlspecialchars_uni() escaped, bevor er in den Text kommt (ExtraDetails liegen roh
 * in der Datenbank -- sonst stored XSS). Nur laden, wenn MyAlerts vorhanden ist
 * (erbt von dessen AbstractFormatter).
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_MyAlerts_Formatter extends MybbStuff_MyAlerts_Formatter_AbstractFormatter
{
    /** @var string Alert-Typ-Code dieser Instanz (relatives_*) */
    private $relatives_code = '';

    public function __construct($mybb, $lang, $alertTypeName = '')
    {
        parent::__construct($mybb, $lang, $alertTypeName);
        $this->relatives_code = (string)$alertTypeName;
    }

    public function init()
    {
        if (!isset($this->lang->myalerts_setting_relatives_delete_request)) {
            $this->lang->load('relatives_myalerts');
        }
    }

    /**
     * @param MybbStuff_MyAlerts_Entity_Alert $alert
     * @param array                           $outputAlert
     * @return string
     */
    public function formatAlert(MybbStuff_MyAlerts_Entity_Alert $alert, array $outputAlert)
    {
        $details = (array)$alert->getExtraDetails();

        $lang_key = 'myalerts_' . $this->relatives_code;
        if ($this->relatives_code === 'relatives_delete_declined') {
            $reason = isset($details['reason']) && in_array($details['reason'], ['user', 'team', 'played'], true)
                ? $details['reason']
                : 'user';
            $lang_key .= '_' . $reason;
        }

        if (!isset($this->lang->{$lang_key}) || $this->lang->{$lang_key} === '') {
            $this->lang->load('relatives_myalerts');
        }
        if (!isset($this->lang->{$lang_key}) || $this->lang->{$lang_key} === '') {
            return '[' . htmlspecialchars_uni($this->relatives_code) . ']';
        }

        $message = (string)$this->lang->{$lang_key};

        // {from_user} liefert MyAlerts bereits formatiert (Profil-Link), nicht escapen.
        $from_user = isset($outputAlert['from_user']) ? (string)$outputAlert['from_user'] : '';
        $message = str_replace('{from_user}', $from_user, $message);

        $person_name = isset($details['person_name']) ? (string)$details['person_name'] : '';
        $message = str_replace('{person_name}', htmlspecialchars_uni($person_name), $message);

        return $message;
    }

    /**
     * Klickziel der Alert-Zeile (Glocken-Dropdown / Alert-Seite).
     *
     * @param MybbStuff_MyAlerts_Entity_Alert $alert
     * @return string
     */
    public function buildShowLink(MybbStuff_MyAlerts_Entity_Alert $alert)
    {
        $details = (array)$alert->getExtraDetails();
        $link = isset($details['link']) ? (string)$details['link'] : 'relatives.php?action=list';
        // Nur eigene Ziele zulassen -- der Link stammt zwar vom Plugin, liegt aber roh in der DB.
        if (strpos($link, 'relatives.php') !== 0) {
            $link = 'relatives.php?action=list';
        }

        return $this->mybb->settings['bburl'] . '/' . htmlspecialchars_uni($link);
    }
}
