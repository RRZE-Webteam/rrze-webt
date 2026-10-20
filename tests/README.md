# Lokale eTranslation-Simulation

Diese Tests sind die Vergleichsbasis für die Reparatur des Plugins. Sie verwenden
die tatsächlichen WordPress-REST-Routen, Berechtigungsprüfungen und Post-Meta-Daten.
Die ausgehende WordPress-HTTP-Schnittstelle wird durch einen lokalen Simulator
ersetzt. Es wird keine Verbindung zur EU hergestellt.

**Der Simulator läuft ausschließlich im ausdrücklich gestarteten WP-CLI-Prozess.**
Er wird vom Plugin nicht automatisch geladen. Der normale Firefox-Editor verwendet
weiterhin die unveränderte Plugin-Anbindung; dort ist mit diesem Schritt noch kein
Simulationsmodus eingeschaltet.

## Webzugriff und Releases

Die `.htaccess` in diesem Verzeichnis sperrt unter Apache 2.4 den HTTP-Zugriff auf
die Tests und alle Fixtures mit `Require all denied`. Voraussetzung ist, dass der
Webserver diese Datei auswertet und Autorisierungsregeln darin erlaubt. WP-CLI
liest die Dateien direkt vom Dateisystem und bleibt davon unberührt. Andere
Webserver, etwa nginx, benötigen eine entsprechende Serverkonfiguration.

