<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns an imported article into paragraphs with InDesign styles, and writes them as an InCopy
 * (ICML) story. AIW_IDML_Builder uses the same paragraphs to fill the article template.
 *
 * The article body is the HTML the DOCX parser produces (p, h1-h6, ul/ol/li, strong/em, a, br and
 * [Bild n: caption] placeholders). Every paragraph gets a style role (title, body, heading2, ...);
 * the role is mapped to a style name from the settings. Style names may contain style groups as
 * "Group:Style". Roles without a name fall back to a related role (see FALLBACK_ROLES); bold and
 * italic without a character style become local formatting.
 *
 * In print, the first paragraph of the article is the Vorspann (intro).
 */
class AIW_ICML_Writer
{
    public const FORMAT_BOLD = 1;
    public const FORMAT_ITALIC = 2;

    private const NO_PARAGRAPH_STYLE = '$ID/NormalParagraphStyle';
    public const NO_CHARACTER_STYLE = '$ID/[No character style]';

    /** InDesign's forced line break (Shift+Enter). */
    public const LINE_BREAK = "\u{2028}";

    private const IMAGE_PATTERN = '/\[\s*(?:Bild|Image)\s*(\d+)\s*(?::([^\]]*))?\]/iu';
    private const RESTRICT_PATTERN = '/\[\s*RESTRICT(?:_START|_END)?\s*\]/iu';

    /**
     * Role used when a role has no style name. Chains end at "body".
     */
    private const FALLBACK_ROLES = [
        'subtitle' => 'body',
        'author' => 'subtitle',
        'vorspann' => 'body',
        'body_first' => 'body',
        'heading2' => 'body',
        'heading3' => 'heading2',
        'heading4' => 'heading3',
        'list_bullet' => 'body',
        'list_bullet_2' => 'list_bullet',
        'list_number' => 'body',
        'list_number_2' => 'list_number',
        'image' => 'body',
        'caption' => 'body',
        'bio_name' => 'heading2',
        'bio' => 'body',
    ];

    private const LIST_ROLES = ['list_bullet', 'list_bullet_2', 'list_number', 'list_number_2'];

    /**
     * @var array<string,string> Style names keyed by role (see AIW_Settings::DEFAULT_INDESIGN_STYLES).
     */
    private array $styles;

    /**
     * @var array<int,array{style:string,runs:array<int,array{text:string,format:int,link:bool}>,restart:bool,number?:int,marker?:string}>
     */
    private array $paragraphs = [];

    /** True when the next body paragraph is the first after the start or a heading. */
    private bool $next_is_first = true;

    /**
     * @param array<string,string> $styles Style names keyed by role (title, body, bold, ...).
     */
    public function __construct(array $styles)
    {
        $this->styles = $styles;
    }

    /**
     * @param array{title?:string,subtitle?:string,author_name?:string,content?:string,bio?:string} $article
     */
    public function build(array $article): string
    {
        $parts = $this->split_article((string) ($article['content'] ?? ''));

        $this->paragraphs = [];
        $this->add_plain_paragraph('title', (string) ($article['title'] ?? ''));
        $this->add_plain_paragraph('subtitle', (string) ($article['subtitle'] ?? ''));
        $this->add_plain_paragraph('author', (string) ($article['author_name'] ?? ''));
        if ($parts['vorspann'] !== null) {
            $this->paragraphs[] = $parts['vorspann'];
        }
        $paragraphs = array_merge($this->paragraphs, $parts['body']);

        $this->paragraphs = [];
        foreach ($this->bio_paragraphs((string) ($article['author_name'] ?? ''), (string) ($article['bio'] ?? '')) as $paragraph) {
            $paragraphs[] = $paragraph;
        }

        return $this->render($paragraphs, (string) ($article['title'] ?? ''));
    }

