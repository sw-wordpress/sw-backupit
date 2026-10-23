# SWBackupIt – Projektauftrag für Codex CLI

## Mandatory Code Standards

Read and follow CODE-STYLE.md before making any code changes.

All newly created and modified code must comply with the
applicable coding standards defined in CODE-STYLE.md.

Run the relevant linters, static analysis and tests before
considering a task complete.

Do not silently disable coding standard rules.
Document justified exceptions explicitly.

## 1. Projektbeschreibung

Entwickle ein professionelles WordPress-Backup- und Disaster-Recovery-Plugin mit dem Namen **SWBackupIt**.

Das Plugin soll funktional an das ehemalige BackupBuddy angelehnt sein, jedoch eine moderne, modulare und besonders robuste Architektur besitzen.

Der Schwerpunkt liegt auf:

- Zuverlässiger Sicherung und Wiederherstellung von WordPress-Installationen
- Unterstützung sehr großer Webseiten und Datenbanken
- Geringem PHP-Ressourcenverbrauch
- Kompatibilität mit eingeschränkten Shared-Hosting-Umgebungen
- Wiederaufnehmbaren Backup-Prozessen
- Flexiblen Backup-Profilen und Zeitplänen
- Externen Speichersystemen
- Unabhängiger Disaster-Recovery- und Migrationsfunktion
- Erweiterbarkeit für Enterprise-Anforderungen

Das Ergebnis soll eine produktionsfähige Open-Source-Anwendung sein. Keine reine Demonstration oder ein Proof of Concept.

Implementiere keine proprietären Bestandteile von BackupBuddy. Die Funktionalität soll eigenständig entwickelt werden.

## 2. Allgemeine Entwicklungsvorgaben

### Backend

- PHP 8.2 oder neuer
- WordPress Plugin API
- WordPress REST API
- Composer für sämtliche externen PHP-Abhängigkeiten
- PSR-4 Autoloading
- PSR-12 Coding Style
- Konsequente Verwendung von Namespaces
- Dependency Injection, soweit sinnvoll
- Kleine, testbare und klar abgegrenzte Komponenten
- Interfaces für austauschbare Implementierungen
- PHP-Typisierung und `strict_types`
- PHPUnit für automatisierte Tests
- PHPStan für statische Codeanalyse
- WordPress Coding Standards, soweit passend

Nutze möglichst etablierte Bibliotheken, statt vorhandene Funktionalität unnötig neu zu entwickeln.

Verwende keine unnötigen externen Dienste. Die Anwendung muss vollständig auf dem eigenen Webserver betrieben werden können.

### Frontend

Verwende:

- Vue 3
- Quasar Framework
- Vite
- TypeScript

Das Frontend wird als Single-Page-Application innerhalb der WordPress-Administration betrieben.

Kommunikation ausschließlich über definierte REST-Endpunkte.

Das Frontend muss als kompiliertes Bundle ausgeliefert werden. Auf dem Zielserver darf kein Node.js erforderlich sein.

Die Oberfläche soll modern, responsiv, übersichtlich und deutsch-/englischsprachig sein.

### Build und Installation

- Composer installiert die PHP-Abhängigkeiten.
- npm installiert die Frontend-Abhängigkeiten.
- Ein Build-Prozess erzeugt ein installierbares WordPress-Plugin-ZIP.
- Das Plugin-ZIP enthält alle erforderlichen PHP-Abhängigkeiten und Frontend-Assets.
- Auf dem Zielhosting dürfen weder Composer noch Node.js erforderlich sein.
- Kein Laden externer JavaScript- oder CSS-Ressourcen zur Laufzeit.

### Grundsätze

- Keine großen Dateien vollständig in den RAM laden.
- Große Datenmengen grundsätzlich streamen oder in Batches verarbeiten.
- Prozesse müssen nach Möglichkeit unterbrechbar und fortsetzbar sein.
- Fehlerzustände müssen persistent gespeichert werden.
- Destruktive Operationen benötigen eine ausdrückliche Bestätigung.
- Keine unnötigen Abhängigkeiten vom WordPress-Bootstrap im Backup-Core.
- Kein Sicherheitsversprechen ohne implementierte und getestete Schutzmaßnahmen.

---

# 3. Projektarchitektur

Trenne die Anwendung logisch in drei Komponenten.

## 3.1 swbackupit/core

Frameworkunabhängige PHP-Bibliothek.

Enthält:

- Backup Engine
- Job Engine
- File Scanner
- Database Exporter
- Archive Engine
- Manifest Manager
- Storage Manager
- Encryption Service
- Retention Manager
- Verification Service
- Event- und Logging-System

Der Core darf keine WordPress-Funktionen benötigen.

## 3.2 swbackupit/wordpress

WordPress-Plugin mit:

- Plugin Bootstrap
- Administrationsoberfläche
- REST API
- Backup-Profilverwaltung
- Zeitplanverwaltung
- WordPress Scheduler Integration
- WP-CLI
- Berechtigungsprüfung
- E-Mail-Benachrichtigungen
- WordPress Lifecycle Hooks

## 3.3 swbackupit/restore

Eigenständige PHP-Anwendung zur Wiederherstellung.

Muss ohne WordPress funktionieren.

Enthält:

- Restore Engine
- Archivverifikation
- Archiventschlüsselung
- Datenbankimport
- URL- und Pfadmigration
- Dateivergleich
- Webbasierter Restore-Assistent
- Eigenständige Restore-CLI

