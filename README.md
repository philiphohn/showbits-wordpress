# ShowBits für WordPress

Das WordPress-Plugin von ShowBits: freigegebene Clips und Zitatkarten eines Workspace in der Mediathek – in WordPress ausgewählt oder von Admins und Ownern aus ShowBits geschickt. Plan, Sicherheitsmodell und Entscheidungen: [`docs/wordpress.md`](../docs/wordpress.md) (D-Int-15).

Ein eigenes Teil-Repository dieses Repos: eigene Tests, eigener CI-Job (`wordpress`), eigenes ZIP. Abspalten, wenn es ein eigenes Repository werden soll:

```bash
git subtree split --prefix=wordpress -b wordpress-plugin
```

## Aufbau

| Pfad | Inhalt |
|---|---|
| `showbits/` | das Plugin, so wie es ins ZIP geht (`showbits.php`, `includes/`, `assets/`, `languages/`, `uninstall.php`, `readme.txt`) |
| `tests/` | PHPUnit gegen schlanke WordPress-Stubs (`tests/bootstrap.php`): Signatur, PKCE, Verschlüsselung, Adressen, API-Aufrufe, Push-Endpunkt, Import-Texte |
| `bin/i18n.php` | sammelt die Texte in `languages/showbits.pot` und macht aus den `.po` die PHP-Übersetzungen (`*.l10n.php`, WordPress 6.5+); scheitert bei fehlender Übersetzung |
| `bin/build.sh` | prüft (Versionen, Syntax, Übersetzungen, Tests) und schreibt `dist/showbits-<version>.zip` |

## Befehle

```bash
composer install
```

```bash
composer check
```

```bash
composer i18n
```

```bash
composer build
```

`composer check` = `php -l` über alle Dateien, Übersetzungen aktuell und vollständig, PHPUnit. Neue Texte: `__( '…', 'showbits' )` im Code, `composer i18n`, die deutsche Übersetzung in `showbits/languages/showbits-de_DE.po` ergänzen, noch einmal `composer i18n`.

**Version:** an drei Stellen gleich, sonst bricht `build.sh` ab: Kopf von `showbits.php` (`Version:`), `SHOWBITS_VERSION` dort, `Stable tag` in `readme.txt`.

## Installieren und verbinden

1. ZIP in WordPress hochladen (Plugins → Installieren → Plugin hochladen), aktivieren. WordPress 6.5+, PHP 8.1+, Website unter `https`.
2. Einstellungen → ShowBits: Adresse von ShowBits (Cloud `https://app.showbits.ai`, OnPremise die eigene) → **Mit ShowBits verbinden**.
3. In ShowBits als Admin oder Owner anmelden, Workspace wählen (Enterprise oder OnPremise), **Verbinden**. Zurück in WordPress steht „Verbunden“.
4. Medien → ShowBits: Clips und Zitatkarten auswählen, **Auswahl importieren**. In ShowBits unter Freigaben → Freigegeben schicken Admins und Owner einzelne Clips mit **An WordPress**.

Für Pushes muss ShowBits `https://<website>/wp-json/showbits/v1/push` erreichen, und WP-Cron muss laufen (am besten ein echter Cron-Job, der `wp-cron.php` aufruft).

## Sicherheit in Kürze

- Kein kopiertes Token: Authorization Code mit PKCE (S256), `state` je WordPress-Person, zehn Minuten (`includes/class-connection.php`).
- Das Token kann nur die Bibliothek lesen (`library:read`); Token und Push-Geheimnis liegen verschlüsselt in den Optionen (libsodium, Schlüssel `SHOWBITS_ENCRYPTION_KEY` in `wp-config.php` oder aus den Salts).
- Pushes zählen nur mit gültiger `ShowBits-Signature` (HMAC-SHA256, fünf Minuten), für diese Website und diesen Workspace, je Push-ID einmal (`includes/class-push-endpoint.php`).
- Dateien: erst den signierten Link bei ShowBits holen (ohne Weiterleitung, das Token geht nie an den Speicher), dann ohne Token laden, nur von öffentlichen Adressen (`wp_safe_remote_get`).
- Rechte: verbinden und einstellen `manage_options`, Bibliothek und Import `upload_files` (Filter `showbits_capability`); jede Aktion mit Nonce.

## Prüfen in einem echten WordPress

Die Unit-Tests ersetzen WordPress. Zwei Durchläufe gegen ein echtes WordPress (Docker: WordPress, MariaDB, WP-CLI; beide räumen hinterher alles weg):

- `bash tests/e2e/run.sh` mit einer nachgebauten ShowBits-API (braucht zusätzlich openssl, ffmpeg und Node mit Playwright): aktivieren, Tabelle, Seiten, Verbinden mit PKCE, Bibliothek, Import mit Poster und Alt-Text, signierter Push über WP-Cron mit Rückmeldung, Trennen. Bildschirmfotos in `tests/e2e/shots/`.
- `bash tests/e2e/plugin-check.sh [Pfad]` – [Plugin Check](https://wordpress.org/plugins/plugin-check/), die Prüfung des WordPress-Verzeichnisses (ohne Pfad lädt es Version 2.1.0 aus dem GitHub-Release): alle Kategorien, auch niedrige Schwere, statisch und zur Laufzeit; scheitert bei jedem Fehler und jeder Warnung. Läuft in CI.

Bilder kommen standardmäßig von Googles Spiegel des Docker Hub (`mirror.gcr.io/library`); `E2E_IMAGE_REGISTRY=docker.io/library` nimmt den Docker Hub selbst.

## Lizenz

GPL-2.0-or-later, wie es WordPress für Plugins vorsieht; gilt nur für dieses Verzeichnis.
