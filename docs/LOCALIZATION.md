# Localization

Geek Cube Studio uses WordPress' native gettext catalogues. The first shipped locale is Brazilian Portuguese (`pt_BR`). The source code stays in English and uses the `geek-cube-studio` text domain, so new locales remain possible without changing storage values, routes, slugs, or API identifiers.

## Files

- `languages/geek-cube-studio.pot` is the source template.
- `languages/geek-cube-studio-pt_BR.po` is the editable Brazilian Portuguese translation.
- `languages/geek-cube-studio-pt_BR.mo` is the compiled file loaded by WordPress.

## Updating a catalogue

After changing a user-facing PHP string, run these commands from the plugin root:

```powershell
php tools\i18n.php make-pot
php tools\i18n.php sync-pt-br
# Edit languages\geek-cube-studio-pt_BR.po and translate any new empty entries.
php tools\i18n.php compile
php tools\i18n.php verify
```

`sync-pt-br` preserves existing translations and adds new source strings with an empty translation. The normal `php tools\build.php` release flow runs `verify`, so it stops before packaging when the POT, PO, MO, and source code do not match.

Polylang remains responsible for public editorial languages and route domains/directories. It does not replace WordPress' locale system for this plugin's administration or player interface.
