# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Configurable fingerprint frame (`LOG_INSIGHT_FINGERPRINT_FRAME`, config `fingerprint.frame`). `method` identifies the topmost application frame as `path.php::Class::method`, so deploys that only shift lines keep their clusters. The default `line` keeps the existing fingerprints unchanged. Switching modes starts new clusters.
- The AI source excerpt for method frames uses the line from the cluster's most recent stack trace.
- Opt-in notifications for severe clusters (`LOG_INSIGHT_NOTIFICATIONS_ENABLED`, config `notifications`). Every analysis at or above `min_severity` (default `critical`) sends one queued notification with the error, location, occurrence count, severity, confidence, root cause, suggested fix and a `log-insight:report` hint. The content passes the redactor first.
- Mail notifications via Laravel's `mail` channel (`LOG_INSIGHT_NOTIFICATIONS_MAIL_TO`).
- Slack notifications via the optional `laravel/slack-notification-channel` package, to an incoming webhook (`LOG_INSIGHT_SLACK_WEBHOOK_URL`) or a channel (`LOG_INSIGHT_SLACK_CHANNEL`). Without the package a warning is logged and mail is still sent.
- `illuminate/notifications` is now a dependency. It ships with every Laravel application.

## [1.0.0] - 2026-10-26

First release.

### Added

#### Log parsing
- Parser for Laravel's default Monolog line format, for `single` and `daily` log files. It streams files and merges multi-line messages, stack traces and previous exceptions into one entry.
- `log-insight:analyze` command with `--file`, `--since` (`30m`, `24h`, `7d`, `2w` or a date) and `--dry-run`.

#### Clustering and storage
- Error fingerprints made of the exception class, the normalized message and the topmost application frame. Plain log messages use level and message instead.
- Message normalization for numbers, UUIDs, ULIDs, e-mail and IP addresses, timestamps, URLs, hashes, tokens, variable path segments and `QueryException` SQL bindings.
- Application-relative paths for logs written on other servers, in Docker containers or in release directories.
- Clusters with occurrence count, first and last occurrence and the most recent stack trace. Re-analyzing the same log never counts entries twice.
- Publishable migrations and Eloquent models for clusters, occurrences and analyses.
- Configurable minimum level for clustering (`LOG_INSIGHT_MIN_LEVEL`, default `error`).

#### Privacy
- Redaction layer that runs before any data leaves the server. It removes:
  - private keys and JWTs
  - authorization headers and bearer tokens
  - common API key formats
  - credentials in URLs
  - secret key-value pairs
  - e-mail and IP addresses
  - string arguments in stack frames (optional)
- Custom redaction patterns via config, validated when the redactor is created.

#### AI analysis
- `AnalyzeClusterJob` queue job per cluster without an analysis. Analyzed clusters are never sent again, and permanent failures are recorded on the cluster.
- Context for the model: error summary, representative stack trace and the source code around the affected line. File access is safe against path traversal.
- Anthropic Messages API client built on Laravel's HTTP client (API version `2026-10-21`). It uses structured JSON output, configurable model, effort, token limit and timeout, retries with back-off that honours `retry-after`, and optional server-side fallbacks for declined requests.
- Validation of the answer (`root_cause`, `suggested_fix`, `affected_files`, `severity`, `confidence`) with one correction request on invalid or truncated JSON.
- `LlmClient` interface for further providers.

#### Events and reports
- `ClusterDetected` and `AnalysisCompleted` events.
- `log-insight:report` command: the top clusters as a table, or the full report as JSON (`--json`). Filters: `--limit`, `--since` and `--severity`.
- Helpful error message when the package migrations have not been run.

#### Quality
- PHPUnit test suite with Orchestra Testbench. AI requests are always faked, and stray HTTP requests fail the test.
- Larastan at level `max` and Laravel Pint.
- GitHub Actions for PHP 8.2–8.5 × Laravel 12/13, MySQL, Postgres, PHPStan and Pint.

[Unreleased]: https://github.com/markdoel/laravel-log-insight/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/markdoel/laravel-log-insight/releases/tag/v1.0.0