Core und Restore sollen möglichst viele gemeinsame Komponenten verwenden.

Eine geeignete Monorepo-Struktur ist erwünscht.

---

# 4. Backup-Profile

Es muss möglich sein, beliebig viele Backup-Profile anzulegen.

Ein Profil definiert:

- Name und Beschreibung
- Backup-Typ
- Einbezogene Verzeichnisse
- Ausgeschlossene Verzeichnisse
- Einbezogene Dateien und Muster
- Ausgeschlossene Dateien und Muster
- Auswahl der Datenbanktabellen
- Ausschluss einzelner Datenbanktabellen
- Komprimierungsverfahren und Komprimierungsstufe
- Verschlüsselung
- Archiv-Volume-Größe
- Ressourcenlimits
- Speicherziele
- Aufbewahrungsregeln
- Benachrichtigungseinstellungen

Unterstützte Backup-Typen:

1. Full Backup
2. Files Only
3. Database Only
4. Incremental
5. Differential

Inkrementelle und differenzielle Backups können in einer späteren Entwicklungsphase umgesetzt werden. Die Architektur muss diese Verfahren jedoch von Beginn an berücksichtigen.

Ein ausgeführter Job muss einen unveränderlichen Snapshot der verwendeten Profilkonfiguration erhalten.

---

# 5. Zeitplanverwaltung

Unterstütze beliebig viele parallele Backup-Zeitpläne.

Mögliche Intervalle:

- Stündlich
- Täglich
- Wöchentlich
- Monatlich
- Frei definierbare Cron-Ausdrücke

Jeder Zeitplan referenziert ein Backup-Profil.

Weitere Anforderungen:

- Aktivierung und Deaktivierung
- Anzeige der nächsten Ausführung
- Anzeige der letzten Ausführung
- Manueller Start
- Fehlerstatus
- Verhinderung unerwünschter paralleler Backups
- Konfigurierbare Retry-Strategie
- Definierte Zeitzone
- Umgang mit Zeitumstellungen

Unterstütze sowohl WP-Cron als auch echte System-Cronjobs.

WP-CLI muss zur Ausführung und Steuerung von Zeitplänen genutzt werden können.

Da WP-Cron vom Website-Traffic abhängig ist, muss die Benutzeroberfläche entsprechend informieren.

---

# 6. Backup Engine

## 6.1 Grundlegender Ablauf

Eine Sicherung durchläuft folgende Phasen:

1. Initialisierung
2. Preflight-Prüfung
3. Dateiinventarisierung
4. Datenbankexport
5. Archivierung
6. Verschlüsselung
7. Lokale Verifikation
8. Upload zu externen Speicherzielen
9. Remote-Verifikation
10. Finalisierung
11. Retention
12. Benachrichtigung

Die Schritte müssen einzeln nachvollziehbar sein.

## 6.2 Job Engine

Implementiere eine persistente Zustandsmaschine.

Mögliche Zustände:

- PENDING
- PREPARING
- SCANNING
- EXPORTING
- ARCHIVING
- ENCRYPTING
- UPLOADING
- VERIFYING
- COMPLETED
- PAUSED
- RETRY_WAITING
- FAILED
- CANCELLED

Jeder Job besitzt:

- UUID
- Backup-Profil
- Konfigurationssnapshot
- Status
- Aktuellen Verarbeitungsschritt
- Startzeit
- Aktualisierungszeit
- Endzeit
- Fortschrittsinformationen
- Fehlerinformationen
- Checkpoint-Daten
- Wiederholungszähler
- Lock beziehungsweise Lease
- Protokolle

Ein Job muss einen abgebrochenen Verarbeitungsschritt nach Möglichkeit wieder aufnehmen können.

Implementiere:

- Persistente Checkpoints
- Idempotente Verarbeitungsschritte
- Sichere Wiederholbarkeit
- Job-Locking
- Lease-Ablauf bei abgestürzten Prozessen
- Kontrolliertes Abbrechen
- Pausieren und Fortsetzen
- Wiederherstellung nach PHP-Timeouts

Nutze kurze Verarbeitungseinheiten, damit auch Shared Hosting unterstützt wird.

Ein Checkpoint darf erst als gültig markiert werden, wenn sämtliche zugehörigen Daten dauerhaft geschrieben wurden.

---

# 7. Datei-Backups

## 7.1 File Scanner

Der File Scanner muss:

- WordPress-Verzeichnisse rekursiv erfassen
- Frei konfigurierbare Verzeichnisse einbeziehen
- Include-/Exclude-Regeln unterstützen
- Glob-Muster unterstützen
- Dateigrößen berücksichtigen
- Dateitypen optional filtern
- Dateien mit Sonderzeichen unterstützen
- Dateiberechtigungen und Zeitstempel erfassen
- Symlinks sicher behandeln
- Nicht lesbare Dateien protokollieren
- Änderungen während des Backups erkennen, soweit möglich

Die Backup-Staging-Verzeichnisse müssen grundsätzlich vom Backup ausgeschlossen werden, damit keine rekursiven Sicherungen entstehen.

Dateien außerhalb des WordPress-Verzeichnisses dürfen nur nach expliziter Freigabe aufgenommen werden.

## 7.2 Dateimanifest

Erfasse je Datei möglichst:

- Relativen Pfad
- Dateigröße
- Änderungsdatum
- Berechtigungen
- Optional SHA-256-Prüfsumme
- Archivvolume
- Archivpfad
- Verarbeitungsstatus

