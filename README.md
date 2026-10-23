# dropfolderdelete

SeedDMS-Extension: zeigt die Dateien im eigenen Ablageordner
(Drop Folder) an und löscht sie einzeln – mit Rückfrage, ohne
Shell-Zugriff auf den Server.

## Installation

`dropfolderdelete/` nach `<seeddms-webroot>/ext/` legen (oder das
ZIP über den Extension Manager hochladen), dann in
**Admin Tools → Extension Manager** aktivieren.

Voraussetzung: Ein Ablageordner ist konfiguriert
(**Einstellungen → Server → Ablageordner**). Der Ablageordner eines
Benutzers ist das Unterverzeichnis mit seinem Login-Namen, z. B.
`/var/lib/seeddms/drop/klaus`.

Der Webserver-Benutzer (im Docker-Image `www-data`) braucht
Schreibrechte auf dieses Verzeichnis, sonst schlägt das Löschen
mit einer Fehlermeldung fehl. Prüfen:

```
docker exec -u www-data <container> sh -c 'touch /var/lib/seeddms/drop/<login>/.t && rm /var/lib/seeddms/drop/<login>/.t && echo ok'
```

Legt z. B. ein Scanner die Dateien per SMB/FTP mit einem anderen
Benutzer ab, das Verzeichnis für die Gruppe `www-data` beschreibbar
machen (Gruppe + `chmod g+w`, ggf. setgid-Bit, damit neue Dateien
die Gruppe erben).

## Benutzung

In der „Informationen“-Box jeder Ordneransicht erscheint die Zeile
**Ablageordner** mit der Anzahl der Dateien, z. B.
`Ablageordner: 3 Dateien – einzeln löschen`. Der Link öffnet eine
eigene Seite:

- Liste aller Dateien mit Änderungsdatum und Größe, sortierbar per
  Klick auf die Spaltenköpfe (Name, Datum, Größe; zweiter Klick
  kehrt die Reihenfolge um).
- Klick auf den Dateinamen öffnet die Datei in einem neuen Tab
  (PDF, Bilder, Text) bzw. lädt sie herunter (alle anderen Typen) –
  praktisch bei nichtssagenden Scanner-Dateinamen.
- **Löschen** führt auf eine Bestätigungsseite mit Name, Größe und
  Datum; erst **Ja, löschen** entfernt die Datei endgültig.
- „Zurück zu SeedDMS“ führt in den Ordner zurück, aus dem die Seite
  geöffnet wurde.

### Papierkorb-Icon im Ablageordner-Menü

Das Ablageordner-Menü oben rechts in der Navigationsleiste (es
erscheint nur, wenn Dateien im Ablageordner liegen) zeigt hinter
jedem Dateinamen ein rotes Papierkorb-Icon. Ein Klick darauf führt
direkt auf die Bestätigungsseite dieser Datei. Nach **Ja, löschen**
oder **Abbrechen** geht es zurück in den Ordner, in dem man gerade
war; das Menü zeigt danach die aktualisierte Anzahl.

Technisch ersetzt die Extension dafür die SeedDMS-View
`DropFolderChooser` durch eine Unterklasse (`'views'` in `conf.php`,
Datei `views/bootstrap/class.DropFolderChooser.php`). Überschrieben
ist nur `menuList()` – mit derselben Ausgabe wie im Original
(SeedDMS 6.0.x) plus dem Icon. Der Auswahldialog beim Hochladen aus
dem Ablageordner bleibt unverändert.

Das Menü listet Dateien in Unterordnern immer mit auf. Ein Icon
bekommen diese nur, wenn in den Einstellungen „Dateien in
Unterordnern einbeziehen“ aktiv ist.

Funktioniert mit den Themes `bootstrap` und `bootstrap4`. Zur
Kontrolle im Browser
`out/out.DropFolderChooser.php?action=menuList&recursive=1&folderid=1`
öffnen und den Quelltext ansehen: Am Ende steht
`<!-- dropfolderdelete: 2 of 2 entries with trash icon -->`. Fehlt
der Kommentar, ist die View-Ersetzung nicht aktiv (siehe nächster
Absatz).

Nach einem Update von 1.0.x die Extension-Liste im Extension
Manager neu einlesen (bzw. die Extension einmal deaktivieren und
wieder aktivieren), damit SeedDMS den neuen `views`-Eintrag aus
`conf.php` übernimmt.

Direkter Link, unabhängig vom UI (z. B. als Lesezeichen):

```
ext/dropfolderdelete/out/out.DropFolderDelete.php
```

## Einstellungen

Admin Tools → Extension Manager → dropfolderdelete → Zahnrad-Symbol:

- **Dateien in Unterordnern des Ablageordners einbeziehen**
  (Standard: aus). Dann werden Dateien mit Pfad angezeigt, z. B.
  `2026/scan_001.pdf`. Verzeichnisse selbst werden nie gelöscht.
- **Zeile „Ablageordner“ ausblenden, wenn der Ablageordner leer
  ist** (Standard: aus).

## Sicherheit

- Jeder Benutzer sieht und löscht nur Dateien in **seinem eigenen**
  Ablageordner. Jeder Pfad wird per `realpath()` aufgelöst; alles
  außerhalb (`../`, absolute Pfade, Symlinks) wird abgewiesen.
- Versteckte Dateien (`.xyz`) und Symlinks werden weder angezeigt
  noch gelöscht.
- Gelöscht wird nur per POST mit einem Formular-Token, der an die
  SeedDMS-Sitzung gebunden ist (Schutz gegen CSRF).
- Der Gastzugang hat keinen Zugriff.
- Jedes Löschen wird ins SeedDMS-Log geschrieben
  (`dropfolderdelete: user <login> deleted <pfad>`).
- HTML-, SVG- und andere aktive Dateitypen werden nie im Browser
  angezeigt, sondern nur heruntergeladen.

## Details

- Die Seite ist schlichtes HTML mit eigenem Stylesheet
  (`out/dropfolderdelete.css`, hell/dunkel nach Systemeinstellung)
  und ohne JavaScript. Sie hängt dadurch nicht von den
  Theme-Interna einer bestimmten SeedDMS-Version ab, und die
  Content-Security-Policy von SeedDMS steht nicht im Weg.
- Die View-Ersetzung für das Menü ist gegen den Code von SeedDMS
  6.0.x geschrieben. Bei einem SeedDMS-Update, das `menuList()` im
  Kern ändert, kann das Menü vom Original abweichen; im Zweifel
  `views/` löschen bzw. den `views`-Eintrag in `conf.php`
  entfernen, dann gilt wieder das Original-Menü.
- Entwickelt für SeedDMS 6.0.x im Docker-Image `usteinm/seeddms`,
  PHP 8.x.

## Lizenz

GPL-2.0, siehe `LICENSE`.
