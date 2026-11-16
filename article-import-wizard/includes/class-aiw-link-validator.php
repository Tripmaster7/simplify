<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Link_Validator
{
    /**
     * Upper bound for the whole link check so a preview never runs into PHP's max_execution_time.
     */
    private const TOTAL_BUDGET_SECONDS = 20;

    /**
     * Characters that commonly follow a URL in running text but are not part of it.
     */
    private const TRAILING_PUNCTUATION = '.,;:!?\'"*';

    /**
     * @return array{valid: string[], invalid: array<int, array{url: string, reason: string}>, skipped: string[]}
     */
    public function validate_links(array $links, int $timeout_ms = 6000): array
    {
        $timeout = max(1, (int) ceil($timeout_ms / 1000));
        $deadline = microtime(true) + self::TOTAL_BUDGET_SECONDS;

        $valid = [];
        $invalid = [];
        $skipped = [];

        foreach ($links as $link) {
            $url = trim((string) $link);
            if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
                continue;
            }

            if (microtime(true) + $timeout > $deadline) {
                $skipped[] = $url;
                continue;
            }

            // wp_safe_remote_* rejects private, loopback and link-local hosts, so document
            // content cannot be used to probe the server's internal network.
            $args = ['timeout' => $timeout, 'redirection' => 3];
            $response = wp_safe_remote_head($url, $args);

            if (is_wp_error($response)) {
                // Connection-level failure (DNS, refused, timeout, blocked host). A GET would
                // fail the same way, so do not spend a second timeout on it.
                $invalid[] = ['url' => $url, 'reason' => $response->get_error_message()];
                continue;
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);

            // Many servers reject HEAD (405, 403, 404, 501, ...) while serving GET fine.
            if ($status_code >= 400 && microtime(true) + $timeout <= $deadline) {
                $get_response = wp_safe_remote_get($url, $args + ['limit_response_size' => 65536]);
                if (!is_wp_error($get_response)) {
                    $status_code = (int) wp_remote_retrieve_response_code($get_response);
                }
            }

            if ($status_code >= 200 && $status_code < 400) {
                $valid[] = $url;
            } else {
                $invalid[] = ['url' => $url, 'reason' => 'HTTP ' . $status_code];
            }
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
            'skipped' => $skipped,
        ];
    }

    /**
     * Turn raw matches from HTML content into the URLs a reader would actually click:
     * decode HTML entities (&amp; -> &) and drop trailing sentence punctuation and
     * unbalanced closing brackets.
     *
     * @param string[] $links
     * @return string[]
     */
    public static function normalize_links(array $links): array
    {
        $normalized = [];
        foreach ($links as $link) {
            $url = self::normalize_link((string) $link);
            if ($url !== '') {
                $normalized[] = $url;
            }
        }

        return array_values(array_unique($normalized));
    }

    public static function normalize_link(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        while ($url !== '') {
            $last = substr($url, -1);

            if (strpos(self::TRAILING_PUNCTUATION, $last) !== false) {
                $url = substr($url, 0, -1);
                continue;
            }

            $pairs = [')' => '(', ']' => '[', '}' => '{'];
            if (isset($pairs[$last]) && substr_count($url, $last) > substr_count($url, $pairs[$last])) {
                $url = substr($url, 0, -1);
                continue;
            }

            break;
        }

        return $url;
    }
}
