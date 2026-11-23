<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fills a copy of the InDesign article template (IDML) with an imported article.
 *
 * The template marks where things go with placeholders typed into its text frames:
 *
 * - [TITEL], [UNTERTITEL], [AUTOR], [REISEZEIT], [BILDUNTERSCHRIFT n]: replaced inside the text,
 *   e.g. "Text und Fotos: [AUTOR]". A paragraph whose value is empty is removed.
 * - [VORSPANN], [BIO]: the paragraph with the placeholder is replaced by the Vorspann (first
 *   paragraph of the article) or the author bio, in the placeholder's paragraph style.
 * - [TEXT]: every frame whose text contains it receives the body text. The frames are linked in
 *   reading order (spread by spread, left to right, top to bottom) into one story.
 * - [AUFMACHERBILD], [BILD n], [AUTORENBILD]: a small text frame lying on top of an empty picture
 *   frame. The picture is placed into the picture frame and the label frame is removed.
 *
 * Everything else in the template stays as it is.
 */
class AIW_IDML_Builder
{
    private const NS_PACKAGE = 'http://ns.adobe.com/AdobeInDesign/idml/1.0/packaging';

    private const TOKEN_PATTERN = '/\[\s*(TITEL|UNTERTITEL|AUTOR|REISEZEIT|VORSPANN|TEXT|BIO|AUFMACHERBILD|AUTORENBILD|BILDUNTERSCHRIFT\s*\d+|BILD\s*\d+)\s*\]/iu';

    private const PICTURE_TOKEN = '/^(AUFMACHERBILD|AUTORENBILD|BILD \d+)$/';

    private const MAX_ENTRIES = 5000;
    private const MAX_SIZE = 100 * 1024 * 1024;

    /** Picture formats InDesign places, by getimagesize() type. */
    private const IMAGE_FORMATS = [
        IMAGETYPE_JPEG => '$ID/JPEG',
        IMAGETYPE_PNG => '$ID/Portable Network Graphics (PNG)',
        IMAGETYPE_TIFF_II => '$ID/TIFF',
        IMAGETYPE_TIFF_MM => '$ID/TIFF',
    ];

    private AIW_ICML_Writer $writer;

    /** @var array<string,string> Package entries in their original order. */
    private array $files = [];

    /** @var array<string,true> Entries whose XML changed and must be written back. */
    private array $changed = [];

    private DOMDocument $designmap;

    /** @var array<string,array{file:string,doc:DOMDocument,el:DOMElement}> Stories by ID. */
    private array $stories = [];

    /** @var array<string,DOMDocument> Spreads by file name. */
    private array $spreads = [];

    /** @var array<string,array{spread:string,el:DOMElement}> Text frames by ID. */
    private array $frames = [];

    /** @var array<string,DOMElement> Paragraph and character styles by Self. */
    private array $style_elements = [];

    /** @var array<string,string[]> Font style names by font family. */
    private array $font_styles = [];

    /** @var string[] */
    private array $warnings = [];

    private int $id_counter = 0;

    public function __construct(AIW_ICML_Writer $writer)
    {
        $this->writer = $writer;
    }

    /**
     * Placeholders found in a template, with how often they occur.
     *
     * @return array{error:string,placeholders:array<string,int>}
     */
    public function analyze(string $template_path): array
    {
        $error = $this->load($template_path);
        if ($error !== '') {
            return ['error' => $error, 'placeholders' => []];
        }

        $found = [];
        foreach ($this->stories as $story) {
            foreach ($this->story_tokens($story['el']) as $token) {
                $found[$token] = ($found[$token] ?? 0) + 1;
            }
        }
        uksort($found, 'strnatcmp');

        return ['error' => '', 'placeholders' => $found];
    }