Das Manifest darf auch bei sehr großen Dateibeständen nicht vollständig im PHP-Arbeitsspeicher gehalten werden müssen.

Entwickle dafür eine geeignete persistente Indexstruktur.

---

# 8. Datenbank-Backups

Unterstütze MySQL und MariaDB.

Funktionen:

- Vollständiger Datenbankexport
- Export einzelner Tabellen
- Ausschluss einzelner Tabellen
- Unterstützung eigener Tabellenpräfixe
- Unterstützung großer Tabellen
- Batchweise Datenausgabe
- Streaming
- Korrekte Behandlung von Binärdaten und Sonderzeichen
- Struktur und Daten
- Views, Trigger und weitere Datenbankobjekte, soweit technisch unterstützt
- Konsistenter Export bei geeigneten Storage Engines

Vermeide das vollständige Laden großer Tabellen.

Die Implementierung muss dokumentieren, unter welchen Bedingungen ein transaktional konsistenter Snapshot möglich ist.

Ein Datenbankexport und die dazugehörigen Dateien sind nicht automatisch ein atomarer gemeinsamer Snapshot.

Für WordPress reicht es nicht, ausschließlich Tabellen mit dem Standardpräfix zu berücksichtigen. Andere Tabellen müssen optional eingeschlossen werden können.

---

# 9. Archive Engine

## 9.1 Archivformat

Verwende standardmäßig ZIP mit ZIP64-Unterstützung.

Vorgaben:

- Komprimierungsstufe konfigurierbar
- Streaming beziehungsweise ressourcenschonende Erstellung
- Unterstützung sehr großer Dateien
- Unterstützung sehr vieler Dateien
- Integritätsprüfung
- Versioniertes Manifest
- Kompatibilität mit gewöhnlichen ZIP-Werkzeugen, soweit möglich

Nutze etablierte PHP-Bibliotheken, wenn sie die Anforderungen erfüllen.

## 9.2 Archivvolumes

Implementiere ein Konzept unabhängiger Archivvolumes.

Beispiel:

- backup-001.zip
- backup-002.zip
- backup-003.zip

Die Standardgröße soll konfigurierbar sein, beispielsweise 256 MB.

Wichtig:

Ein Volume ist ein eigenständiges, abgeschlossenes Archiv und nicht einfach ein beliebig abgeschnittenes ZIP.

Große Einzeldateien benötigen ein definiertes Chunking-Verfahren beziehungsweise eine geeignete Sonderbehandlung.

Ein vollständig geschriebenes und verifiziertes Volume muss nach einem Prozessabbruch nicht erneut erstellt werden.

Das Manifest beschreibt die Reihenfolge, Inhalte und Abhängigkeiten sämtlicher Volumes.

Biete optional einen Export als einzelnes ZIP an.

## 9.3 Backup-Format

Das Backup-Format muss versioniert sein.

Ein Backup besteht logisch aus:

- Globalem Manifest
- Dateiindex
- Datenbankexporten
- Archivvolumes
- Metadaten
- Prüfsummen
- Optionalen Verschlüsselungsinformationen
- Optionalen Referenzen auf vorherige Backups

Das Manifest muss außerhalb der WordPress-Datenbank verwendbar sein.

Die eigenständige Restore-Anwendung muss dieses Format vollständig interpretieren können.

---

# 10. Storage

Verwende **League Flysystem 3** als grundlegende Storage-Abstraktion.

## 10.1 Speicherziele

Mindestens:

- Lokales Dateisystem
- SFTP
- S3-kompatibler Objektspeicher

Die Architektur muss zusätzliche Adapter ermöglichen, beispielsweise:

- WebDAV
- Weitere S3-Anbieter
- FTP/FTPS, optional
- Weitere Cloud-Speicher

Zusätzliche Adapter dürfen optionale Composer-Abhängigkeiten besitzen.

## 10.2 Backup-Staging

Der normale Ablauf:

1. Backup lokal erstellen.
2. Backup verifizieren.
3. Archivvolumes auf externe Ziele übertragen.
4. Remote-Daten verifizieren.
5. Backup finalisieren.
6. Staging nach Richtlinie bereinigen.

Die lokale Staging-Area muss konfigurierbar sein.

Auf Shared Hosting sind Platzmangel und eingeschränkte Dateirechte zu berücksichtigen.

## 10.3 Uploads

Unterstütze:

- Mehrere Speicherziele je Backup
- Fortschrittsanzeige
- Retry mit Backoff
- Konfigurierbare Timeouts
- Chunked Upload
- Resumable Upload, soweit Provider dies unterstützt
- Verifikation nach Übertragung
- Temporäre Objektnamen und sichere Finalisierung

Flysystem stellt nicht für jeden Anbieter dieselben erweiterten Upload-Funktionen bereit.

Definiere deshalb zusätzliche Capability-Interfaces, etwa für Multipart-Uploads und serverseitige Objektoperationen.

Fehlgeschlagene Uploads auf ein Ziel dürfen erfolgreiche Replikate anderer Ziele nicht beschädigen.

Der Status muss pro Speicherziel dokumentiert werden.

---

# 11. Backup-Retention

Unterstütze mehrere Aufbewahrungskriterien:

- Maximale Anzahl Backups
- Maximales Alter
- Maximale Speichernutzung, soweit bestimmbar
- Tägliche Aufbewahrung
- Wöchentliche Aufbewahrung
- Monatliche Aufbewahrung
- Optional jährliche Aufbewahrung

