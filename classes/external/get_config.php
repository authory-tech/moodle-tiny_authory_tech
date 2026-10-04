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

namespace tiny_authory_tech\external;

use assign;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_quiz\quiz_settings;
use stdClass;
use tiny_authory_tech\constants;

/**
 * External function authory_tech_get_config: get user configuration.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_config extends external_api {
    /**
     * Returns the parameters for the authory_tech_get_config function
     *
     * @return external_function_parameters Parameters definition for the external function
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'course id', VALUE_DEFAULT, 0),
            'cmid' => new external_value(PARAM_INT, 'cmid', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Get authory_tech configuration settings for a course and course module
     *
     * @param int $courseid The course ID to get config for
     * @param int $cmid The course module ID to get config for
     * @return array Array containing config status and sync interval
     */
    public static function execute($courseid, $cmid) {
        global $PAGE, $USER, $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/lib/editor/tiny/plugins/authory_tech/lib.php');
        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'courseid' => $courseid,
                'cmid' => $cmid,
            ],
        );

        $context = context_module::instance($params['cmid']);
        self::validate_context($context);
        require_capability("tiny/authory_tech:writingreport", $context);

        $config       = tiny_authory_tech_status($params['courseid']);
        $syncinterval = get_config('tiny_authory_tech', "syncinterval");
        $cm           = get_coursemodule_from_id('', $params['cmid'], $params['courseid'], false, MUST_EXIST);
        $rubrics      = constants::get_rubrics("mod_{$cm->modname}", $context, $cm->modname);

        $submissiondata = new stdClass();
        if ($cm->modname === 'assign') {
            $assign     = new assign($context, null, null);
            $submission = $assign->get_user_submission($USER->id, false);
            $grade      = $assign->get_user_grade($USER->id, false);

            $submissiondata->current = $submission;
            $submissiondata->grade = $grade;
        }
        $quizdata = new stdClass();
        if ($cm->modname === 'quiz') {
            $quiz = quiz_settings::create_for_cmid($params['cmid'], $USER->id);
            $quiz = $quiz->get_quiz();
            $quizdata->intro = base64_encode($quiz->intro);
            $quizdata->open = $quiz->timeopen;
            $quizdata->close = $quiz->timeclose;
        }
        $pastesetting = constants::get_paste_setting($params['courseid'], $params['cmid']);
        $statepopup   = constants::enable_state_popup();
        $course       = get_course($params['courseid']);
        $coursefullname = format_string($course->fullname, true, ['context' => $context]);

        $plan = constants::get_plan();
        $planinfo = [
            'plan'              => $plan,
            'trial_expired'     => constants::is_trial_expired(),
            'trial_days_remaining' => constants::get_trial_days_remaining(),
            'student_limit'     => get_config('tiny_authory_tech', 'student_limit'),
            'course_limit'      => get_config('tiny_authory_tech', 'course_limit'),
            'teacher_limit'     => get_config('tiny_authory_tech', 'teacher_limit'),
        ];

        $data    = [
            'status'        => $config,
            'sync_interval' => $syncinterval,
            'userid'        => $USER->id,
            'apikey_status' => constants::has_api_key(),
            'mod_state'     => constants::is_active(),
            'plugins'       => json_encode(constants::NAMES),
            'rubrics'       => json_encode($rubrics),
            'submission'    => json_encode($submissiondata),
            'quizinfo'      => json_encode($quizdata),
            'pastesetting'  => $pastesetting,
            'plan_info'     => json_encode($planinfo),
            'state_popup_enabled' => $statepopup,
            'course_fullname' => $coursefullname,
        ];
        return $data;
    }

    /**
     * Returns description of method result value for authory_tech_get_config
     *
     * @return external_single_structure Returns a structure containing config status and sync interval
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'config'),
            'sync_interval' => new external_value(PARAM_INT, 'Data Sync interval'),
            'userid' => new external_value(PARAM_INT, 'userid'),
            'apikey_status' => new external_value(PARAM_BOOL, 'api key status'),
            'mod_state' => new external_value(PARAM_BOOL, "Authory.tech Module wise active/deactive state"),
            'plugins' => new external_value(PARAM_TEXT, "Supported Plugins Names"),
            'rubrics' => new external_value(PARAM_TEXT, "Assignment or forums rubrics"),
            'submission' => new external_value(PARAM_TEXT, "Submission status"),
            'quizinfo' => new external_value(PARAM_TEXT, 'quiz info'),
            'pastesetting'  => new external_value(PARAM_TEXT, 'Paste setting'),
            'plan_info' => new external_value(PARAM_TEXT, 'JSON: plan name, trial days remaining, limit state'),
            'state_popup_enabled' => new external_value(PARAM_BOOL, 'Whether to show the active-state popup once per session'),
            'course_fullname' => new external_value(PARAM_TEXT, 'Full name of the course the module belongs to'),
        ]);
    }
}
