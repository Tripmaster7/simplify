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
}
