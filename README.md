# ShowBits für WordPress

Das WordPress-Plugin von ShowBits: freigegebene Clips und Zitatkarten eines Workspace in der Mediathek – in WordPress ausgewählt oder von Admins und Ownern aus ShowBits geschickt. Plan, Sicherheitsmodell und Entscheidungen: [`docs/wordpress.md`](../docs/wordpress.md) (D-Int-15).

Ein eigenes Teil-Repository des App-Repositorys: eigene Tests, eigener CI-Job (`wordpress`), eigenes ZIP. Öffentlich als Kopie unter [github.com/philiphohn/showbits-wordpress](https://github.com/philiphohn/showbits-wordpress) (D-Int-21); dort fehlen die Dokumente des App-Repositorys, auf die dieses README verweist (`docs/wordpress.md`). Gearbeitet wird im App-Repository; nach jedem Merge mit Änderungen in `wordpress/` dort auf `main`:

```bash
git subtree split --prefix=wordpress -b wordpress-plugin
```

```bash
git push git@github.com:philiphohn/showbits-wordpress.git wordpress-plugin:main
```

Der Push ist immer ein Fast-Forward, solange Änderungen an `wordpress/` mit einem Merge-Commit nach `main` kommen (nicht Squash, nicht Rebase): Sonst bekommen die Commits neue Hashes und die Kopie passt nicht mehr. Nie mit `--force`.

## Aufbau

| Pfad | Inhalt |
|---|---|
| `showbits/` | das Plugin, so wie es ins ZIP geht (`showbits.php`, `includes/`, `assets/`, `uninstall.php`, `readme.txt`), ohne Übersetzungen |
| `languages/` | die Übersetzungen, nicht im Plugin (D-Int-20): je Locale `showbits-<locale>.po`, daraus `.l10n.php` und `.mo`, Vorlage `showbits.pot`; der Bestand für translate.wordpress.org, im echten WordPress dort, wo Sprachpakete landen |
| `tests/` | PHPUnit gegen schlanke WordPress-Stubs (`tests/bootstrap.php`): Signatur, PKCE, Verschlüsselung und eigene Salts, Adressen, API-Aufrufe, Push-Endpunkt mit Paarungswerten je Versuch, Import-Texte, ganze Downloads |
| `bin/i18n.php` | sammelt die Texte (auch die Beschreibung im Kopf von `showbits.php`) in `languages/showbits.pot`, bringt jede `showbits-<locale>.po` auf diesen Stand und macht daraus `*.l10n.php` (WordPress 6.5+) und `*.mo`; prüft jede Übersetzung (Platzhalter, Namen, Adressen, Pfeile, Kopf mit den Pluralformen von translate.wordpress.org) und scheitert, solange etwas fehlt oder nicht stimmt |
| `bin/build.sh` | prüft (Versionen, Syntax, Übersetzungen, Tests) und schreibt `dist/showbits-<version>.zip` |
| `.wordpress-org/` | Bilder für die Seite im WordPress-Verzeichnis, nicht im Plugin: `icon-128x128.png` und `icon-256x256.png` (aus `public/icon-512.png` der Website); nach der Freigabe ins SVN des Plugins unter `assets/`, Banner und Screenshots kommen dazu |
| `LICENSE` | GPL-2.0, der Text zur Lizenz im Kopf jeder Datei |

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

`composer check` = `php -l` über alle Dateien, Übersetzungen aktuell, vollständig und heil, PHPUnit (10.5, für PHP 8.1 gesperrt).

**Übersetzungen:** 68 Sprachen und Varianten mit den Locale-Codes von WordPress, je eine `languages/showbits-<locale>.po` ([`docs/wordpress.md`](../docs/wordpress.md) §5.4: Anrede, Menüpfade, Schreibweisen). Das Plugin bringt sie nicht mit: WordPress holt Übersetzungen als Sprachpakete von translate.wordpress.org, und diese Dateien sind der Bestand, der dort eingespielt wird (D-Int-20). Neue Texte: `__( '…', 'showbits' )` im Code, `composer i18n` (der neue Text steht dann leer in jeder `.po`), in allen `.po` übersetzen, noch einmal `composer i18n`. Eine einzelne Datei prüfen, ohne etwas zu schreiben:

```bash
php bin/i18n.php --lint languages/showbits-fr_FR.po
```

**Version:** an drei Stellen gleich, sonst bricht `build.sh` ab: Kopf von `showbits.php` (`Version:`), `SHOWBITS_VERSION` dort, `Stable tag` in `readme.txt`.

## Installieren und verbinden

1. ZIP aus ShowBits laden (Integrationen → WordPress → **Plugin herunterladen**, oder `composer build`) und in WordPress hochladen (Plugins → Installieren → Plugin hochladen), aktivieren. WordPress 6.5+, PHP 8.1+, Website unter `https`, für ShowBits erreichbar.
2. In ShowBits erzeugt ein Admin oder Owner des Workspace unter Integrationen → WordPress einen **Site-Key** für die Adresse der Website (24 Stunden, einmal).
3. In WordPress unter Einstellungen → ShowBits: Adresse von ShowBits (Cloud `https://app.showbits.ai`, OnPremise die eigene), Site-Key einfügen, **Verbinden**. Wer selbst Admin oder Owner in ShowBits ist, kann stattdessen **Über ShowBits verbinden** wählen und dort zustimmen.
4. Medien → ShowBits: Clips und Zitatkarten auswählen, **Auswahl importieren**; ShowBits schickt sie der Website. In ShowBits unter Freigaben → Freigegeben schicken Admins und Owner einzelne Clips mit **An WordPress**.

Jeder Tarif (Creator, Business, Enterprise, OnPremise) hat das Plugin, ohne Zugang zur REST-API; der Tarif begrenzt Websites je Workspace und Importe am Tag. ShowBits muss `https://<website>/wp-json/showbits/v1/push` erreichen: Auch was in der Bibliothek gewählt wird, kommt als Push. WP-Cron sollte laufen (am besten ein echter Cron-Job, der `wp-cron.php` aufruft); Importe aus der Bibliothek laufen auch ohne, solange die Seite offen ist.

## Sicherheit in Kürze

- Ein Site-Key gilt einmal, 24 Stunden, nur für den Host, für den er erzeugt wurde; aus der Zustimmungsseite zehn Minuten und nur mit dem PKCE-Verifier dieses Versuchs (`includes/class-connection.php`).
- Beim Verbinden fragt ShowBits an der Push-Adresse nach dem HMAC einer frischen Challenge unter einem Zufallswert, den nur diese Website gerade hat (`includes/class-push-endpoint.php`): Ein kopierter Site-Key verbindet anderswo nichts.
- Danach hat die Website einen eigenen Schlüssel, der nur die Routen des Plugins öffnet (`/api/wordpress/v1`, nicht die REST-API), und ein Push-Geheimnis; beide liegen verschlüsselt in den Optionen (libsodium, Schlüssel `SHOWBITS_ENCRYPTION_KEY` in `wp-config.php` oder aus den Salts).
- Pushes zählen nur mit gültiger `ShowBits-Signature` (HMAC-SHA256, fünf Minuten), für diese Website und diesen Workspace, je Push-ID einmal. Jeder Push bringt ein Ticket für die Dateien seines Clips; ohne Ticket gibt ShowBits keine Datei heraus.
- Dateien: erst den signierten Link bei ShowBits holen (mit Schlüssel und Ticket), dann ohne Schlüssel laden, nur von öffentlichen Adressen (`wp_safe_remote_get`).
- Rechte: verbinden und einstellen `manage_options`, Bibliothek und Import `upload_files` (Filter `showbits_capability`); jede Aktion mit Nonce.

## Prüfen in einem echten WordPress

Die Unit-Tests ersetzen WordPress. Zwei Durchläufe gegen ein echtes WordPress (Docker: WordPress, MariaDB, WP-CLI; beide räumen hinterher alles weg):

- `bash tests/e2e/run.sh` mit einem nachgebauten ShowBits (braucht zusätzlich openssl, ffmpeg und Node mit Playwright): aktivieren, Tabelle, Seiten, Verbinden mit Site-Key und über die Zustimmungsseite (PKCE), jeweils mit der Prüfung an der Push-Adresse, Bibliothek (ohne Clips unter Embargo, Markup als Text), Import als Push mit Ticket, Poster und Alt-Text, Texte als reiner Text, signierter Push über WP-Cron mit Rückmeldung (auch wenn die erste verloren geht), abgeschnittener Download, gesperrter Clip, alle Übersetzungen als Sprachpakete so, wie WordPress sie liest (`.l10n.php` und `.mo` gleich, Sprache, Pluralformen), Einstellungen und Bibliothek in Sprachen von rechts nach links und mit langen Texten, breit und schmal, Trennen (45 Prüfungen), auf WordPress 7.1 (Images mit Digest). Bildschirmfotos in `tests/e2e/shots/`. Läuft auch in CI.
- `bash tests/e2e/plugin-check.sh [Pfad]` – [Plugin Check](https://wordpress.org/plugins/plugin-check/), die Prüfung des WordPress-Verzeichnisses (ohne Pfad lädt es Version 2.1.0 aus dem GitHub-Release): alle Kategorien, auch niedrige Schwere, statisch und zur Laufzeit; scheitert bei jedem Fehler und jeder Warnung. Läuft in CI.

Bilder kommen standardmäßig von Googles Spiegel des Docker Hub (`mirror.gcr.io/library`); `E2E_IMAGE_REGISTRY=docker.io/library` nimmt den Docker Hub selbst.

## Lizenz

GPL-2.0-or-later, wie es WordPress für Plugins vorsieht (von Legal bestätigt am 2026-11-01); gilt nur für dieses Verzeichnis.
