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

        $paragraphs = $this->extract_paragraphs($document_xml);
        $title = '';
        $subtitle = '';
        $metadata = [];
        $html_parts = [];

        foreach ($paragraphs as $paragraph) {
            $text = trim($paragraph['text']);
            if ($text === '') {
                continue;
            }

            $text = $this->extract_metadata_from_text($text, $metadata);
            $text = trim($text);
            if ($text === '') {
                continue;
            }

            $header_tag = $this->extract_body_header_tag($text);
            if (is_array($header_tag)) {
                $header_level = isset($header_tag['level']) ? (int) $header_tag['level'] : 0;
                $header_text = isset($header_tag['text']) ? trim((string) $header_tag['text']) : '';

                if ($header_text === '') {
                    continue;
                }

                if ($header_level === 1) {
                    if ($title === '') {
                        $title = $header_text;
                        continue;
                    }

                    $text = $header_text;
                } elseif ($header_level === 2) {
                    if ($subtitle === '') {
                        $subtitle = $header_text;
                        continue;
                    }

                    $text = $header_text;
                } elseif ($header_level === 3 || $header_level === 4) {
                    $html_parts[] = '<h' . $header_level . '>' . esc_html($header_text) . '</h' . $header_level . '>';
                    continue;
                } else {
                    $text = $header_text;
                }
            }

            $style = strtolower($paragraph['style']);
            if ($title === '' && ($this->contains($style, 'title') || $this->contains($style, 'heading1'))) {
                $title = $text;
                continue;
            }

            if ($subtitle === '' && ($this->contains($style, 'subtitle') || $this->contains($style, 'heading2'))) {
                $subtitle = $text;
                continue;
            }

            $html_parts[] = '<p>' . esc_html($text) . '</p>';
        }

        if ($title === '' && !empty($paragraphs)) {
            foreach ($paragraphs as $paragraph) {
                $candidate = trim($paragraph['text']);
                if ($candidate !== '') {
                    $title = $candidate;
                    break;
                }
            }
        }

        $writing_date = $this->extract_core_created_date($core_xml);
        $doc_author = $this->extract_core_author($core_xml);
        $content = implode("\n", $html_parts);
        $links = $this->extract_urls_from_text($content);
        $placeholders = $this->extract_image_placeholders($content);

        if (!empty($metadata['title']) && $title === '') {
            $title = (string) $metadata['title'];
        }

        $title = $this->normalize_metadata_text($title);

        if (!empty($metadata['subtitle']) && $subtitle === '') {
            $subtitle = (string) $metadata['subtitle'];
        }

        $subtitle = $this->normalize_metadata_text($subtitle);

        if (!empty($metadata['writing_date'])) {
            $writing_date = (string) $metadata['writing_date'];
        }

        if (!empty($metadata['author_name']) && $doc_author === '') {
            $doc_author = (string) $metadata['author_name'];
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

    private function extract_paragraphs(string $document_xml): array
    {
        $xml = simplexml_load_string($document_xml);
        if ($xml === false) {
            return [];
        }

        $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraph_nodes = $xml->xpath('//w:body/w:p');
        if (!is_array($paragraph_nodes)) {
            return [];
        }

        $paragraphs = [];
        foreach ($paragraph_nodes as $node) {
            $text_nodes = $node->xpath('.//w:t');
            $parts = [];

            if (is_array($text_nodes)) {
                foreach ($text_nodes as $text_node) {
                    $parts[] = (string) $text_node;
                }
            }

            $style = '';
            $style_nodes = $node->xpath('./w:pPr/w:pStyle');
            if (is_array($style_nodes) && isset($style_nodes[0])) {
                $attrs = $style_nodes[0]->attributes('w', true);
                if (isset($attrs['val'])) {
                    $style = (string) $attrs['val'];
                }
            }

            $paragraphs[] = [
                'text' => implode('', $parts),
                'style' => $style,
            ];
        }

        return $paragraphs;
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
            'author_name' => '/\[(AUTHOR_NAME)\s*:\s*([^\]]+)\]/i',
            'writing_date' => '/\[(WRITING_DATE|DATE)\s*:\s*([^\]]+)\]/i',
            'bio' => '/\[(BIO)\s*:\s*([^\]]+)\]/i',
        ];

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
        $text = preg_replace('/^\[(TITLE|HEADLINE|H1|SUBTITLE|SUBHEADER|H2|AUTHOR_MEMBERSHIP|MEMBERSHIP|MEMBER_ID|AUTHOR_NAME|WRITING_DATE|DATE|BIO)\s*:\s*([^"]+?)\]$/i', '$2', trim($text));
        $text = preg_replace('/^\[(TITLE|HEADLINE|H1|SUBTITLE|SUBHEADER|H2|AUTHOR_MEMBERSHIP|MEMBERSHIP|MEMBER_ID|AUTHOR_NAME|WRITING_DATE|DATE|BIO)\s*:\s*([^\]]+?)\]$/i', '$2', (string) $text);

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