Implementiere Grandfather-Father-Son-Retention.

Aufbewahrungsregeln müssen pro Backup-Profil beziehungsweise Speicherziel konfigurierbar sein.

Wichtig:

Bei inkrementellen Backups darf ein benötigtes Basisbackup nicht automatisch gelöscht werden.

Eine Retention-Entscheidung muss die komplette Abhängigkeitskette berücksichtigen.

Weitere Anforderungen:

- Dry-Run
- Anzeige geplanter Löschungen
- Schutz manuell markierter Backups
- Schutz des letzten gültigen Full Backups
- Fehlerbehandlung bei nicht erreichbaren Speichersystemen
- Keine Löschung aufgrund unvollständiger oder inkonsistenter Metadaten

---

# 12. Verschlüsselung

Unterstütze optionale clientseitige Backup-Verschlüsselung.

Vorgaben:

- Authentifizierte Verschlüsselung
- Bevorzugt PHP Sodium
- Streamingfähiges Verfahren
- Keine vollständigen Archive im RAM
- Sichere Schlüsselableitung
- Separate Schlüsselverwaltung
- Konfigurierbare Schlüsselidentifikation
- Verifikation vor der Entschlüsselung beziehungsweise während des authentifizierten Datenstroms

Passwörter und Schlüssel dürfen nicht unverschlüsselt in Logs oder Manifesten abgelegt werden.

Ein verschlüsseltes Backup muss durch die Standalone-Restore-Anwendung entschlüsselt werden können.

Die Verschlüsselung darf nicht von geheimen Daten abhängig sein, die ausschließlich in der ursprünglichen WordPress-Datenbank gespeichert sind.

Implementiere ein versioniertes, dokumentiertes Verschlüsselungsformat.

Die notwendige Sodium-Erweiterung muss vor Verwendung geprüft werden.

---

# 13. Standalone-Restore

**Dies ist eine zentrale Kernfunktion des Projekts.**

Die Wiederherstellung muss unabhängig von einer lauffähigen WordPress-Installation funktionieren.

Entwickle eine eigenständige PHP-Anwendung, die zusammen mit den Backups oder separat verteilt werden kann.

## 13.1 Startmöglichkeiten

- Webbasierter Restore-Assistent
- Kommandozeilenanwendung

Der Web-Assistent muss vor jeglichem Zugriff auf Backup-Inhalte wirksam geschützt sein.

Keine unauthentifizierte Installation oder Datenbankoperation.

## 13.2 Restore-Funktionen

- Backup einlesen
- Backup-Format prüfen
- Backup-Volumes erkennen
- Integrität prüfen
- Verschlüsselte Backups entschlüsseln
- Zielverzeichnis festlegen
- Existierende Dateien erkennen
- Dateien wiederherstellen
- Datenbank importieren
- Datenbankverbindung ändern
- WordPress-Konfiguration anpassen
- Website-URL ändern
- Pfadreferenzen migrieren
- Fortschritt anzeigen
- Fehler protokollieren
- Abgebrochene Wiederherstellungen nach Möglichkeit fortsetzen

## 13.3 Migration und Klonen

Eine WordPress-Installation muss auf einen neuen Server, eine andere Domain oder ein anderes Unterverzeichnis übertragen werden können.

Beispiel:

Quelle:

`https://alte-domain.de`

Ziel:

`https://neue-domain.de/wordpress`

Notwendige Funktionen:

- URL-Migration
- Pfadmigration
- Anpassung der Datenbankverbindung
- Anpassung des Tabellenpräfixes, soweit notwendig
- Korrekte Behandlung serialisierter PHP-Daten
- Korrekte Behandlung von Arrays und Objektdaten
- Erhaltung der Datenintegrität
- Optionaler Dry-Run der Migration

Verwende niemals blindes SQL-String-Replacement, das serialisierte PHP-Daten beschädigen kann.

Vermeide das Ausführen nicht vertrauenswürdiger PHP-Objekte während der Verarbeitung serialisierter Daten.

## 13.4 Selektiver Restore

Ermögliche:

- Wiederherstellung einzelner Dateien
- Wiederherstellung kompletter Verzeichnisse
- Selektiven Datenbankrestore, soweit sicher möglich
- Auswahl aus dem Backup-Dateiindex
- Überschreibungsregeln

## 13.5 Vergleich vor Restore

Vergleiche Dateien aus dem Backup mit dem lokalen Dateisystem.

Zeige:

- Nur im Backup vorhanden
- Nur lokal vorhanden
- Geändert
- Identisch
- Nicht vergleichbar

Vergleichskriterien:

- Pfad
- Dateigröße
- Änderungsdatum
- Optional SHA-256

Hashbasierte Vergleiche sind genauer als reine Metadatenvergleiche.

Der Nutzer muss Unterschiede vor dem Wiederherstellen einsehen können.

## 13.6 Sicherheit

Besonders wichtig:

- Schutz vor Zip Slip und Directory Traversal
- Keine unkontrollierte Extraktion über Symlinks
- Schutz vor unberechtigtem Überschreiben beliebiger Serverdateien
- Begrenzung von Entpackgrößen und Ressourcen
- Explizite Bestätigung des Zielpfades
- Geschützte Zugangsdaten
- Keine öffentlichen Backup-Downloads
- Kontrollierter Abschluss
- Sperrung und Löschung der Restore-Anwendung nach erfolgreichem Abschluss