Die Tests bleiben im Repository und dürfen auch auf `main` liegen. Die
`.gitattributes` im Plugin-Hauptverzeichnis schließt das gesamte Testverzeichnis
aus Git-Archiven und den automatisch erzeugten GitHub-Quellcode-Downloads aus.
Für eigene ZIP-Pakete gilt der Ablauf unter
[Release archives](../README.md#release-archives). Es gibt keine GitHub Action,
die Tests beim Merge verbietet.

## Starten

Voraussetzungen:

- WordPress mit aktiviertem RRZE WEB-T und einem vorhandenen Website-Administrator.
- PHP ab 8.2 und WP-CLI. Composer, PHPUnit und neue npm-Abhängigkeiten sind nicht nötig.
- Eine Adresse mit `localhost`, `.localhost`, `127.0.0.1` oder `::1`.
- `WP_ENVIRONMENT_TYPE` ist `local`, `development` oder `staging`.
- Keine externe HTTP-Freigabe über `WP_ACCESSIBLE_HOSTS`.

Aus dem Plugin-Verzeichnis:

```sh
npm run test:integration -- https://nat.multisite.localhost:8890
```

Ohne npm:

```sh
bash tests/run.sh https://nat.multisite.localhost:8890
```

Der Wrapper wechselt ins WordPress-Hauptverzeichnis, weil das lokale Plugin
`rrze-cli` dieses Arbeitsverzeichnis verlangt. Bei abweichender Verzeichnisstruktur
oder einem verlinkten Plugin kann der Befehl von dort direkt gestartet werden:

```sh
wp --url=https://nat.multisite.localhost:8890 \
  --require=/absoluter/pfad/rrze-webt/tests/cli.php \
  rrze-webt test
```

Einzelne Fälle und strenger Abnahmelauf:

```sh
npm run test:integration -- https://nat.multisite.localhost:8890 --only=delivery_v2
npm run test:integration -- https://nat.multisite.localhost:8890 --strict
```

## Ergebnisse richtig lesen

| Ausgabe | Bedeutung | Normaler Exitcode |
| --- | --- | --- |
| `PASS` | Das geprüfte Verhalten ist erfüllt. | 0 |
| `KNOWN` | Ein in `known-failures.php` dokumentierter Defekt wurde reproduziert. | 0 |
| `FAIL` | Neue Abweichung, fehlerhafte Testvoraussetzung oder Ausnahme. | 1 |
| `XPASS` | Ein bekannter Defekt ist behoben; sein Baseline-Eintrag muss entfernt werden. | 1 |
| `ENV BLOCKED` | Die Website blockiert anonyme Rückrufe vor dem WEB-T-Handler. | 0 |

Mit `--strict` führen auch `KNOWN` und `ENV BLOCKED` zu Exitcode 1. Ein erfolgreicher
normaler Lauf bestätigt daher die **bekannte Ausgangslage**, nicht die vollständige
v2-Kompatibilität. Ein Setup-Fehler oder eine Exception darf nie als `KNOWN` gelten.
Nach jeder Reparatur wird der zugehörige Eintrag in `known-failures.php` entfernt;
die Prüfung selbst bleibt bestehen.

Der Bericht gibt außerdem an, ob eigene Entwurfsseiten zurückgeblieben sind.
PHP-Deprecation-Meldungen anderer installierter Plugins werden nicht unterdrückt.

## Zwei Protokolle mit unterschiedlichen Aufgaben

**Legacy** stellt den bisher verwendeten Ablauf nach: Digest-Challenge, Annahme
und eine spätere Base64-Dokumentlieferung. Damit lassen sich Jobs, Timeouts und
Callbacks untersuchen, obwohl der aktuelle Sender noch kein v2 spricht. Der
Simulator prüft dabei keine vollständige kryptografische Digest-Authentifizierung.

**V2** prüft den für das Plugin relevanten Teil des neuen Vertrags: exakte
`askTranslate`-Adresse, Basic Authentication mit Testzugangsdaten, verschachteltes
`documentToTranslate.document`, `deliveries.http`, Fehlerbenachrichtigung und
JSON-Rücklieferung mit `result`. Alte Felder, falsche Pfade und falsche
Authentifizierung werden abgewiesen. Das ist kein vollständiger EU-Server und
kein allgemeiner OpenAPI-Validator.

Die v2-Empfangstests legen einen passenden Auftrag direkt im echten `JobStore` an.
So verdeckt ein Fehler im Sender nicht zusätzlich die Fehler im Empfänger.

Quellen des simulierten Formats, geprüft am 20. Oktober 2026:

- [Einführung in eTranslation REST v2](https://language-tools.ec.europa.eu/dev-corner/etranslation/rest-v2/introduction)
- [Migrationsleitfaden](https://language-tools.ec.europa.eu/dev-corner/additional-resources/migration-from-v1-to-v2)
- [Offizielle OpenAPI-Datei](https://language-tools.ec.europa.eu/assets/downloads/eTranslation-RestV2.yaml)

Die HTML-Fixtures sind eigens verfasste, feste Beispieltexte mit Gutenberg-Blöcken,
Formatierung und einem Link. Sie sind keine maschinellen Übersetzungsergebnisse.
Dass diese Fixtures erhalten bleiben, belegt noch nicht, dass die echte EU-
Übersetzung beliebige Gutenberg- oder RRZE-Blöcke unverändert zurückliefert.

## Abgedeckte Abläufe

- Anfrage annehmen, Auftrag abfragen und Ergebnis kontrolliert später zustellen.
- Legacy-Dokumentlieferung und neue v2-Dokumentlieferung.
- Ungültige Zugangsdaten, Transport-Timeout und Fehlermeldung im Callback.
- Abgeschnittenes JSON und gültiges JSON ohne Übersetzungsdaten.
- Ausbleibende Rückmeldung mit abgelaufener Auftragsfrist.
- Rückmeldung vor der Annahmeantwort, doppelte Rückmeldung und doppelte Anfrage.
- Falsche Auftragsnummer oder Zielsprache im Callback.
- Anonyme Zugriffe auf Übersetzen, Auftragsliste und Auftragsbestätigung.
- Erhalt des gespeicherten Ausgangsinhalts bis zur bewussten Übernahme.
- Blockierung unerwarteter ausgehender HTTP-Anfragen.

Eine Verzögerung wird durch eine explizit freigegebene Callback-Warteschlange
simuliert. Für abgelaufene Jobs wird nur der Ablaufzeitpunkt des Testauftrags in
die Vergangenheit gesetzt. Die Tests warten weder 20 Sekunden noch fünf Minuten.
Echte parallele PHP-Prozesse, Browser-Timeouts, TLS und externe Erreichbarkeit sind
damit noch nicht geprüft.

## Umgebung, Isolation und Aufräumen

Die lokale Website beschränkt anonyme REST-Anfragen derzeit mit HTTP 403. Der
Testlauf prüft diese Regel zuerst unverändert mit einem neuen, unbekannten Token.
Für die folgenden Plugin-Tests wird ausschließlich innerhalb des jeweiligen
CLI-Aufrufs `rest_pre_dispatch` vorübergehend ausgesetzt und in `finally`
wiederhergestellt. Routing, Argumentvalidierung und die Berechtigungsprüfungen
der WEB-T-Routen laufen weiterhin über den echten WordPress-Dispatcher. Dadurch
kann eine allgemeine REST-Sperre keine fehlende Plugin-Berechtigungsprüfung
verdecken. Es werden keine Website- oder Netzwerkfreigaben gespeichert.

Weitere Vorkehrungen:

- `WP_HTTP_BLOCK_EXTERNAL` wird vor dem WordPress-Bootstrap aktiviert; während
  der Tests beantwortet `pre_http_request` sämtliche WordPress-HTTP-Aufrufe lokal.
- Die Testadresse nutzt die reservierte Domain `webt.invalid`.
- Zugangsdaten und Sprachliste werden nur für den Prozess durch Fixtures ersetzt.
  Gespeicherte Einstellungen werden nicht überschrieben.
- Während der Fälle wird `wp_mail` abgefangen und kein WP-Cron-Lauf gestartet.
- Jeder Fall erstellt eine eigene Entwurfsseite mit einer eindeutigen Besitzmarke.
  Nur diese IDs werden nach Kontrolle der Marke samt Revisionen und Metadaten
  wieder gelöscht, auch nach fehlgeschlagenen Prüfungen.
- Es werden keine Benutzer angelegt. Der angemeldete Benutzer wird ausschließlich
  im Testprozess gewechselt und anschließend wiederhergestellt.
- Vorhandene Seiten wie `test-48` werden weder gelesen noch verändert.

Die HTTP-Isolation umfasst die WordPress-HTTP-API. Sie ist keine Betriebssystem-
Sandbox für andere Plugins, die eigene Netzwerkbibliotheken oder externe Prozesse
verwenden. Andere installierte Plugins können auf Testseiten-Hooks reagieren und
beispielsweise lokale Logeinträge anlegen. Für vollständig isolierte CI-Läufe ist
eine eigene Testdatenbank sinnvoll.

Bei einem hart abgebrochenen PHP-Prozess kann `finally` nicht laufen. Übrig
gebliebene Entwürfe sind am Titel „WEB-T disposable integration fixture“ und
an der Metadatenmarke `_rrze_webt_test_run` erkennbar. Der nächste Lauf löscht
keine fremden oder früheren Entwürfe automatisch.
