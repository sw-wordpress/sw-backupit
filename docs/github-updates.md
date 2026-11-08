# Verifizierte manuelle GitHub-Updates

Stand: 8. November 2026. Temporärer Vertriebsweg bis zu einem Store-Eintrag. Adaptiert aus der Referenz
[churchtools-suite@5f81c09](https://github.com/sw-wordpress/churchtools-suite/tree/5f81c09) (`docs/GITHUB-UPDATES*.md`); eigene
Namen, Repository-ID und Schlüssel. **Status: Code vorhanden, aber nicht produktiv aktiv.** Ohne öffentlichen Schlüssel im Plugin,
geschützte GitHub-Umgebung und einen ersten signierten Release bleibt der Provider gesperrt (kein Update wird angeboten).

## Verhalten

- Updates erscheinen in der nativen Pluginübersicht (Update URI `https://github.com/sw-wordpress/sw-backupit`). Installiert wird nur über
  WordPress' Einzel-, AJAX- oder Sammelupdate; Automatik (`auto_update_plugin`) ist für dieses Plugin immer aus, auch für alte gecachte Angebote.
- Prüfung gegen GitHub nur in berechtigten interaktiven Backend-Sitzungen (`update_plugins`, Multisite: Netzwerkrechte, auch über
  `admin-ajax.php`): höchstens einmal je 24 h, erst nach dem Seitenaufbau; Fehler zählen als Versuch. Der Zeitstempel liegt in einer
  Option, Transient-Löschung hebt ihn nicht auf. Manuell über „Jetzt auf Updates prüfen“ (Zeile des Plugins in der Pluginübersicht) mit Nonce und mindestens 60 s Abstand.
  Kein GitHub-Abruf im Frontend, Cron, REST oder WP-CLI. Anzeigen lesen nur den lokalen Cache (höchstens 7 Tage gültig).
- Vertrauensanker sind ausschließlich öffentliche Ed25519-Schlüssel in `packages/wordpress/src/Update/Config/trusted-keys.php`
  (optional überschreibbar durch die Konstante `SWBACKUPIT_RELEASE_PUBLIC_KEYS`; keine gemeinsame globale Liste). Ohne Schlüssel, Signatur oder
  Sodium/ZipArchive: gesperrt, kein Hash-only-Fallback.
- Signiert werden die exakten Manifestbytes (`sw-plugin-release.json` + `.sig`). Bindung an Repository und numerische ID (`1432510453`), Release-ID/Tag,
  Asset-ID, Dateiname `sw-backupit-<version>.zip`, Slug, Version, Größe, SHA-256; doppelte JSON-Schlüssel, Prereleases/Drafts, Rollback
  (höchste gesehene Version) und Systemvoraussetzungen werden abgelehnt.
- Vor der Übergabe an WordPress wird das Manifest erneut verifiziert und das ZIP geprüft: Hash, Größe, genau ein Wurzelordner `sw-backupit/`,
  sichere Pfade, nur Dateien/Verzeichnisse (keine Symlinks), keine Duplikate (case-insensitiv), Entpacklimits, Plugin-Header = Manifest.
  Es gibt keine eigene Entpack-/Kopierinstallation; temporäre Dateien werden bereinigt.
- Lock: atomar (Compare-and-Delete), Lease 120 s, abgelaufene Locks sind übernehmbar, ein alter Besitzer kann weder freigeben noch veröffentlichen.
- Deaktivieren entfernt zuerst eigene Angebote und die Auto-Update-Auswahl (Fremdes bleibt), Deinstallieren auch Zustand/Lock/Migrationsflag.
- Es gab vor dieser Umsetzung keinen Updater/Installer im Plugin; die einmalige Migration (`admin_init`) räumt nur eigene Angebote und die Auto-Update-Auswahl.

Dateien: `packages/wordpress/src/Update/{Manifest,Transport,Package,Store,Service,Provider}.php`, `Config/{source,descriptors,trusted-keys}.php`,
`assets/js/swbackupit-updates.js`, `bin/release-manifest.php`, `.github/workflows/main.yml` (Release) und `tests.yml` (PR-Tests).
Namen: Optionen `swbi_verified_github_updates_v1`/`…_lock_v1`/`swbi_verified_updates_migrated`, AJAX `swbi_check_github_updates`, Nonce `swbi_verified_updates`,
Filter `swbi_verified_release_public_keys`/`swbi_verified_update_descriptors`, Skript `swbi-verified-updates`, JS-Global `swbiVerifiedUpdates`, DOM `swbi-verified-updates-*`.

## Einmalige Betreiber-Einrichtung

Reihenfolge: (1) Schlüssel erzeugen und öffentlichen Teil ins Plugin übernehmen, (2) GitHub-Umgebung `plugin-releases` einrichten, (3) öffentlichen Schlüssel
**vorab** an bestehende Installationen verteilen, (4) erst dann taggen. Der erste Release darf seinen eigenen Schlüssel nicht legitimieren.

Die Befehle sind für Bash/Zsh auf dem lokalen Entwicklungs-/Release-Rechner (PHP mit Sodium), nicht für den WordPress-Webserver. Der Checkout liegt unter
`/var/www/html/wordpress_dev/wp-content/plugins/sw-backupit`. Private Dateien liegen ausschließlich unter `$HOME/.local/share/sw-backupit-release-keys`
(Rechte 0700/0600), außerhalb von Checkout und Webroot; separat verschlüsselt sichern, nie in Git, ZIPs oder synchronisierte Ordner. Die Blöcke wurden mit
Wegwerfschlüsseln geprüft; produktive Schlüssel erzeugt diese Dokumentation nicht.

**1. Einmalig Schlüssel erzeugen.** Verweigert einen vorhandenen Ordner (kein Überschreiben). Die Key-ID ist öffentlich; bei Rotation neue ID und neuen Ordner verwenden.

```bash
(
  set -eu
  umask 077
  export SWBI_RELEASE_KEYS_DIR="$HOME/.local/share/sw-backupit-release-keys"
  php <<'PHP'
<?php
declare(strict_types=1);
if (!function_exists('sodium_crypto_sign_keypair')) {
    throw new RuntimeException('PHP-Sodium fehlt.');
}
$dir = getenv('SWBI_RELEASE_KEYS_DIR');
if (!$dir || file_exists($dir) || is_link($dir)) {
    throw new RuntimeException('Schlüsselordner existiert bereits oder Pfad fehlt. Keine Dateien verändert.');
}
if (!mkdir($dir, 0700, true)) {
    throw new RuntimeException('Schlüsselordner konnte nicht angelegt werden.');
}
$id = 'sw-backupit-2026-11';
$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);
$files = [
    'signing-key.base64' => base64_encode($secret) . "\n",
    'public-keys.php' => "<?php\nreturn " . var_export([$id => base64_encode($public)], true) . ";\n",
    'key-id.txt' => $id . "\n",
];
foreach ($files as $name => $contents) {
    $handle = fopen($dir . '/' . $name, 'x');
    if (!$handle || fwrite($handle, $contents) !== strlen($contents)) {
        throw new RuntimeException('Schlüsseldatei konnte nicht vollständig geschrieben werden.');
    }
    fclose($handle);
}
sodium_memzero($secret);
sodium_memzero($pair);
echo "Schlüsseldateien erzeugt; privater Schlüssel wurde nicht ausgegeben.\n";
PHP
)
```

**2. Nur den öffentlichen Schlüssel ins Plugin übernehmen.** Erhält vorhandene öffentliche Schlüssel, lehnt eine abweichende Belegung derselben Key-ID ab; kein privater Schlüssel gelangt in eine Umgebungsvariable oder nach WordPress.

```bash
(
  set -eu
  export SWBI_PLUGIN_DIR='/var/www/html/wordpress_dev/wp-content/plugins/sw-backupit'
  export SWBI_RELEASE_KEYS_DIR="$HOME/.local/share/sw-backupit-release-keys"
  php <<'PHP'
<?php
declare(strict_types=1);
$target = getenv('SWBI_PLUGIN_DIR') . '/packages/wordpress/src/Update/Config/trusted-keys.php';
$existing = require $target;
$incoming = require getenv('SWBI_RELEASE_KEYS_DIR') . '/public-keys.php';
if (!is_array($existing) || !is_array($incoming) || count($incoming) !== 1) {
    throw new RuntimeException('Ungültiger öffentlicher Schlüsselring.');
}
foreach ($incoming as $id => $key) {
    $decoded = is_string($key) ? base64_decode($key, true) : false;
    if (!is_string($decoded) || strlen($decoded) !== 32 || (isset($existing[$id]) && $existing[$id] !== $key)) {
        throw new RuntimeException('Ungültiger öffentlicher Schlüssel oder widersprüchliche Key-ID.');
    }
    $existing[$id] = $key;
}
$contents = "<?php\n/** Public Ed25519 verification keys. */\nreturn " . var_export($existing, true) . ";\n";
if (file_put_contents($target, $contents, LOCK_EX) !== strlen($contents)) {
    throw new RuntimeException('Öffentlicher Schlüsselring konnte nicht geschrieben werden.');
}
echo "Öffentlicher Schlüsselring aktualisiert.\n";
PHP
  php -l "$SWBI_PLUGIN_DIR/packages/wordpress/src/Update/Config/trusted-keys.php"
  git -C "$SWBI_PLUGIN_DIR" diff -- packages/wordpress/src/Update/Config/trusted-keys.php
)
```

Die angezeigte Änderung darf ausschließlich den öffentlichen Schlüssel enthalten; nach Review regulär committen.

**3. GitHub-Secret und Key-ID selbst im Browser hinterlegen.** Das übernimmt ausschließlich der Betreiber; weder dieses Dokument noch ein Harness führt dafür CLI-/API-Befehle aus.

Öffne [sw-wordpress/sw-backupit → Settings → Environments](https://github.com/sw-wordpress/sw-backupit/settings/environments) (Repository-Administratorrechte nötig).
Wähle `plugin-releases` oder lege die Umgebung mit genau diesem Namen über **New environment** an. Richte zuerst die Freigaben (**Required reviewers**) und unter
**Deployment branches and tags** die erlaubten Release-Tags ein (Muster `v*` und `[0-9]*`, keine Branches). Der Release-Workflow bricht ohne diese Umgebung nicht ab,
ist dann aber ungeschützt – daher vor dem ersten Tag einrichten.

Öffne lokal `~/.local/share/sw-backupit-release-keys/signing-key.base64` in einem vertrauenswürdigen Editor und kopiere die eine Base64-Zeile ohne Anführungszeichen
und Leerzeichen. Unter **Environment secrets → Add secret**: Name exakt `SW_PLUGIN_RELEASE_SIGNING_KEY`, Wert = diese Zeile (privater 64-Byte-Sodium-Schlüssel, Base64;
nicht der öffentliche Schlüssel, nicht die PHP-Datei, kein GitHub-Token). Den Wert nie in Chat, Issue oder Protokoll kopieren; danach Zwischenablage leeren.

Unter **Environment variables → Add variable**: Name `SW_PLUGIN_RELEASE_KEY_ID`, Wert = Inhalt von `~/.local/share/sw-backupit-release-keys/key-id.txt` ohne Zeilenumbruch
(unverändert `sw-backupit-2026-11`). Prüfe, dass beide Einträge in der **Umgebung** `plugin-releases` (nicht nur unter Repository-Secrets) stehen und die
Key-ID exakt dem Eintrag in `packages/wordpress/src/Update/Config/trusted-keys.php` entspricht. Der Secret-Wert ist danach nicht mehr lesbar. Diese Einrichtung erzeugt keinen Tag
und veröffentlicht nichts. Die Namen dürfen in getrennten Repositories gleich sein; je Plugin/Release-Quelle ein eigener Schlüssel.

Referenzen: [Environment-Secrets](https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/use-secrets#creating-secrets-for-an-environment),
[Environment-Variablen](https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/use-variables#creating-configuration-variables-for-an-environment).

## Vertrauensanker verteilen, Rotation, Store

- **Erstverteilung:** Das Plugin mit dem öffentlichen Schlüssel einmal vertrauenswürdig installieren (kontrollierter manueller ZIP-Upload bzw. lokale Konfiguration über
  `SWBACKUPIT_RELEASE_PUBLIC_KEYS`). Erst danach akzeptieren Installationen signierte Folgeupdates. Ein Release wird nie von seinem eigenen, erstmals remote geladenen Schlüssel legitimiert.
- **Rotation:** Neuen Schlüssel mit neuer Key-ID erzeugen (Block 1 mit neuer ID/neuem Ordner), mit Block 2 **zusätzlich** eintragen und ein Übergangsrelease bauen, das noch mit dem
  alten Schlüssel signiert ist und beide öffentlichen Schlüssel ausliefert. Nach dessen Installation Secret und Variable auf den neuen Schlüssel umstellen; den alten Eintrag erst im Folgerelease entfernen.
- **Release:** Tag `vX.Y.Z` (oder `X.Y.Z`, optional `-NN`) = Header `Version` = `SWBACKUPIT_VERSION`. Der Workflow baut das ZIP, lädt es in einen Draft, signiert die tatsächlichen
  Asset-IDs, lädt Manifest und Signatur hoch und veröffentlicht erst dann. Fehlen Secret/Variable, bricht er vor dem Build ab; veröffentlichte Releases werden nie überschrieben.
- **Wechsel zu WordPress.org:** Store-Builds müssen den temporären GitHub-Provider (`Update\Provider::init()`-Aufruf, `Update/`-Klassen, Skript) und den Header `Update URI` entfernen;
  anschließend liefert wordpress.org die Updates. Bestehende GitHub-Installationen erhalten dafür ein letztes signiertes Übergangsrelease.

## Tests und Grenzen

- `tests/wp/tests/VerifiedUpdatesTest.php` (Einzelsite und Multisite, nur privates WordPress/Test-DB, Wegwerfschlüssel, simuliertes HTTP, echte native Upgrader-Läufe),
  `apps/admin` `npm run test:updates-ui` (Browser, Coverage des Skripts), `tests/release/verify-signed-build.php <dist>` (echtes Build-ZIP mit Wegwerfschlüssel, Vollständigkeit, Abbruch ohne Secret).
  Alle laufen ausschließlich in `tests.yml` auf Pull Requests; der Release-Workflow führt keine Testsuite aus.
- Grenzen: `REST_REQUEST`/`WP_CLI` können in PHPUnit nicht pro Test gesetzt werden und teilen sich mit der Cron-/Frontend-Sperre denselben Ausdruck in `Service::interactive()`;
  die End-to-End-Veröffentlichung mit echtem GitHub und echtem Schlüssel ist ungetestet, bis der Betreiber die Einrichtung vorgenommen hat.
