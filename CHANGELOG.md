# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Package skeleton with service provider, publishable config and CI pipeline.
- Parser for the Laravel/Monolog line format (single and daily logs), including multi-line messages, stack traces and previous exceptions.
- `log-insight:analyze` command with `--file`, `--since` and `--dry-run` options.
- Error fingerprinting: exception class, normalized message (IDs, UUIDs, e-mail and IP addresses, timestamps, URLs, variable path segments) and topmost application frame.
- Clustering of recurring errors with occurrence count, first and last occurrence; re-analyzing the same log never counts entries twice.
- Publishable migrations and Eloquent models for clusters, occurrences and analyses.
- `ClusterDetected` event for newly seen errors.
- Configurable minimum level for clustering (`LOG_INSIGHT_MIN_LEVEL`, default `error`).
- Redaction layer that removes secrets, tokens, passwords, credentials in URLs, e-mail and IP addresses before data is sent to an AI provider, with optional redaction of string arguments in stack frames and custom patterns via config.
- AI analysis of new clusters through the Anthropic Messages API (Laravel HTTP client, no SDK) with structured JSON output: `root_cause`, `suggested_fix`, `affected_files`, `severity`, `confidence`.
- `AnalyzeClusterJob` queue job; analyzed clusters are never sent again and permanent failures are recorded on the cluster.
- Context for the model: representative stack trace and the source code around the affected line, redacted before sending.
- Validation of the model's answer with one correction request on invalid JSON.
- Retries with back-off for rate limits, overload and connection errors (honours `retry-after`), and opt-in server-side fallbacks for declined requests.
- `LlmClient` interface for additional providers.
- `AnalysisCompleted` event.
- `log-insight:report` command: top clusters with analysis status, severity and root cause as a table, or the full report including suggested fixes with `--json`; filters `--limit`, `--since` and `--severity`.