    /**
     * The article body as paragraphs. The first paragraph is the Vorspann (style role
     * "vorspann") when the article starts with a normal paragraph. Picture placeholders become an
     * "image" paragraph with the picture number, followed by a "caption" paragraph with the same
     * number when a caption was given.
     *
     * @return array{vorspann:?array,body:array<int,array>}
     */
    public function split_article(string $html): array
    {
        $this->paragraphs = [];
        $this->next_is_first = true;
        $this->add_html($html);
        $paragraphs = $this->paragraphs;
        $this->paragraphs = [];

        $vorspann = null;
        if (!empty($paragraphs) && in_array($paragraphs[0]['style'], ['body', 'body_first'], true)) {
            $vorspann = array_shift($paragraphs);
            $vorspann['style'] = 'vorspann';

            // The paragraph after the Vorspann opens the body text.
            foreach ($paragraphs as $index => $paragraph) {
                if ($paragraph['style'] === 'body' && $this->style_name('body_first') !== '') {
                    $paragraphs[$index]['style'] = 'body_first';
                }
                if (in_array($paragraph['style'], ['body', 'body_first'], true)) {
                    break;
                }
            }
        }

        return ['vorspann' => $vorspann, 'body' => $paragraphs];
    }

    /**
     * Author name and bio. Blank lines separate bio paragraphs; single line breaks stay line breaks
     * (as on the website).
     *
     * @return array<int,array>
     */
    public function bio_paragraphs(string $author_name, string $bio, bool $with_name = true): array
    {
        $this->paragraphs = [];
        $bio = trim($bio);
        if ($bio !== '') {
            if ($with_name) {
                $this->add_plain_paragraph('bio_name', $author_name);
            }
            foreach (preg_split('/\R\s*\R/u', $bio) ?: [] as $bio_paragraph) {
                $this->add_plain_paragraph('bio', (string) preg_replace('/[ \t]*\R[ \t]*/u', self::LINE_BREAK, trim($bio_paragraph)));
            }
        }

        $paragraphs = $this->paragraphs;
        $this->paragraphs = [];

        return $paragraphs;
    }

    // ---------------------------------------------------------------------------------------------
    // Style resolution (shared with AIW_IDML_Builder)
    // ---------------------------------------------------------------------------------------------

    public function style_name(string $role): string
    {
        return trim((string) ($this->styles[$role] ?? ''));
    }

    /**
     * The role whose style name is used for $role, following FALLBACK_ROLES. Returns '' when not
     * even "body" has a name.
     */
    public function resolve_role(string $role, ?callable $exists = null): string
    {
        $seen = [];
        while ($role !== '' && !isset($seen[$role])) {
            $seen[$role] = true;
            $name = $this->style_name($role);
            if ($name !== '' && ($exists === null || $exists($name))) {
                return $role;
            }
            $role = self::FALLBACK_ROLES[$role] ?? '';
        }

        return '';
    }

    /**
     * The runs of a paragraph as they are written. List items whose role has no list style get
     * their bullet or number as text, so the list stays readable.
     *
     * @return array<int,array{text:string,format:int,link:bool}>
     */
    public function output_runs(array $paragraph, string $resolved_role): array
    {
        $runs = $paragraph['runs'];
        if (!empty($paragraph['marker']) && in_array($paragraph['style'], self::LIST_ROLES, true) && !in_array($resolved_role, self::LIST_ROLES, true)) {
            array_unshift($runs, ['text' => $paragraph['marker'] . "\t", 'format' => 0, 'link' => false]);
        }

        return $runs;
    }

    /**
     * The character style for a run, or '' for none (then bold/italic are local formatting).
     *
     * @param array{text:string,format:int,link:bool} $run
     */
    public function character_style_for(array $run): string
    {
        if ($run['link'] && $this->style_name('link') !== '') {
            return $this->style_name('link');
        }

        $role = [0 => '', self::FORMAT_BOLD => 'bold', self::FORMAT_ITALIC => 'italic', self::FORMAT_BOLD | self::FORMAT_ITALIC => 'bold_italic'][$run['format'] & 3];

        return $role !== '' ? $this->style_name($role) : '';
    }

