# SWBackupIt – Sicherheits- und Funktionsaudit

Stand: 24. Oktober 2026 · geprüfter Commit: `12b5503b3125fe1d2bbffd654f3bcebbadde0ecc`

## Zusammenfassung

Es wurden **7 Findings** festgestellt: **3 hoch, 3 mittel, 1 niedrig**. Kein kritisches Finding wurde nachgewiesen. Besonders wichtig sind der Schutz lokal abgelegter Backups und die Zuverlässigkeit unterbrochener SQL-Restores. Grüne Bestandstests decken die unten reproduzierten Randfälle nicht ausreichend ab.

Die Bewertung berücksichtigt Auswirkung und notwendige Voraussetzungen, nicht nur technische Möglichkeiten. „Reproduziert“ bezeichnet einen tatsächlich ausgeführten lokalen Nachweis; „Codebefund“ eine nachvollzogene Implementierung ohne Ende-zu-Ende-Ausnutzung. Es wurden keine produktiven Backups gelöscht und keine Wiederherstellung auf die reale WordPress-Installation ausgeführt. Der Plugin-Code wurde nicht verändert.

Alle nachfolgenden Fundstellen beziehen sich auf das absolute Basisverzeichnis **`/var/www/html/wordpress_dev/wp-content/plugins/swbackupit`**. Um absolute Pfade zu erhalten, ist dieses den angegebenen Dateisuffixen voranzustellen. Zeilenangaben gelten für den geprüften Stand.

| ID | Schwere | Bereich | Befund | Nachweis |
|---|---|---|---|---|
| F-01 | Hoch | Sicherheit | Backups im Webroot sind ohne wirksame Serversperre direkt abrufbar | Codebefund, konfigurationsabhängig |
| F-02 | Hoch | Funktion | SQL-Resume scheitert bei mehreren bereits ausgeführten Statements | Reproduziert |
| F-03 | Hoch | Funktion | Zurückgestellte Views gehen bei Tick-Wechsel verloren; Retry funktioniert nicht mehrstufig | Reproduziert |
| F-04 | Mittel | Funktion | SQL-Resume überspringt notwendige Session-Einstellungen | Codebefund |
| F-05 | Mittel | Funktion | Verschlüsselte Backup-Artefakte lassen sich nicht über REST herunterladen | WP-Testausgabe und Codebefund |
| F-06 | Mittel | Sicherheit | Als einmalig dokumentierter Restore-Token ist wiederverwendbar | Reproduziert |
| F-07 | Niedrig | Release/Funktion | ZIP-Build übernimmt möglicherweise veraltete Admin-Assets | Codebefund |

## F-01 – Hoch: Kein serverunabhängiger Zugriffsschutz bei Staging im Webroot

**Fundstellen:** `packages/wordpress/src/Support/Settings.php:157–179`; `packages/core/src/Backup/BackupLayout.php:83–104`; `packages/core/src/Backup/Step/PreflightStep.php:72–76`.

**Problem:** Wenn das bevorzugte externe Verzeichnis nicht verwendet werden kann, wird unter `WP_CONTENT_DIR/swbackupit-staging-<suffix>` gespeichert. Der Schutz besteht aus `.htaccess` und einer leeren `index.php`; die Preflight-Prüfung warnt lediglich. Ein Webserver, der diese `.htaccess` nicht auswertet, kann bekannte ZIP-, SQL-, Index- und Manifestpfade direkt ausliefern. Die leere Indexdatei verhindert keinen Zugriff auf einzelne Dateien. Zudem prüft `is_inside_docroot()` gegen `ABSPATH`, nicht gegen das tatsächliche DocumentRoot: Bei WordPress in einem Unterverzeichnis kann auch das vermeintlich externe Geschwisterverzeichnis öffentlich sein.

**Voraussetzungen/Auswirkung:** Öffentlich erreichbares Staging, fehlende wirksame Serverregel und Kenntnis des Artefaktpfades. Zufällige Verzeichnisnamen erschweren das Erraten, ersetzen aber keine Autorisierung. Bei unverschlüsselten Backups droht Offenlegung der Datenbank und von `wp-config.php`; laufende Backups erzeugen außerdem zunächst Klartextartefakte. Kein öffentlicher HTTP-Abruf auf dieser Installation wurde getestet; eine tatsächliche Exposition wird nicht behauptet.

**Empfehlung:** Nicht öffentliches Staging verbindlich verlangen oder ohne nachgewiesene HTTP-Sperre sicher abbrechen. Tatsächliche Webroot-/Alias-Konfiguration berücksichtigen, restriktive Dateirechte verwenden und einen dokumentierten Nginx-/Apache-/IIS-Schutz anbieten. Der Gesundheitsstatus muss den Fehler deutlich anzeigen.