    /**
     * @param array{title?:string,subtitle?:string,author_name?:string,travel_period?:string,content?:string,bio?:string} $article
     * @param array<string,array{file:string,link:string}> $pictures Keyed by AUFMACHERBILD, BILD n, AUTORENBILD.
     * @return array{error:string,idml:string,warnings:string[]}
     */
    public function build(string $template_path, array $article, array $pictures): array
    {
        $error = $this->load($template_path);
        if ($error !== '') {
            return ['error' => $error, 'idml' => '', 'warnings' => []];
        }

        $parts = $this->writer->split_article((string) ($article['content'] ?? ''));
        $captions = [];
        $body = [];
        foreach ($parts['body'] as $paragraph) {
            if ($paragraph['style'] === 'caption' && !empty($paragraph['number'])) {
                $captions[(int) $paragraph['number']] = implode('', array_column($paragraph['runs'], 'text'));
            } elseif ($paragraph['style'] !== 'image') {
                $body[] = $paragraph;
            }
        }

        if (!$this->fill_body($body)) {
            return ['error' => __('The InDesign template has no [TEXT] placeholder for the article text.', 'article-import-wizard'), 'idml' => '', 'warnings' => []];
        }

        $this->place_pictures($pictures);

        $inline = [
            'TITEL' => (string) ($article['title'] ?? ''),
            'UNTERTITEL' => (string) ($article['subtitle'] ?? ''),
            'AUTOR' => (string) ($article['author_name'] ?? ''),
            'REISEZEIT' => (string) ($article['travel_period'] ?? ''),
        ];
        $blocks = [
            'VORSPANN' => $parts['vorspann'] !== null ? [$parts['vorspann']] : [],
            'BIO' => $this->writer->bio_paragraphs((string) ($article['author_name'] ?? ''), (string) ($article['bio'] ?? ''), false),
        ];

        foreach ($this->stories as $id => $story) {
            $tokens = $this->story_tokens($story['el']);
            if (empty($tokens)) {
                continue;
            }

            $paragraphs = $this->read_paragraphs($story['el']);
            foreach ($tokens as $token) {
                if (isset($blocks[$token])) {
                    $role = $token === 'BIO' ? 'bio' : 'vorspann';
                    $paragraphs = $this->replace_block($paragraphs, $token, $blocks[$token], [$role]);
                } elseif (isset($inline[$token])) {
                    $paragraphs = $this->replace_inline($paragraphs, $token, $inline[$token]);
                } elseif (preg_match('/^BILDUNTERSCHRIFT (\d+)$/', $token, $matches) === 1) {
                    $paragraphs = $this->replace_inline($paragraphs, $token, $captions[(int) $matches[1]] ?? '');
                }
            }
            $this->write_paragraphs($story['el'], $paragraphs);
            $this->changed[$story['file']] = true;
        }

        return ['error' => '', 'idml' => $this->package(), 'warnings' => $this->warnings];
    }

    // ---------------------------------------------------------------------------------------------
    // Loading
    // ---------------------------------------------------------------------------------------------

