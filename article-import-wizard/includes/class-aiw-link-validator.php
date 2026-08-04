<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Link_Validator
{
    public function validate_links(array $links, int $timeout_ms = 6000): array
    {
        $timeout = max(1, (int) ceil($timeout_ms / 1000));

        $valid = [];
        $invalid = [];

        foreach ($links as $link) {
            $url = trim((string) $link);
            if ($url === '') {
                continue;
            }

            if (!preg_match('/^https?:\/\//i', $url)) {
                continue;
            }

            $response = wp_remote_head($url, ['timeout' => $timeout, 'redirection' => 3]);
            if (is_wp_error($response)) {
                $response = wp_remote_get($url, ['timeout' => $timeout, 'redirection' => 3]);
            }

            if (is_wp_error($response)) {
                $invalid[] = ['url' => $url, 'reason' => $response->get_error_message()];
                continue;
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            if ($status_code >= 200 && $status_code < 400) {
                $valid[] = $url;
            } else {
                $invalid[] = ['url' => $url, 'reason' => 'HTTP ' . $status_code];
            }
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
        ];
    }
}