**Regressionstest:** Webserver-Matrix mit WordPress im Root und Unterverzeichnis; direkter GET auf bekannte Testartefakte muss ohne Anmeldung abgewiesen werden. Auch Zwischenartefakte und Restore-State prüfen.

## F-02 – Hoch: SQL-Resume ist nach einem Abbruch nicht zuverlässig

**Fundstellen:** `packages/restore/src/Sql/SqlImporter.php:85–109`; `packages/restore/src/RestoreEngine.php:195–209`.

**Problem:** Der SQL-Zähler wird nur alle 200 Statements gespeichert. SQL wird unabhängig davon ausgeführt und committed. Nach einem Abbruch können deshalb mehrere Statements bereits angewendet worden sein, obwohl der persistierte Zähler dahinterliegt. Beim Resume wird lediglich für das erste neue Statement ein Duplicate-Key-Fehler toleriert; der nächste führt zum Abbruch. Für Tabellen ohne eindeutigen Schlüssel können stattdessen Zeilen doppelt eingefügt werden. Auch DDL oder nicht idempotente Statements sind durch diese Sonderbehandlung nicht abgesichert.

**Nachweis:** In einer zufällig benannten Datenbank der privaten Testinstanz wurde ein Checkpoint bei Statement 200 simuliert. Anschließend wurden Statements 201–203 als bereits committed hinterlassen. Resume mit `skip=200` tolerierte 201 und scheiterte bei 202 mit `Database import failed.`; die Tabelle blieb bei 203 statt 205 Zeilen stehen. Testdatenbank wurde anschließend entfernt.

**Auswirkung:** Eine unterbrochene Wiederherstellung bleibt mit teilweise importierter Datenbank liegen; bei Wiederholung sind Datenverdopplungen möglich. Dies betrifft die zentrale zugesagte Wiederaufnehmbarkeit.

**Empfehlung:** Fortschritt und DML gemeinsam in der Zieldatenbank transaktional committen; DDL gesondert behandeln. Alternativ klar definierte, sicher wiederholbare Importsegmente mit Neustart ab Segmentgrenze implementieren. Duplicate-Key-Fehler nicht pauschal als Beweis für vollständige Ausführung eines Statements werten.

**Regressionstest:** Abbruch nach jedem Statement zwischen zwei Checkpoints, inklusive Multirow-INSERT, Tabellen ohne Primärschlüssel und DDL; nach Resume exakte Daten- und Strukturidentität prüfen.

## F-03 – Hoch: View-Abhängigkeiten können unbemerkt verloren gehen

**Fundstellen:** `packages/restore/src/Sql/SqlImporter.php:23–24,68–79,111–124`; `packages/restore/src/RestoreEngine.php:192–214`.

**Problem A:** Fehlgeschlagene View-Definitionen werden nur im Arbeitsspeicher des Importers gehalten. Die Engine checkpointet den Part als erledigt und kann vor `finalize()` wegen Zeitbudget zurückkehren. Der nächste Tick erzeugt einen neuen Importer ohne diese Definitionen. Die Endprüfung kennt die verlorenen Views nicht und kann erfolgreich sein.

**Problem B:** Die vermeintliche Schleife mit bis zu fünf Retry-Durchläufen ruft `runStatement(..., true)` auf. Dadurch werden fehlende Abhängigkeiten nicht erneut zurückgestellt; bereits der erste noch nicht auflösbare View beendet `finalize()` mit einer Exception.

**Nachweis:** Part 1 enthielt `CREATE VIEW v_a AS SELECT * FROM v_z`; vor Part 2 wurde der Importer wie bei einem Tick-Wechsel neu erzeugt. Part 2 erstellte `v_z`. `finalize()` meldete keinen Fehler, obwohl `v_a` fehlte. Zusätzlich scheiterte eine View-Kette `v_a2 → v_b2 → v_c2 → v_z2` innerhalb desselben Importers beim ersten Finalisierungsversuch, obwohl sie durch mehrere Durchläufe auflösbar wäre.

**Auswirkung:** Unvollständige Datenbank trotz scheinbar erfolgreichem Import oder unnötiger Restore-Abbruch bei gültigen Abhängigkeiten.

**Empfehlung:** Offene Definitionen dauerhaft checkpointen oder in einer getrennten, wiederholbaren Objektphase abarbeiten. Abhängigkeitsfehler bis zum letzten Durchlauf erneut einreihen; anschließend Vollständigkeit aller erwarteten Objekte prüfen.

**Regressionstest:** Mindestens dreistufige View-Kette, abhängigkeitswidrige Reihenfolge und erzwungener Tick-Wechsel zwischen SQL-Parts; Erfolg nur bei Existenz und Benutzbarkeit sämtlicher Views.

