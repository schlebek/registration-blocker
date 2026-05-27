# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.3.0] - 2026-05-27

### Added
- Nextend Social Login integration: `nextend_social_login_before_register_user` action (priority 1)
  blocks new account creation via social OAuth (Google, Facebook, X) before any database write.
  NSL listed in the integrations status grid on the settings page.
- ListingPro (CridioStudio) integration: intercepts AJAX registration actions
  (`lp_register`, `lp_register_user`, `lp_vendor_register`) before `wp_insert_user()` is called.
  Blocked attempts now appear in the log as "ListingPro" instead of "wp_insert_user (direct)".
- CubeWP Forms integration: intercepts AJAX form submissions and `cwp_before_form_process` filter
  for registration forms built in the CubeWP form builder.
- Application Passwords blocking (WP 5.6+): disabled for all non-administrator users via
  `wp_is_application_passwords_available_for_user` filter — covers both the profile screen and
  `POST /wp/v2/users/{id}/application-passwords` REST endpoint.
- REST API: blocks `POST /wp/v2/users/{id}/application-passwords` for non-admins (defense in depth
  alongside the Application Passwords filter above).
- Log deduplication: identical masked IP addresses are not logged more than once per 60 seconds,
  preventing a single bot from flooding the 100-entry log.
- `detect_registration_source()` helper: resolves the AJAX action name to a human-readable plugin
  label, improving log context for all direct `wp_insert_user()` catches.

### Fixed
- Social login registration bypass — Nextend Social Login was creating users via `wp_insert_user()`
  while bypassing standard WordPress registration hooks (`registration_errors`, `register_post`).
- PHP 7.4 compatibility: removed `mixed` return/parameter type hints from `rest_guard()` —
  `mixed` is only available as a native type hint from PHP 8.0.

## [1.2.0] - 2026-05-26

### Added
- `wp_pre_insert_user_data` filter (priority 1) that intercepts any direct `wp_insert_user()` call
  from non-admins — catches form builders, membership plugins and custom code that bypass standard
  registration hooks. A `WP_Error` is returned before any database write occurs.
- New log label: `wp_insert_user (direct)` for attempts caught by this filter.

### Fixed
- Registrations bypassing all standard hooks (resulting in an empty log) are now blocked at the
  lowest possible WordPress level.

## [1.1.0] - 2025-05-26

### Added
- Blocked attempt logging with masked IPs (last IPv4 octet / last two IPv6 groups hidden).
- Admin bar status indicator ("Rejestracja: OFF") visible on every screen.
- Dashboard widget with today / 7-day / total stats and 5 recent entries.
- CSV log export via `admin_post_rb_export_log` (nonce-protected).
- REST API user enumeration protection — blocks `GET /wp/v2/users` for non-administrators.
- Activation hook that writes `users_can_register = 0` to the database.
- `uninstall.php` — removes all plugin options on uninstall.
- Singleton protection via `__clone()` and `__wakeup()`.
- Open redirect protection: redirect URL is validated against the site domain.

### Changed
- WooCommerce options now forced via `pre_option_*` filters instead of modifying setting defaults.
- Admin page redesigned: card layout, stats row, integration status grid, log table with hover timestamps.
- Redirect URL sanitization returns an error and falls back to current value on invalid input.

### Fixed
- URL redirect matching now uses exact path segment comparison — prevents false positives on slugs
  containing "registration" (e.g. `/car-registration`).
- Paid Memberships Pro integration no longer blocks plan changes for already logged-in users.

### Security
- AJAX clear-log action secured with `check_ajax_referer()` and `current_user_can('manage_options')`.
- CSV export secured with `check_admin_referer()` and capability check.

## [1.0.0] - 2025-05-01

### Added
- Initial release.
- WordPress Core blocking: `register_post`, `registration_errors`, `login_form_register`, `login_init`.
- WooCommerce: `woocommerce_registration_enabled`, `woocommerce_checkout_registration_enabled`, `woocommerce_register_post`.
- BuddyPress / BuddyBoss: `bp_core_signup_is_enabled`, `bp_get_signup_allowed`, `bp_screens`.
- Ultimate Member, ProfilePress, MemberPress, Paid Memberships Pro, Restrict Content Pro.
- bbPress, User Registration (WPEverest).
- REST API: blocks `POST /wp/v2/users` for non-administrators.
- XML-RPC: removes `wp.newUser` and `wp.registerNewBlog` methods.
- Admin settings page with configurable block message and redirect URL.

[Unreleased]: https://github.com/schlebek/registration-blocker/compare/v1.3.0...HEAD
[1.3.0]: https://github.com/schlebek/registration-blocker/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/schlebek/registration-blocker/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/schlebek/registration-blocker/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/schlebek/registration-blocker/releases/tag/v1.0.0
