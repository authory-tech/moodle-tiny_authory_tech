# Changelog

All notable changes to the `tiny_authory_tech` Moodle plugin are documented here.

## [0.3.1] - 2026-10-22

Covers the unreleased 0.3.0 and the 0.2.x development releases as well.

### Security
- A student could read another user's keystrokes, submission text, writing statistics, comments and replay annotations by passing that user's file id or file name to the report web services, `download_json.php` or the PDF export. Every read now takes the course module and the owner from the stored record: users can read their own data, and anyone else needs `tiny/authory_tech:view` in that module.
- Comment lookups for forum and PDF annotator posts are limited to the course module the capability was checked in.
- `authory_tech_resubmit_payload_data` checks that the file belongs to the given module and to the caller (or that the caller is a teacher there). `authory_tech_update_pdf_annote_id` and `authory_tech_user_comments` use the current user and the module's course instead of request values. `authory_tech_write_local_to_json` always requires a course module, so its context, capability and plan-limit checks can't be skipped.
- Submission HTML shown in the writing-differences view goes through Moodle's HTML cleaning.

### Added
- "Authory.tech is active" notice, shown once per session. Students must acknowledge it; it names the course and records the acknowledgement as an event. Administrators can turn it off.
- Support for Moodle 5.0, 5.1 and 5.2, tested in CI alongside 4.5.

### Changed
- The privacy provider declares, exports and deletes all four tables with personal data (keystroke files, comments, writing statistics and writing differences) on every path.
- Course reset deletes a course's writing data in a fixed number of queries instead of two queries per file.
- Web service functions moved from `externallib.php` to one class per function in `classes/external/`. Function names, parameters and return values are unchanged.
- The assignment dashboard's student panel is rendered from a Mustache template instead of HTML strings built in JavaScript.
- Minimum Moodle version raised to 4.5 (`requires = 2024100700`) to match the supported range.
- Dashboard durations ignore long idle gaps, and typing speed shows "unavailable" instead of 0 when the type-server can't provide stats.
- Only English strings ship with the plugin. Other translations will be contributed through AMOS.

### Removed
- The "Analytics PDF" download in the writing reports, together with the bundled html2pdf.js library (an old build whose bundled libraries had known security advisories). The JSON download is unchanged.
- `authory_tech_filtered_writing` web service (unused, and its query could not run on any database), the non-quiz branch of `authory_tech_get_comment_link`, and the unused pluginfile callbacks.

### Fixed
- Dead links in the "Learn more" dialog.
- Creating an activity without the course module form (web services, course restore) in a course with Authory.tech enabled raised an "Undefined property: authory_tech_status" warning.
- `authory_tech_get_user_list` and `authory_tech_get_module_list` failed on any course with more than one activity; they now check the capability in the course context, and no longer load `mod/quiz/attemptlib.php` (a debugging notice on Moodle 4.2+).
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