## F-04 – Mittel: SQL-Resume stellt Session-Voraussetzungen nicht wieder her

**Fundstellen:** `packages/core/src/Database/DatabaseExporter.php`, Methode `openPart()`; `packages/core/src/Database/ConnectionFactory.php:35–39`; `packages/restore/src/Sql/SqlImporter.php:85–91`; `packages/restore/src/RestoreEngine.php:192–199`.

**Problem:** Jeder Exportpart enthält unter anderem `SET FOREIGN_KEY_CHECKS=0`, `SET UNIQUE_CHECKS=0` und `SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'`. Bei Resume innerhalb eines Parts überspringt der Importer diese Statements zusammen mit den bereits ausgeführten Daten. Die Engine verwendet pro Tick jedoch eine neue Verbindung. `ConnectionFactory` setzt zwar Zeichensatz und Zeitzone, aber nicht diese Importvoraussetzungen; der SQL-Modus wird sogar auf den leeren String gesetzt.

**Auswirkung:** Nach Resume können Fremdschlüsseloperationen scheitern oder explizite Nullwerte in AUTO_INCREMENT-Spalten anders importiert werden als vor der Unterbrechung. Die normale Wiederaufnahme an einer Partgrenze ist davon nicht betroffen, da dort der Header ausgeführt wird.

**Empfehlung:** Definierte Import-Session vor jedem `importPart()` einschließlich Resume explizit initialisieren, unabhängig vom Statement-Offset. Voraussetzungen und abschließende Wiederherstellung der Session dokumentieren.

**Regressionstest:** Neue Verbindung und Resume mitten im Part; AUTO_INCREMENT-Wert 0 sowie Fremdschlüssel mit noch fehlenden Referenzen prüfen.

## F-05 – Mittel: REST-Download verschlüsselter Backups schlägt fehl

**Fundstellen:** `packages/wordpress/src/Rest/BackupsController.php:375–393`; `packages/restore/src/Backup/BackupSet.php:84–133,179–182`.

**Problem:** Der Download öffnet das Backup ohne KeyProvider und verwendet `indexPath()`, `volumePath()` und `databasePartPath()`. Diese Methoden liefern nicht die gespeicherten verschlüsselten Dateien, sondern versuchen zu entschlüsseln. Bei verschlüsselten Backups endet dies mit einer CryptoException und HTTP 404. Nur das Manifest wird direkt ausgeliefert.

**Nachweis:** Die ausgeführte WordPress-Testsuite protokollierte für verschlüsselte Volumes und Index jeweils HTTP 404 mit `This backup is encrypted. Provide the encryption key to read its contents.` Die Suite blieb dennoch grün. Der Datenbankpart folgt demselben Codepfad, wurde nicht separat per HTTP getestet.

**Auswirkung:** Benutzer können verschlüsselte Backups über diesen REST-Endpunkt nicht vollständig exportieren und extern für Disaster Recovery sichern.

**Empfehlung:** Die vorhandenen `storedIndexPath()`, `storedVolumePath()` und `storedDatabasePartPath()` für den Artefaktdownload verwenden. Verschlüsseltes Original samt ursprünglichem Namen streamen; Entschlüsselung nur als getrennte, ausdrücklich angeforderte Funktion anbieten.

**Regressionstest:** Für jeden Artefakttyp verschlüsselter Backups HTTP 200 sowie Byte-/Hash-Gleichheit mit der gespeicherten `.swbe`-Datei verlangen; kein Schlüssel darf für den Download des Chiffrats nötig sein.

## F-06 – Mittel: Restore-Token ist nicht einmalig

**Fundstellen:** `packages/restore/src/Web/Auth.php:53–79`; `packages/restore/src/Web/WebSetup.php:10–15,29–45`; Sicherheitsversprechen in `docs/security.md`.

**Problem:** Nach erfolgreicher Anmeldung wird eine Session angelegt, der Token aber weder verbraucht noch als verwendet markiert. Solange Gültigkeit und App-Lock es erlauben, erzeugt derselbe Token weitere Sessions. Das widerspricht der Dokumentation als „one-time token“.

**Nachweis:** Zweimaliger Aufruf von `Auth::login()` mit demselben gültigen Token lieferte zweimal `ok=true`.

**Voraussetzungen/Auswirkung:** Ein Angreifer benötigt den Token; dies ist kein Authentifizierungsbypass ohne Geheimnis. Ein nach der ersten Benutzung abgegriffener Token bleibt jedoch bis Ablauf oder Sperre zur Anmeldung am mächtigen Restore-Wizard nutzbar.

