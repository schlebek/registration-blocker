<div align="center">

# 🔒 Registration Blocker

**Block user registration at every level — WordPress core, WooCommerce, BuddyPress, REST API and more.**

[![Version](https://img.shields.io/badge/version-1.1.0-0073aa?style=flat-square)](https://github.com/chlebek/registration-blocker/releases)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green?style=flat-square)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-5.9%2B-3858e9?style=flat-square&logo=wordpress&logoColor=white)](https://wordpress.org/plugins/registration-blocker/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Tested up to](https://img.shields.io/badge/tested%20up%20to-WP%206.7-46b450?style=flat-square)](https://wordpress.org)

</div>

---

## Overview

Most "disable registration" solutions only toggle the native WordPress option — leaving every third-party plugin free to create accounts through its own forms and APIs. **Registration Blocker** closes all of those gaps simultaneously: it hooks into 12+ integration points and blocks new user creation at the filter, form, REST, and XML-RPC levels.

Blocked attempts are logged locally with masked IPs, visible from a clean admin dashboard.

---

## Features

### 🛡️ Multi-layer blocking

| Layer | What is blocked |
|-------|-----------------|
| WordPress Core | `register_post` hook, `users_can_register` option, `/wp-login.php?action=register` |
| URL matching | Redirects `/register`, `/registration`, `/signup`, `/sign-up`, `/rejestracja` slugs |
| WooCommerce | My Account & Checkout registration forms |
| BuddyPress / BuddyBoss | Signup page and form submission |
| Ultimate Member | Registration form and page |
| ProfilePress | Registration form submission |
| MemberPress | `mepr_allow_new_user_registration` filter |
| Paid Memberships Pro | Guest registration (existing users can still change plans) |
| Restrict Content Pro | `rcp_registration_is_open` filter |
| bbPress | User registration capability |
| User Registration (WPEverest) | Form and user action |
| REST API | `POST /wp/v2/users` for non-admins + user enumeration via `GET /wp/v2/users` |
| XML-RPC | `wp.newUser` and `wp.registerNewBlog` methods |

### 🔐 Security

- Forces `users_can_register = 0` to the database on activation
- Validates redirect URL against the site domain — no open redirects
- Masks IPs in logs (last IPv4 octet / last two IPv6 groups hidden)
- Blocks user enumeration via REST API for non-administrators
- Singleton with `__clone` and `__wakeup` protection

### 📊 Admin interface

- **Stats cards** — blocked attempts today / last 7 days / total
- **Integration grid** — live detection of which plugins are active
- **Blocked attempts log** — masked IP, integration, identifier, timestamp
- **CSV export** of the full log
- **Admin bar indicator** — visible on every screen
- **Dashboard widget** — quick summary on WP home screen

---

## Screenshots

| Admin page | Dashboard widget |
|------------|-----------------|
| Stats, integration status, and blocked attempts log | Today / 7-day / total counters with recent entries |

---

## Requirements

| | Minimum |
|-|---------|
| WordPress | 5.9 |
| PHP | 7.4 |
| Database | MySQL 5.6 / MariaDB 10.0 |

---

## Installation

**From WordPress admin**

1. Go to **Plugins → Add New Plugin**
2. Search for **Registration Blocker**
3. Click **Install Now**, then **Activate**

**Manual upload**

1. Download the latest `.zip` from [Releases](https://github.com/chlebek/registration-blocker/releases)
2. Go to **Plugins → Add New Plugin → Upload Plugin**
3. Upload the zip, then **Install Now** and **Activate**

**Via WP-CLI**

```bash
wp plugin install registration-blocker --activate
```

No additional configuration is required. Registration is blocked immediately on activation.

---

## Configuration

Go to **Settings → Blokada rejestracji**.

| Option | Default | Description |
|--------|---------|-------------|
| Block message | `Rejestracja jest wyłączona.` | Shown to users who try to register via forms |
| Redirect URL | Site homepage | Where to redirect users visiting registration pages. Must be on the same domain. |

---

## FAQ

**Does this prevent admins from creating users?**  
No. Users with `create_users` capability can still create accounts via WP Admin or the REST API.

**Does it break WooCommerce guest checkout?**  
No. Guest checkout stays available — only account creation is disabled.

**Where is the log stored?**  
In `wp_options` (key: `rb_blocked_log`), up to 100 entries. No data is sent externally.

**What happens on deactivation?**  
The plugin intentionally does not restore `users_can_register` — you decide when to re-enable registration.

**What happens on uninstall?**  
All options (`rb_message`, `rb_redirect_url`, `rb_blocked_log`) are deleted from the database.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a full history.

---

## Contributing

Bug reports and feature requests are welcome via [GitHub Issues](https://github.com/chlebek/registration-blocker/issues).  
Please use the provided issue templates.

---

## License

[GPL-2.0-or-later](LICENSE) © [Chlebek](https://chlebek.me)
