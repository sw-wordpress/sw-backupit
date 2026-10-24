# sw-backupit – CODE-STYLE.md

**Status:** Verbindlich  
**Geltungsbereich:** Gesamtes sw-backupit-Monorepo
**Zielgruppe:** Menschen, Codex CLI und andere Coding-Agenten  
**Projekt:** WordPress Backup, Restore & Disaster Recovery

> **Grundsatz:** Zuverlässige Wiederherstellbarkeit und Sicherheit haben Vorrang vor Bequemlichkeit, Micro-Optimierungen und rein ästhetischen Code-Konventionen. Der Code muss auch unter PHP-Prozessabbrüchen und eingeschränkten Hosting-Ressourcen kontrolliert funktionieren.

## 1. Priorität und Geltungsbereich

Diese Datei ergänzt `SPECIFICATION.md`, `AGENTS.md`, die Architektur-Dokumentation und die offiziellen WordPress-Standards. Konflikte werden in dieser Reihenfolge gelöst:

1. Sicherheitsanforderungen und explizite Produktanforderungen aus `SPECIFICATION.md`.
2. Die bereichsspezifischen Regeln dieser Datei.
3. Bestehende Architekturentscheidungen (ADRs) und öffentliche Schnittstellenverträge.
4. Offizielle Standards und Framework-Konventionen.

**Keine stillschweigenden Ausnahmen:** Ist eine Regel für einen konkreten Anwendungsfall ungeeignet, wird die Abweichung eng begrenzt, begründet, dokumentiert und mit einem gezielten Tool-Suppressionshinweis versehen. Niemals einen gesamten Prüfstandard deaktivieren, um einzelne Meldungen zu vermeiden.

| Pfad (Beispiel) | Stil | Tooling |
|---|---|---|
| `packages/wordpress/`, Plugin-Bootstrap, WP-CLI-Integration | WordPress PHP Coding Standards (WPCS) | PHPCS + WordPressCS |
| `packages/core/` | PSR-12 + PSR-4 + projektspezifische Regeln | PHP-CS-Fixer oder PHPCS, PHPStan |
| `packages/restore/` | PSR-12 + PSR-4 | PHP-CS-Fixer oder PHPCS, PHPStan |
| `apps/admin/` bzw. `frontend/` | Vue 3, TypeScript, ESLint, Prettier | ESLint, vue-tsc, Prettier |
| `tests/` | Stil der getesteten Komponente; Tests dürfen lesbarere Hilfsstrukturen verwenden | PHPUnit, PHPCS/Formatter |
| `vendor/`, `node_modules/`, generierte Assets | Nicht manuell bearbeiten | Von eigenen Lints ausnehmen |

**Wichtig:** WordPressCS ist mehr als ein Formatter: seine Security-, Internationalisierungs- und Datenbank-Regeln gelten für WordPress-Integrationscode auch dann, wenn Details des Layouts abweichen. Third-Party-Code darf nicht zur Einhaltung eigener Regeln umformatiert werden.

## 2. PHP-Version, Abhängigkeiten und Interoperabilität

