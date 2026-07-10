# Changelog

All notable changes to the `tiny_authory_tech` Moodle plugin are documented here.

## [0.1.9] - 2026-07-11

### Fixed
- `generate_webtoken` web service now validates context and requires `tiny/authory_tech:editsettings` before regenerating the site-wide sync token (was reachable by any teacher).
- Renamed two non-frankenstyled global functions (`authory_tech_test_connection`, `authory_tech_approve_token`) to the `tiny_authory_tech_` prefix.
- Per-course-module enable/disable and paste settings now live in a dedicated `tiny_authory_tech_cm_settings` table instead of dynamically-named plugin config keys, and are best-effort carried over on course restore.
- `classes/sqs.php` now sends SQS requests through Moodle's `\curl` wrapper instead of calling `curl_init()` directly.
- Forum comment/file tracking updates now run in an ad-hoc task (`tiny_authory_tech\task\process_forum_event`) instead of synchronously inside the `mod_forum` event observers.
- Replaced remaining hard-coded UI strings (assignment dashboard breadcrumb, document view sidebar titles) with `get_string()` calls.
- Added missing `@license` markers across AMD source files.

## [0.1.8] - 2026-05-08

### Added
- Initial public release as **Authory.tech** (`tiny_authory_tech`), adapted from [moodle-tiny_cursive](https://github.com/cursiveinc/moodle-tiny_cursive) by Cursive Technology, Inc.
- TinyMCE keystroke and writing-process capture for assignments, quizzes, forums, lessons, and OU Blog.
- Teacher writing reports and student self-reports with session replay.
- Assignment dashboard with per-student submission overviews, metric cards, and class averages.
- Integration with the Authory.tech ML server for authorship verification and writing analytics.
- Scheduled cron task for background upload of keystroke JSON to the ML server.
- GDPR-compliant privacy provider.
- Multilingual support: English (`en`), Spanish (`es`), Catalan (`ca`), Valencian Catalan (`ca_valencia`).
- Site health check (Moodle Check API) for web services prerequisite validation.
