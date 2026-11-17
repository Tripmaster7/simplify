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

        if (preg_match('/^0\d{4}$/', $identifier) === 1) {
            $user = $this->find_by_membership_number($identifier);
            return $user instanceof WP_User ? [$user] : [];
        }

        $users = get_users([
            'number' => 500,
            'fields' => 'all',
        ]);
        if (!is_array($users) || empty($users)) {
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

    private function normalize_name(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        return function_exists('mb_strtolower')
            ? mb_strtolower($name, 'UTF-8')
            : strtolower($name);
    }
}
