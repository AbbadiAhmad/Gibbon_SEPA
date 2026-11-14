<?php
/*
 * Translation of the module texts.
 *
 * The module texts are translated with the gettext domain "Sepa", from
 * modules/Sepa/i18n/<locale>/LC_MESSAGES/Sepa.mo (compiled from Sepa.po).
 * Gibbon provides __m() for this; the function below is only used when the Gibbon version has none.
 * Without a translation of the user's language the English text is shown.
 */
if (!function_exists('__m')) {
    function __m($text, $params = [], $options = [])
    {
        static $bound = false;
        if (!$bound) {
            if (function_exists('bindtextdomain')) {
                bindtextdomain('Sepa', dirname(__DIR__) . '/i18n');
                bind_textdomain_codeset('Sepa', 'UTF-8');
            }
            $bound = true;
        }

        $translated = function_exists('dgettext') ? dgettext('Sepa', $text) : $text;
        foreach ((array) $params as $key => $value) {
            $translated = str_replace('{' . $key . '}', (string) $value, $translated);
        }
        return $translated;
    }
}