    /**
     * Join neighbouring runs with the same formatting.
     *
     * @param array<int,array{text:string,format:int,link:bool}> $runs
     * @return array<int,array{text:string,format:int,link:bool}>
     */
    public function merge_runs(array $runs): array
    {
        $merged = [];
        foreach ($runs as $run) {
            $last = count($merged) - 1;
            if ($last >= 0 && $this->character_style_for($merged[$last]) === $this->character_style_for($run) && ($merged[$last]['format'] & 3) === ($run['format'] & 3)) {
                $merged[$last]['text'] .= $run['text'];
            } else {
                $merged[] = $run;
            }
        }

        return $merged;
    }

    /**
     * "Group:Style" -> ["Group", "Style"]. The built-in "$ID/..." names are never split.
     *
     * @return string[]
     */
    public static function style_path(string $name): array
    {
        if (strpos($name, '$ID/') === 0) {
            return [$name];
        }

        $parts = array_values(array_filter(array_map('trim', explode(':', $name)), static function (string $part): bool {
            return $part !== '';
        }));

        return empty($parts) ? [$name] : $parts;
    }

    public static function style_reference(string $kind, string $name): string
    {
        return $kind . '/' . implode('%3a', self::style_path($name));
    }

    /**
     * Remove characters that are not allowed in XML and the private marker characters of the parser.
     */
    public static function clean_text(string $text): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]|[\x{E000}-\x{E013}]/u', '', $text);
    }

    // ---------------------------------------------------------------------------------------------
    // HTML to paragraphs
    // ---------------------------------------------------------------------------------------------

    private function add_plain_paragraph(string $style, string $text, int $number = 0): void
    {
        $this->push_paragraph($style, [['text' => $text, 'format' => 0, 'link' => false]], false, $number);
    }

    private function add_html(string $html): void
    {
        if (trim($html) === '') {
            return;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="aiw-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return;
        }

        $root = $document->getElementById('aiw-root');
        if ($root instanceof DOMElement) {
            $this->add_block_children($root);
        }
    }

    private function add_block_children(DOMNode $parent): void
    {
        $loose_runs = [];

        foreach ($parent->childNodes as $child) {
            $tag = $child instanceof DOMElement ? strtolower($child->tagName) : '';

            if (in_array($tag, ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'div', 'blockquote', 'figure', 'section', 'article', 'table'], true)) {
                $this->add_runs_paragraph('body', $loose_runs);
                $loose_runs = [];
            }

            switch ($tag) {
                case 'p':
                    $this->add_runs_paragraph('body', $this->collect_runs($child));
                    break;

                case 'h1':
                case 'h2':
                    $this->add_runs_paragraph('heading2', $this->collect_runs($child));
                    break;

                case 'h3':
                    $this->add_runs_paragraph('heading3', $this->collect_runs($child));
                    break;

                case 'h4':
                case 'h5':
                case 'h6':
                    $this->add_runs_paragraph('heading4', $this->collect_runs($child));
                    break;

                case 'ul':
                case 'ol':
                    $this->add_list($child, 0);
                    break;

                case 'div':
                case 'blockquote':
                case 'figure':
                case 'section':
                case 'article':
                case 'table':
                    $this->add_block_children($child);
                    break;

                default:
                    // Text or inline markup directly in the body (manually entered content).
                    $loose_runs = array_merge($loose_runs, $this->collect_runs($child));
            }
        }

        $this->add_runs_paragraph('body', $loose_runs);
    }

    private function add_list(DOMElement $list, int $level): void
    {
        $ordered = strtolower($list->tagName) === 'ol';
        $style = $ordered
            ? ($level === 0 ? 'list_number' : 'list_number_2')
            : ($level === 0 ? 'list_bullet' : 'list_bullet_2');
        $restart = $ordered;
        $counter = 0;

        foreach ($list->childNodes as $item) {
            if (!$item instanceof DOMElement || strtolower($item->tagName) !== 'li') {
                continue;
            }

            $runs = [];
            $nested = [];
            foreach ($item->childNodes as $child) {
                if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['ul', 'ol'], true)) {
                    $nested[] = $child;
                } else {
                    $runs = array_merge($runs, $this->collect_runs($child));
                }
            }

            $counter++;
            $marker = $ordered ? $counter . '.' : ($level === 0 ? '•' : '–');
            if ($this->add_runs_paragraph($style, $runs, $restart, $marker)) {
                $restart = false;
            }

            foreach ($nested as $nested_list) {
                $this->add_list($nested_list, $level + 1);
            }
        }
    }

    /**
     * @return array<int,array{text:string,format:int,link:bool}>
     */
    private function collect_runs(DOMNode $node, int $format = 0, bool $link = false): array
    {
        if ($node instanceof DOMText) {
            $text = (string) preg_replace('/\s+/u', ' ', $node->data);
            return $text === '' ? [] : [['text' => $text, 'format' => $format, 'link' => $link]];
        }

        if (!$node instanceof DOMElement) {
            return [];
        }

        $tag = strtolower($node->tagName);
        if ($tag === 'br') {
            return [['text' => self::LINE_BREAK, 'format' => $format, 'link' => $link]];
        }
        if (in_array($tag, ['img', 'script', 'style'], true)) {
            return [];
        }

        if ($tag === 'strong' || $tag === 'b') {
            $format |= self::FORMAT_BOLD;
        } elseif ($tag === 'em' || $tag === 'i') {
            $format |= self::FORMAT_ITALIC;
        } elseif ($tag === 'a' && $node->getAttribute('href') !== '') {
            $link = true;
        }

        $runs = [];
        foreach ($node->childNodes as $child) {
            $runs = array_merge($runs, $this->collect_runs($child, $format, $link));
        }

        return $runs;
    }

    /**
     * Add one paragraph. Image placeholders split it: text before the placeholder, the image
     * placeholder and its caption, then the rest of the text in the same style. [RESTRICT]
     * anchors have no meaning in print and are removed.
     *
     * @param array<int,array{text:string,format:int,link:bool}> $runs
     * @return bool Whether a paragraph with the given style was added.
     */
    private function add_runs_paragraph(string $style, array $runs, bool $restart = false, string $marker = ''): bool
    {
        $added = false;
        $current = [];

        foreach ($runs as $run) {
            $text = (string) preg_replace(self::RESTRICT_PATTERN, '', $run['text']);
            preg_match_all(self::IMAGE_PATTERN, $text, $images, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            $offset = 0;
            foreach ($images as $image) {
                $before = substr($text, $offset, $image[0][1] - $offset);
                if ($before !== '') {
                    $current[] = ['text' => $before, 'format' => $run['format'], 'link' => $run['link']];
                }
                $offset = $image[0][1] + strlen($image[0][0]);

                if ($this->push_paragraph($style, $current, $restart, 0, $added ? '' : $marker)) {
                    $added = true;
                    $restart = false;
                }
                $current = [];

                $number = (int) $image[1][0];
                $this->add_plain_paragraph('image', '[Bild ' . $number . ']', $number);
                $this->add_plain_paragraph('caption', trim((string) ($image[2][0] ?? '')), $number);
            }

            $rest = substr($text, $offset);
            if ($rest !== '') {
                $current[] = ['text' => $rest, 'format' => $run['format'], 'link' => $run['link']];
            }
        }

        if ($this->push_paragraph($style, $current, $restart, 0, $added ? '' : $marker)) {
            $added = true;
        }

        return $added;
    }

    /**
     * @param array<int,array{text:string,format:int,link:bool}> $runs
     * @return bool False when the paragraph is empty and was skipped.
     */
    private function push_paragraph(string $style, array $runs, bool $restart = false, int $number = 0, string $marker = ''): bool
    {
        $runs = $this->trim_runs($runs);
        if (empty($runs)) {
            return false;
        }

        if ($style === 'body' && $this->next_is_first && $this->style_name('body_first') !== '') {
            $style = 'body_first';
        }

        if (in_array($style, ['body', 'body_first'], true)) {
            $this->next_is_first = false;
        } elseif (in_array($style, ['title', 'subtitle', 'author', 'vorspann', 'heading2', 'heading3', 'heading4'], true)) {
            $this->next_is_first = true;
        }

        $paragraph = ['style' => $style, 'runs' => $runs, 'restart' => $restart];
        if ($number > 0) {
            $paragraph['number'] = $number;
        }
        if ($marker !== '') {
            $paragraph['marker'] = $marker;
        }
        $this->paragraphs[] = $paragraph;

        return true;
    }

    /**
     * Remove leading and trailing whitespace of the paragraph and drop empty runs.
     *
     * @param array<int,array{text:string,format:int,link:bool}> $runs
     * @return array<int,array{text:string,format:int,link:bool}>
     */
    private function trim_runs(array $runs): array
    {
        $runs = array_values(array_filter($runs, static function (array $run): bool {
            return $run['text'] !== '';
        }));

        $pattern_start = '/^[\s\x{2028}]+/u';
        $pattern_end = '/[\s\x{2028}]+$/u';

        while (!empty($runs)) {
            $runs[0]['text'] = (string) preg_replace($pattern_start, '', $runs[0]['text']);
            if ($runs[0]['text'] !== '') {
                break;
            }
            array_shift($runs);
        }

        while (!empty($runs)) {
            $last = count($runs) - 1;
            $runs[$last]['text'] = (string) preg_replace($pattern_end, '', $runs[$last]['text']);
            if ($runs[$last]['text'] !== '') {
                break;
            }
            array_pop($runs);
        }

        return $runs;
    }

    // ---------------------------------------------------------------------------------------------
    // ICML output
    // ---------------------------------------------------------------------------------------------

    private function paragraph_style_name(string $role): string
    {
        $resolved = $this->resolve_role($role);

        return $resolved !== '' ? $this->style_name($resolved) : self::NO_PARAGRAPH_STYLE;
    }

    /**
     * Local font style for bold/italic without a character style. ICML does not know the
     * template's fonts, so the common style names are used.
     */
    private static function local_font_style(int $format): string
    {
        return [0 => '', self::FORMAT_BOLD => 'Bold', self::FORMAT_ITALIC => 'Italic', self::FORMAT_BOLD | self::FORMAT_ITALIC => 'Bold Italic'][$format & 3];
    }

    private function render(array $paragraphs, string $story_title): string
    {
        $used_paragraph_styles = [self::NO_PARAGRAPH_STYLE => true];
        $used_character_styles = [self::NO_CHARACTER_STYLE => true];
        foreach ($paragraphs as $paragraph) {
            $used_paragraph_styles[$this->paragraph_style_name($paragraph['style'])] = true;
            foreach ($paragraph['runs'] as $run) {
                $name = $this->character_style_for($run);
                if ($name !== '') {
                    $used_character_styles[$name] = true;
                }
            }
        }

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString("\t");
        $xml->startDocument('1.0', 'UTF-8', 'yes');
        $xml->writePi('aid', 'style="50" type="snippet" readerVersion="6.0" featureSet="513" product="8.0(370)"');
        $xml->writePi('aid', 'SnippetType="InCopyInterchange"');

        $xml->startElement('Document');
        $xml->writeAttribute('DOMVersion', '8.0');
        $xml->writeAttribute('Self', 'aiw_document');

        $this->write_style_group($xml, 'CharacterStyle', array_keys($used_character_styles));
        $this->write_style_group($xml, 'ParagraphStyle', array_keys($used_paragraph_styles));

        $xml->startElement('Story');
        $xml->writeAttribute('Self', 'aiw_story');
        $xml->writeAttribute('AppliedTOCStyle', 'n');
        $xml->writeAttribute('TrackChanges', 'false');
        $xml->writeAttribute('StoryTitle', self::clean_text($story_title));
        $xml->writeAttribute('AppliedNamedGrid', 'n');

        $xml->startElement('StoryPreference');
        $xml->writeAttribute('OpticalMarginAlignment', 'false');
        $xml->writeAttribute('OpticalMarginSize', '12');
        $xml->writeAttribute('FrameType', 'TextFrameType');
        $xml->writeAttribute('StoryOrientation', 'Horizontal');
        $xml->writeAttribute('StoryDirection', 'LeftToRightDirection');
        $xml->endElement();

        $last_paragraph = count($paragraphs) - 1;
        foreach ($paragraphs as $paragraph_index => $paragraph) {
            $xml->startElement('ParagraphStyleRange');
            $xml->writeAttribute('AppliedParagraphStyle', self::style_reference('ParagraphStyle', $this->paragraph_style_name($paragraph['style'])));
            if ($paragraph['restart'] && in_array($this->resolve_role($paragraph['style']), self::LIST_ROLES, true)) {
                // A new numbered list starts at 1 instead of continuing the previous list.
                $xml->writeAttribute('NumberingContinue', 'false');
                $xml->writeAttribute('NumberingStartAt', '1');
            }

            $runs = $this->merge_runs($this->output_runs($paragraph, $this->resolve_role($paragraph['style'])));
            $last_run = count($runs) - 1;
            foreach ($runs as $run_index => $run) {
                $character_style = $this->character_style_for($run);
                $xml->startElement('CharacterStyleRange');
                $xml->writeAttribute('AppliedCharacterStyle', self::style_reference('CharacterStyle', $character_style !== '' ? $character_style : self::NO_CHARACTER_STYLE));
                if ($character_style === '' && self::local_font_style($run['format']) !== '') {
                    $xml->writeAttribute('FontStyle', self::local_font_style($run['format']));
                }
                $xml->writeElement('Content', self::clean_text($run['text']));
                if ($run_index === $last_run && $paragraph_index !== $last_paragraph) {
                    $xml->writeElement('Br');
                }
                $xml->endElement();
            }

            $xml->endElement();
        }

        $xml->endElement(); // Story
        $xml->endElement(); // Document
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * Declare the used styles, nested in their style groups. When the story is placed into the
     * template, InDesign keeps the template's definitions of styles with the same name.
     *
     * @param string[] $names
     */
    private function write_style_group(XMLWriter $xml, string $kind, array $names): void
    {
        $tree = [];
        foreach ($names as $name) {
            $path = self::style_path($name);
            $node = &$tree;
            foreach (array_slice($path, 0, -1) as $group) {
                if (!isset($node['groups'][$group])) {
                    $node['groups'][$group] = [];
                }
                $node = &$node['groups'][$group];
            }
            $node['styles'][] = $path;
            unset($node);
        }

        $xml->startElement('Root' . $kind . 'Group');
        $xml->writeAttribute('Self', 'aiw_root_' . strtolower($kind) . '_group');
        $this->write_style_tree($xml, $kind, $tree, []);
        $xml->endElement();
    }

    private function write_style_tree(XMLWriter $xml, string $kind, array $tree, array $group_path): void
    {
        foreach ($tree['styles'] ?? [] as $path) {
            $reference = implode('%3a', $path);
            $xml->startElement($kind);
            $xml->writeAttribute('Self', $kind . '/' . $reference);
            $xml->writeAttribute('Name', $reference);
            $xml->endElement();
        }

        foreach ($tree['groups'] ?? [] as $group => $subtree) {
            $path = array_merge($group_path, [$group]);
            $xml->startElement($kind . 'Group');
            $xml->writeAttribute('Self', $kind . 'Group/' . implode('%3a', $path));
            $xml->writeAttribute('Name', implode('%3a', $path));
            $this->write_style_tree($xml, $kind, $subtree, $path);
            $xml->endElement();
        }
    }
}
