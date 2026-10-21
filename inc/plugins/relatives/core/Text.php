<?php
/**
 * relatives - Text
 *
 * Kleine Text-Helfer rund um 4-Byte-Zeichen (Emojis). Die Plugin-Tabellen laufen
 * bewusst mit utf8 (3-Byte, siehe Installer) -- MySQL ersetzt dort jedes Zeichen
 * ausserhalb der BMP (z.B. 💎) durch "?". Deshalb werden solche Zeichen vor dem
 * Speichern als numerische HTML-Entity (&#128142;) abgelegt. htmlspecialchars_uni()
 * laesst diese Entities stehen, die HTML-Ausgabe zeigt also wieder das Emoji.
 *
 * Fuer das JSON des optischen Stammbaums, das per textContent gerendert wird, werden
 * die Entities wieder in echte Zeichen zurueckverwandelt.
 */

if (!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

class Relatives_Text
{
    /**
     * Ersetzt alle 4-Byte-UTF-8-Zeichen durch &#NNN;. Mit $max_length wird danach auf
     * so viele Zeichen gekuerzt, ohne eine Entity mittendrin abzuschneiden.
     */
    public static function encode_4byte(string $text, int $max_length = 0): string
    {
        $text = (string)preg_replace_callback('/[\x{10000}-\x{10FFFF}]/u', function (array $match): string {
            return '&#' . mb_ord($match[0], 'UTF-8') . ';';
        }, $text);

        if ($max_length > 0 && mb_strlen($text) > $max_length) {
            $text = mb_substr($text, 0, $max_length);
            // Halbe Entity am Ende ("&#1281") wieder entfernen.
            $text = (string)preg_replace('/&#?[0-9]*$/', '', $text);
        }
        return $text;
    }

    /**
     * Wandelt numerische Entities (dezimal und hex) in echte Zeichen zurueck --
     * rekursiv fuer ganze Arrays. Nur Zeichen ab U+00A0: so entstehen aus Entities
     * nie <, >, &, " oder ' und keine Steuerzeichen.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function decode_numeric_entities($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::decode_numeric_entities($item);
            }
            return $value;
        }
        if (!is_string($value) || strpos($value, '&#') === false) {
            return $value;
        }

        return preg_replace_callback('/&#(?:([0-9]{1,7})|[xX]([0-9a-fA-F]{1,6}));/', function (array $match): string {
            $code = (isset($match[2]) && $match[2] !== '') ? (int)hexdec($match[2]) : (int)$match[1];
            if ($code < 0xA0 || $code > 0x10FFFF || ($code >= 0xD800 && $code <= 0xDFFF)) {
                return $match[0];
            }
            return mb_chr($code, 'UTF-8');
        }, $value);
    }
}
