# Redaktions-Spickzettel: WordPress Artikel-Import-Assistent

Diese Seite ausdrucken und am Arbeitsplatz bereithalten.

## 1) Schnellablauf
1. Artikel in Word als `.docx` vorbereiten.
2. In WordPress anmelden. Der Artikel-Import-Assistent öffnet sich direkt (sonst im Menü auf **Artikel-Import** klicken).
3. Den Namen oder die Mitgliedsnummer des echten Autors eingeben.
4. DOCX hochladen.
5. Kategorien prüfen. Manche sind eventuell schon vorausgewählt.
6. Aufmacherbild und Inline-Bilder hochladen.
7. Auf **Vorschau erzeugen** klicken.
8. Warnungen zu Autor, Links und Bildern prüfen.
9. Bei **Nach der Prüfung** die Auswahl prüfen: als Entwurf speichern oder direkt veröffentlichen.
10. Auf **Import abschließen** klicken.
11. Optional fürs Heft: **Für InDesign herunterladen** klicken (in der Vorschau, nach dem Import oder in der Beitragsliste unter dem Titel) und die ZIP-Datei an das Layout geben. Der erste Absatz des Artikels wird im Heft zum Vorspann.

Im Menü sehen Sie nur **Beiträge**, **Medien** und **Artikel-Import**. Profil und Abmelden finden Sie oben rechts unter Ihrem Namen.

## 2) Grundregeln
- Immer als DOCX (`.docx`) speichern, nicht als `.doc`.
- Der Autor muss stimmen: vollständigen Namen oder Mitgliedsnummer eingeben.
- Wird kein passender Autor gefunden, bekommt der Artikel den Standardautor. Die Vorschau zeigt dann eine Warnung. In diesem Fall die Eingabe korrigieren und die Vorschau neu erzeugen.
- Passen mehrere Autoren zum Namen, einen genaueren Namen oder die Mitgliedsnummer eingeben.
- Das eingeloggte Redaktionskonto ist nur intern relevant.
- Zum Aktualisieren den Artikel mit gleichem Titel und gleichem Autor erneut hochladen. Titel und Autor bleiben fest; Text, Bilder, Kategorien und Bio werden ersetzt. Nur geänderte Bilder hochladen.

## 3) Metadaten-Tags in der DOCX
Diese Tags exakt so verwenden.

Pflicht oder dringend empfohlen:
- [TITLE: Ihre Überschrift]
- [SUBTITLE: Ihre Unterzeile]
- [WRITING_DATE: 2026-08-04] (wird das Beitragsdatum; 04.08.2026 geht auch)
- [AUTHOR_MEMBERSHIP: 01234]

Optional:
- [BIO: Kurzer Autorentext] (darf mehrere Absätze lang sein; mit ] abschließen)
- [REISEZEIT: Mai bis August 2024] (nur fürs Heft, Zeile „Reisezeit“)
- [AUTHOR_NAME: Vorname Nachname] (nur fürs Heft: Autorenname für das InDesign-Skript; die Website nimmt die Mitgliedsnummer)
- [RESTRICT] (Beginn des Mitgliederbereichs; ohne Marker: automatisch, standardmäßig nach dem 2. Absatz oder früher bei langen Absätzen ab 1.000 Zeichen)
- [HEADER Zwischenüberschrift]
- [HEADER 3 Zwischenüberschrift]
- [HEADER 4 Zwischenüberschrift]

Regel für Überschriften:
- H1 bleibt dem Artikeltitel vorbehalten.
- H2 kommt aus dem Untertitel oder aus einem einfachen `[HEADER ...]` im Fließtext.
- Für tiefere Ebenen im Fließtext `[HEADER 3 ...]` und `[HEADER 4 ...]` verwenden.

Das Ende des Mitgliederbereichs wird automatisch nach dem letzten Absatz gesetzt; die Autoren-Bio ist immer öffentlich.

Nur für Altdokumente:
- [RESTRICT_START] (wie [RESTRICT])
- [RESTRICT_END] (wird ignoriert)

## 4) Inline-Bildmarker im Artikeltext
Marker an den Stellen einsetzen, an denen Bilder erscheinen sollen:
- [Bild 1]
- [Bild 2: Kurze Bildunterschrift]
- [Image 3: English caption]

Regeln:
- Die Nummerierung beginnt bei 1.
- Bilder in derselben Reihenfolge hochladen.
- Wenn Marker und Uploads nicht zusammenpassen, zeigt die Vorschau eine Warnung.

Formatierung:
- Normale Word-Formatierung verwenden: **fett**, *kursiv*, Links sowie Aufzählungen und nummerierte Listen (auch verschachtelt) bleiben erhalten.
- Unterstreichungen, Farben, Schriftarten und Tabellen werden nicht übernommen.
- Kein Markdown tippen (`**fett**`, `# Überschrift`); es erscheint wörtlich im Text.

## 5) DOCX-Vorlage zum Kopieren
Diesen Block in ein neues Word-Dokument kopieren und ausfüllen.

[TITLE: ]
[SUBTITLE: ]
[WRITING_DATE: ]
[AUTHOR_MEMBERSHIP: ]

Erster Absatz (im Heft: Vorspann)...

Zweiter Absatz mit Bildmarker [Bild 1: Bildunterschrift].

[RESTRICT]  (optional; ohne Marker beginnt der geschützte Teil automatisch, standardmäßig nach dem 2. Absatz)

Weiterer Absatz mit [Bild 2].

[HEADER Zwischenüberschrift]
Absatz für Ebene H2.

[HEADER 3 Hintergrund]
Absatz für Ebene H3.

[HEADER 4 Detail]
Absatz für Ebene H4.

[BIO: ]

## 6) Bild-Checkliste
- Aufmacherbild hochgeladen
- Inline-Bilder in korrekter Reihenfolge hochgeladen
- Bild für Autoren-Bio hochgeladen (falls benötigt)

## 7) Link-Checkliste
Vor dem Fertigstellen des Entwurfs:
- Warnungen zu ungültigen Links in der Vorschau prüfen
- Wenn `CHECK LINK` rot erscheint, den Link vor der Veröffentlichung korrigieren

## 8) Abschluss-Checkliste vor dem Upload
- DOCX-Format bestätigt
- Autor bestätigt (Name oder Mitgliedsnummer), keine Autor-Warnung in der Vorschau
- Kategorien geprüft
- Metadaten-Tags ausgefüllt
- Bildmarker und Uploads passen zusammen
- Vorschau geprüft
- Entwurf oder Veröffentlichen bewusst gewählt
- Import abgeschlossen

## 9) Häufige Fehler
- Falscher Dateityp (`.doc` statt `.docx`)
- Autor fehlt oder ist falsch geschrieben: Der Artikel bekommt dann den Standardautor
- Vorausgewählte Kategorien oder „Direkt veröffentlichen“ nicht geprüft
- Defektes Markerformat (fehlende schließende `]`)
- Bildnummern übersprungen (`Bild 1`, `Bild 3`)
- Vorschau-Schritt vergessen
