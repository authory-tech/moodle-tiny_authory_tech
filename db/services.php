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

/**
 * Tiny authory_tech plugin.
 *
 * @package tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @author kuldeep singh <mca.kuldeep.sekhon@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'authory_tech_get_user_list' => [
        'classname' => 'tiny_authory_tech\external\get_user_list',
        'description' => 'get quiz list by course',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_get_module_list' => [
        'classname' => 'tiny_authory_tech\external\get_module_list',
        'description' => 'get quiz list by course',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_test_connection' => [
        'classname' => 'tiny_authory_tech\external\test_connection',
        'description' => 'Test connectivity to the ML server',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:editsettings',
    ],
    'authory_tech_approve_token' => [
        'classname' => 'tiny_authory_tech\external\approve_token',
        'description' => 'generate Reports for download',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:editsettings',
    ],
    'authory_tech_user_comments' => [
        'classname' => 'tiny_authory_tech\external\user_comments',
        'description' => 'User Comments',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:write',
    ],
    'authory_tech_get_comment_link' => [
        'classname' => 'tiny_authory_tech\external\get_comment_link',
        'description' => ' Comments Links',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_get_assign_comment_link' => [
        'classname' => 'tiny_authory_tech\external\get_assign_comment_link',
        'description' => ' Comments Links',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_get_forum_comment_link' => [
        'classname' => 'tiny_authory_tech\external\get_forum_comment_link',
        'description' => 'get forum comments links',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_get_assign_grade_comment' => [
        'classname' => 'tiny_authory_tech\external\get_assign_grade_comment',
        'description' => 'get assign grade comments',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_user_list_submission_stats' => [
        'classname' => 'tiny_authory_tech\external\user_list_submission_stats',
        'description' => 'get user submissions status',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],

    'authory_tech_get_assign_overview_stats' => [
        'classname' => 'tiny_authory_tech\external\get_assign_overview_stats',
        'description' => 'Proxy GET /assignments/:cmid/stats to the type server',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],

    'authory_tech_get_assign_dashboard_students' => [
        'classname' => 'tiny_authory_tech\external\get_assign_dashboard_students',
        'description' => 'Get students with submission data for an assignment dashboard',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],

    'authory_tech_filtered_writing' => [
        'classname' => 'tiny_authory_tech\external\filtered_writing',
        'description' => 'generate Reports for download',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_store_user_writing' => [
        'classname' => 'tiny_authory_tech\external\store_user_writing',
        'description' => 'Storing User Writings',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:write',
    ],
    'authory_tech_get_reply_json' => [
        'classname' => 'tiny_authory_tech\external\get_reply_json',
        'description' => 'return the stored json file',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_get_writing_statistics' => [
        'classname' => 'tiny_authory_tech\external\get_writing_statistics',
        'description' => 'return the stored analytics data',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_store_writing_differences' => [
        'classname' => 'tiny_authory_tech\external\store_writing_differences',
        'description' => 'store writing difference data',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:write',
    ],
    'authory_tech_get_writing_differences' => [
        'classname' => 'tiny_authory_tech\external\get_writing_differences',
        'description' => 'return the stored writing difference data',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_generate_webtoken' => [
        'classname' => 'tiny_authory_tech\external\generate_webtoken',
        'description' => 'Generate a webservice token',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:editsettings',
    ],
    'authory_tech_write_local_to_json' => [
        'classname' => 'tiny_authory_tech\external\write_local_to_json',
        'description' => 'Store User writing as json',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_get_config' => [
        'classname' => 'tiny_authory_tech\external\get_config',
        'description' => 'get user configuration',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:writingreport',
    ], 'authory_tech_get_lesson_submission_data' => [
        'classname' => 'tiny_authory_tech\external\get_lesson_submission_data',
        'description' => 'get lesson data',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ], 'authory_tech_disable_all_course' => [
        'classname' => 'tiny_authory_tech\external\disable_all_course',
        'description' => 'disable authory_tech for all courses',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:editsettings',
    ], 'authory_tech_record_state_popup_accepted' => [
        'classname' => 'tiny_authory_tech\external\record_state_popup_accepted',
        'description' => 'record that the user acknowledged the active-state popup',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:write',
    ], 'authory_tech_get_oublog_submission_data' => [
        'classname' => 'tiny_authory_tech\external\get_oublog_submission_data',
        'description' => 'get oublog data',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ], 'authory_tech_resubmit_payload_data' => [
        'classname' => 'tiny_authory_tech\external\resubmit_payload_data',
        'description' => 'resubmit payload data',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_get_autosave_content' => [
        'classname' => 'tiny_authory_tech\external\get_autosave_content',
        'description' => 'resubmit payload data',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_update_pdf_annote_id' => [
        'classname' => 'tiny_authory_tech\external\update_pdf_annote_id',
        'description' => 'update resourceid for pdf annote analytics file',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:view',
    ],
    'authory_tech_get_student_stats' => [
        'classname' => 'tiny_authory_tech\external\get_student_stats',
        'description' => 'Get student writing stats from the type server',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:writingreport',
    ],
    'authory_tech_get_replay_annotations' => [
        'classname' => 'tiny_authory_tech\external\get_replay_annotations',
        'description' => 'Get pre-computed AI annotations and analytics for a replay session',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'tiny/authory_tech:writingreport',
    ],
];

// We define the services to install as pre-build services.
// A pre-build service is not editable by administrator.

$services = [
    'Authory.tech Json Service' => [
        'functions' => [
            'authory_tech_get_module_list',
            'authory_tech_user_comments',
            'authory_tech_get_comment_link',
            'authory_tech_user_list_submission_stats',
            'authory_tech_approve_token',
            'authory_tech_get_assign_comment_link',
            'authory_tech_get_forum_comment_link',
            'authory_tech_get_assign_grade_comment',
            'authory_tech_store_user_writing',
            'authory_tech_get_reply_json',
            'authory_tech_filtered_writing',
            'authory_tech_get_writing_statistics',
            'authory_tech_store_writing_differences',
            'authory_tech_get_writing_differences',
            'authory_tech_generate_webtoken',
            'authory_tech_write_local_to_json',
            'authory_tech_get_config',
            'authory_tech_get_student_stats',
            'authory_tech_get_assign_overview_stats',
            'authory_tech_get_assign_dashboard_students',
            'authory_tech_get_replay_annotations',
        ],
        'shortname' => 'authory_tech_json_service',
        'downloadfiles' => 1, // Allow file downloads.
        'uploadfiles' => 1, // Allow file uploads.
        'restrictedusers' => 0,
        'enabled' => 0,
        'timecreated' => time(), // Time the service was created.
        'timemodified' => time(), // Time the service was last modified.
    ],
];