Der Restore soll bei Fehlern keine irreführende Erfolgsmeldung anzeigen.

Ein vollständiger Rollback kann nicht generell garantiert werden. Implementiere stattdessen eine nachvollziehbare Wiederherstellungsstrategie mit Checkpoints und möglichst sicheren Austauschoperationen.

---

# 14. Inkrementelle und differenzielle Backups

Plane die Architektur von Beginn an dafür.

## Incremental

Sichert Änderungen seit dem vorherigen passenden Backup.

## Differential

Sichert Änderungen seit dem letzten Full Backup.

Berücksichtige:

- Neue Dateien
- Geänderte Dateien
- Gelöschte Dateien
- Umbenannte Dateien
- Änderungen an Dateimetadaten
- Vorgängerreferenzen
- Abhängigkeiten bei Retention
- Rekonstruktion des vollständigen Datenbestands

Verwende eine Backup-Chain beziehungsweise ein referenzierbares Manifestmodell.

Für die erste Erweiterungsstufe dürfen Datenbanken weiterhin vollständig gesichert werden.

Echte inkrementelle Datenbanksicherungen sind ein separates, technisch aufwendigeres Feature.

Ein Restore muss automatisch bestimmen können, welche Backup-Volumes zur Rekonstruktion eines bestimmten Zeitpunkts erforderlich sind.

---

# 15. Monitoring und Logs

Die Administrationsoberfläche muss detaillierte Fortschrittsinformationen anzeigen.

Beispiele:

- Aktuelle Phase
- Aktuell verarbeitete Datei
- Anzahl aller Dateien
- Bereits verarbeitete Dateien
- Verarbeitete Bytes
- Gesamtgröße
- Komprimierungsrate
- Upload-Fortschritt
- Übertragungsrate
- Bisherige Laufzeit
- Geschätzte Restdauer
- Aktuelle Warnungen
- Fehler
- Status einzelner Speicherziele

Fortschritt und Ereignisse werden serverseitig persistent gespeichert.

Frontend-Aktualisierung vorzugsweise über REST-Polling.

Optional SSE, sofern die Hosting-Umgebung dies unterstützt.

Logs benötigen:

- Zeitstempel
- Severity
- Job-ID
- Phase
- Meldung
- Strukturierte technische Details
- Filterbarkeit
- Sichere Behandlung sensibler Informationen

Protokolle dürfen bei großen Backups nicht unbegrenzt anwachsen.

---

# 16. Benachrichtigungen

Unterstütze E-Mail-Benachrichtigungen über WordPress Mail.

Ereignisse:

- Backup erfolgreich
- Backup fehlgeschlagen
- Backup mit Warnungen
- Upload fehlgeschlagen
- Backup überfällig
- Speicherplatz knapp
- Restore erfolgreich
- Restore fehlgeschlagen

Konfiguration pro Profil beziehungsweise Zeitplan.

Benachrichtigungen müssen dedupliziert werden können, um bei wiederholten Retries keine Nachrichtenflut zu erzeugen.

---

# 17. Pre-/Post-Hooks

Entwickle ein erweiterbares Event-System.

Mindestens folgende Events:

- before_backup
- after_backup
- backup_failed
- before_database_export
- after_database_export
- before_upload
- after_upload
- before_restore
- after_restore
- restore_failed

Unterstütze WordPress Actions/Filters sowie frameworkunabhängige Core-Events.

Ein Hook darf niemals unbemerkt den Backup-Zustand inkonsistent machen.

Definiere, welche Hooks Fehler auslösen dürfen und wie mit Exceptions umgegangen wird.

Keine frei konfigurierbare Ausführung beliebiger Shell-Befehle über die Weboberfläche.

---

# 18. WordPress Administration

Implementiere eine moderne Vue-/Quasar-Oberfläche.

## Dashboard

Anzeige von:

- Backup-Gesundheit
- Letztem erfolgreichem Backup
- Letztem fehlgeschlagenem Backup
- Nächster geplanter Sicherung
- Anzahl vorhandener Backups
- Speicherverbrauch
- Zustand der Speicherziele
- Warnungen

## Backup-Profile

- Erstellen
- Bearbeiten
- Kopieren
- Löschen
- Manuell ausführen

## Zeitpläne

- Übersicht
- Konfiguration
- Aktivieren/deaktivieren
- Nächster Lauf
- Letzter Lauf

## Backups

- Tabelle vorhandener Sicherungen
- Filter
- Detailansicht
- Archiv durchsuchen
- Dateivergleich
- Herunterladen
- Verifizieren
- Wiederherstellen
- Löschen

## Laufende Jobs

- Fortschrittsanzeige
- Aktuelle Phase
- Live-Logs
- Pause
- Fortsetzen
- Abbrechen

## Speicherziele

- Speicherziel erstellen
- Zugangsdaten hinterlegen
- Verbindung prüfen
- Remote-Backups entdecken
- Speichernutzung anzeigen, soweit unterstützt

## Einstellungen

- Staging-Verzeichnis
- Ressourcenlimits
- Verschlüsselung
- Benachrichtigungen
- Logging
- Cron
- Standard-Retention

Verwende konsistente UI-Komponenten und klare Fehlerdialoge.

Die Benutzeroberfläche darf niemals alleinige Instanz für sicherheitskritische Validierungen sein. Jede REST-Aktion benötigt serverseitige Berechtigungs- und Eingabeprüfungen.

---

# 19. REST API

