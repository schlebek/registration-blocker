# Contributing

Bug reports and feature requests are welcome. Pull requests are accepted for bug fixes and new plugin integrations.

## Bug reports

Use the [bug report template](https://github.com/schlebek/registration-blocker/issues/new?template=1-bug-report.yml).
Include your WordPress version, PHP version, plugin version, and steps to reproduce.

## Feature requests

Use the [feature request template](https://github.com/schlebek/registration-blocker/issues/new?template=2-feature-request.yml).

## Pull requests

1. Fork the repository and create a branch from `master`.
2. Make your changes. Keep commits focused — one logical change per commit.
3. Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
4. Update `CHANGELOG.md` under `[Unreleased]` if the change is user-facing.
5. Open a pull request using the provided template.

## Commit messages

Follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/):

```
feat: add support for WP-Members plugin
fix: prevent false-positive redirect on /car-registration
security: block user enumeration via REST API
```

## Security vulnerabilities

See [SECURITY.md](SECURITY.md) — do not open public issues for security reports.
