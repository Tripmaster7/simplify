<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_DOCX_Parser
{
    public function parse(string $file_path): array
    {
        if (!class_exists('ZipArchive')) {
            return [
                'title' => '',
                'subtitle' => '',
                'content' => '',
                'writing_date' => '',
                'doc_author' => '',
                'links' => [],
                'placeholders' => [],
                'error' => __('ZipArchive is not available on this server.', 'article-import-wizard'),
            ];
        }

        $zip = new ZipArchive();
        $opened = $zip->open($file_path);
        if ($opened !== true) {
            return [
                'title' => '',
                'subtitle' => '',
                'content' => '',
                'writing_date' => '',
                'doc_author' => '',
                'links' => [],
                'placeholders' => [],
                'error' => __('Could not open DOCX file.', 'article-import-wizard'),
            ];
        }

        $document_xml = $zip->getFromName('word/document.xml');
        $core_xml = $zip->getFromName('docProps/core.xml');
        $rels_xml = $zip->getFromName('word/_rels/document.xml.rels');
        $styles_xml = $zip->getFromName('word/styles.xml');
        $numbering_xml = $zip->getFromName('word/numbering.xml');
        $zip->close();

        if (!is_string($document_xml) || $document_xml === '') {
            return [
                'title' => '',
                'subtitle' => '',
                'content' => '',
                'writing_date' => '',
                'doc_author' => '',
                'links' => [],
                'placeholders' => [],
                'error' => __('DOCX document content is missing.', 'article-import-wizard'),
            ];
        }

        $this->link_targets = [];
        $this->load_styles($styles_xml);
        $this->load_numbering($numbering_xml);
        $paragraphs = $this->extract_paragraphs($document_xml, $this->extract_hyperlink_relationships($rels_xml));
        $title = '';
        $subtitle = '';
        $metadata = [];
        $html_parts = [];
        $first_body_text = '';
        $list_items = [];

        foreach ($paragraphs as $paragraph) {
            $text = trim($paragraph['text']);
            $plain = trim($this->strip_markup_markers($text));
            if ($plain === '') {
                continue;
            }

            // Metadata markers are matched on the plain text. A paragraph that contained markers
            // continues without its links and formatting; any other paragraph keeps them.
            $without_markers = $this->extract_metadata_from_text($plain, $metadata);
            if ($without_markers !== $plain) {
                $text = $without_markers;
            }

            $text = trim($text);
            if (trim($this->strip_markup_markers($text)) === '') {
                continue;
            }

            // Bold/italic on a [HEADER n ...] line is dropped (headings carry their own weight);
            // links inside the heading are kept.
            $header_tag = $this->extract_body_header_tag(trim($this->strip_format_markers($text)));
            if (is_array($header_tag)) {
                $html_parts = $this->flush_list($html_parts, $list_items);

                $header_level = isset($header_tag['level']) ? (int) $header_tag['level'] : 0;
                $header_text = isset($header_tag['text']) ? trim((string) $header_tag['text']) : '';

                if (trim($this->strip_markup_markers($header_text)) === '') {
                    continue;
                }

                if ($header_level === 1) {
                    if ($title === '') {
                        $title = $this->strip_markup_markers($header_text);
                        continue;
                    }

                    $html_parts[] = '<h1>' . $this->render_inline($header_text) . '</h1>';
                    continue;
                } elseif ($header_level >= 2 && $header_level <= 6) {
                    $html_parts[] = '<h' . $header_level . '>' . $this->render_inline($header_text) . '</h' . $header_level . '>';
                    continue;
                } else {
                    $text = $header_text;
                }
            }

            if (is_array($paragraph['list'])) {
                // A top-level item that switches between bullets and numbers starts a new list.
                if (!empty($list_items) && $paragraph['list']['level'] === 0 && $paragraph['list']['ordered'] !== $list_items[0]['ordered']) {
                    $html_parts = $this->flush_list($html_parts, $list_items);
                }

                if ($first_body_text === '') {
                    $first_body_text = $this->strip_markup_markers($text);
                }

                $list_items[] = [
                    'level' => $paragraph['list']['level'],
                    'ordered' => $paragraph['list']['ordered'],
                    'html' => $this->render_inline($text),
                ];
                continue;
            }

            $html_parts = $this->flush_list($html_parts, $list_items);

            $style = strtolower($paragraph['style']);
            if ($title === '' && ($this->contains($style, 'title') || $this->contains($style, 'heading1'))) {
                $title = $this->strip_markup_markers($text);
                continue;
            }

            if ($subtitle === '' && ($this->contains($style, 'subtitle') || $this->contains($style, 'heading2'))) {
                $subtitle = $this->strip_markup_markers($text);
                continue;
            }

            if ($first_body_text === '') {
                $first_body_text = $this->strip_markup_markers($text);
            }

            $html_parts[] = '<p>' . $this->render_inline($text) . '</p>';
        }

        $html_parts = $this->flush_list($html_parts, $list_items);

        // A [TITLE: ...] marker must be applied before the "first paragraph" fallback; otherwise a
        // leading marker such as [AUTHOR_MEMBERSHIP: 01234] would become the title.
        if ($title === '' && !empty($metadata['title'])) {
            $title = (string) $metadata['title'];
        }

        if ($title === '' && $first_body_text !== '') {
            $title = $first_body_text;
        }

        $writing_date = $this->extract_core_created_date($core_xml);
        $doc_author = $this->extract_core_author($core_xml);
        $content = implode("\n", $html_parts);
        $links = array_values(array_unique(array_merge(
            $this->extract_urls_from_text($content),
            $this->extract_hrefs($content)
        )));
        $placeholders = $this->extract_image_placeholders($content);

        $title = $this->normalize_metadata_text($title);

        if (!empty($metadata['subtitle'])) {
            $subtitle = (string) $metadata['subtitle'];
        }

        $subtitle = $this->normalize_metadata_text($subtitle);

        if (!empty($metadata['writing_date'])) {
            $writing_date = (string) $metadata['writing_date'];
        }

        return [
            'title' => $title,
            'subtitle' => $subtitle,
            'content' => $content,
            'writing_date' => $writing_date,
            'doc_author' => $doc_author,
            'metadata' => $metadata,
            'links' => $links,
            'placeholders' => $placeholders,
            'error' => '',
        ];
    }

    /**
     * Paragraph text carries inline markup as private-use marker characters, so marker and
     * heading parsing can keep working on plain strings:
     * - Hyperlinks: LINK_OPEN <index into $link_targets> LINK_SEPARATOR <link text> LINK_CLOSE.
     * - Formatting: one FORMAT_MARKERS character whenever bold/italic changes; its index is a
     *   bitmask of FORMAT_BOLD and FORMAT_ITALIC that applies to the text that follows.
     * The markers become HTML only after the text has been escaped (see render_inline()).
     */
    private const LINK_OPEN = "\u{E000}";
    private const LINK_SEPARATOR = "\u{E001}";
    private const LINK_CLOSE = "\u{E002}";
    private const FORMAT_MARKERS = ["\u{E010}", "\u{E011}", "\u{E012}", "\u{E013}"];
    private const FORMAT_BOLD = 1;
    private const FORMAT_ITALIC = 2;

    /**
     * Image placeholders and restriction anchors are processed later as plain text, so inline
     * formatting is never allowed to wrap them.
     */
    private const STANDALONE_TOKEN_PATTERN = '\[\s*(?:Bild|Image)\s*\d+\s*(?::[^\]]*)?\]|\[\s*RESTRICT(?:_START|_END)?\s*\]';

    private const NS_WORD = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const NS_RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_PACKAGE_RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * @var string[] Link URLs referenced by index from the marker characters.
     */
    private array $link_targets = [];

    /**
     * @var array<string,array{num_id:string,level:int}> Numbering inherited from paragraph styles (e.g. "List Bullet").
     */
    private array $style_numbering = [];

    /**
     * @var array<string,int> FORMAT_* bitmask of character styles (e.g. "Strong", "Emphasis").
     */
    private array $character_style_formats = [];

    /**
     * @var array<string,array<int,bool>> For each numbering ID: whether each level is numbered (true) or bulleted (false).
     */
    private array $list_level_ordered = [];

    private function load_xml($xml): ?DOMXPath
    {
        if (!is_string($xml) || $xml === '') {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::NS_WORD);

        return $xpath;
    }

    private function word_attribute(?DOMNode $node, string $name): string
    {
        return $node instanceof DOMElement ? (string) $node->getAttributeNS(self::NS_WORD, $name) : '';
    }

    private function first_node(DOMXPath $xpath, string $query, DOMNode $context): ?DOMNode
    {
        $nodes = $xpath->query($query, $context);

        return $nodes !== false && $nodes->length > 0 ? $nodes->item(0) : null;
    }

    private function load_styles($styles_xml): void
    {
        $this->style_numbering = [];
        $this->character_style_formats = [];

        $xpath = $this->load_xml($styles_xml);
        if (!$xpath) {
            return;
        }

        foreach ($xpath->query('//w:styles/w:style') ?: [] as $style) {
            $style_id = $this->word_attribute($style, 'styleId');
            if ($style_id === '') {
                continue;
            }

            $type = $this->word_attribute($style, 'type');
            if ($type === 'paragraph') {
                $num_id = $this->word_attribute($this->first_node($xpath, './w:pPr/w:numPr/w:numId', $style), 'val');
                if ($num_id !== '' && $num_id !== '0') {
                    $this->style_numbering[$style_id] = [
                        'num_id' => $num_id,
                        'level' => (int) $this->word_attribute($this->first_node($xpath, './w:pPr/w:numPr/w:ilvl', $style), 'val'),
                    ];
                }
            } elseif ($type === 'character') {
                $run_properties = $this->first_node($xpath, './w:rPr', $style);
                if ($run_properties) {
                    $this->character_style_formats[$style_id] = $this->apply_format_toggles(0, $run_properties);
                }
            }
        }
    }

    private function load_numbering($numbering_xml): void
    {
        $this->list_level_ordered = [];

        $xpath = $this->load_xml($numbering_xml);
        if (!$xpath) {
            return;
        }

        $abstract_levels = [];
        foreach ($xpath->query('//w:numbering/w:abstractNum') ?: [] as $abstract) {
            $levels = [];
            foreach ($xpath->query('./w:lvl', $abstract) ?: [] as $level) {
                $format = $this->word_attribute($this->first_node($xpath, './w:numFmt', $level), 'val');
                $levels[(int) $this->word_attribute($level, 'ilvl')] = !in_array($format, ['bullet', 'none'], true);
            }
            $abstract_levels[$this->word_attribute($abstract, 'abstractNumId')] = $levels;
        }

        foreach ($xpath->query('//w:numbering/w:num') ?: [] as $num) {
            $abstract_id = $this->word_attribute($this->first_node($xpath, './w:abstractNumId', $num), 'val');
            $this->list_level_ordered[$this->word_attribute($num, 'numId')] = $abstract_levels[$abstract_id] ?? [];
        }
    }

    /**
     * @return array{level:int,ordered:bool}|null
     */
    private function detect_list(DOMXPath $xpath, DOMNode $paragraph, string $style): ?array
    {
        // Word can number headings ("1. Introduction"); those stay headings, not list items.
        $style_lower = strtolower($style);
        if ($this->contains($style_lower, 'heading') || $this->contains($style_lower, 'title')) {
            return null;
        }

        $num_id = $this->word_attribute($this->first_node($xpath, './w:pPr/w:numPr/w:numId', $paragraph), 'val');
        $level_node = $this->first_node($xpath, './w:pPr/w:numPr/w:ilvl', $paragraph);

        if ($num_id === '' && isset($this->style_numbering[$style])) {
            $num_id = $this->style_numbering[$style]['num_id'];
            if (!$level_node) {
                $level = $this->style_numbering[$style]['level'];
            }
        }

        // numId 0 explicitly removes numbering inherited from the style.
        if ($num_id === '' || $num_id === '0') {
            return null;
        }

        $level = isset($level) ? $level : (int) $this->word_attribute($level_node, 'val');
        $level = max(0, min(8, $level));
        $levels = $this->list_level_ordered[$num_id] ?? [];

        return [
            'level' => $level,
            'ordered' => $levels[$level] ?? ($levels[0] ?? false),
        ];
    }

    private function run_format(DOMNode $text_node): int
    {
        $run = $text_node->parentNode;
        if (!$run instanceof DOMElement || $run->localName !== 'r') {
            return 0;
        }

        $format = 0;
        foreach ($run->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->localName !== 'rPr') {
                continue;
            }

            foreach ($child->childNodes as $property) {
                if ($property instanceof DOMElement && $property->localName === 'rStyle') {
                    $format = $this->character_style_formats[$this->word_attribute($property, 'val')] ?? 0;
                }
            }

            // Direct formatting on the run overrides its character style.
            $format = $this->apply_format_toggles($format, $child);
        }

        return $format;
    }

    private function apply_format_toggles(int $format, DOMNode $run_properties): int
    {
        foreach ($run_properties->childNodes as $property) {
            if (!$property instanceof DOMElement || $property->namespaceURI !== self::NS_WORD) {
                continue;
            }

            $flag = $property->localName === 'b' ? self::FORMAT_BOLD : ($property->localName === 'i' ? self::FORMAT_ITALIC : 0);
            if ($flag === 0) {
                continue;
            }

            $value = strtolower($this->word_attribute($property, 'val'));
            $enabled = !in_array($value, ['0', 'false', 'off', 'none'], true);
            $format = $enabled ? ($format | $flag) : ($format & ~$flag);
        }

        return $format;
    }

    /**
     * @return array<string,string> External hyperlink targets keyed by relationship ID.
     */
    private function extract_hyperlink_relationships($rels_xml): array
    {
        if (!is_string($rels_xml) || $rels_xml === '') {
            return [];
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($rels_xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }

        $targets = [];
        foreach ($document->getElementsByTagNameNS(self::NS_PACKAGE_RELATIONSHIPS, 'Relationship') as $relationship) {
            $type = (string) $relationship->getAttribute('Type');
            if (substr($type, -strlen('/hyperlink')) !== '/hyperlink') {
                continue;
            }

            $id = (string) $relationship->getAttribute('Id');
            if ($id !== '') {
                $targets[$id] = (string) $relationship->getAttribute('Target');
            }
        }

        return $targets;
    }

    private function extract_paragraphs(string $document_xml, array $relationships = []): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($document_xml, LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::NS_WORD);
        $paragraph_nodes = $xpath->query('//w:body/w:p');
        if ($paragraph_nodes === false) {
            return [];
        }

        $paragraphs = [];
        foreach ($paragraph_nodes as $node) {
            $state = [
                'text' => '',
                'link_open' => false,
                'fields' => [],
                'format' => 0,
            ];
            $this->walk_paragraph_node($node, $relationships, $state);
            if ($state['link_open']) {
                $state['text'] .= self::LINK_CLOSE;
            }

            $style = $this->word_attribute($this->first_node($xpath, './w:pPr/w:pStyle', $node), 'val');

            $paragraphs[] = [
                'text' => $state['text'],
                'style' => $style,
                'list' => $this->detect_list($xpath, $node, $style),
            ];
        }

        return $paragraphs;
    }

    /**
     * Collect a paragraph's text in document order, marking hyperlinks. Handles both forms Word
     * writes: <w:hyperlink r:id="..."> elements and HYPERLINK field codes (w:fldSimple, or
     * w:fldChar begin/separate/end with w:instrText).
     */
    private function walk_paragraph_node(DOMNode $node, array $relationships, array &$state): void
    {
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            if ($child->namespaceURI !== self::NS_WORD) {
                $this->walk_paragraph_node($child, $relationships, $state);
                continue;
            }

            switch ($child->localName) {
                case 't':
                    $text = $this->strip_markup_markers($child->textContent);
                    if ($text === '') {
                        break;
                    }

                    $format = $this->run_format($child);
                    if ($format !== $state['format']) {
                        $state['text'] .= self::FORMAT_MARKERS[$format];
                        $state['format'] = $format;
                    }

                    $state['text'] .= $text;
                    break;

                case 'hyperlink':
                    // Links to bookmarks inside the document (w:anchor only) have no r:id and stay plain text.
                    $relationship_id = (string) $child->getAttributeNS(self::NS_RELATIONSHIPS, 'id');
                    $target = $relationship_id !== '' && isset($relationships[$relationship_id]) ? $relationships[$relationship_id] : '';
                    $started = $this->open_link($target, $state);
                    $this->walk_paragraph_node($child, $relationships, $state);
                    if ($started) {
                        $this->close_link($state);
                    }
                    break;

                case 'fldSimple':
                    $started = $this->open_link($this->parse_hyperlink_field((string) $child->getAttributeNS(self::NS_WORD, 'instr')), $state);
                    $this->walk_paragraph_node($child, $relationships, $state);
                    if ($started) {
                        $this->close_link($state);
                    }
                    break;

                case 'fldChar':
                    $type = (string) $child->getAttributeNS(self::NS_WORD, 'fldCharType');
                    if ($type === 'begin') {
                        $state['fields'][] = ['instr' => '', 'started' => false];
                    } elseif ($type === 'separate' && !empty($state['fields'])) {
                        $index = count($state['fields']) - 1;
                        $state['fields'][$index]['started'] = $this->open_link(
                            $this->parse_hyperlink_field($state['fields'][$index]['instr']),
                            $state
                        );
                    } elseif ($type === 'end' && !empty($state['fields'])) {
                        $field = array_pop($state['fields']);
                        if ($field['started']) {
                            $this->close_link($state);
                        }
                    }
                    break;

                case 'instrText':
                    if (!empty($state['fields'])) {
                        $state['fields'][count($state['fields']) - 1]['instr'] .= $child->textContent;
                    }
                    break;

                case 'delText':
                case 'pPr':
                case 'rPr':
                    break;

                default:
                    $this->walk_paragraph_node($child, $relationships, $state);
            }
        }
    }

    private function open_link(string $target, array &$state): bool
    {
        $target = $this->safe_link_target($target);
        if ($target === '' || $state['link_open']) {
            return false;
        }

        $index = count($this->link_targets);
        $this->link_targets[] = $target;
        $state['text'] .= self::LINK_OPEN . $index . self::LINK_SEPARATOR;
        $state['link_open'] = true;

        return true;
    }

    private function close_link(array &$state): void
    {
        $state['text'] .= self::LINK_CLOSE;
        $state['link_open'] = false;
    }

    private function parse_hyperlink_field(string $instruction): string
    {
        // HYPERLINK "https://example.com" \o "tooltip"  |  HYPERLINK https://example.com
        // A field with only \l "bookmark" points inside the document and yields no target.
        if (preg_match('/^\s*HYPERLINK\s+(?:"([^"]*)"|([^\s\\\\"]+))/i', $instruction, $matches) !== 1) {
            return '';
        }

        return isset($matches[2]) && $matches[2] !== '' ? $matches[2] : (string) $matches[1];
    }

    /**
     * Only web and mail links are imported; anything else (file://, javascript:, relative paths)
     * is kept as plain text.
     */
    private function safe_link_target(string $target): string
    {
        $target = trim($target);
        if (preg_match('#^(https?://[^\s]+|mailto:[^\s]+)$#i', $target) !== 1) {
            return '';
        }

        return $target;
    }

    /**
     * Remove all link and formatting markers, leaving plain text.
     */
    private function strip_markup_markers(string $text): string
    {
        return (string) preg_replace('/\x{E000}\d+\x{E001}|[\x{E000}-\x{E002}\x{E010}-\x{E013}]/u', '', $text);
    }

    /**
     * Remove only the formatting markers, keeping links.
     */
    private function strip_format_markers(string $text): string
    {
        return (string) preg_replace('/[\x{E010}-\x{E013}]/u', '', $text);
    }

    /**
     * Escape the text and turn its markers into well-nested HTML: <a> for links, <strong>/<em>
     * for bold/italic. Formatting tags are closed before every link boundary and around image
     * placeholders and [RESTRICT] anchors, so those tokens always stand on their own.
     */
    private function render_inline(string $text): string
    {
        // Word often splits a marker such as [Bild 1] into differently formatted runs. Move any
        // formatting change out of bracketed spans (keeping the last one, so the text after the
        // bracket still gets the right format) so placeholders stay recognisable.
        $text = (string) preg_replace_callback(
            '/\[[^\]\x{E000}-\x{E002}]*\]/u',
            function (array $matches): string {
                if (preg_match_all('/[\x{E010}-\x{E013}]/u', $matches[0], $found) === 0) {
                    return $matches[0];
                }

                return $this->strip_format_markers($matches[0]) . end($found[0]);
            },
            $text
        );

        $tokens = preg_split(
            '/(\x{E000}\d+\x{E001}|\x{E002}|[\x{E010}-\x{E013}]|' . self::STANDALONE_TOKEN_PATTERN . ')/iu',
            esc_html($text),
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        if (!is_array($tokens)) {
            return esc_html($this->strip_markup_markers($text));
        }

        $html = '';
        $wanted_format = 0;
        $open_tags = [];
        $link_start = null;

        $close_formatting = static function () use (&$html, &$open_tags): void {
            while (!empty($open_tags)) {
                $html .= '</' . array_pop($open_tags) . '>';
            }
        };

        foreach ($tokens as $token) {
            if (preg_match('/^\x{E000}(\d+)\x{E001}$/u', $token, $matches) === 1) {
                $close_formatting();
                $target = $this->link_targets[(int) $matches[1]] ?? '';
                if ($target !== '' && $link_start === null) {
                    $html .= '<a href="' . esc_url($target) . '">';
                    $link_start = strlen($html);
                }
                continue;
            }

            if ($token === self::LINK_CLOSE) {
                $close_formatting();
                if ($link_start !== null) {
                    $inner = substr($html, $link_start);
                    if (trim(wp_strip_all_tags($inner)) === '') {
                        // Drop links without visible text, keep whatever was inside.
                        $html = (string) preg_replace('/<a href="[^"]*">$/', '', substr($html, 0, $link_start)) . $inner;
                    } else {
                        $html .= '</a>';
                    }
                    $link_start = null;
                }
                continue;
            }

            $format_index = array_search($token, self::FORMAT_MARKERS, true);
            if ($format_index !== false) {
                $wanted_format = (int) $format_index;
                continue;
            }

            if (preg_match('/^(?:' . self::STANDALONE_TOKEN_PATTERN . ')$/iu', $token) === 1) {
                $close_formatting();
                $html .= $token;
                continue;
            }

            // Plain text: open/close <strong>/<em> to match the wanted format, keeping nesting valid.
            $wanted_tags = [];
            if ($wanted_format & self::FORMAT_BOLD) {
                $wanted_tags[] = 'strong';
            }
            if ($wanted_format & self::FORMAT_ITALIC) {
                $wanted_tags[] = 'em';
            }

            $common = 0;
            while ($common < count($open_tags) && $common < count($wanted_tags) && $open_tags[$common] === $wanted_tags[$common]) {
                $common++;
            }
            while (count($open_tags) > $common) {
                $html .= '</' . array_pop($open_tags) . '>';
            }
            for ($i = $common; $i < count($wanted_tags); $i++) {
                $html .= '<' . $wanted_tags[$i] . '>';
                $open_tags[] = $wanted_tags[$i];
            }

            $html .= $token;
        }

        $close_formatting();
        if ($link_start !== null) {
            $html .= '</a>';
        }

        // Tidy up: no formatting tags around only whitespace.
        return (string) preg_replace('/<(strong|em)>(\s*)<\/\1>/', '$2', $html);
    }

    /**
     * Turn the collected list items into one nested <ul>/<ol> and append it to the HTML parts.
     *
     * @param array<int,array{level:int,ordered:bool,html:string}> $list_items Cleared afterwards.
     */
    private function flush_list(array $html_parts, array &$list_items): array
    {
        if (empty($list_items)) {
            return $html_parts;
        }

        $html = '';
        $open_lists = [];

        foreach ($list_items as $index => $item) {
            // Nest at most one level deeper than the previous item; the first item is always top level.
            $target_depth = $index === 0 ? 1 : max(1, min($item['level'] + 1, count($open_lists) + 1));

            if ($target_depth > count($open_lists)) {
                while (count($open_lists) < $target_depth) {
                    $tag = $item['ordered'] ? 'ol' : 'ul';
                    $html .= '<' . $tag . '>';
                    $open_lists[] = $tag;
                }
            } else {
                $html .= '</li>';
                while (count($open_lists) > $target_depth) {
                    $html .= '</' . array_pop($open_lists) . '></li>';
                }
            }

            $html .= '<li>' . $item['html'];
        }

        $html .= '</li>';
        while (!empty($open_lists)) {
            $html .= '</' . array_pop($open_lists) . '>';
            if (!empty($open_lists)) {
                $html .= '</li>';
            }
        }

        $html_parts[] = $html;
        $list_items = [];

        return $html_parts;
    }

    private function extract_hrefs(string $content): array
    {
        preg_match_all('/<a\s[^>]*href="([^"]+)"/i', $content, $matches);

        return array_map(static function (string $href): string {
            return html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $matches[1] ?? []);
    }

    private function extract_core_created_date($core_xml): string
    {
        if (!is_string($core_xml) || $core_xml === '') {
            return '';
        }

        $xml = simplexml_load_string($core_xml);
        if ($xml === false) {
            return '';
        }

        $xml->registerXPathNamespace('dcterms', 'http://purl.org/dc/terms/');
        $created = $xml->xpath('//dcterms:created');
        if (!is_array($created) || !isset($created[0])) {
            return '';
        }

        return (string) $created[0];
    }

    private function extract_core_author($core_xml): string
    {
        if (!is_string($core_xml) || $core_xml === '') {
            return '';
        }

        $xml = simplexml_load_string($core_xml);
        if ($xml === false) {
            return '';
        }

        $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $creator = $xml->xpath('//dc:creator');
        if (!is_array($creator) || !isset($creator[0])) {
            return '';
        }

        return (string) $creator[0];
    }

    private function extract_urls_from_text(string $content): array
    {
        if ($content === '') {
            return [];
        }

        preg_match_all('~https?://[^\s<]+~i', wp_strip_all_tags($content), $matches);
        if (!isset($matches[0]) || !is_array($matches[0])) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }

    private function extract_image_placeholders(string $content): array
    {
        $pattern = '/\[(Bild|Image)\s*(\d+)\s*(?::([^\]]*))?\]/i';
        preg_match_all($pattern, wp_strip_all_tags($content), $matches, PREG_SET_ORDER);

        $results = [];
        foreach ($matches as $match) {
            $results[] = [
                'token' => $match[0],
                'index' => isset($match[2]) ? (int) $match[2] : 0,
                'caption' => isset($match[3]) ? trim((string) $match[3]) : '',
            ];
        }

        return $results;
    }

    private function extract_metadata_from_text(string $text, array &$metadata): string
    {
        $patterns = [
            'title' => '/\[(TITLE|HEADLINE|H1)\s*:\s*([^\]]+)\]/i',
            'subtitle' => '/\[(SUBTITLE|SUBHEADER|H2)\s*:\s*([^\]]+)\]/i',
            'author_membership' => '/\[(AUTHOR_MEMBERSHIP|MEMBERSHIP|MEMBER_ID)\s*:\s*([^\]]+)\]/i',
            'writing_date' => '/\[(WRITING_DATE|DATE)\s*:\s*([^\]]+)\]/i',
            'bio' => '/\[(BIO)\s*:\s*([^\]]+)\]/i',
        ];

        $text = (string) preg_replace('/\[AUTHOR_NAME\s*:\s*[^\]]*\]/i', '', $text);

        foreach ($patterns as $key => $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $value = isset($match[2]) ? trim((string) $match[2]) : '';
                    if ($value === '') {
                        continue;
                    }

                    if (!isset($metadata[$key])) {
                        $metadata[$key] = $value;
                    } elseif (!isset($metadata[$key . '_duplicate'])) {
                        $metadata[$key . '_duplicate'] = true;
                    }
                }

                $text = (string) preg_replace($pattern, '', $text);
            }
        }

        if (preg_match('/\[\s*RESTRICT(?:_START)?\s*\]/iu', $text)) {
            $metadata['restriction_anchor'] = true;
        }

        if (preg_match('/\[\s*(RESTRICT_START|RESTRICT_END)\s*\]/iu', $text)) {
            if (!isset($metadata['restriction_flags'])) {
                $metadata['restriction_flags'] = [];
            }

            if (preg_match('/\[\s*RESTRICT_START\s*\]/iu', $text)) {
                $metadata['restriction_flags'][] = 'RESTRICT_START';
            }
            if (preg_match('/\[\s*RESTRICT_END\s*\]/iu', $text)) {
                $metadata['restriction_flags'][] = 'RESTRICT_END';
            }

            $text = (string) preg_replace('/\[\s*RESTRICT_(?:START|END)\s*\]/iu', '', $text);
        }

        return $text;
    }

    private function contains(string $haystack, string $needle): bool
    {
        return strpos($haystack, $needle) !== false;
    }

    private function normalize_metadata_text(string $text): string
    {
        $text = preg_replace('/^\[(TITLE|HEADLINE|H1|SUBTITLE|SUBHEADER|H2|AUTHOR_MEMBERSHIP|MEMBERSHIP|MEMBER_ID|WRITING_DATE|DATE|BIO)\s*:\s*([^"]+?)\]$/i', '$2', trim($text));
        $text = preg_replace('/^\[(TITLE|HEADLINE|H1|SUBTITLE|SUBHEADER|H2|AUTHOR_MEMBERSHIP|MEMBERSHIP|MEMBER_ID|WRITING_DATE|DATE|BIO)\s*:\s*([^\]]+?)\]$/i', '$2', (string) $text);

        return trim((string) $text);
    }

    private function extract_body_header_tag(string $text): ?array
    {
        if (!preg_match('/^\[\s*HEADER(?:\s+([1-6]))?\s*:?\s+(.+?)\s*\]$/iu', trim($text), $matches)) {
            return null;
        }

        return [
            'level' => isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 2,
            'text' => trim((string) ($matches[2] ?? '')),
        ];
    }
}
