=== Registration Blocker ===
Contributors: chlebek
Tags: registration, block registration, security, users, members
Requires at least: 5.9
Tested up to: 6.7
Stable tag: 1.2.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Disable user registration site-wide — WordPress core, WooCommerce, BuddyPress, Ultimate Member, REST API and more.

== Description ==

Registration Blocker disables user registration at every level. Unlike simply turning off the WordPress setting, it blocks registration across all integration points so no plugin can bypass it.

**Blocked integrations:**

* WordPress Core — wp-login.php, register_post hook, users_can_register option
* WooCommerce — My Account and Checkout registration forms
* BuddyPress / BuddyBoss — signup page and form submission
* Ultimate Member
* ProfilePress
* MemberPress
* Paid Memberships Pro — guest registration only (existing users can change plans)
* Restrict Content Pro
* bbPress
* User Registration (WPEverest)
* REST API — POST /wp/v2/users for non-administrators
* REST API — user enumeration via GET /wp/v2/users for non-administrators
* XML-RPC — wp.newUser and wp.registerNewBlog methods

**Security features:**

* Forces users_can_register = 0 in the database on activation
* Validates redirect URL against the site domain (prevents open redirect)
* Masks IP addresses in logs (last octet hidden for IPv4, last two groups for IPv6)
* Blocks user enumeration via REST API for non-administrators
* Removes XML-RPC user creation methods
* Singleton with __clone and __wakeup protection

**Admin features:**

* Stats: today / last 7 days / total blocked attempts
* Per-integration status showing which plugins are active on the site
* Log table: masked IP, integration name, identifier, time ago (with full UTC timestamp on hover)
* Export log to CSV
* Clear log via AJAX (nonce-protected)
* Admin bar indicator: "Rejestracja: OFF"
* Dashboard widget with recent blocked attempts

== Installation ==

1. Upload the `registration-blocker` folder to `/wp-content/plugins/`
2. Activate the plugin through **Plugins → Installed Plugins**
3. Configure via **Settings → Blokada rejestracji**

No configuration is required — the plugin blocks all registration immediately on activation.

== Frequently Asked Questions ==

= Does this prevent admins from creating users? =

No. Users with the `create_users` capability can still create accounts via WP Admin or the REST API.

= Does it break WooCommerce guest checkout? =

No. Guest checkout remains available; only account creation is disabled.

= Where is the log stored? =

In the WordPress database (wp_options), holding up to 100 entries. No data is sent externally.

= Is the redirect URL validated? =

Yes. Only URLs on the same domain as the WordPress installation are accepted, preventing open redirect attacks.

= What happens when the plugin is deactivated? =

The plugin does not restore users_can_register automatically — the administrator decides whether to re-enable registration manually.

= What happens on uninstall? =

All plugin options (message, redirect URL, blocked attempts log) are removed from the database.

== Screenshots ==

1. Admin page — stats, integration status grid, blocked attempts log
2. Admin bar indicator

== Changelog ==

= 1.2.0 =
* Added `wp_pre_insert_user_data` filter that intercepts direct `wp_insert_user()` calls from non-admins — blocks form builders, membership plugins and custom code bypassing standard registration hooks.
* Fixed: registrations bypassing all standard hooks (empty log) now blocked at the lowest possible WordPress level.

= 1.1.0 =
* Added blocked attempt logging with masked IPs
* Added admin bar status indicator ("Rejestracja: OFF")
* Added dashboard widget with stats and recent attempts
* Added CSV log export
* Added REST API user enumeration protection
* Fixed URL redirect matching — exact path segment comparison (no false positives on slugs like /car-registration)
* Fixed Paid Memberships Pro integration — no longer blocks plan changes for existing logged-in users
* Added open redirect protection for the redirect URL setting
* Added singleton protection (__clone, __wakeup)
* Added activation hook to write users_can_register = 0 to the database
* Added uninstall.php for clean data removal
* WooCommerce options forced via pre_option_* filters (no database writes)

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.2.0 =
Adds low-level blocking of direct wp_insert_user() calls. Recommended for all sites where registrations were bypassing the plugin.

= 1.1.0 =
Security improvements and admin UI overhaul. Upgrade recommended.
