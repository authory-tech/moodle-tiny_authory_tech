<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tiny_authory_tech;

use question_engine;

/**
 * Class constants
 *
 * @package    tiny_authory_tech
 * @copyright  2025 Cursive Technology, Inc. <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class constants {
    /**
     * Array of supported activity module names.
     * const array NAMES List of module names where authory_tech can be used
     */
    public const NAMES = ["assign", "forum", "quiz", "lesson", 'pdfannotator']; // Excluded oublog.
    /**
     * Array mapping module names to their corresponding rubric areas.
     * Used to identify the correct rubric area for different module types.
     * const array RUBRIC_AREA Mapping of module names to rubric areas
     */
    public const RUBRIC_AREA = ['assign' => 'submissions', 'forum' => 'forum', 'quiz' => 'quiz', 'lesson' =>
                            'lesson'];

    /**
     * Array mapping page body IDs to their corresponding handler functions and module types.
     * Each entry consists of:
     * - Key: The page body ID string
     * - Value: Array containing [handler function name, module type]
     * const array BODY_IDS Mapping of page IDs to handlers
     */
    public const BODY_IDS = [
            'page-mod-forum-discuss'        => ['append_fourm_post', 'forum'],
            'page-mod-forum-view'           => ['append_fourm_post', 'forum'],
            'page-mod-assign-view'          => ['inject_assign_dashboard_button', 'assign'],
            'page-mod-assign-grader'        => ['show_url_in_submission_grade', 'assign'],
            'page-mod-assign-grading'       => ['append_submissions_table', 'assign'],
            'page-mod-quiz-review'          => ['show_url_in_quiz_detail', 'quiz'],
            'page-course-view-participants' => ['append_participants_table', 'course'],
            'page-mod-lesson-essay'         => ['append_lesson_grade_table', 'lesson'],
            'page-mod-pdfannotator-view'    => ['append_pdfannotator', 'pdfannotator'],
        ];


    /**
     * Returns the current plan name persisted by tiny_authory_tech_check_subscriptions().
     * Falls back to 'trial' when not yet set.
     */
    public static function get_plan(): string {
        return get_config('tiny_authory_tech', 'plan') ?: 'trial';
    }

    /**
     * Returns true when the trial has expired (trial_start + 60 days < now).
     * Always returns false for non-trial plans.
     */
    public static function is_trial_expired(): bool {
        if (self::get_plan() !== 'trial') {
            return false;
        }
        $trialstart = (int) get_config('tiny_authory_tech', 'trial_start');
        if (!$trialstart) {
            return false;
        }
        return time() > $trialstart + 60 * DAY_SECS;
    }

    /**
     * Returns the number of trial days remaining (0 when expired or not on trial).
     */
    public static function get_trial_days_remaining(): int {
        if (self::get_plan() !== 'trial') {
            return 0;
        }
        $trialstart = (int) get_config('tiny_authory_tech', 'trial_start');
        if (!$trialstart) {
            return 60;
        }
        $remaining = ($trialstart + 60 * DAY_SECS - time()) / DAY_SECS;
        return max(0, (int) $remaining);
    }

    /**
     * Returns true if $courseid is within the plan's course limit.
     *
     * Trial: maximum 2 distinct courses may have keystroke data.
     * Other plans: no limit.
     *
     * @param int $courseid
     */
    public static function within_course_limit(int $courseid): bool {
        global $DB;
        if (self::get_plan() !== 'trial' || $courseid <= 0) {
            return true;
        }
        $limit = (int)(get_config('tiny_authory_tech', 'course_limit') ?: 2);

        // If this course already has data it is one of the tracked courses — allow.
        if ($DB->record_exists('tiny_authory_tech_files', ['courseid' => $courseid])) {
            return true;
        }
        $existing = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT courseid) FROM {tiny_authory_tech_files} WHERE courseid > 0"
        );
        return $existing < $limit;
    }

    /**
     * Returns true if the current user is within the plan's per-course student limit.
     *
     * Trial:  max 35 distinct students per course.
     * Centro: max 300 distinct students across all courses.
     * Institution: no limit.
     *
     * @param int $courseid
     */
    public static function within_student_limit(int $courseid): bool {
        global $DB, $USER;
        $plan = self::get_plan();
        if ($plan === 'institution' || $courseid <= 0) {
            return true;
        }

        // Existing students are always allowed.
        if ($DB->record_exists('tiny_authory_tech_files', ['userid' => $USER->id, 'courseid' => $courseid])) {
            return true;
        }

        $storedlimit = get_config('tiny_authory_tech', 'student_limit');
        $limit = $storedlimit !== false ? (int)$storedlimit : ($plan === 'trial' ? 35 : 300);

        if ($plan === 'trial') {
            $count = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid) FROM {tiny_authory_tech_files} WHERE courseid = :cid",
                ['cid' => $courseid]
            );
        } else {
            // Centro: total across all courses.
            $count = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT userid) FROM {tiny_authory_tech_files}"
            );
        }
        return $count < $limit;
    }

    /**
     * Get the paste setting for a specific course and course module
     *
     * @param int|null $courseid The course ID, defaults to current course if null
     * @param int|null $cmid The course module ID, defaults to current module if null
     * @return string The paste setting value - either 'allow' or the configured setting
     */
    public static function get_paste_setting($courseid = null, $cmid = null) {
        global $COURSE;

        if ($courseid === null) {
            $courseid = $COURSE->id;
        }

        if ($cmid === null) {
            $cmid = tiny_authory_tech_get_cmid($courseid) ?? 0;
        }

        $settings = self::get_cm_settings($cmid);

        return ($settings && !empty($settings->pastesetting)) ? $settings->pastesetting : 'allow';
    }

    /**
     * Get the stored Authory.tech settings row for a course module, if any.
     *
     * @param int $cmid The course module ID
     * @return \stdClass|false The settings record, or false if none exists
     */
    public static function get_cm_settings($cmid) {
        global $DB;
        return $DB->get_record('tiny_authory_tech_cm_settings', ['cmid' => $cmid]);
    }

    /**
     * Check whether Authory.tech is enabled for a specific course module.
     * Defaults to enabled when no row exists, matching the plugin's historical default.
     *
     * @param int $cmid The course module ID
     * @return bool True if enabled
     */
    public static function is_cm_enabled($cmid) {
        $settings = self::get_cm_settings($cmid);
        return $settings ? (bool) $settings->status : true;
    }

    /**
     * Persist Authory.tech settings for a specific course module.
     *
     * @param int $courseid The course ID
     * @param int $cmid The course module ID
     * @param int $status 1 to enable, 0 to disable
     * @param string|null $pastesetting Paste behaviour to store, or null to leave it unchanged
     * @return void
     */
    public static function set_cm_settings($courseid, $cmid, $status, $pastesetting = null) {
        global $DB;

        $existing = self::get_cm_settings($cmid);
        if ($existing) {
            $existing->status = $status;
            if ($pastesetting !== null) {
                $existing->pastesetting = $pastesetting;
            }
            $existing->timemodified = time();
            $DB->update_record('tiny_authory_tech_cm_settings', $existing);
            return;
        }

        $DB->insert_record('tiny_authory_tech_cm_settings', (object) [
            'cmid' => $cmid,
            'courseid' => $courseid,
            'status' => $status,
            'pastesetting' => $pastesetting ?? 'allow',
            'timemodified' => time(),
        ]);
    }

    /**
     * Get a cmid-keyed map of Authory.tech enabled-status for every course module in a course.
     * Used by reporting/filter UIs that previously bulk-read CUR* config keys.
     *
     * @param int $courseid The course ID
     * @return array cmid => bool enabled
     */
    public static function get_cm_settings_map($courseid) {
        global $DB;

        $rows = $DB->get_records('tiny_authory_tech_cm_settings', ['courseid' => $courseid], '', 'cmid, status');
        $map = [];
        foreach ($rows as $row) {
            $map[$row->cmid] = (bool) $row->status;
        }
        return $map;
    }
    /**
     * Flag indicating whether to enable replay functionality.
     * Controlled via plugin configuration setting.
     * @return bool Whether to enable replay functionality
     */
    public static function enable_replay() {
        $config = get_config('tiny_authory_tech', 'enable_replay');
        // If not set, default to true for backward compatibility.
        if ($config === false) {
            return true;
        }
        // Otherwise return the configured value (1 for true, 0 for false).
        return (bool)$config;
    }

    /**
     * Flag indicating whether to show the "Authory.tech is active" popup once per session.
     * Controlled via plugin configuration setting.
     * @return bool Whether to show the active-state popup
     */
    public static function enable_state_popup() {
        $config = get_config('tiny_authory_tech', 'enable_state_popup');
        // If not set, default to true.
        if ($config === false) {
            return true;
        }
        // Otherwise return the configured value (1 for true, 0 for false).
        return (bool)$config;
    }


    /**
     * Check if the authory_tech functionality is active for the current page/context
     *
     * @return bool True if authory_tech is active, false otherwise
     */
    public static function is_active() {
        global $PAGE;
        $instance = $PAGE->cm->id ?? 0;
        $courseid = $PAGE->cm->course ?? $PAGE->course->id;
        $state    = self::is_cm_enabled($instance);

        // Condition changed for course participants list.
        if ($PAGE->bodyid === array_keys(self::BODY_IDS)[5] && get_config('tiny_authory_tech', "authory_tech-$courseid")) {
            $state = true;
        }

        return $state ? true : false;
    }

    /**
     * Check if a valid API key exists for authory_tech functionality
     *
     * @return bool True if valid API key exists, false otherwise
     */
    public static function has_api_key() {
        global $CFG;
        require_once($CFG->dirroot . '/lib/editor/tiny/plugins/authory_tech/lib.php');

        $secret       = get_config('tiny_authory_tech', 'secretkey');
        $apikey       = get_config('tiny_authory_tech', 'apiKey');
        $syncinterval = get_config('tiny_authory_tech', 'ApiSyncInterval') ?: 0;
        $now          = time();
        $nextsync     = strtotime('+5 minutes');

        if (empty($secret)) {
            if ($apikey !== false || $apikey !== "0") {
                set_config('apiKey', false, 'tiny_authory_tech');
            }
            if ($syncinterval < $now) {
                set_config('ApiSyncInterval', $nextsync, 'tiny_authory_tech');
            }
            return false;
        }

        if ($syncinterval <= $now) {
            $response = tiny_authory_tech_approve_token();
            $data      = json_decode($response);
            $newkey = (!empty($data->status) && $data->status) ? $data->status : false;

            if ($newkey != boolval($apikey)) {
                set_config('apiKey', $newkey, 'tiny_authory_tech');
            }
            set_config('ApiSyncInterval', $nextsync, 'tiny_authory_tech');
            $apikey = $newkey;
        }

        return boolval($apikey);
    }


    /**
     * Determines if a submission is resubmittable based on upload and analytics data
     *
     * @param array|object $data Data containing uploaded, effort_ratio and total_time_seconds fields
     * @param int $fileid ID of the file record to check upload status
     * @return bool True if resubmittable, false otherwise
     */
    public static function is_resubmitable($data, $fileid) {
        global $DB;

        if (!self::has_api_key()) {
            return false;
        }

        $data = (object) $data;

        $upload = $DB->get_record('tiny_authory_tech_files', ['id' => $fileid], 'uploaded', IGNORE_MISSING);
        $upload = $upload ? intval($upload->uploaded) : 0;

        $effort = intval($data->effort_ratio ?? 9999999); // Default to high value if not set, it is possible to get effort 0.
        $analytics = intval($data->total_time_seconds ?? 0);

        return ($upload > 0 && ($effort === 9999999 || $analytics === 0));
    }

    /**
     * Check if the current user is a teacher or admin in the given context
     *
     * @param \context $context The context to check roles in
     * @return bool True if user is teacher/admin, false otherwise
     */
    public static function is_teacher_admin($context) {

        global $USER;

        if (is_siteadmin($USER)) {
                return true;
        }
        // Get roles for user in given context.
        if (has_capability('tiny/authory_tech:view', $context, $USER->id, false)) {
            return true;
        }

        return false;
    }

    /**
     * Saves an auto-save record for authory_tech content
     *
     * @param array $params Parameters containing:
     *                      - cmid: Course module ID
     *                      - resourceid: Resource identifier
     *                      - courseid: Course ID
     *                      - original_content: Content to save
     *                      - questionid: Optional question ID
     * @return int|bool The new record ID or false on failure
     */
    public static function authory_tech_auto_save($params) {
        global $DB, $USER;
        if (self::no_difference($params) || empty(self::normalize_string($params['originalText']))) {
            return false;
        }
        try {
            $autosave = new \stdClass();
            $autosave->userid = $USER->id;
            $autosave->cmid = $params['cmid'];
            $autosave->modulename = $params['modulename'] . "_autosave";
            $autosave->resourceid = $params['resourceId'];
            $autosave->courseid = $params['courseId'];
            $autosave->usercomment = trim($params['originalText']);
            $autosave->questionid = $params['questionid'];
            $autosave->timemodified = time();
            return $DB->insert_record('tiny_authory_tech_comments', $autosave);
        } catch (\dml_exception $e) {
            return false;
        }
    }

    /**
     * Checks if there is a difference between the current content and previously saved content
     *
     * @param array $params Parameters containing:
     *                      - originalText: Current content to compare
     *                      - cmid: Course module ID
     *                      - resourceId: Resource identifier
     *                      - modulename: Module name
     *                      - questionid: Optional question ID
     * @return bool True if content has changed, false if same
     */
    public static function no_difference($params) {
        global $DB, $USER;
        $record = null;
        if ($params['questionid']) {
            $record = $DB->get_records('tiny_authory_tech_comments', [
                'cmid' => $params['cmid'],
                'modulename' => $params['modulename'] . "_autosave",
                'resourceid' => $params['resourceId'],
                'userid' => $USER->id,
                'questionid' => $params['questionid'],
                'courseid' => $params['courseId'],
            ], 'timemodified DESC', 'usercomment', 0, 1);
            $record = reset($record);
        } else {
            $record = $DB->get_records('tiny_authory_tech_comments', [
                'cmid' => $params['cmid'],
                'modulename' => $params['modulename'] . "_autosave",
                'resourceid' => $params['resourceId'],
                'userid' => $USER->id,
                'courseid' => $params['courseId'],
            ], 'timemodified DESC', 'usercomment', 0, 1);
            $record = reset($record);
        }

        if ($record) {
            $a = self::normalize_string($record->usercomment);
            $b = self::normalize_string($params['originalText']);
            return strcasecmp($a, $b) === 0;
        }

        return false;
    }

    /**
     * Normalizes a string by converting HTML entities, removing non-breaking spaces,
     * and standardizing whitespace
     *
     * @param string $str The string to normalize
     * @return string The normalized string
     */
    public static function normalize_string($str) {
        $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $str = preg_replace('/[\xC2\xA0]/', ' ', $str); // Replace NBSP with normal space.
        $str = preg_replace('/\s+/', ' ', $str); // Normalize multiple spaces.
        return trim($str);
    }

    /**
     * Get rubrics associated with a course module and course
     *
     * @param string $component The course module ID
     * @param \stdClass $context The course ID
     * @param string $area The area to retrieve rubrics for
     * @return array Array of rubric records containing id and name
     */
    public static function get_rubrics($component, $context, $area): array {
        global $CFG;
        if (!isset(self::RUBRIC_AREA[$area])) {
            return [];
        }

        require_once($CFG->dirroot . '/grade/grading/lib.php');
        require_once($CFG->dirroot . '/grade/grading/form/rubric/lib.php');
        $gradingmanager = get_grading_manager($context, $component, self::RUBRIC_AREA[$area]);
        $controller = $gradingmanager->get_active_controller();

        if (!$controller) {
            return [];
        }
        $definition = $controller->get_definition();

        return array_values($definition->rubric_criteria ?? []);
    }

    /**
     * Extracts the question ID from an editor ID string
     *
     * @param string $editorid The editor ID containing question information
     * @return int|null The question ID if found, null otherwise
     */
    public static function get_question_id($editorid) {
        $editoridarr = explode(':', $editorid);
        if (count($editoridarr) > 1) {
            $uniqueid = substr($editoridarr[0] . "\n", 1);
            $slot = substr($editoridarr[1] . "\n", 0, -11);
            $quba = question_engine::load_questions_usage_by_activity($uniqueid);
            $question = $quba->get_question($slot, false);
            return $question->id;
        }
        return 0;
    }
}