Erstelle eine versionierte REST API.

Beispielhafte Ressourcen:

`/swbackupit/v1/profiles`

`/swbackupit/v1/schedules`

`/swbackupit/v1/jobs`

`/swbackupit/v1/backups`

`/swbackupit/v1/storage-targets`

`/swbackupit/v1/settings`

`/swbackupit/v1/health`

Weitere Endpunkte:

- Backup starten
- Job pausieren
- Job fortsetzen
- Job abbrechen
- Job-Logs abrufen
- Backup-Dateien durchsuchen
- Dateien vergleichen
- Backup verifizieren
- Restore vorbereiten
- Restore ausführen
- Storage-Verbindung testen
- Remote-Backups importieren

Sämtliche Endpunkte benötigen geeignete WordPress Capabilities.

Mutierende Anfragen müssen authentifiziert und gegen CSRF geschützt sein.

Verwende konsistente JSON-Antworten und standardisierte Fehlerstrukturen.

---

# 20. WP-CLI

Implementiere eine vollständige WP-CLI-Integration.

Beispiel:

```bash
wp swbackupit profile list
wp swbackupit profile show full
wp swbackupit backup create --profile=full
wp swbackupit backup list
wp swbackupit backup status <id>
wp swbackupit backup verify <id>
wp swbackupit backup compare <id>
wp swbackupit backup restore <id>
wp swbackupit job resume <id>
wp swbackupit job cancel <id>
wp swbackupit schedule list
wp swbackupit schedule run <id>
wp swbackupit storage list
wp swbackupit storage test <id>
wp swbackupit retention run --dry-run
```

Kommandos müssen sinnvolle Exit-Codes und maschinenlesbare Ausgaben unterstützen.

Für Automatisierungen soll optional JSON als Ausgabeformat möglich sein.

Die eigenständige Restore-CLI darf nicht von WP-CLI abhängig sein.

---

# 21. Persistenz

Verwende für Jobs und Backup-Metadaten dedizierte WordPress-Datenbanktabellen.

Vorgeschlagene Tabellen:

- swbi_profiles
- swbi_schedules
- swbi_jobs
- swbi_backups
- swbi_backup_locations
- swbi_storage_targets
- swbi_job_events

Nutze das WordPress-Tabellenpräfix.

Implementiere:

- Datenbankschema-Versionierung
- Automatische Migrationen
- Geeignete Indizes
- Bereinigung alter Ereignisse
- Integritätsprüfungen
- Sichere Updates

Das externe Backup-Manifest bleibt unabhängig von diesen Tabellen.

Ein Standalone-Restore muss auch dann möglich sein, wenn sämtliche SWBackupIt-Tabellen verloren gegangen sind.

---

# 22. Enterprise-Funktionen

Berücksichtige im Architekturkonzept zusätzlich:

## Backup Verification

- Integritätsprüfung nach Erstellung
- Verifikation nach Upload
- Regelmäßige Hintergrundprüfung
- Erkennung fehlender Volumes

## Restore Testing

- Automatisierte Testwiederherstellung in isolierter Umgebung
- Dokumentation des Restore-Ergebnisses
- Keine Veränderung produktiver Systeme

## Disaster Recovery

- Export eines vollständigen Recovery-Pakets
- Restore von externem Storage
- Wiederaufbau des Backup-Katalogs aus Manifesten

## Multi-Destination

- Mehrere Speicherziele
- Unabhängige Replikationsstatus
- Wiederholung fehlgeschlagener Übertragungen

## Immutable Backups

- Schutz vor unbeabsichtigten Löschungen
- Verwendung von Object Lock, soweit vom Storage unterstützt
- Respektierung gesetzter Aufbewahrungsfristen

## Audit Logging

- Wer hat ein Backup erstellt?
- Wer hat ein Backup heruntergeladen?
- Wer hat ein Backup gelöscht?
- Wer hat einen Restore durchgeführt?
- Welche Konfiguration wurde verändert?

## WordPress Multisite

Plane Multisite-Unterstützung.

Vollständiges Netzwerkbackup zuerst; selektive Wiederherstellung einzelner Sites kann eine spätere Ausbaustufe sein.

## Backup vor Updates

Optionale automatische Sicherung vor:

- WordPress Core Updates
- Plugin Updates
- Theme Updates

Update-Hooks dürfen keine unsichere Blockierung oder Endlosschleife verursachen.

---

# 23. Performance und Ressourcenmanagement

Die Anwendung muss auch auf eingeschränktem Shared Hosting zuverlässig arbeiten können, soweit dessen technische Grenzen dies zulassen.

Vorgaben:

- Keine großen Daten vollständig in den Arbeitsspeicher laden
- Streaming I/O
- Kleine Verarbeitungseinheiten
- Zeitbudget pro Worker-Aufruf
- Konfigurierbare Batch-Größen
- Konfigurierbare ZIP-Volume-Größe
- Konfigurierbare Upload-Parallelität
- Begrenzte Log-Aufbewahrung
- Kontrollierte Temp-Dateien
- Speichervorprüfung
- Regelmäßige Checkpoints
- Ressourcenmonitoring

Berücksichtige insbesondere:

- PHP max_execution_time
- PHP memory_limit
- PHP-FPM Worker-Abbrüche
- Eingeschränkte Dateiberechtigungen
- Begrenzten lokalen Speicher
- Instabile externe Verbindungen
- Unzuverlässiges WP-Cron
- PHP-Prozesse ohne Shell-Zugriff