- Mindestversion: **PHP 8.2** (solange `SPECIFICATION.md` nichts anderes festlegt).
- `declare(strict_types=1);` in eigenen PHP-Dateien, soweit sinnvoll und mit dem Laufzeitkontext kompatibel.
- Alle Klassen über Composer/PSR-4 laden; kein eigenes rekursives `require`-System für Klassen.
- Dependency Versions per `composer.json` mit sinnvollen Constraints; `composer.lock` bei Anwendungen/buildbaren Distributionen committen. Für einzelne veröffentlichte Bibliotheken den Lockfile-Umgang bewusst festlegen.
- Laufzeitabhängigkeiten des ausgelieferten Plugins bundeln; auf dem Zielhosting werden Composer oder Node.js nicht vorausgesetzt.
- Keine PHP- oder WordPress-Funktionen benutzen, die die festgelegte Mindestversion ausschließen.
- `ext-zip`, `ext-sodium`, `ext-json`, `ext-mbstring` etc. nach tatsächlichem Einsatz als Pflicht- oder optionale Abhängigkeiten dokumentieren und im Preflight prüfen.
- Keine unkontrollierten globalen Funktionen, Klassen oder Konstanten; PSR-4-Namespace unter einem eindeutigen Vendor-Präfix, z. B. `SWBackupIt\` (Festlegung im Architekturentscheid).
- Die Namespace-Präfixe `WP` und `WordPress` nicht für eigene Klassen reservieren.
- Gemeinsame Libraries dürfen keine WordPress-Funktionen (`get_option`, `wpdb`, `apply_filters` etc.) direkt aufrufen.
- PHP-Abhängigkeiten über dedizierte Adapter abstrahieren; Schnittstellen im Core definieren, Implementierungen in den Adaptern.
- Composer-Autoloading beim Plugin-Start konfliktarm integrieren; Kollisionen mit Bibliotheken anderer Plugins prüfen. Bei Bedarf Namespacing-/Prefixing-Strategie für gebündelte Dependencies prüfen.

## 3. WordPress-spezifischer PHP-Stil

Der Inhalt dieses Abschnitts gilt für den WordPress-Adapter und alle WordPress-nahen Dateien. Für Core und Standalone-Restore gelten stattdessen die PSR-12-Formatierungsregeln.

### 3.1 Benennung und Dateinamen

- WordPress-nahe freie Funktionen, Methoden, Variablen, Hooks und Option-/Meta-Schlüssel: **`snake_case`**.
- Globale Symbole, Actions, Filters, Cron-Hooks, REST-Namespace, Options, Transients und Datenbanktabellen eindeutig präfixen: `swbi_` bzw. `swbackupit_`; das gewählte Schema je Kategorie dokumentieren und konsistent verwenden.
- Konstante Namen: `SWBI_*` oder `SWBACKUPIT_*` in `UPPER_SNAKE_CASE`.
- WordPress-native Klassenkonvention: `Pascal_Words_With_Underscores`, falls globaler WP-Stil genutzt wird. **Projektentscheidung:** PSR-4-Klassen innerhalb des eigenen Namespaces dürfen `PascalCase` verwenden, um Autoloading und Core-Konsistenz zu gewährleisten; dies ist ein ausdrücklich dokumentierter WPCS-Stilausnahmefall, kein Sicherheits-Ausnahmefall.
- WordPress-konforme PHP-Dateien möglichst `kebab-case.php`; für PSR-4-Klassen muss der Dateiname exakt zur Klasse und zum Autoloader passen (`BackupController.php`). Keine unübersichtlichen Doppel-Konventionen.
- REST-Routen: lower-case, nachvollziehbare Ressourcennamen, konsistent mit API-Versionierung (`sw-backupit/v1`).
- Textdomain: **`sw-backupit`** und konsistent in Plugin-Metadaten und Übersetzungsaufrufen.

### 3.2 Formatierung

- Tabs zur Einrückung im WordPress-Adapter, Spaces nur zur visuellen Ausrichtung.
- Leerzeichen innerhalb von WordPress-Funktionsklammern: `get_option( 'swbi_settings' )`.
- Kontrollblöcke: `if ( $condition ) { ... }`, `foreach ( $items as $item ) { ... }`.
- Explizite Vergleiche mit `===`/`!==`; kein implizites Vergleichen von IDs, Rollen, Status, Pfaden oder Ergebnissen.
- Arrays im WP-Adapter im WPCS-Stil `array( ... )` und mit trailing comma bei mehrzeiligen Arrays. Im Core/Restore PSR-12 mit `[...]`.
- Keine PHP-Schlussmarke `?>` in reinen PHP-Dateien.
- Keine Kurz-PHP-Tags `<?` oder `<?=` im WordPress-Adapter.
- Strings ohne Interpolation in einfachen Anführungszeichen.
- Funktionsaufrufe und verschachtelte Kontrollstrukturen lesbar umbrechen; keine willkürlichen Einzeiler.
- Kommentare erläutern **warum**, nicht die offensichtliche Syntax.
- Öffentliche Methoden und Hooks durch knappe PHPDoc-Kommentare mit Parametern, Rückgabe, Fehlern und gegebenenfalls `@since` dokumentieren.

```php
<?php
/**
 * WordPress backup integration.
 *
 * @package sw-backupit
 */

declare(strict_types=1);

namespace SWBackupIt\WordPress\Api;

