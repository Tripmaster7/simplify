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
        'Restriction Start Shortcode' => 'Shortcode Restriktion Start',
        'Restriction End Shortcode' => 'Shortcode Restriktion Ende',
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
        'You do not have permission to access this page.' => 'Sie haben keine Berechtigung, auf diese Seite zuzugreifen.',
        'Unauthorized.' => 'Nicht autorisiert.',
        'You do not have permission to create categories.' => 'Sie haben keine Berechtigung, Kategorien zu erstellen.',
        'Membership number is required.' => 'Mitgliedsnummer ist erforderlich.',
        'Please select at least one category.' => 'Bitte wählen Sie mindestens eine Kategorie aus.',
        'No user found for that membership number.' => 'Für diese Mitgliedsnummer wurde kein Benutzer gefunden.',
        'DOCX membership number does not match the wizard membership number.' => 'Die DOCX-Mitgliedsnummer stimmt nicht mit der im Assistenten überein.',
        'DOCX is missing the [RESTRICT] anchor.' => 'In der DOCX fehlt der [RESTRICT]-Anker.',
        'Title and content are required (either manually or from DOCX).' => 'Titel und Inhalt sind erforderlich (manuell oder aus DOCX).',
        'Preview generated. Review and confirm draft creation.' => 'Vorschau erstellt. Bitte prüfen und Entwurfserstellung bestätigen.',
        'Preview token missing. Please run preview first.' => 'Vorschau-Token fehlt. Bitte zuerst Vorschau ausführen.',
        'Preview data expired. Please run preview again.' => 'Vorschau-Daten sind abgelaufen. Bitte Vorschau erneut ausführen.',
        'At least one category is required. Please generate preview again.' => 'Mindestens eine Kategorie ist erforderlich. Bitte Vorschau erneut erzeugen.',
        'Attributed author is missing in preview payload.' => 'Zugeordneter Autor fehlt in den Vorschau-Daten.',
        'Draft #%1$s created. Attributed author: %2$s' => 'Entwurf #%1$s erstellt. Zugeordneter Autor: %2$s',
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

        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();
        if (stripos((string) $locale, 'de') !== 0) {
            return false;
        }

        return true;
    }
}
