<?php
/*
 * Translation of the module texts.
 *
 * The module texts are translated with the gettext domain "Sepa", from
 * modules/Sepa/i18n/<locale>/LC_MESSAGES/Sepa.mo (compiled from Sepa.po).
 * Gibbon provides __m() for this; the function below is only used when the Gibbon version has none.
 * Without a translation of the user's language the English text is shown.
 */
// Gibbon core declares __m() unconditionally in its functions.php. That file is often loaded AFTER this one
// (moduleFunctions.php is required before gibbon.php), so function_exists() alone is not enough:
// declaring the fallback first would cause "Cannot redeclare __m()". Only declare it when the core has none.
$sepaCoreFunctions = dirname(__DIR__, 3) . '/functions.php';
$sepaCoreHasM = is_file($sepaCoreFunctions) && strpos((string) file_get_contents($sepaCoreFunctions), 'function __m(') !== false;
if (!$sepaCoreHasM && !function_exists('__m')) {
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
