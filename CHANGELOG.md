# Changelog

All notable changes to the `tiny_authory_tech` Moodle plugin are documented here.

## [0.3.0] - 2026-10-04

Covers the 0.2.x development releases as well.

### Added
- "Authory.tech is active" notice, shown once per session. Students must acknowledge it; it names the course and records the acknowledgement as an event. Administrators can turn it off.
- Support for Moodle 5.0, 5.1 and 5.2, tested in CI alongside 4.5.

### Changed
- Web service functions moved from `externallib.php` to one class per function in `classes/external/`. Function names, parameters and return values are unchanged.
- The assignment dashboard's student panel is rendered from a Mustache template instead of HTML strings built in JavaScript.
- Minimum Moodle version raised to 4.5 (`requires = 2024100700`) to match the supported range.
- Dashboard durations ignore long idle gaps, and typing speed shows "unavailable" instead of 0 when the type-server can't provide stats.
- Only English strings ship with the plugin. Other translations will be contributed through AMOS.

### Fixed
- `authory_tech_disable_all_course` could be called by any logged-in user. It now validates its parameters and context and requires `tiny/authory_tech:editsettings`.
- On trial sites, keystroke saving failed with "Undefined constant DAY_SECS" once the trial start date was recorded.
- Database-specific SQL (`LIMIT`/`OFFSET`, `CONCAT()`, a bound `ORDER BY`) replaced with portable DML calls.
- Hard-coded English text in settings, templates and JavaScript replaced with language strings.
- Status messages from the remote server on the settings page are shown as text, not HTML.
- `common.js` exported its constants only as a default export, so named imports were undefined and could stop TinyMCE from loading.

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