Keine Architekturentscheidung darf voraussetzen, dass dauerhaft ein PHP-Prozess im Hintergrund läuft.

Auf dedizierten Servern soll eine leistungsfähigere CLI-Ausführung möglich sein.

---

# 24. Sicherheit

Sicherheit besitzt hohe Priorität.

Berücksichtige mindestens:

- WordPress Capability Checks
- REST-Authentifizierung und Nonces
- Input Validation
- Output Escaping
- SQL Injection Protection
- Path Traversal Protection
- Zip Slip Protection
- Sichere Symlink-Behandlung
- Schutz vor ZIP-Bombs
- Authentifizierte Verschlüsselung
- Sichere Speicherung von Zugangsdaten
- Keine öffentlichen Backups
- Sichere temporäre Verzeichnisse
- Rate Limiting für sensible Restore-Aktionen
- Geschützte Standalone-Restore-Oberfläche
- Keine ungewollte Ausführung archivierter PHP-Dateien
- Keine unkontrollierte Deserialisierung
- Schutz vor SSRF durch frei konfigurierbare Storage-Endpunkte
- Audit-Logs für kritische Operationen

Backup-Dateien enthalten häufig Zugangsdaten, personenbezogene Daten und WordPress-Konfigurationen. Behandle sie grundsätzlich als vertraulich.

---

# 25. Tests

Implementiere automatisierte Tests auf mehreren Ebenen.

## Unit Tests

- Profile
- Filterregeln
- Dateiscanner
- Manifest
- Job-Zustandsmaschine
- Retention
- Verschlüsselung
- Backup-Abhängigkeiten

## Integrationstests

- ZIP-Erstellung
- Datenbankexport
- Flysystem
- S3-kompatibler Storage
- Job-Persistenz
- WordPress REST API
- WP-CLI

## End-to-End-Tests

- WordPress installieren
- Beispieldaten erzeugen
- Full Backup durchführen
- WordPress-Dateien und Datenbank entfernen
- Standalone-Restore durchführen
- Wiederhergestellte Installation prüfen

## Fehler- und Lasttests

Simuliere:

- PHP-Timeout
- Prozessabbruch
- Netzwerkunterbrechung
- Defektes Backup-Volume
- Fehlendes Volume
- Falschen Entschlüsselungsschlüssel
- Vollgelaufenen Datenträger
- Beschädigten Datenbankexport
- Sehr viele kleine Dateien
- Einzelne sehr große Dateien
- Gleichzeitige Backup-Anforderungen
- Veränderte Dateien während der Sicherung
- Fehlgeschlagene Retention-Löschungen

Besonders wichtig ist, dass abgeschlossene Archivvolumes nach Prozessabbrüchen nicht unnötig neu erstellt werden.

---

# 26. Dokumentation

Erstelle zwei getrennte Dokumentationen.

## Benutzerhandbuch

Datei: `docs/user-guide.md`

Kurz, verständlich und praxisorientiert.

Beschreibe:

- Installation
- Backup-Profile
- Zeitpläne
- Speicherziele
- Manuelle Sicherungen
- Backup-Übersicht
- Wiederherstellung
- Migration auf andere Domain oder Server
- Verschlüsselung
- Benachrichtigungen
- Fehlerbehebung

## Technische Dokumentation

Datei: `docs/architecture.md`

Prägnant, aber technisch nachvollziehbar.

Beschreibe:

- Komponenten
- Verzeichnisstruktur
- Abhängigkeiten
- Datenmodelle
- Job-State-Machine
- Archivformat
- Restore-Prozess
- Storage-Abstraktion
- Erweiterungspunkte
- Sicherheitsmodell
- Tests

Zusätzliche Dateien:

- `README.md`
- `CONTRIBUTING.md`
- `AGENTS.md`
- `docs/backup-format.md`
- `docs/security.md`
- `docs/wp-cli.md`
- `docs/development.md`

Erstelle ein Architekturdiagramm in Mermaid.

Die Dokumentation muss dem tatsächlichen Implementierungsstand entsprechen.

---

# 27. Entwicklungsphasen

Setze das Projekt iterativ um.

## Phase 1 – Core / MVP

- Repository-Struktur
- Composer-Konfiguration
- WordPress-Plugin-Bootstrap
- Vue-/Quasar-Grundgerüst
- Datenbankschema
- Backup-Profile
- Zeitpläne
- Full Backup
- Files Only
- Database Only
- ZIP/ZIP64
- Volumekonzept
- Lokales Staging
- Persistente Jobs
- Checkpoints
- Fortschrittsanzeige
- WP-CLI
- Einfacher Standalone-Restore
- Erste automatisierte Tests

Die erste Phase muss bereits einen vollständigen Backup-/Restore-Durchlauf ermöglichen.

## Phase 2 – Storage & Recovery

- Flysystem
- S3 und SFTP
- Mehrere Speicherziele
- Upload-Retry
- Remote-Verifikation
- Retention
- Verschlüsselung
- Erweiterter Standalone-Restore
- URL- und Pfadmigration
- Selektiver File-Restore
- Dateivergleich
- Benachrichtigungen
- Verbesserte Tests

## Phase 3 – Advanced Backup

- Inkrementelle Backups
- Differenzielle Backups
- Vollständiges Resume
- Chunked Upload
- Multipart Upload
- Backup-Chains
- Abhängigkeitsbewusste Retention
- Erweiterbares Hook-System

## Phase 4 – Enterprise

