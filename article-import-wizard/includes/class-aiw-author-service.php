<?php

if (!defined('ABSPATH')) {
    exit;
}

class AIW_Author_Service
{
    public function find_by_membership_number(string $membership_number): ?WP_User
    {
        $membership_number = trim($membership_number);

        if ($membership_number === '') {
            return null;
        }

        $user = get_user_by('login', $membership_number);

        return ($user instanceof WP_User) ? $user : null;
    }

    public function find_by_identifier(string $identifier): ?WP_User
    {
        $matches = $this->find_matches_by_identifier($identifier);
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @return WP_User[]
     */
    public function find_matches_by_identifier(string $identifier): array
    {
        $identifier = $this->normalize_name($identifier);
        if ($identifier === '') {
            return [];
        }

        if (AIW_Settings::is_membership_number($identifier)) {
            $user = $this->find_by_membership_number($identifier);
            return $user instanceof WP_User ? [$user] : [];
        }

        $users = $this->find_candidates($identifier);
        if (empty($users)) {
            return [];
        }

        $exact = [];
        $partial = [];

        foreach ($users as $user) {
            if (!$user instanceof WP_User) {
                continue;
            }

            $display_name = $this->normalize_name((string) $user->display_name);
            $first_name = $this->normalize_name((string) $user->first_name);
            $last_name = $this->normalize_name((string) $user->last_name);
            $full_name = $this->normalize_name(trim($first_name . ' ' . $last_name));
            $reverse_name = $this->normalize_name(trim($last_name . ' ' . $first_name));
            $login = $this->normalize_name((string) $user->user_login);

            if (in_array($identifier, array_filter([$display_name, $full_name, $reverse_name, $login]), true)) {
                $exact[] = $user;
                continue;
            }

            if (
                ($display_name !== '' && strpos($display_name, $identifier) !== false)
                || ($full_name !== '' && strpos($full_name, $identifier) !== false)
                || ($first_name !== '' && strpos($first_name, $identifier) !== false)
                || ($last_name !== '' && strpos($last_name, $identifier) !== false)
            ) {
                $partial[] = $user;
            }
        }

        $matches = !empty($exact) ? $exact : $partial;
        $unique = [];
        foreach ($matches as $match) {
            $unique[(int) $match->ID] = $match;
        }

        return array_values($unique);
    }

    /**
     * Users whose login, display name, first name or last name contains the identifier or one of
     * its words, so "Max Muster" also finds first name "Max" plus last name "Muster". The
     * exact/partial ranking in find_matches_by_identifier() narrows these down.
     *
     * @return WP_User[]
     */
    private function find_candidates(string $identifier): array
    {
        $terms = array_filter(explode(' ', $identifier), static function (string $term): bool {
            return $term !== '';
        });
        $terms[] = $identifier;

        $by_id = [];
        foreach (array_unique($terms) as $term) {
            $queries = [
                [
                    'search' => '*' . $term . '*',
                    'search_columns' => ['user_login', 'display_name', 'user_nicename'],
                ],
                [
                    'meta_query' => [
                        'relation' => 'OR',
                        ['key' => 'first_name', 'value' => $term, 'compare' => 'LIKE'],
                        ['key' => 'last_name', 'value' => $term, 'compare' => 'LIKE'],
                    ],
                ],
            ];

            foreach ($queries as $query) {
                foreach (get_users($query + ['fields' => 'all']) as $user) {
                    if ($user instanceof WP_User) {
                        $by_id[(int) $user->ID] = $user;
                    }
                }
            }
        }

        return array_values($by_id);
    }

    /**
     * The author used when no author is entered or none matches: the configured default
     * publisher, otherwise the logged-in user. Null when neither is available.
     */
    public function get_fallback_author(): ?WP_User
    {
        $publisher_id = AIW_Settings::get_default_publisher_id();
        if ($publisher_id > 0) {
            $publisher = get_userdata($publisher_id);
            if ($publisher instanceof WP_User) {
                return $publisher;
            }
        }

        $current_user = wp_get_current_user();
        return ($current_user instanceof WP_User && $current_user->ID > 0) ? $current_user : null;
    }

    private function normalize_name(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        return function_exists('mb_strtolower')
            ? mb_strtolower($name, 'UTF-8')
            : strtolower($name);
    }
}