**Empfehlung:** Tokenverbrauch und Sessionerstellung unter derselben exklusiven Sperre atomar durchführen. Verbrauchsstatus getrennt von der Gültigkeit bereits bestehender Sessions halten, damit die erste Session weiterarbeiten kann. Alternativ Sicherheitsversprechen korrigieren; für diese Funktion ist tatsächliche Einmalverwendung vorzuziehen.

**Regressionstest:** Zweite Anmeldung und zwei parallele Anmeldungen mit demselben Token: genau eine neue Session zulassen; die erste Session muss weiter gültig bleiben.

## F-07 – Niedrig: Release-Build kann veraltete Admin-Oberfläche ausliefern

**Fundstelle:** `bin/build-zip.sh:12–15,19–43`.

**Problem:** Der Admin-Build läuft nur, wenn `assets/admin/app.js` fehlt. Bereits vorhandene Assets werden unabhängig vom aktuellen TypeScript-/Vue-Quellstand in das ZIP übernommen. Nach Änderungen am Frontend kann ein erfolgreicher ZIP-Build dadurch eine alte Oberfläche mit neuer Backend-Version ausliefern.

**Auswirkung:** Fehlende UI-Korrekturen oder API-/UI-Inkompatibilität im ausgelieferten Plugin; die Existenz eines veralteten veröffentlichten Releases wurde nicht nachgewiesen.

**Empfehlung:** Release-Assets stets aus dem gelockten Quellstand neu bauen. Ein optionaler Skip muss ausdrücklich gewählt und über einen Quell-/Build-Hash validiert werden. CI sollte den Release-Build in einem sauberen Checkout ausführen.

**Regressionstest:** Quelländerung bei bereits vorhandenem `app.js` muss den erzeugten Admin-Bundle-Inhalt ändern oder den Build wegen unpassender Artefakte abbrechen lassen.

## Ausgeführte Prüfungen und Grenzen

| Prüfung | Ergebnis |
|---|---|
| PHPUnit Unit + Integration, ohne Result-Cache | 121 Tests, 523 Assertions, erfolgreich |
| PHPUnit E2E, ohne Result-Cache | 14 Tests, 191 Assertions, erfolgreich |
| WordPress Single-Site-Suite | 48 Tests, 257 Assertions, 4 übersprungen; übrige erfolgreich |
| PHPStan, Level 8 | Keine Fehler; Konfiguration umfasst nur Core und Restore, nicht WordPress-Adapter |
| Admin `npm run typecheck` | Erfolgreich |
| Admin Vitest | 8 Testdateien, 54 Tests erfolgreich; Vue-Router-Warnung im Profile-Wizard-Test |
| Gezielte PHP-/MariaDB-Reproduktionen | F-02, beide Varianten von F-03 und F-06 bestätigt; ausschließlich zufällige Testdatenbank auf 127.0.0.1:33077 und temporäre Verzeichnisse; anschließend bereinigt |

Prüfschwerpunkt waren lokale Quellcodepfade für Staging, REST-Artefaktzugriff, Restore-Authentifizierung, Dateiextraktion, SQL-Import/-Export und Wiederaufnahme. Vorhandene Sicherheitsmaßnahmen wie Capability-Prüfung, PathGuard, Hash-Verifikation und authentifizierte Verschlüsselung sind berücksichtigt; ihr Vorhandensein ist keine umfassende Sicherheitszertifizierung.

Nicht durchgeführt: produktiver HTTP-Penetrationstest, reale S3-/SFTP-Providerprüfung, Multisite-Suite, Lasttests, vollständiger Dependency-/CVE-Abgleich, vollständiger Browser-Ablauf und unabhängiger Review aller Quellzeilen. Das vorhandene Distributions-ZIP wurde nicht vollständig gegen den Quellstand auditiert. Empfehlungen ersetzen keine Validierung auf der tatsächlichen Hosting-Konfiguration. Die Reproduktionshilfe und Prüfprotokolle liegen nur temporär unter `/tmp/swbi-audit-*` und sind keine dauerhaften Regressionstests im Repository.

## Empfohlene Reihenfolge

1. **Sofort:** Reale Staging-Erreichbarkeit prüfen, Backups außerhalb öffentlich ausgelieferter Verzeichnisse ablegen (F-01).
2. **Vor Vertrauen in Disaster Recovery:** SQL-Resume und View-Objektphase korrigieren; Crash-/Tick-Grenzen gezielt testen (F-02 bis F-04).
3. **Vor dem nächsten Release:** Verschlüsselten Artefaktdownload reparieren und Token-Einmalverwendung implementieren (F-05, F-06).
4. **Releaseprozess härten:** Saubere, reproduzierbare Frontend-Builds erzwingen (F-07).