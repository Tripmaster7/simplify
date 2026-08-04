# Redaktions-Spickzettel: WordPress Artikel-Import-Assistent

Diese Seite ausdrucken und am Arbeitsplatz bereithalten.

## 1) Schnellablauf
1. Artikel in Word als `.docx` vorbereiten.
2. In WordPress den Artikel-Import-Assistenten öffnen.
3. Die Mitgliedsnummer des echten Autors eingeben.
4. DOCX hochladen.
5. Aufmacherbild und Inline-Bilder hochladen.
6. Auf Vorschau erzeugen klicken.
7. Warnungen zu Links und Bildern prüfen.
8. Auf Entwurf aus Vorschau erstellen klicken.

## 2) Grundregeln
- Immer als DOCX (`.docx`) speichern, nicht als `.doc`.
- Die Mitgliedsnummer muss korrekt sein.
- Das eingeloggte Redaktionskonto ist nur intern relevant.
- Der öffentliche Autor wird immer über die Mitgliedsnummer bestimmt.

## 3) Metadaten-Tags in der DOCX
Diese Tags exakt so verwenden.

Pflicht oder dringend empfohlen:
- [TITLE: Ihre Überschrift]
- [SUBTITLE: Ihre Unterzeile]
- [WRITING_DATE: 2026-08-04]
- [AUTHOR_MEMBERSHIP: 123456]

Optional:
- [AUTHOR_NAME: Vorname Nachname]
- [BIO: Kurzer Autorentext]
- [RESTRICT]
- [HEADER Zwischenüberschrift]
- [HEADER 3 Zwischenüberschrift]
- [HEADER 4 Zwischenüberschrift]

Regel für Überschriften:
- H1 bleibt dem Artikeltitel vorbehalten.
- H2 kommt aus dem Untertitel oder aus einem einfachen `[HEADER ...]` im Fließtext.
- Für tiefere Ebenen im Fließtext `[HEADER 3 ...]` und `[HEADER 4 ...]` verwenden.

Nur für Altdokumente:
- [RESTRICT_START]
- [RESTRICT_END]

## 4) Inline-Bildmarker im Artikeltext
Marker an den Stellen einsetzen, an denen Bilder erscheinen sollen:
- [Bild 1]
- [Bild 2: Kurze Bildunterschrift]
- [Image 3: English caption]

Regeln:
- Die Nummerierung beginnt bei 1.
- Bilder in derselben Reihenfolge hochladen.
- Wenn Marker und Uploads nicht zusammenpassen, zeigt die Vorschau eine Warnung.

## 5) DOCX-Vorlage zum Kopieren
Diesen Block in ein neues Word-Dokument kopieren und ausfüllen.

[TITLE: ]
[SUBTITLE: ]
[WRITING_DATE: ]
[AUTHOR_MEMBERSHIP: ]
[AUTHOR_NAME: ]

[RESTRICT]

Erster Absatz...

Zweiter Absatz mit Bildmarker [Bild 1: Bildunterschrift].

Weiterer Absatz mit [Bild 2].

[HEADER Zwischenüberschrift]
Absatz für Ebene H2.

[HEADER 3 Hintergrund]
Absatz für Ebene H3.

[HEADER 4 Detail]
Absatz für Ebene H4.

[RESTRICT_END]  (nur Altformat; der Assistent setzt den schließenden Shortcode normalerweise vor die Bio)

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
- Mitgliedsnummer bestätigt
- Metadaten-Tags ausgefüllt
- Bildmarker und Uploads passen zusammen
- Vorschau geprüft
- Entwurf erstellt

## 9) Häufige Fehler
- Falscher Dateityp (`.doc` statt `.docx`)
- Fehlende Mitgliedsnummer
- Defektes Markerformat (fehlende schließende `]`)
- Bildnummern übersprungen (`Bild 1`, `Bild 3`)
- Vorschau-Schritt vergessen