final class Backup_Controller {
	public function register_routes(): void {
		register_rest_route(
			'sw-backupit/v1',
			'/backups',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_backups' ),
				'permission_callback' => array( $this, 'can_manage_backups' ),
			)
		);
	}

	public function can_manage_backups(): bool {
		return current_user_can( 'manage_options' );
	}
}
```

Hinweis: Das Beispiel zeigt Format und explizite Berechtigungsprüfung. Die endgültigen Capabilities werden feingranular spezifiziert; `manage_options` ist **nicht automatisch** die richtige Berechtigung für jede Aktion.

### 3.3 WordPress-Hooks

- Hooks nur im Adapter registrieren; der Core darf keine WordPress-Hooks kennen.
- Hook-Registrierung möglichst zentral in dedizierten Bootstrap-/Registrar-Klassen.
- Bei `add_action()` und `add_filter()` Priorität und Argumentanzahl bewusst wählen.
- Abmeldbare Callbacks bevorzugen; keine schwer entfernbaren anonymen Closures für langlebige Hooks.
- Hooks mit Präfix `swbi_...` und dokumentiertem Datenvertrag.
- Hooks erst auslösen, nachdem der persistente Status einen definierten, konsistenten Zustand erreicht hat.
- Exceptions aus Drittanbieter-Hooks abfangen, protokollieren und anhand expliziter Policy behandeln; nicht pauschal ignorieren.
- Keine zeitintensiven Backup-Vorgänge ungebremst innerhalb von `init`, `admin_init` oder Seitenaufrufen ausführen.

## 4. WordPress-Sicherheit: zwingende Regeln

### 4.1 Eingabevalidierung und Ausgabe-Escaping

- **Validate early, sanitize appropriately, escape late.** Validierung schützt die Datenmodelle; Sanitization ist typabhängig; Ausgabe-Escaping ist kontextabhängig.
- Daten aus `$_GET`, `$_POST`, `$_REQUEST`, Cookies, REST-Requests, Datenbank und Remote-Storage nie ungeprüft vertrauen.
- Request-Daten zunächst `wp_unslash()` berücksichtigen, dann passend zum erwarteten Typ validieren/sanitisieren.
- Zahlen mit Bereichsprüfung, Enums über Allowlists, UUIDs mit konkretem Parser, Dateipfade mit eigener Pfadpolicy prüfen.
- HTML-Text mit `esc_html()`, HTML-Attribute mit `esc_attr()`, URLs mit `esc_url()` und erlaubtes HTML mit `wp_kses()` / `wp_kses_post()` behandeln.
- JavaScript-Daten per `wp_json_encode()` serialisieren, bevorzugt über WordPress Script-API/konfiguriertes Initialisierungsobjekt statt Inline-HTML-Konkatenation.
- Escaping **nicht** mit Sanitization verwechseln; `sanitize_text_field()` ist nicht geeignet, um SQL, Dateipfade, beliebige URLs oder HTML-Ausgabe pauschal abzusichern.
- Keine ungefilterte Ausgabe von Dateipfaden, Stacktraces, Storage-Credentials oder entschlüsselten Metadaten im Admin-Frontend.

### 4.2 Berechtigungen und Nonces

- Für jede privilegierte Operation `current_user_can()` auf passende Capabilities prüfen; bei objektbezogenen Aktionen auch Zugriff auf das konkrete Backup/Storage-Ziel prüfen.
- **Nonces sind keine Autorisierung.** Sie ergänzen den CSRF-Schutz, ersetzen aber weder Authentifizierung noch Capability Checks.
- Klassische Admin-POST-Aktionen mit `check_admin_referer()` bzw. `wp_verify_nonce()` absichern.
- REST-Endpoints mit `permission_callback` registrieren; authentifizierte Cookie-REST-Anfragen benötigen den vorgesehenen WordPress-REST-Nonce-Mechanismus.
- WP-CLI unter dem effektiven Nutzer-/Prozesskontext sicher ausführen; kritische Restore-/Delete-Befehle brauchen explizite Schutzmaßnahmen und definierte Bestätigungen.
- Separate Capabilities planen, etwa Backup einsehen, Backup ausführen, Backup herunterladen, Speicherziele konfigurieren, Backup löschen, Restore durchführen.
- Besonders hohe Schutzanforderung für Backup-Downloads: Archive enthalten unter Umständen Zugangsdaten und personenbezogene Inhalte.

### 4.3 SQL und WordPress-Datenbankzugriff

- Im WordPress-Adapter `$wpdb` und `$wpdb->prepare()` für parametrisierbare Werte verwenden.
- SQL-Identifier (Tabellen-/Spaltennamen) nicht mit normalen Platzhaltern behandeln; gegen explizite Allowlist prüfen und die Unterstützung für `%i` nur unter passender WordPress-Mindestversion nutzen.
- Geeignete `$wpdb->insert()`, `update()`, `delete()`-Methoden bevorzugen.
- Schemaänderungen per versionierter Migration; `dbDelta()` nur mit seinem erwarteten SQL-Format und mit Tests verwenden.
- Tabellennamen über `$wpdb->prefix` beziehungsweise `$wpdb->base_prefix` je nach Datenmodell bilden, niemals `wp_` hardcoden.
- Tabellen- und Indexnamen präfixen, Multisite-Semantik bewusst festlegen.
- Keine unkontrollierten DDL-Operationen im normalen Requestpfad.
- Datenbank-Dumps und Restore-Importe als eigene Streaming-Services; niemals als riesige `get_results()`-Ladung.

### 4.4 REST API

- Namespace versionieren: `sw-backupit/v1`.
- `register_rest_route()` auf `rest_api_init`.
- Jede Route hat einen expliziten `permission_callback`.
- Parameter mit `args`, Typen, Default, `validate_callback` und `sanitize_callback` beschreiben, sofern geeignet.
- REST-Antworten über `WP_REST_Response`, Fehler über `WP_Error` mit passenden HTTP-Statuscodes.
- GET ohne Seiteneffekte; POST/PUT/PATCH/DELETE nur bei passenden Berechtigungen; schreibende Endpunkte idempotent gestalten, wenn sinnvoll.
- Collection-Endpoints paginieren und begrenzen; kein komplettes 100.000-Dateien-Manifest als eine JSON-Antwort.
- Keine geheimen Zugangsdaten, beliebigen lokalen Pfade oder unredigierten Fehlermeldungen in API-Antworten.
- Backup-Downloads nach Autorisierung streamen, mit sicheren Content-Disposition-Headern und ohne öffentliche statische URL.

### 4.5 Filesystem und Archive

- WordPress Filesystem API dort berücksichtigen, wo Host-Zugriffsmodelle/WordPress-Konventionen sie erfordern; im Backup-Core einen eigenen expliziten `FilesystemInterface`/Flysystem-Adapter verwenden.
- Pfade normalisieren, Zielwurzel kanonisieren und jede Operation auf erlaubte Wurzelverzeichnisse beschränken.
- `realpath()` allein genügt bei nicht existierenden Zieldateien und Symlinks nicht: Segmentprüfung, Symlink-Policy und Race-Condition-Risiken separat behandeln.
- Niemals Backup-/Staging-Dateien innerhalb öffentlich erreichbarer WordPress-Verzeichnisse ungeschützt ablegen. Webzugriff serverseitig verhindern und die Konfiguration prüfen; `.htaccess` allein schützt nicht auf allen Webservern.
- Restore: ZIP-Einträge strikt prüfen (absolute Pfade, `..`, Backslashes, Nullbytes, Symlinks, Sonderdateien, Entpackgrößen, Duplikate, Groß-/Kleinschreibung und Pfadkollisionen).
- Kein `extractTo()` auf ungeprüfte Archive ohne kontrollierte Validierungs- und Extraktionslogik.
- Keine Annahme, dass das bloße Verifizieren eines ZIP die sichere Extraktion gewährleistet.
- Schreiboperationen über temporäre Dateien und atomare Umbenennung **wo vom Dateisystem unterstützt**; vor einem Checkpoint Flush-/Persistenzbedingungen klären.

### 4.6 HTTP/Remote-Storage

- WordPress HTTP API (`wp_remote_request()` etc.) im WordPress-Adapter nutzen, sofern kein speziell benötigter Storage-SDK-Client zuständig ist.
- Benutzerkonfigurierbare Endpunkte strikt gegen SSRF absichern: Zielhosts, DNS/IP, Redirects, Ports und interne Netzbereiche nach definierter Policy bewerten.
- TLS-Zertifikatsprüfung nicht deaktivieren.
- Zeitlimits, Retry-Budgets, Rate-Limits und maximale Antwortgrößen definieren.
- Credentials niemals in Querystrings oder Logs; kurzlebige Tokens bevorzugen, sofern Anbieter dies unterstützt.

## 5. Internationalisierung und Accessibility

- Alle benutzerseitigen Strings übersetzbar; Textdomain `sw-backupit`.
- Für PHP: `__()`, `_e()`, `esc_html__()`, `esc_html_e()`, `esc_attr__()` nach Ausgabekontext.
- Dynamische Inhalte über `sprintf()`/Platzhalter einfügen und mit Übersetzerkommentaren bei unklaren Variablen dokumentieren.
- Keine Variablen als erstes Argument von Übersetzungsfunktionen; keine Satzfragmente dynamisch zusammensetzen.
- Pluralformen mit `_n()` und passenden Platzhaltern behandeln.
- JS/Vue: WordPress `@wordpress/i18n` verwenden, wenn Integration in das WP-Übersetzungssystem vorgesehen ist; Build- und Script-Translation-Ladeweg dokumentieren und testen.
- Datums-/Zahlenformatierung nach Benutzer-Locale und WordPress-Zeitzone; Zeitstempel intern eindeutig (vorzugsweise UTC) speichern.
- Tastaturbedienbarkeit, sichtbarer Fokus, semantische Labels, ARIA bei Bedarf und ausreichende Kontraste einhalten; WCAG 2.2 AA als Projektziel, WordPress-Accessibility-Vorgaben berücksichtigen.
- Live-Fortschritt so ankündigen, dass Screenreader nicht durch sekündliche Meldungsfluten überlastet werden.

## 6. Backend-Architektur und Codequalität

### 6.1 Design

- SOLID als Orientierung, keine abstrakten Hierarchien ohne konkreten Bedarf.
- Konstruktor-Injektion statt globaler Service-Locator-Zugriffe.
- Domainobjekte und Wertobjekte bevorzugen, wenn sie ungültige Zustände verhindern.
- Öffentliche APIs klein, stabil, typisiert und dokumentiert halten.
- Kein WordPress-Typ (`WP_Error`, `WP_REST_Request`, `wpdb`) im Core-Interface.
- Fehler im Core als typisierte Exceptions/Resultate modellieren; im WordPress-Adapter in `WP_Error` bzw. REST-Antworten übersetzen.
- Keine breit gestreuten statischen Singleton-Abhängigkeiten.
- `readonly`, Enums und Union Types dort nutzen, wo sie Klarheit schaffen und PHP 8.2-kompatibel sind.
- Keine magischen Strings für Job-Status oder Backup-Typen: Enums/Wertobjekte verwenden.

### 6.2 Benennung im Core/Restore

- Klassen/Interfaces/Enums: `PascalCase` (`BackupJob`, `StorageAdapterInterface`).
- Methoden/Eigenschaften/Variablen: `camelCase` (`restoreBackup()`, `$volumePath`).
- Konstanten: `UPPER_SNAKE_CASE`.
- Ein primärer Typ pro Datei, PSR-4-Dateiname identisch zur Klasse.
- Keine Abkürzungen ohne etablierten Kontext; `backupId` statt `bid`, `storageTarget` statt `st`.

### 6.3 Fehlerbehandlung

- Keine leeren `catch`-Blöcke.
- Keine Exceptions als gewöhnliche Schleifensteuerung.
- Fehlerklassen mit Ursache, reproduzierbarem Kontext, Retry-Eignung und sicherer Nutzermeldung.
- Keine `@`-Unterdrückung von PHP-Warnungen als Standardweg.
- Keine `die()`, `exit()` oder `var_dump()` in Domain-Services. Im WordPress-Einstiegspunkt WordPress-konforme Response/Exit-Wege verwenden.
- Zustandswechsel für Jobs immer persistiert und testbar ausführen.
- Retry nur bei transienten Fehlern; Backoff mit Obergrenze und Jitter.
- Fehlertoleranz darf fehlende Daten niemals als vollständiges Backup deklarieren.

### 6.4 Logging

- PSR-3-Schnittstelle im Core.
- Strukturierte Events mit `jobId`, `phase`, `eventCode`, Severity, Zeitstempel und wenigen sicheren Feldern.
- Keine Zugangsdaten, Tokens, Klartextpasswörter, Schlüssel, DB-Dumps, vollständige Secrets aus `wp-config.php` loggen.
- Pfade bei Bedarf verkürzen oder redigieren; Datenschutz und Geheimhaltung beachten.
- Log-Rotation/-Retention und Event-Limits, damit der Log selbst kein Speicherrisiko darstellt.

## 7. Backup-, Restore- und Datenintegritätsregeln

Diese Regeln sind für sw-backupit **verbindlich** und wichtiger als kosmetischer Stil.

1. Kein Erfolg ohne vollständiges Manifest, geschlossene Volumes und erfüllte konfigurierbare Verifikations-/Replikationsrichtlinie.
2. Manifestformat versionieren; Schemaänderungen mit expliziter Migrations-/Kompatibilitätsstrategie.
3. Backups unabhängig von der WordPress-Datenbank lesbar halten.
4. Archivvolumes unveränderlich abschließen; nie ein veröffentlichtes Volume in-place verändern.
5. Checkpoint erst nach dauerhaft abgeschlossenem Verarbeitungsschritt fortschreiben.
6. Jobs müssen nach Prozessabbruch deterministisch entweder fortgesetzt oder kontrolliert neu gestartet werden können.
7. Doppelte Job-Ausführung durch Lock/Lease und Zustandsprüfung abfangen; Recovery bei abgelaufenen Leases ausdrücklich testen.
8. Wiederholung von Upload/Verifikation darf keine inkonsistenten doppelten Backup-Einträge erzeugen.
9. Retention löscht nie benötigte Basissicherungen oder Abhängigkeiten inkrementeller Ketten.
10. Löschen nur nach präziser Auswahl, Schutz der letzten gültigen Sicherung, Dry-Run und expliziter Policy.
11. Datenbankexport in begrenzten Batches/Streams; Snapshots, Transaktionsgrenzen und Inkonsistenzrisiken offenlegen.
12. Restore-Dateipfade und Datenbankziele vor schreibenden Operationen validieren; vorher möglichst Preflight/Simulation ausführen.
13. URL-Migration für PHP-serialisierte Werte strukturerhaltend behandeln; keine blinde globale String-Ersetzung in SQL-Dumps.
14. Deserialisierung unbekannter Objekte nur unter sicherem, dokumentiertem Modell (`allowed_classes => false` soweit passend); niemals unkontrolliert Code aus Backups ausführen.
15. Für verschlüsselte Archive authentifizierte Streaming-Kryptografie verwenden; Schlüssel nicht nur in Quellinstallation speichern.
16. Quell- und Zielumgebung bei Restore klar trennen; keine impliziten Änderungen an produktiven Systemen.
17. Keine pauschale Garantie atomarer Datenbank-/Filesystem-Snapshots oder vollständiger Restore-Rollbacks behaupten.
18. Für jede Backup-Formatversion mindestens einen automatisierten Restore-Kompatibilitätstest erhalten.

## 8. Datenbank und Migrationen

- Datenbankschema in versionierten Migrationen dokumentieren; Migrationen bei wiederholtem Ausführen sicher gestalten.
- Indizes für Job-Status, Erstellungszeit, Speicherziel, Backup-Chain und Eventabfragen festlegen.
- Eindeutige Constraints für Job-/Backup-IDs und technische Idempotenzschlüssel.
- Keine massiven Migrationen synchron auf jedem Admin-Request.
- Keine stillen Tabellendrops bei Plugin-Deaktivierung.
- **Deaktivierung ≠ Deinstallation:** Daten und Einstellungen nur nach explizit definiertem Uninstall-Verhalten und Nutzerentscheidung entfernen.
- `dbDelta()`-Besonderheiten in Tests prüfen; WordPress-Multisite-Präfixe berücksichtigen.
- Backup-Katalog und archiviertes Manifest so entkoppeln, dass Storage-Reconciliation möglich ist.

## 9. Frontend: Vue 3 / Quasar / TypeScript

- Vue 3 Composition API mit `<script setup lang="ts">`.
- TypeScript `strict: true`, `noImplicitAny`; kein `any` ohne dokumentierte Ausnahme.
- Komponenten nach Feature/Domäne strukturieren, nicht alles in einer großen `components/`-Liste.
- API-Zugriffe nur durch typisierte API-Clients/Composables; keine verteilten `fetch()`-Aufrufe in Komponenten.
- Server ist Source of Truth für Jobzustände; UI-Fortschritt nicht frei simulieren.
- Für langlaufende Aufgaben Polling mit Cleanup, Backoff und Sichtbarkeits-/Unmount-Behandlung.
- Keine geheimen Tokens/Zugangsdaten in Local Storage, URL, Console oder Fehler-Tracking.
- Alle mutierenden Aktionen mit serverseitigem Permission Check; Clientvalidierung ist nur UX.
- Einheitliche Zustände: loading, empty, success, warning, error und retry.
- Destruktive Aktionen per Bestätigungsdialog mit Zielobjekt und Auswirkungen.
- Screens und Navigation müssen auf kleineren Admin-Viewports funktionieren.
- Vite produziert lokal gebündelte Assets; keine verpflichtenden externen CDN-Ressourcen.
- CSS ausschließlich scoped oder durch klaren `swbi-`-Namespace gegen WP-Admin-Kollisionen schützen.
- ESLint + Prettier sind verbindlich; Quasar-Konventionen und Vue-Reaktivitätsregeln berücksichtigen.
- Externe/Benutzer-Daten niemals via ungeprüftem `v-html` rendern.

```vue
<script setup lang="ts">
import { computed } from 'vue';

