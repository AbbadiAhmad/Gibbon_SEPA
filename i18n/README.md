# Translations of the SEPA module

The module texts are English in the code (`__m('English text')`) and are translated with the
gettext domain `Sepa`:

```
i18n/Sepa.pot                       template: all texts, empty translations
i18n/de_DE/LC_MESSAGES/Sepa.po      German (source)   -> Sepa.mo (compiled, used by Gibbon)
i18n/ar_SA/LC_MESSAGES/Sepa.po      Arabic (source)   -> Sepa.mo
```

A user sees the language chosen in Gibbon (user profile / system default); English users see the
English source text, so there is no English file. A text without a translation is shown in English.

## After adding or changing texts in the code

```bash
# 1. update the template
xgettext --language=PHP --keyword=__m --from-code=UTF-8 --add-comments -o i18n/Sepa.pot \
    $(ls *.php src/*.php src/*/*.php)

# 2. merge the new template into each language, then translate the new (empty) entries
msgmerge --update i18n/de_DE/LC_MESSAGES/Sepa.po i18n/Sepa.pot
msgmerge --update i18n/ar_SA/LC_MESSAGES/Sepa.po i18n/Sepa.pot

# 3. compile (the .mo files are what Gibbon reads, commit them)
msgfmt --check -o i18n/de_DE/LC_MESSAGES/Sepa.mo i18n/de_DE/LC_MESSAGES/Sepa.po
msgfmt --check -o i18n/ar_SA/LC_MESSAGES/Sepa.mo i18n/ar_SA/LC_MESSAGES/Sepa.po
```

Keep the placeholders (`%s`, `%d`, `%1$s`, `{number}`) exactly as in the English text.

## Another language

Create `i18n/<locale>/LC_MESSAGES/Sepa.po` with `msgmerge`/`msginit` from `Sepa.pot`
(the locale code is the one of the Gibbon language, e.g. `fr_FR`), translate and compile it.
The language has to be installed in Gibbon (System Admin > Language Settings).
