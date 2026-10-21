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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use moodle_exception;
use question_engine;
use stdClass;

/**
 * External function authory_tech_user_comments: user Comments.
 *
 * @package    tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class user_comments extends external_api {
     /**
      * Returns the parameters definition for authory_tech_user_comments_func
      *
      * @return external_function_parameters Parameters definition for storing user comments
      */
    public static function execute_parameters() {
        return new external_function_parameters(
            [
                'modulename' => new external_value(PARAM_TEXT, 'modulename', VALUE_DEFAULT, ''),
                'cmid' => new external_value(PARAM_INT, 'cmid', VALUE_DEFAULT, 0),
                'resourceid' => new external_value(PARAM_INT, 'resourceid', VALUE_DEFAULT, 0),
                'courseid' => new external_value(PARAM_INT, 'courseid', VALUE_DEFAULT, 0),
                'usercomment' => new external_value(PARAM_TEXT, 'usercomment', VALUE_DEFAULT, null),
                'timemodified' => new external_value(PARAM_INT, 'timemodified', VALUE_DEFAULT, 0),
                'editorid' => new external_value(PARAM_TEXT, 'editorid', VALUE_DEFAULT, ''),
            ],
        );
    }

    /**
     * Store user comments for authory_tech writing
     *
     * @param string $modulename The name of the module
     * @param int $cmid Course module ID
     * @param int $resourceid Resource ID
     * @param int $courseid Ignored, kept for compatibility: the course is taken from the course module
     * @param string $usercomment The user's comment text
     * @param int $timemodified Time when comment was modified
     * @param string $editorid Editor instance ID
     * @return bool True if comment saved successfully, false otherwise
     * @throws \coding_exception If parameters are invalid
     * @throws moodle_exception If user lacks required capabilities
     */
    public static function execute(
        $modulename,
        $cmid,
        $resourceid,
        $courseid,
        $usercomment,
        $timemodified,
        $editorid,
    ) {
        global $DB, $USER, $CFG;

        require_once($CFG->dirroot . '/question/engine/lib.php');

        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'modulename' => $modulename,
                'cmid' => $cmid,
                'resourceid' => $resourceid,
                'courseid' => $courseid,
                'usercomment' => $usercomment,
                'timemodified' => $timemodified,
                'editorid' => $editorid,
            ],
        );
        require_once($CFG->libdir . '/accesslib.php');
        // Capability check. The course is taken from the course module, not from the request.
        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability("tiny/authory_tech:write", $context);

        $userid = $USER->id;
        $editoridarr = explode(':', $params['editorid']);
        if (count($editoridarr) > 1) {
            $uniqueid = substr($editoridarr[0] . "\n", 1);
            $slot = substr($editoridarr[1] . "\n", 0, -11);
            $quba = question_engine::load_questions_usage_by_activity($uniqueid);
            $question = $quba->get_question($slot, false);
            $questionid = $question->id;
        }
        $dataobject = new stdClass();
        $dataobject->userid = $userid;
        $dataobject->cmid = $params['cmid'];
        $dataobject->modulename = $params['modulename'];
        $dataobject->resourceid = $params['resourceid'];
        $dataobject->courseid = $cm->course;
        $dataobject->questionid = $questionid ?? 0;
        $dataobject->usercomment = $params['usercomment'];
        $dataobject->timemodified = $params['timemodified'];

        try {
            $DB->insert_record('tiny_authory_tech_comments', $dataobject);
            return true;
        } catch (moodle_exception $e) {
            debugging($e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Returns the expected return value for the user comments function
     *
     * @return external_value The return value definition for user comments response
     */
    public static function execute_returns() {
        return new external_value(PARAM_BOOL, 'All User Comments');
    }
}