type JobStatus = 'pending' | 'running' | 'completed' | 'failed';

const props = defineProps<{
  status: JobStatus;
  processedBytes: number;
  totalBytes: number;
}>();

const progress = computed(() =>
  props.totalBytes > 0 ? props.processedBytes / props.totalBytes : 0,
);
</script>

<template>
  <q-linear-progress :value="progress" />
</template>
```

## 10. PHPDoc, Kommentare, Sprache

- Technische Kommentare, API-Dokumentation, Commit-Messages und Codebezeichner: **Englisch**.
- Benutzeroberfläche: übersetzbare Strings; Deutsch und Englisch bereitstellen.
- Öffentliche Klassen/Methoden mit kurzem Zweck, Typangaben und wichtigen Vor-/Nachbedingungen dokumentieren.
- `@throws` für relevante Fehlerverträge; bei WordPress-Hooks `@since`/`@param` und nachvollziehbare Filter-Rückgaben ergänzen.
- Bei Checkpoints, Verschlüsselung und Restore explizit festhalten, unter welchen Voraussetzungen Daten als gültig gelten.
- Keine TODOs ohne Tracking-Referenz oder konkret benannte Folgearbeit.
- Keine automatisch generierten, redundanten Kommentare für jede einzelne Variable.

## 11. Tests und statische Qualitätssicherung

### 11.1 Pflicht-Werkzeuge

- WordPress Coding Standards über `wp-coding-standards/wpcs` und PHP_CodeSniffer.
- PHPCompatibilityWP für den WordPress-Bereich, plus generisches PHPCompatibility bei frameworkunabhängigem Code, jeweils auf festgelegte PHP-Versionen konfiguriert.
- PHPStan für eigene produktive PHP-Quellen; nach Möglichkeit hohes Level, Baseline nur begründet und fortlaufend reduzieren.
- PHPUnit für Unit- und Integrationstests.
- ESLint, Prettier und `vue-tsc --noEmit` für das Frontend.
- Composer Audit und npm Audit als Dependency-Sicherheitschecks; Funde beurteilen, nicht blind ignorieren.

### 11.2 PHPCS-Konfiguration

Eigene Regeln in `phpcs.xml.dist` definieren, **getrennt nach Bereich**. Mindestens:

- WordPress-Adapter: `WordPress`-Ruleset plus projektspezifischer Namespace-/PSR-4-Ausnahme.
- Core/Restore: PSR-12, kein pauschales WPCS-Layout über diese Verzeichnisse.
- WordPress-Security-Sniffs nicht zugunsten von PSR-12 abschalten.
- `vendor/`, generierte Bundles und fremder Code ausgeschlossen.
- PHPCompatibilityWP mit z. B. `testVersion=8.2-`, sofern der projektierte PHP-Supportbereich so lautet.
- Den tatsächlich verwendeten Regelsatz und gezielte Ausnahmen im Repository versionieren und in CI prüfen.

**Achtung:** Die konkrete Sniff-Kombination muss beim Projekt-Bootstrap gegen die installierte WordPressCS-Version getestet werden; eine vermeintlich plausible XML-Konfiguration ist kein Ersatz für einen erfolgreichen Lint-Lauf.

### 11.3 Testvorgaben

- Jede neue Domain-Funktion erhält automatisierte Unit-Tests.
- Backup-Format, Dateifilter, Manifest, Volume-Finalisierung, Retention und Job-Zustandsmaschine besonders ausführlich testen.
- Testfälle: viele kleine Dateien, Dateien größer als Volume-Limit, PHP-Timeout, Retry, fehlendes Volume, unlesbare Datei, falscher Key, Storage-Ausfall, Symlink, Path Traversal und fehlerhafte DB-Verbindung.
- Für jeden vollständigen Sicherungspfad mindestens einen End-to-End-Restore-Test.
- Test-Wiederherstellungen nur in isolierten temporären Zielverzeichnissen und Datenbanken.
- Keine Tests mit echten Produktions-Credentials oder produktiven WordPress-Datenbanken.
- Regressionstests für sicherheitsrelevante Fixes verpflichtend.
- CI muss Format/Lint, statische Analyse, Unit Tests, relevante Integrationstests und Build prüfen.

## 12. Dateinamen, Repository-Hygiene und Git

- UTF-8, LF-Zeilenenden, abschließender Zeilenumbruch, kein trailing whitespace.
- `.editorconfig` für PHP-WPCS-Tabs und PSR-/Frontend-Spaces passend nach Verzeichnis erstellen.
- PSR-4-Klassennamen und Dateinamen konsistent halten, insbesondere auf case-sensitiven Systemen.
- Keine Secrets in `.env`, Fixtures, Screenshots, Logs oder Commits; `.env.example` nur mit Platzhaltern.
- Keine großen Testarchive oder generierten Build-Artefakte unnötig in Git speichern.
- Vendor/Node-Dependencies gemäß gewähltem Releaseprozess; das auslieferbare WordPress-Plugin-ZIP enthält alle Laufzeitkomponenten.
- Änderungen klein und fachlich kohärent halten; Refactoring nicht ohne Bedarf mit Sicherheitsfixes vermischen.
- Migrationen und öffentliche Schema-/API-Änderungen mit dokumentierter Abwärtskompatibilität.
- Keine automatischen Pushes, Releases, produktiven Restores oder Löschoperationen durch Codex ohne explizite Freigabe.

## 13. Verbindliche Codex-Checkliste pro Feature

Vor Umsetzung:

- [ ] Betroffenen Bereich und seinen Coding Standard identifiziert.
- [ ] Risiko für Backup-Integrität, Restore-Sicherheit, Berechtigungen und Ressourcen geprüft.
- [ ] API-/Datenvertrag und Fehlerszenarien festgelegt.

Während Umsetzung:

- [ ] Keine Core-Abhängigkeit zu WordPress eingeführt.
- [ ] Typisierung, Namenskonvention und geeignete Abstraktionen eingehalten.
- [ ] Eingaben validiert; Ausgaben kontextgerecht escaped.
- [ ] Capabilities, REST-Nonces und Storage-Zugriffe korrekt abgesichert.
- [ ] Keine vollständige Verarbeitung großer Dateien/Tabellen im RAM.
- [ ] Persistenz, Retry/Resume und Teilfehler berücksichtigt.
- [ ] Keine Secrets oder vertraulichen Nutzerdaten geloggt.

Vor Abschluss:

- [ ] Formatter/PHPCS erfolgreich.
- [ ] PHPStan, PHPCompatibility und TypeScript-Prüfungen erfolgreich oder Blocker transparent dokumentiert.
- [ ] Unit-/Integrationstests erfolgreich.
- [ ] Für Restore-relevante Änderungen passender End-to-End-Test erfolgreich.
- [ ] Dokumentation, Changelog/Implementierungsplan und gegebenenfalls ADR aktualisiert.
- [ ] Keine behauptete Funktion, die nicht tatsächlich implementiert ist.
- [ ] Sicherheitskritische Entscheidungen und Ausnahmen dokumentiert.

## 14. Offizielle Referenzen

- WordPress PHP Coding Standards: https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/
- WordPress Coding Standards (Übersicht): https://developer.wordpress.org/coding-standards/wordpress-coding-standards/
- WordPress Plugin Handbook: https://developer.wordpress.org/plugins/
- WordPress Data Validation: https://developer.wordpress.org/plugins/security/data-validation/
- WordPress REST API: https://developer.wordpress.org/rest-api/
- WordPress Database API: https://developer.wordpress.org/apis/making-http-requests/ (HTTP API); https://developer.wordpress.org/reference/classes/wpdb/ (wpdb)
- WordPressCS: https://github.com/WordPress/WordPress-Coding-Standards
- PHPCompatibilityWP: https://github.com/PHPCompatibility/PHPCompatibilityWP
- PSR-12: https://www.php-fig.org/psr/psr-12/
- PSR-4: https://www.php-fig.org/psr/psr-4/
- PSR-3: https://www.php-fig.org/psr/psr-3/

---

**Anweisung an Codex:** Lies diese Datei bei Arbeitsbeginn und vor Architektur- oder Sicherheitsänderungen. Halte die Verzeichnisgrenzen zwischen WordPress-Adapter, Core und Standalone-Restore ein. Eine Änderung gilt erst als fertig, wenn Stil-, Sicherheits- und Funktionstests durchlaufen wurden oder konkrete nicht ausführbare Prüfungen offen dokumentiert sind.
