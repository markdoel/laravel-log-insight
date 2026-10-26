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