    private function load(string $template_path): string
    {
        $this->files = [];
        $this->changed = [];
        $this->stories = [];
        $this->spreads = [];
        $this->frames = [];
        $this->style_elements = [];
        $this->font_styles = [];
        $this->warnings = [];

        $invalid = __('The InDesign template is not a valid IDML file. In InDesign, save it with File > Export > InDesign Markup (IDML).', 'article-import-wizard');

        $zip = new ZipArchive();
        if (!is_readable($template_path) || $zip->open($template_path) !== true) {
            return $invalid;
        }

        $total = 0;
        if ($zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();
            return $invalid;
        }
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_SIZE) {
                $zip->close();
                return $invalid;
            }
            $name = (string) ($stat['name'] ?? '');
            $content = $zip->getFromIndex($index);
            if ($name !== '' && is_string($content)) {
                $this->files[$name] = $content;
            }
        }
        $zip->close();

        $designmap = $this->load_xml('designmap.xml');
        if (!$designmap) {
            return $invalid;
        }
        $this->designmap = $designmap;

        foreach ($designmap->getElementsByTagNameNS(self::NS_PACKAGE, 'Story') as $reference) {
            $file = (string) $reference->getAttribute('src');
            $doc = $this->load_xml($file);
            $story = $doc ? $this->first_child($doc->documentElement, 'Story') : null;
            if ($story) {
                $this->stories[(string) $story->getAttribute('Self')] = ['file' => $file, 'doc' => $doc, 'el' => $story];
            }
        }

        foreach ($designmap->getElementsByTagNameNS(self::NS_PACKAGE, 'Spread') as $reference) {
            $file = (string) $reference->getAttribute('src');
            $doc = $this->load_xml($file);
            if (!$doc) {
                continue;
            }
            $this->spreads[$file] = $doc;
            foreach ($doc->getElementsByTagName('TextFrame') as $frame) {
                $this->frames[(string) $frame->getAttribute('Self')] = ['spread' => $file, 'el' => $frame];
            }
        }

        $styles = $this->load_xml('Resources/Styles.xml');
        if ($styles) {
            foreach (['ParagraphStyle', 'CharacterStyle'] as $kind) {
                foreach ($styles->getElementsByTagName($kind) as $style) {
                    $this->style_elements[(string) $style->getAttribute('Self')] = $style;
                }
            }
        }

        $fonts = $this->load_xml('Resources/Fonts.xml');
        if ($fonts) {
            foreach ($fonts->getElementsByTagName('FontFamily') as $family) {
                $names = [];
                foreach ($family->getElementsByTagName('Font') as $font) {
                    $names[] = (string) $font->getAttribute('FontStyleName');
                }
                $this->font_styles[(string) $family->getAttribute('Name')] = $names;
            }
        }

        return empty($this->stories) ? $invalid : '';
    }

    private function load_xml(string $name): ?DOMDocument
    {
        if (!isset($this->files[$name])) {
            return null;
        }

        $document = new DOMDocument();
        $document->preserveWhiteSpace = true;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($this->files[$name], LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    private function first_child(DOMNode $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return string[] Normalized placeholders in a story, e.g. "TITEL", "BILD 2".
     */
    private function story_tokens(DOMElement $story): array
    {
        $text = '';
        foreach ($story->getElementsByTagName('Content') as $content) {
            $text .= $content->textContent;
        }

        preg_match_all(self::TOKEN_PATTERN, $text, $matches);

        return array_values(array_unique(array_map([$this, 'normalize_token'], $matches[1])));
    }

    private function normalize_token(string $token): string
    {
        return (string) preg_replace('/^(BILDUNTERSCHRIFT|BILD)\s*(\d+)$/', '$1 $2', strtoupper(trim($token)));
    }

    private function token_regex(string $token): string
    {
        $parts = explode(' ', $token);

        return '/\[\s*' . preg_quote($parts[0], '/') . (isset($parts[1]) ? '\s*' . preg_quote($parts[1], '/') : '') . '\s*\]/iu';
    }

    // ---------------------------------------------------------------------------------------------
    // Paragraph model
    // ---------------------------------------------------------------------------------------------

    /**
     * Read a story into paragraphs: ['attrs' => [...], 'props' => ?DOMElement, 'runs' => [run]],
     * run = ['attrs' => [...], 'props' => ?DOMElement, 'items' => [string|DOMElement]].
     */
    private function read_paragraphs(DOMElement $story): array
    {
        $paragraphs = [];

        foreach (iterator_to_array($story->childNodes) as $range) {
            if (!$range instanceof DOMElement || $range->localName !== 'ParagraphStyleRange') {
                continue;
            }

            $paragraph_attrs = $this->attributes($range);
            $paragraph_props = null;
            $current = ['attrs' => $paragraph_attrs, 'props' => null, 'runs' => []];

            foreach ($range->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }

                if ($child->localName === 'Properties') {
                    $paragraph_props = $child;
                    $current['props'] = $child;
                    continue;
                }

                if ($child->localName !== 'CharacterStyleRange') {
                    $current['runs'][] = ['attrs' => ['AppliedCharacterStyle' => 'CharacterStyle/$ID/[No character style]'], 'props' => null, 'items' => [$child]];
                    continue;
                }

                $run_attrs = $this->attributes($child);
                $run_props = null;
                $run = ['attrs' => $run_attrs, 'props' => null, 'items' => []];

                foreach ($child->childNodes as $item) {
                    if (!$item instanceof DOMElement) {
                        continue;
                    }

                    if ($item->localName === 'Properties') {
                        $run_props = $item;
                        $run['props'] = $item;
                    } elseif ($item->localName === 'Content') {
                        $run['items'][] = $item->textContent;
                    } elseif ($item->localName === 'Br') {
                        if (!empty($run['items'])) {
                            $current['runs'][] = $run;
                        }
                        $paragraphs[] = $current;
                        $current = ['attrs' => $paragraph_attrs, 'props' => $paragraph_props, 'runs' => []];
                        $run = ['attrs' => $run_attrs, 'props' => $run_props, 'items' => []];
                    } else {
                        $run['items'][] = $item;
                    }
                }

                if (!empty($run['items'])) {
                    $current['runs'][] = $run;
                }
            }

            if (!empty($current['runs'])) {
                $paragraphs[] = $current;
            }
        }

        return $paragraphs;
    }

    private function write_paragraphs(DOMElement $story, array $paragraphs): void
    {
        $document = $story->ownerDocument;
        $template = null;
        foreach (iterator_to_array($story->childNodes) as $child) {
            if ($child instanceof DOMElement && $child->localName === 'ParagraphStyleRange') {
                $template = $template ?? $child;
                $story->removeChild($child);
            }
        }

        if (empty($paragraphs)) {
            // A story always keeps one (empty) paragraph.
            $paragraphs[] = [
                'attrs' => $template ? ['AppliedParagraphStyle' => (string) $template->getAttribute('AppliedParagraphStyle')] : [],
                'props' => null,
                'runs' => [],
            ];
        }

        $last_paragraph = count($paragraphs) - 1;
        foreach (array_values($paragraphs) as $paragraph_index => $paragraph) {
            $range = $document->createElement('ParagraphStyleRange');
            foreach ($paragraph['attrs'] as $name => $value) {
                $range->setAttribute($name, $value);
            }
            if ($paragraph['props'] instanceof DOMElement) {
                $range->appendChild($paragraph['props']->cloneNode(true));
            }

            $runs = $paragraph['runs'];
            if (empty($runs)) {
                $runs[] = ['attrs' => ['AppliedCharacterStyle' => 'CharacterStyle/$ID/[No character style]'], 'props' => null, 'items' => []];
            }

            $last_run = count($runs) - 1;
            foreach ($runs as $run_index => $run) {
                $character_range = $document->createElement('CharacterStyleRange');
                foreach ($run['attrs'] as $name => $value) {
                    $character_range->setAttribute($name, $value);
                }
                if ($run['props'] instanceof DOMElement) {
                    $character_range->appendChild($run['props']->cloneNode(true));
                }
                foreach ($run['items'] as $item) {
                    if (is_string($item)) {
                        $content = $document->createElement('Content');
                        $content->appendChild($document->createTextNode(AIW_ICML_Writer::clean_text($item)));
                        $character_range->appendChild($content);
                    } else {
                        $character_range->appendChild($item->cloneNode(true));
                    }
                }
                if ($run_index === $last_run && $paragraph_index !== $last_paragraph) {
                    $character_range->appendChild($document->createElement('Br'));
                }
                $range->appendChild($character_range);
            }

            $story->appendChild($range);
        }
    }

    /**
     * @return array<string,string>
     */
    private function attributes(DOMElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[$attribute->nodeName] = $attribute->nodeValue;
        }

        return $attributes;
    }

    private function paragraph_text(array $paragraph): string
    {
        $text = '';
        foreach ($paragraph['runs'] as $run) {
            foreach ($run['items'] as $item) {
                if (is_string($item)) {
                    $text .= $item;
                }
            }
        }

        return $text;
    }

    private function replace_inline(array $paragraphs, string $token, string $value): array
    {
        $regex = $this->token_regex($token);
        $value = (string) preg_replace('/\s*\R\s*/u', ' ', trim($value));
        $result = [];

        foreach ($paragraphs as $paragraph) {
            if (preg_match($regex, $this->paragraph_text($paragraph)) !== 1) {
                $result[] = $paragraph;
                continue;
            }

            if ($value === '') {
                // No value: the whole line goes, e.g. "Reisezeit: [REISEZEIT]".
                continue;
            }

            foreach ($paragraph['runs'] as $run_index => $run) {
                foreach ($run['items'] as $item_index => $item) {
                    if (is_string($item)) {
                        $paragraph['runs'][$run_index]['items'][$item_index] = (string) preg_replace_callback($regex, static function () use ($value): string {
                            return $value;
                        }, $item);
                    }
                }
            }
            $result[] = $paragraph;
        }

        return $result;
    }

    /**
     * Replace the paragraph holding $token by generated paragraphs.
     *
     * @param string[] $own_roles Roles that keep the placeholder's paragraph style.
     */
    private function replace_block(array $paragraphs, string $token, array $generated, array $own_roles): array
    {
        $regex = $this->token_regex($token);
        $result = [];

        foreach ($paragraphs as $paragraph) {
            if (preg_match($regex, $this->paragraph_text($paragraph)) !== 1) {
                $result[] = $paragraph;
                continue;
            }

            foreach ($this->convert_paragraphs($generated, $paragraph, $own_roles) as $new_paragraph) {
                $result[] = $new_paragraph;
            }
        }

        return $result;
    }

    /**
     * Turn writer paragraphs into template paragraphs, based on the placeholder paragraph $base.
     *
     * @param string[] $own_roles
     */
    private function convert_paragraphs(array $generated, array $base, array $own_roles): array
    {
        $base_style = (string) ($base['attrs']['AppliedParagraphStyle'] ?? '');
        $base_run = $base['runs'][0] ?? ['attrs' => ['AppliedCharacterStyle' => 'CharacterStyle/$ID/[No character style]'], 'props' => null];
        $exists = function (string $name): bool {
            return isset($this->style_elements[AIW_ICML_Writer::style_reference('ParagraphStyle', $name)]);
        };

        $converted = [];
        foreach ($generated as $paragraph) {
            $role = (string) $paragraph['style'];
            $style = $base_style;
            if (!in_array($role, $own_roles, true)) {
                $resolved = $this->writer->resolve_role($role, $exists);
                if ($resolved !== '' && !in_array($resolved, array_merge($own_roles, ['body']), true)) {
                    $style = AIW_ICML_Writer::style_reference('ParagraphStyle', $this->writer->style_name($resolved));
                }
            }
            $resolved_role = $this->writer->resolve_role($role, $exists);

            $same_style = $style === $base_style;
            $attrs = $same_style ? $base['attrs'] : ['AppliedParagraphStyle' => $style];
            if (!empty($paragraph['restart']) && in_array($resolved_role, ['list_number', 'list_number_2'], true)) {
                $attrs['NumberingContinue'] = 'false';
                $attrs['NumberingStartAt'] = '1';
            }

            $runs = [];
            foreach ($this->writer->merge_runs($this->writer->output_runs($paragraph, $resolved_role)) as $run) {
                $run_attrs = $same_style ? $base_run['attrs'] : ['AppliedCharacterStyle' => 'CharacterStyle/$ID/[No character style]'];
                $character_style = $this->writer->character_style_for($run);
                $character_reference = $character_style !== '' ? AIW_ICML_Writer::style_reference('CharacterStyle', $character_style) : '';

                if ($character_reference !== '' && isset($this->style_elements[$character_reference])) {
                    $run_attrs['AppliedCharacterStyle'] = $character_reference;
                } elseif (($run['format'] & 3) !== 0) {
                    $run_attrs['FontStyle'] = $this->font_style_for($style, $run_attrs, $run['format']);
                }

                $runs[] = ['attrs' => $run_attrs, 'props' => $same_style ? $base_run['props'] : null, 'items' => [$run['text']]];
            }

            $converted[] = ['attrs' => $attrs, 'props' => $same_style ? $base['props'] : null, 'runs' => $runs];
        }

        return $converted;
    }

    // ---------------------------------------------------------------------------------------------
    // Fonts
    // ---------------------------------------------------------------------------------------------

    /**
     * The local font style for bold/italic text in a paragraph style, e.g. "Bold Italic" for bold
     * text in an italic paragraph, picked from the styles the font family has.
     */
    private function font_style_for(string $paragraph_style, array $run_attrs, int $format): string
    {
        $base = (string) ($run_attrs['FontStyle'] ?? $this->style_property($paragraph_style, 'FontStyle', 'Regular'));
        $bold = ($format & AIW_ICML_Writer::FORMAT_BOLD) !== 0 || preg_match('/bold|black|heavy|semibold/i', $base) === 1;
        $italic = ($format & AIW_ICML_Writer::FORMAT_ITALIC) !== 0 || preg_match('/italic|oblique/i', $base) === 1;

        $candidates = $bold && $italic
            ? ['Bold Italic', 'Semibold Italic', 'Bold Oblique', 'Black Italic']
            : ($bold ? ['Bold', 'Semibold', 'Black', 'Heavy'] : ($italic ? ['Italic', 'Oblique', 'It'] : ['Regular', 'Roman', 'Book', 'R']));

        $family = $this->style_property($paragraph_style, 'AppliedFont', '');
        $available = $this->font_styles[$family] ?? [];
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    /**
     * A paragraph style property (attribute or Properties child), following BasedOn.
     */
    private function style_property(string $style_reference, string $name, string $default): string
    {
        $seen = [];
        while ($style_reference !== '' && !isset($seen[$style_reference]) && isset($this->style_elements[$style_reference])) {
            $seen[$style_reference] = true;
            $style = $this->style_elements[$style_reference];

            if ($style->hasAttribute($name)) {
                return (string) $style->getAttribute($name);
            }

            $properties = $this->first_child($style, 'Properties');
            $value = $properties ? $this->first_child($properties, $name) : null;
            if ($value) {
                return trim($value->textContent);
            }

            $based_on = $properties ? $this->first_child($properties, 'BasedOn') : null;
            $based_on = $based_on ? trim($based_on->textContent) : '';
            $style_reference = $based_on === '' ? '' : (strpos($based_on, 'ParagraphStyle/') === 0 ? $based_on : 'ParagraphStyle/' . $based_on);
        }

        return $default;
    }

    // ---------------------------------------------------------------------------------------------
    // Body text
    // ---------------------------------------------------------------------------------------------

    /**
     * Link every [TEXT] frame into one story and fill it with the body text.
     */
    private function fill_body(array $body): bool
    {
        $text_stories = [];
        foreach ($this->stories as $id => $story) {
            if (in_array('TEXT', $this->story_tokens($story['el']), true)) {
                $text_stories[] = $id;
            }
        }
        if (empty($text_stories)) {
            return false;
        }

        // Each story's frames in thread order; the chains in reading order.
        $chains = [];
        $spread_order = array_flip(array_keys($this->spreads));
        foreach ($text_stories as $story_id) {
            $chain = $this->story_frames($story_id);
            if (empty($chain)) {
                continue;
            }
            $first = $this->frames[$chain[0]];
            $box = $this->bounds_in_spread($first['el']);
            $chains[] = ['story' => $story_id, 'frames' => $chain, 'key' => [$spread_order[$first['spread']] ?? 0, round($box[0]), round($box[1])]];
        }
        if (empty($chains)) {
            return false;
        }
        usort($chains, static function (array $a, array $b): int {
            return $a['key'] <=> $b['key'];
        });

        $main_story = $chains[0]['story'];
        $frames = array_merge(...array_column($chains, 'frames'));
        foreach ($frames as $index => $frame_id) {
            $frame = $this->frames[$frame_id]['el'];
            $frame->setAttribute('ParentStory', $main_story);
            $frame->setAttribute('PreviousTextFrame', $frames[$index - 1] ?? 'n');
            $frame->setAttribute('NextTextFrame', $frames[$index + 1] ?? 'n');
            $this->changed[$this->frames[$frame_id]['spread']] = true;
        }

        $story = $this->stories[$main_story];
        $paragraphs = $this->read_paragraphs($story['el']);
        $base = null;
        foreach ($paragraphs as $paragraph) {
            if (preg_match($this->token_regex('TEXT'), $this->paragraph_text($paragraph)) === 1) {
                $base = $paragraph;
                break;
            }
        }
        $base = $base ?? ($paragraphs[0] ?? ['attrs' => [], 'props' => null, 'runs' => []]);

        // The [TEXT] frames hold only the body text; other text in them is template filler.
        $this->write_paragraphs($story['el'], $this->convert_paragraphs($body, $base, ['body']));
        $this->changed[$story['file']] = true;

        foreach (array_slice(array_column($chains, 'story'), 1) as $story_id) {
            $this->delete_story($story_id);
        }

        return true;
    }

    /**
     * @return string[] Frame IDs of a story in thread order.
     */
    private function story_frames(string $story_id): array
    {
        $frames = [];
        foreach ($this->frames as $id => $frame) {
            if ($frame['el']->getAttribute('ParentStory') === $story_id) {
                $frames[$id] = $frame['el'];
            }
        }

        $start = null;
        foreach ($frames as $id => $frame) {
            if (!isset($frames[$frame->getAttribute('PreviousTextFrame')])) {
                $start = $id;
                break;
            }
        }

        $ordered = [];
        while ($start !== null && isset($frames[$start]) && !in_array($start, $ordered, true)) {
            $ordered[] = $start;
            $next = $frames[$start]->getAttribute('NextTextFrame');
            $start = isset($frames[$next]) ? $next : null;
        }

        return $ordered;
    }

    private function delete_story(string $story_id): void
    {
        if (!isset($this->stories[$story_id])) {
            return;
        }

        $file = $this->stories[$story_id]['file'];
        unset($this->files[$file], $this->changed[$file], $this->stories[$story_id]);

        foreach (iterator_to_array($this->designmap->getElementsByTagNameNS(self::NS_PACKAGE, 'Story')) as $reference) {
            if ($reference->getAttribute('src') === $file) {
                $reference->parentNode->removeChild($reference);
            }
        }

        $root = $this->designmap->documentElement;
        $list = array_filter(preg_split('/\s+/', (string) $root->getAttribute('StoryList')) ?: [], static function (string $id) use ($story_id): bool {
            return $id !== '' && $id !== $story_id;
        });
        $root->setAttribute('StoryList', implode(' ', $list));
        $this->changed['designmap.xml'] = true;
    }

    // ---------------------------------------------------------------------------------------------
    // Pictures
    // ---------------------------------------------------------------------------------------------

    /**
     * @param array<string,array{file:string,link:string}> $pictures
     */
    private function place_pictures(array $pictures): void
    {
        foreach ($this->stories as $story_id => $story) {
            $labels = array_values(array_filter($this->story_tokens($story['el']), static function (string $token): bool {
                return preg_match(self::PICTURE_TOKEN, $token) === 1;
            }));
            if (empty($labels)) {
                continue;
            }
            $label = $labels[0];

            foreach ($this->story_frames($story_id) as $frame_id) {
                $spread_file = $this->frames[$frame_id]['spread'];
                $frame = $this->frames[$frame_id]['el'];
                $rectangle = $this->rectangle_under($spread_file, $frame);

                if ($rectangle === null) {
                    $this->warnings[] = sprintf(
                        /* translators: %s: placeholder, e.g. [BILD 2] */
                        __('The InDesign template has no picture frame under the placeholder [%s].', 'article-import-wizard'),
                        $label
                    );
                } elseif (isset($pictures[$label])) {
                    $this->place_image($rectangle, $pictures[$label]['file'], $pictures[$label]['link']);
                }

                $frame->parentNode->removeChild($frame);
                unset($this->frames[$frame_id]);
                $this->changed[$spread_file] = true;
            }

            $this->delete_story($story_id);
        }
    }

    /**
     * The smallest rectangle in the spread that contains the centre of $frame.
     */
    private function rectangle_under(string $spread_file, DOMElement $frame): ?DOMElement
    {
        $box = $this->bounds_in_spread($frame);
        $center_x = ($box[0] + $box[2]) / 2;
        $center_y = ($box[1] + $box[3]) / 2;

        $best = null;
        $best_area = INF;
        foreach ($this->spreads[$spread_file]->getElementsByTagName('Rectangle') as $rectangle) {
            $rect = $this->bounds_in_spread($rectangle);
            if ($center_x < $rect[0] || $center_x > $rect[2] || $center_y < $rect[1] || $center_y > $rect[3]) {
                continue;
            }
            $area = ($rect[2] - $rect[0]) * ($rect[3] - $rect[1]);
            if ($area < $best_area) {
                $best = $rectangle;
                $best_area = $area;
            }
        }

        return $best;
    }

    private function place_image(DOMElement $rectangle, string $file, string $link): void
    {
        $size = @getimagesize($file);
        if (!is_array($size) || !isset(self::IMAGE_FORMATS[$size[2]]) || $size[0] <= 0 || $size[1] <= 0) {
            $this->warnings[] = sprintf(
                /* translators: %s: file name in the download */
                __('%s could not be placed in the InDesign document (only JPEG, PNG and TIFF). Place it by hand from the Links folder.', 'article-import-wizard'),
                $link
            );
            return;
        }

        [$left, $top, $right, $bottom] = $this->inner_bounds($rectangle);
        $frame_width = $right - $left;
        $frame_height = $bottom - $top;
        if ($frame_width <= 0 || $frame_height <= 0) {
            return;
        }

        // Fill the frame proportionally, centred (InDesign's "Fill Frame Proportionally").
        $width = (float) $size[0];
        $height = (float) $size[1];
        $scale = max($frame_width / $width, $frame_height / $height);
        $x = $left + ($frame_width - $width * $scale) / 2;
        $y = $top + ($frame_height - $height * $scale) / 2;
        $ppi = $this->number(72 / $scale);

        $document = $rectangle->ownerDocument;
        foreach (iterator_to_array($rectangle->childNodes) as $child) {
            if ($child instanceof DOMElement && in_array($child->localName, ['Image', 'EPS', 'PDF', 'PICT', 'WMF', 'ImportedPage'], true)) {
                $rectangle->removeChild($child);
            }
        }
        $rectangle->setAttribute('ContentType', 'GraphicType');

        $image = $document->createElement('Image');
        $image->setAttribute('Self', $this->new_id());
        $image->setAttribute('ItemTransform', implode(' ', [$this->number($scale), '0', '0', $this->number($scale), $this->number($x), $this->number($y)]));
        $image->setAttribute('Space', '$ID/RGB');
        $image->setAttribute('ActualPpi', '72 72');
        $image->setAttribute('EffectivePpi', $ppi . ' ' . $ppi);
        $image->setAttribute('ImageRenderingIntent', 'UseColorSettings');
        $image->setAttribute('LocalDisplaySetting', 'Default');
        $image->setAttribute('ImageTypeName', self::IMAGE_FORMATS[$size[2]]);
        $image->setAttribute('AppliedObjectStyle', 'ObjectStyle/$ID/[None]');
        $image->setAttribute('Visible', 'true');
        $image->setAttribute('Name', '$ID/');

        $properties = $document->createElement('Properties');
        $bounds = $document->createElement('GraphicBounds');
        $bounds->setAttribute('Left', '0');
        $bounds->setAttribute('Top', '0');
        $bounds->setAttribute('Right', $this->number($width));
        $bounds->setAttribute('Bottom', $this->number($height));
        $properties->appendChild($bounds);
        $image->appendChild($properties);

        $link_element = $document->createElement('Link');
        $link_element->setAttribute('Self', $this->new_id());
        $link_element->setAttribute('AssetURL', '$ID/');
        $link_element->setAttribute('AssetID', '$ID/');
        $link_element->setAttribute('LinkResourceURI', 'file:' . str_replace('%2F', '/', rawurlencode($link)));
        $link_element->setAttribute('LinkResourceFormat', self::IMAGE_FORMATS[$size[2]]);
        $link_element->setAttribute('StoredState', 'Normal');
        $link_element->setAttribute('LinkClassID', '35906');
        $link_element->setAttribute('LinkClientID', '257');
        $link_element->setAttribute('LinkResourceModified', 'false');
        $link_element->setAttribute('LinkObjectModified', 'false');
        $link_element->setAttribute('ShowInUI', 'true');
        $link_element->setAttribute('CanEmbed', 'true');
        $link_element->setAttribute('CanUnembed', 'true');
        $link_element->setAttribute('CanPackage', 'true');
        $link_element->setAttribute('ImportPolicy', 'NoAutoImport');
        $link_element->setAttribute('ExportPolicy', 'NoAutoExport');
        $link_element->setAttribute('LinkResourceSize', '0~' . dechex((int) @filesize($file)));
        $image->appendChild($link_element);

        $rectangle->appendChild($image);
    }

    private function new_id(): string
    {
        $this->id_counter++;

        return 'aiw' . $this->id_counter;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    // ---------------------------------------------------------------------------------------------
    // Geometry
    // ---------------------------------------------------------------------------------------------

    /**
     * Bounds of an item in its own coordinates: [left, top, right, bottom].
     *
     * @return float[]
     */
    private function inner_bounds(DOMElement $item): array
    {
        $xs = [];
        $ys = [];
        $properties = $this->first_child($item, 'Properties');
        $geometry = $properties ? $this->first_child($properties, 'PathGeometry') : null;
        if ($geometry) {
            foreach ($geometry->getElementsByTagName('PathPointType') as $point) {
                $anchor = array_map('floatval', preg_split('/\s+/', trim((string) $point->getAttribute('Anchor'))) ?: []);
                if (count($anchor) === 2) {
                    $xs[] = $anchor[0];
                    $ys[] = $anchor[1];
                }
            }
        }

        return empty($xs) ? [0.0, 0.0, 0.0, 0.0] : [min($xs), min($ys), max($xs), max($ys)];
    }

    /**
     * Bounds of an item in spread coordinates, through its own and its groups' transforms.
     *
     * @return float[]
     */
    private function bounds_in_spread(DOMElement $item): array
    {
        [$left, $top, $right, $bottom] = $this->inner_bounds($item);
        $points = [[$left, $top], [$right, $top], [$right, $bottom], [$left, $bottom]];

        for ($node = $item; $node instanceof DOMElement && $node->localName !== 'Spread'; $node = $node->parentNode) {
            if (!$node->hasAttribute('ItemTransform')) {
                continue;
            }
            $m = array_map('floatval', preg_split('/\s+/', trim((string) $node->getAttribute('ItemTransform'))) ?: []);
            if (count($m) !== 6) {
                continue;
            }
            foreach ($points as $index => [$x, $y]) {
                $points[$index] = [$m[0] * $x + $m[2] * $y + $m[4], $m[1] * $x + $m[3] * $y + $m[5]];
            }
        }

        $xs = array_column($points, 0);
        $ys = array_column($points, 1);

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    // ---------------------------------------------------------------------------------------------
    // Output
    // ---------------------------------------------------------------------------------------------

    private function package(): string
    {
        foreach ($this->stories as $story) {
            if (isset($this->changed[$story['file']])) {
                $this->files[$story['file']] = (string) $story['doc']->saveXML();
            }
        }
        foreach ($this->spreads as $file => $doc) {
            if (isset($this->changed[$file])) {
                $this->files[$file] = (string) $doc->saveXML();
            }
        }
        if (isset($this->changed['designmap.xml'])) {
            $this->files['designmap.xml'] = (string) $this->designmap->saveXML();
        }

        $path = tempnam(sys_get_temp_dir(), 'aiw-idml');
        if (!is_string($path)) {
            return '';
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            return '';
        }

        // The mimetype entry must come first and be stored uncompressed.
        $zip->addFromString('mimetype', $this->files['mimetype'] ?? 'application/vnd.adobe.indesign-idml-package');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        foreach ($this->files as $name => $content) {
            if ($name !== 'mimetype') {
                $zip->addFromString($name, $content);
            }
        }
        $zip->close();

        $idml = (string) file_get_contents($path);
        @unlink($path);

        return $idml;
    }
}
