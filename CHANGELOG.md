# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.0] - 2026-05-26

### Added
- Low-level `wp_pre_insert_user_data` filter (priority 1) that blocks any `wp_insert_user()` call from non-admins — catches direct calls from form builders, membership plugins and custom code that bypass standard registration hooks. WP_Error is returned before any database write occurs.
- New log method label: `wp_insert_user (direct)` for attempts caught by this filter.

### Fixed
- Registrations bypassing all standard hooks (empty log) now blocked at the lowest possible level.

## [1.1.0] - 2025-05-26

### Added
- Blocked attempt logging with masked IPs (last IPv4 octet / last two IPv6 groups hidden)
- Admin bar status indicator ("Rejestracja: OFF") visible on every screen
- Dashboard widget with today / 7-day / total stats and 5 recent entries
- CSV log export via `admin_post_rb_export_log` (nonce-protected)
- REST API user enumeration protection — blocks `GET /wp/v2/users` for non-administrators
- Activation hook that writes `users_can_register = 0` to the database
- `uninstall.php` — removes all plugin options on uninstall
- Singleton protection via `__clone()` and `__wakeup()`
- Open redirect protection: redirect URL is validated against the site domain

### Changed
- WooCommerce options now forced via `pre_option_*` filters instead of modifying setting defaults
- Admin page redesigned: card layout, stats row, integration status grid, log table with hover timestamps
- Redirect URL sanitization returns an error and falls back to current value on invalid input

### Fixed
- URL redirect matching now uses exact path segment comparison — prevents false positives on slugs containing "registration" (e.g. `/car-registration`)
- Paid Memberships Pro integration no longer blocks plan changes for already logged-in users

### Security
- AJAX clear-log action secured with `check_ajax_referer()` and `current_user_can('manage_options')`
- CSV export secured with `check_admin_referer()` and capability check

## [1.0.0] - 2025-05-01

### Added
- Initial release
- WordPress Core blocking: `register_post`, `registration_errors`, `login_form_register`, `login_init`
- WooCommerce: `woocommerce_registration_enabled`, `woocommerce_checkout_registration_enabled`, `woocommerce_register_post`
- BuddyPress / BuddyBoss: `bp_core_signup_is_enabled`, `bp_get_signup_allowed`, `bp_screens`
- Ultimate Member, ProfilePress, MemberPress, Paid Memberships Pro, Restrict Content Pro
- bbPress, User Registration (WPEverest)
- REST API: blocks `POST /wp/v2/users` for non-administrators
- XML-RPC: removes `wp.newUser` and `wp.registerNewBlog` methods
- Admin settings page with configurable block message and redirect URL