- Audit Logging
- Restore-Testautomatisierung
- Health Monitoring
- Multisite
- Immutable Storage
- Automatische Pre-Update-Backups
- Erweiterte Fehlerbehandlung
- Last- und Sicherheitstests

---

# 28. Arbeitsweise des Coding-Agenten

Arbeite strukturiert und selbstständig.

## Initialisierung

1. Prüfe den aktuellen Zustand des Repositories.
2. Analysiere bereits vorhandene Dateien und Implementierungen.
3. Erstelle eine sinnvolle Projektstruktur.
4. Lege die technische Architektur fest.
5. Dokumentiere wichtige Entscheidungen.
6. Erstelle einen nachvollziehbaren Implementierungsplan.

Schreibe diesen Plan nach `docs/implementation-plan.md`.

Erstelle außerdem eine Datei `AGENTS.md`, die die langfristigen Architekturregeln und Entwicklungskonventionen dokumentiert.

## Implementierung

Arbeite anschließend iterativ.

Für jedes Feature:

1. Anforderungen analysieren.
2. Schnittstellen festlegen.
3. Implementierung erstellen.
4. Tests ergänzen.
5. Tests und statische Analyse ausführen.
6. Dokumentation aktualisieren.
7. Implementierungsplan aktualisieren.

Bevorzuge vertikale, vollständig funktionsfähige Implementierungsschritte gegenüber einer Vielzahl leerer Klassen und Platzhalter.

Verwende keine Mock-Implementierungen im produktiven Programmcode, um fehlende Funktionalität zu verschleiern.

## Qualität

- Implementiere nachvollziehbare Fehlerbehandlung.
- Vermeide unnötige Komplexität.
- Verwende saubere Abstraktionen.
- Berücksichtige Shared Hosting.
- Vermeide unnötige Datenbankabfragen.
- Halte Sicherheitsgrenzen konsequent ein.
- Nutze echte Integrationstests für Backup- und Restore-Pfade.
- Kennzeichne nicht implementierte Features explizit.

## Autonomie und Grenzen

Du darfst eigenständig:

- Dateien erstellen und bearbeiten
- Projektstruktur festlegen
- Composer- und npm-Abhängigkeiten hinzufügen
- Tests ausführen
- Dokumentation erstellen
- Refactoring durchführen

Verändere keine fremden Projekte oder Dateien außerhalb des zugewiesenen Repositories.

Führe keine destruktiven Operationen auf produktiven WordPress-Installationen oder externen Speichersystemen durch.

Veröffentliche keinen Code und pushe keine Änderungen ohne ausdrückliche Freigabe.

Sicherheitsrelevante Architekturentscheidungen müssen dokumentiert werden.

## Fortschrittsberichte

Berichte nach jedem abgeschlossenen Meilenstein:

- Implementierte Funktionen
- Wichtige Architekturentscheidungen
- Ausgeführte Tests und Ergebnisse
- Bekannte Einschränkungen
- Nächster Implementierungsschritt

---

# 29. Definition of Done

Ein Feature gilt nur dann als abgeschlossen, wenn:

- Es tatsächlich implementiert ist.
- Seine API definiert ist.
- Notwendige Tests existieren.
- Tests erfolgreich ausgeführt wurden oder konkrete Blocker dokumentiert sind.
- Fehlerfälle berücksichtigt wurden.
- Die Dokumentation aktualisiert wurde.
- Es keine bekannten kritischen Sicherheitsprobleme enthält.

Ein Backup gilt nur dann als erfolgreich, wenn:

1. Sämtliche erforderlichen Daten verarbeitet wurden.
2. Alle erforderlichen Archivvolumes vollständig vorliegen.
3. Das Manifest vollständig und gültig ist.
4. Die vorgesehenen Integritätsprüfungen erfolgreich waren.
5. Die erforderlichen Speicherziele entsprechend ihrer konfigurierten Erfolgsrichtlinie bestätigt wurden.

Ein erfolgreicher Backup-Job bedeutet nicht automatisch, dass ein späterer Restore garantiert erfolgreich sein wird. Ein separater Restore-Test muss entsprechend ausgewiesen werden.

---

# 30. Erster Arbeitsauftrag

Beginne mit Phase 1.

Erstelle zunächst:

1. Die vollständige Repository-Struktur.
2. Die Composer-Konfiguration.
3. Das WordPress-Plugin-Grundgerüst.
4. Die Vue-3-/Quasar-/Vite-Integration.
5. Die technischen Architekturinterfaces.
6. Die Datenmodelle.
7. Die Job-State-Machine.
8. Den grundlegenden lokalen Backup-/Restore-Workflow.
9. Ein erstes automatisiertes End-to-End-Testszenario.
10. Die initiale technische Dokumentation.

**Wichtig:** Implementiere von Anfang an einen realen, durchgängigen Backup-/Restore-Pfad. Priorisiere dessen Zuverlässigkeit vor zusätzlichen Funktionen.

Erstelle zuerst einen nachvollziehbaren Implementierungsplan und arbeite ihn anschließend schrittweise ab. Unterbrich die Implementierung nicht für unnötige Rückfragen. Falls technische Entscheidungen nicht eindeutig vorgegeben sind, wähle eine robuste, etablierte Lösung und dokumentiere die Begründung.

Das langfristige Ziel ist ein leistungsfähiges, sicheres und zuverlässig wiederherstellbares Enterprise-Backup-Plugin für WordPress.
