<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Admin_I18n
{
    /**
     * @var array<string,string>
     */
    private array $de_translations = [
        'Article Import Wizard' => 'Artikel-Import-Assistent',
        'Article Import' => 'Artikel-Import',
        'Article Import Wizard Settings' => 'Einstellungen: Artikel-Import-Assistent',
        'Import Defaults' => 'Import-Standards',
        'Restriction Start Shortcode' => 'Geschützter Abschnitt – Anfang',
        'Restriction End Shortcode' => 'Geschützter Abschnitt – Ende',
        'Default Inline Image Slots' => 'Standardanzahl Inline-Bildslots',
        'Notification Email' => 'Benachrichtigungs-E-Mail',
        'Link Check Timeout (ms)' => 'Link-Check-Timeout (ms)',
        'Broken Link Handling' => 'Umgang mit fehlerhaften Links',
        'Replace with CHECK LINK' => 'Durch CHECK LINK ersetzen',
        'Flag only (keep original)' => 'Nur markieren (Original behalten)',
        'Flag only' => 'Nur markieren',
        'Help' => 'Hilfe',
        'Open Draft' => 'Entwurf öffnen',
        'Preview Before Draft Creation' => 'Vorschau vor Entwurfserstellung',
        'Review this generated article. If everything looks good, create the draft.' => 'Prüfen Sie den erzeugten Artikel. Wenn alles passt, erstellen Sie den Entwurf.',
        'Membership Number' => 'Mitgliedsnummer',
        'Resolved Public Author' => 'Öffentlich sichtbarer Autor',
        'Categories' => 'Kategorien',
        'None' => 'Keine',
        'Title' => 'Titel',
        'Source Document' => 'Quelldokument',
        'Author Bio for Profile' => 'Autoren-Bio für das Profil',
        'No bio provided.' => 'Keine Bio angegeben.',
        'Validation' => 'Validierung',
        'Invalid Links:' => 'Ungültige Links:',
        'Missing Image Slots:' => 'Fehlende Bildslots:',
        'Metadata Warnings:' => 'Metadaten-Warnungen:',
        'Generated Content Preview' => 'Vorschau des erzeugten Inhalts',
        'Create Draft From Preview' => 'Entwurf aus Vorschau erstellen',
        'Start New Import' => 'Neuen Import starten',
        'Guided Article Import' => 'Geführter Artikel-Import',
        'Upload a DOCX file or provide content manually. Public attribution always uses the membership number user account.' => 'Laden Sie eine DOCX-Datei hoch oder geben Sie Inhalte manuell ein. Die öffentliche Autorenzuordnung nutzt immer das Benutzerkonto der Mitgliedsnummer.',
        'Inserted at the [RESTRICT] anchor inside the article.' => 'Wird am [RESTRICT]-Anker im Artikel eingefügt.',
        'Inserted after the article body and before the author bio.' => 'Wird nach dem Artikeltext und vor der Autoren-Bio eingefügt.',
        'Must match the author username exactly.' => 'Muss exakt dem Autoren-Benutzernamen entsprechen.',
        'Select at least one category. Multiple categories are allowed.' => 'Wählen Sie mindestens eine Kategorie. Mehrfachauswahl ist möglich.',
        'New Category' => 'Neue Kategorie',
        'Article Title' => 'Artikeltitel',
        'Optional if title is available in DOCX.' => 'Optional, wenn der Titel in der DOCX enthalten ist.',
        'Travel Date' => 'Reisedatum',
        'Optionally select the date of the travel.' => 'Wählen Sie optional das Datum der Reise aus.',
        'DOCX File' => 'DOCX-Datei',
        'DOCX is parsed for title/content/placeholders.' => 'Die DOCX wird auf Titel/Inhalt/Platzhalter analysiert.',
        'Article Content' => 'Artikelinhalt',
        'Optional if content is available in DOCX.' => 'Optional, wenn der Inhalt in der DOCX enthalten ist.',
        'Headline Picture' => 'Aufmacherbild',
        'Inline Picture Count' => 'Anzahl Inline-Bilder',
        'Upload images in order. They replace [Bild 1], [Bild 2], ... markers.' => 'Bilder in der Reihenfolge hochladen. Sie ersetzen die Marker [Bild 1], [Bild 2], ...',
        'Inline Pictures' => 'Inline-Bilder',
        'One upload field is generated for each selected picture slot.' => 'Für jeden gewählten Bildslot wird ein Upload-Feld erzeugt.',
        'Author Bio' => 'Autoren-Bio',
        'Author Bio Picture' => 'Bild zur Autoren-Bio',
        'Restriction shortcodes come from plugin settings and DOCX markers.' => 'Restriktions-Shortcodes stammen aus Plugin-Einstellungen und DOCX-Markern.',
        'Generate Preview' => 'Vorschau erzeugen',
        'Picture Slot' => 'Bildslot',
        'Enter new category name' => 'Neuen Kategorienamen eingeben',
        'Enter five digits starting with 0.' => 'Geben Sie fünf Ziffern ein, beginnend mit 0.',
        'Enter a valid five-digit membership number starting with 0.' => 'Geben Sie eine gültige fünfstellige Mitgliedsnummer ein, die mit 0 beginnt.',
        'Please select a valid travel date.' => 'Bitte wählen Sie ein gültiges Reisedatum aus.',
        'Checking membership number...' => 'Mitgliedsnummer wird geprüft ...',
        'Author:' => 'Autor:',
        'No author found for that membership number.' => 'Für diese Mitgliedsnummer wurde kein Autor gefunden.',
        'The membership number could not be checked. Please try again.' => 'Die Mitgliedsnummer konnte nicht geprüft werden. Bitte versuchen Sie es erneut.',
        'You do not have permission to access this page.' => 'Sie haben keine Berechtigung, auf diese Seite zuzugreifen.',
        'Unauthorized.' => 'Nicht autorisiert.',
        'You do not have permission to create categories.' => 'Sie haben keine Berechtigung, Kategorien zu erstellen.',
        'Membership number is required.' => 'Mitgliedsnummer ist erforderlich.',
        'Please select at least one category.' => 'Bitte wählen Sie mindestens eine Kategorie aus.',
        'No user found for that membership number.' => 'Für diese Mitgliedsnummer wurde kein Benutzer gefunden.',
        'DOCX membership number does not match the wizard membership number.' => 'Die DOCX-Mitgliedsnummer stimmt nicht mit der im Assistenten überein.',
        '[WRITING_DATE: %s] is not a valid date, so the post date was not set. Use the format 2025-01-15 or 15.01.2025.' => '[WRITING_DATE: %s] ist kein gültiges Datum, daher wurde das Beitragsdatum nicht gesetzt. Verwenden Sie das Format 2025-01-15 oder 15.01.2025.',
        'The writing date %s is in the future. Publishing the draft will schedule it for that date.' => 'Das Schreibdatum %s liegt in der Zukunft. Beim Veröffentlichen wird der Entwurf für dieses Datum geplant.',
        'Post Date' => 'Beitragsdatum',
        'The [BIO: marker is not closed with "]". The bio was not imported and its text was kept in the article. Add "]" at the end of the bio.' => 'Der [BIO:-Marker ist nicht mit „]“ geschlossen. Die Bio wurde nicht übernommen und ihr Text blieb im Artikel. Setzen Sie „]“ an das Ende der Bio.',
        'from [WRITING_DATE]' => 'aus [WRITING_DATE]',
        'Not set. The publish date will be used.' => 'Nicht gesetzt. Es wird das Veröffentlichungsdatum verwendet.',
        'No [RESTRICT] marker found. The restricted part starts automatically after paragraph %d.' => 'Kein [RESTRICT]-Marker gefunden. Der geschützte Teil beginnt automatisch nach Absatz %d.',
        'The restricted part is empty because nothing follows the restriction start. Add a [RESTRICT] marker earlier in the article.' => 'Der geschützte Teil ist leer, weil nach dem Restriktionsbeginn nichts mehr folgt. Setzen Sie einen [RESTRICT]-Marker weiter vorne im Artikel.',
        'The restriction start and end shortcodes are required. Please set them under Settings > Article Import Wizard.' => 'Die Shortcodes für Beginn und Ende der Restriktion sind erforderlich. Bitte unter Einstellungen > Artikel-Import-Assistent festlegen.',
        'The restriction start shortcode is required and must be a shortcode such as [restrict]. The default was restored.' => 'Der Shortcode für den Restriktionsbeginn ist erforderlich und muss ein Shortcode wie [restrict] sein. Der Standardwert wurde wiederhergestellt.',
        'The restriction end shortcode is required. It was set to %s to match the start shortcode.' => 'Der Shortcode für das Restriktionsende ist erforderlich. Er wurde passend zum Beginn auf %s gesetzt.',
        'Required. Inserted at the [RESTRICT] marker, or automatically after the 2nd paragraph (earlier if the first paragraph reaches 1,000 characters).' => 'Erforderlich. Wird am [RESTRICT]-Marker eingefügt, sonst automatisch nach dem 2. Absatz (früher, wenn der erste Absatz 1.000 Zeichen erreicht).',
        'Required. Always inserted after the last paragraph, before the author bio, so the bio stays public.' => 'Erforderlich. Wird immer nach dem letzten Absatz und vor der Autoren-Bio eingefügt, damit die Bio öffentlich bleibt.',
        'The DOCX contains more than one [%s] marker. The first value was used.' => 'Die DOCX enthält mehr als einen [%s]-Marker. Der erste Wert wurde verwendet.',
        '%d link(s) were not checked because the link check took too long. Please check them manually.' => '%d Link(s) wurden nicht geprüft, weil die Link-Prüfung zu lange dauerte. Bitte manuell prüfen.',
        'Image "%1$s" could not be uploaded: %2$s' => 'Bild „%1$s“ konnte nicht hochgeladen werden: %2$s',
        'Title and content are required (either manually or from DOCX).' => 'Titel und Inhalt sind erforderlich (manuell oder aus DOCX).',
        'Preview generated. Review and confirm draft creation.' => 'Vorschau erstellt. Bitte prüfen und Entwurfserstellung bestätigen.',
        'Preview token missing. Please run preview first.' => 'Vorschau-Token fehlt. Bitte zuerst Vorschau ausführen.',
        'Preview data expired. Please run preview again.' => 'Vorschau-Daten sind abgelaufen. Bitte Vorschau erneut ausführen.',
        'At least one category is required. Please generate preview again.' => 'Mindestens eine Kategorie ist erforderlich. Bitte Vorschau erneut erzeugen.',
        'Attributed author is missing in preview payload.' => 'Zugeordneter Autor fehlt in den Vorschau-Daten.',
        'Draft #%1$s created. Attributed author: %2$s' => 'Entwurf #%1$s erstellt. Zugeordneter Autor: %2$s',
        'Page Navigation' => 'Seiten-Navigation',
        'Back Link on Sub-Pages' => 'Rücklink auf Unterseiten',
        'No pages with sub-pages found.' => 'Keine Seiten mit Unterseiten gefunden.',
        'Sub-pages of the selected pages show a link back to them at the top and bottom, for example the chapters of a guide linking back to its table of contents.' => 'Unterseiten der ausgewählten Seiten zeigen oben und unten einen Link zurück zu ihnen, zum Beispiel die Kapitel eines Ratgebers mit einem Link zurück zum Inhaltsverzeichnis.',
        'The article selected for update changed. Please review the preview again.' => 'Der zur Aktualisierung ausgewählte Artikel hat sich geändert. Bitte prüfen Sie die Vorschau erneut.',
        'The uploaded DOCX temp file could not be verified.' => 'Die temporäre hochgeladene DOCX-Datei konnte nicht verifiziert werden.',
        'The DOCX file contains too many archive entries.' => 'Die DOCX-Datei enthält zu viele Archiveinträge.',
        'A DOCX archive entry is too large.' => 'Ein Archiveintrag der DOCX-Datei ist zu groß.',
        'The DOCX file expands to too much data.' => 'Die DOCX-Datei entpackt sich zu einem zu großen Datenumfang.',
        'Import Another Article' => 'Weiteren Artikel importieren',
        'Prepare one Word document and add the article, pictures and author number below. Public attribution always uses the member account.' => 'Bereiten Sie ein Word-Dokument vor und fügen Sie unten den Artikel, die Bilder und die Mitgliedsnummer hinzu. Die öffentliche Zuordnung verwendet immer das Mitgliederkonto.',
        'Before you start' => 'Vor dem Start',
        'Save the article as a DOCX file in Word.' => 'Speichern Sie den Artikel in Word als DOCX-Datei.',
        'Know the author’s five-digit member number.' => 'Halten Sie die fünfstellige Mitgliedsnummer des Autors bereit.',
        'Prepare the headline picture and any pictures used inside the article.' => 'Bereiten Sie das Aufmacherbild und alle Bilder im Artikel vor.',
        'Open the short preparation guide' => 'Kurzanleitung öffnen',
        'Protected Section Start' => 'Beginn des geschützten Abschnitts',
        'Protected Section End' => 'Ende des geschützten Abschnitts',
        'Problems to Check' => 'Zu prüfende Hinweise',
        'This article is published. Confirming replaces its live text, pictures, categories and author bio. Continue?' => 'Dieser Artikel ist veröffentlicht. Das Bestätigen ersetzt den öffentlichen Text, die Bilder, Kategorien und die Autoren-Bio. Fortfahren?',
        'After review' => 'Nach der Prüfung',
        'Save as draft' => 'Als Entwurf speichern',
        'Publish immediately' => 'Direkt veröffentlichen',
        'Publishing will make the article publicly visible as soon as you confirm.' => 'Durch die Veröffentlichung wird der Artikel sofort nach der Bestätigung öffentlich sichtbar.',
        'This article will be saved as a draft. An editor with publishing permission can publish it later.' => 'Dieser Artikel wird als Entwurf gespeichert. Ein Editor mit Veröffentlichungsrecht kann ihn später veröffentlichen.',
        'Complete Import' => 'Import abschließen',
        'You do not have permission to publish articles. The import was not completed.' => 'Sie haben keine Berechtigung, Artikel zu veröffentlichen. Der Import wurde nicht abgeschlossen.',
        'Article #%1$s published. Attributed author: %2$s' => 'Artikel #%1$s veröffentlicht. Zugeordneter Autor: %2$s',
        'The new article will be published publicly immediately. Continue?' => 'Der neue Artikel wird sofort öffentlich veröffentlicht. Fortfahren?',
        'Open Article' => 'Artikel öffnen',
        'Update Article' => 'Artikel aktualisieren',
        'Update Published Article' => 'Veröffentlichten Artikel aktualisieren',
        'This upload updates an existing article.' => 'Dieser Upload aktualisiert einen vorhandenen Artikel.',
        'Article #%1$d (%2$s):' => 'Artikel #%1$d (%2$s):',
        'The text, pictures, categories and bio are replaced by this upload. Title, author and status stay unchanged.' => 'Text, Bilder, Kategorien und Autoren-Bio werden durch diesen Upload ersetzt. Titel, Autor und Status bleiben unverändert.',
        'Picture slots without a new upload keep their previous picture: %s' => 'Bildplätze ohne neuen Upload behalten das vorherige Bild: %s',
        'Preview Before Draft Creation' => 'Vorschau vor dem Speichern',
        'Review this generated article. If everything looks good, create the draft.' => 'Prüfen Sie den erzeugten Artikel. Wenn alles passt, speichern Sie ihn.',
        'Create as a new article instead (the existing article stays unchanged)' => 'Stattdessen als neuen Artikel erstellen (der vorhandene Artikel bleibt unverändert)',
        'Start New Import' => 'Neuen Import starten',
        'Article #%1$s updated. Attributed author: %2$s' => 'Artikel #%1$s aktualisiert. Zugeordneter Autor: %2$s',
        'Preview generated. This upload updates an existing article. Review and confirm the update.' => 'Vorschau erstellt. Dieser Upload aktualisiert einen vorhandenen Artikel. Prüfen und Aktualisierung bestätigen.',
        'Preview generated. Review and confirm draft creation.' => 'Vorschau erstellt. Bitte prüfen und speichern.',
        'This upload matches the existing article #%1$d "%2$s", which you are not allowed to edit. Please ask an editor to import the update.' => 'Dieser Upload passt zum vorhandenen Artikel #%1$d „%2$s“, den Sie nicht bearbeiten dürfen. Bitte eine Person mit Bearbeitungsrecht um den Update-Import.',
        'The article to update is no longer available or you may not edit it. Please generate the preview again.' => 'Der zu aktualisierende Artikel ist nicht mehr verfügbar oder Sie dürfen ihn nicht bearbeiten. Bitte die Vorschau erneut erstellen.',
        'The article could not be saved.' => 'Der Artikel konnte nicht gespeichert werden.',
        'Only DOCX files are allowed.' => 'Es sind nur DOCX-Dateien erlaubt.',
        'Uploaded DOCX temp file is missing.' => 'Die temporäre hochgeladene DOCX-Datei fehlt.',
        'Uploaded DOCX temp file is not accessible.' => 'Auf die temporäre hochgeladene DOCX-Datei kann nicht zugegriffen werden.',
        'Could not open DOCX file.' => 'Die DOCX-Datei konnte nicht geöffnet werden.',
        'ZipArchive is not available on this server.' => 'ZipArchive ist auf diesem Server nicht verfügbar.',
        'DOCX document content is missing.' => 'Der Dokumentinhalt fehlt in der DOCX-Datei.',
        'Back to overview: %s' => 'Zurück zur Übersicht: %s',
        'New' => 'Neu',
    ];

    public function register(): void
    {
        add_filter('gettext', [$this, 'translate_admin_ui'], 20, 3);
    }

    public function translate_admin_ui(string $translation, string $text, string $domain): string
    {
        if ($domain !== 'article-import-wizard') {
            return $translation;
        }

        if (!$this->is_german_admin_context()) {
            return $translation;
        }

        // Keep this only as a fallback when no .mo translation exists yet.
        if ($translation !== $text) {
            return $translation;
        }

        if (isset($this->de_translations[$text])) {
            return $this->de_translations[$text];
        }

        return $translation;
    }

    private function is_german_admin_context(): bool
    {
        if (!is_admin()) {
            return false;
        }

        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page !== 'aiw-import-wizard' && $page !== 'aiw-settings') {
            return false;
        }

        $locale = determine_locale();
        if (stripos((string) $locale, 'de') !== 0) {
            return false;
        }

        return true;
    }
}
