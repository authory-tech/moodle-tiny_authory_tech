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
namespace tiny_authory_tech\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as meta_provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use stdClass;

/**
 * Privacy Subsystem implementation for tiny_authory_tech.
 *
 * All personal data lives in four tables, keyed by course module: the keystroke files
 * (tiny_authory_tech_files) and the comments and autosaves (tiny_authory_tech_comments) hold a
 * userid, and the writing statistics (tiny_authory_tech_user_writing) and writing differences
 * (tiny_authory_tech_writing_diff) belong to the user of the file they point at. Export and the
 * three deletion paths all cover the four tables.
 *
 * @copyright  Cursive Technology, Inc. <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @author     Brain Station 23 <sales@brainstation-23.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, meta_provider, plugin_provider {
    /**
     * Returns information about how tiny_authory_tech stores its data.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('tiny_authory_tech_files', [
            'userid' => 'privacy:metadata:database:tiny_authory_tech:userid',
            'content' => 'privacy:metadata:database:tiny_authory_tech:content',
            'original_content' => 'privacy:metadata:database:tiny_authory_tech:original_content',
            'timemodified' => 'privacy:metadata:database:tiny_authory_tech:timemodified',
        ], 'privacy:metadata:database:tiny_authory_tech');

        $collection->add_database_table('tiny_authory_tech_comments', [
            'userid' => 'privacy:metadata:database:tiny_authory_tech_comments:userid',
            'usercomment' => 'privacy:metadata:database:tiny_authory_tech_comments:commenttext',
            'timemodified' => 'privacy:metadata:database:tiny_authory_tech_comments:timemodified',
        ], 'privacy:metadata:database:tiny_authory_tech_comments');

        $collection->add_database_table('tiny_authory_tech_user_writing', [
            'file_id' => 'privacy:metadata:database:tiny_authory_tech_user_writing:file_id',
            'total_time_seconds' => 'privacy:metadata:database:tiny_authory_tech_user_writing:total_time_seconds',
            'key_count' => 'privacy:metadata:database:tiny_authory_tech_user_writing:key_count',
            'keys_per_minute' => 'privacy:metadata:database:tiny_authory_tech_user_writing:keys_per_minute',
            'character_count' => 'privacy:metadata:database:tiny_authory_tech_user_writing:character_count',
            'characters_per_minute' => 'privacy:metadata:database:tiny_authory_tech_user_writing:characters_per_minute',
            'word_count' => 'privacy:metadata:database:tiny_authory_tech_user_writing:word_count',
            'words_per_minute' => 'privacy:metadata:database:tiny_authory_tech_user_writing:words_per_minute',
            'backspace_percent' => 'privacy:metadata:database:tiny_authory_tech_user_writing:backspace_percent',
            'score' => 'privacy:metadata:database:tiny_authory_tech_user_writing:score',
            'copy_behavior' => 'privacy:metadata:database:tiny_authory_tech_user_writing:copy_behavior',
        ], 'privacy:metadata:database:tiny_authory_tech_user_writing');

        $collection->add_database_table('tiny_authory_tech_writing_diff', [
            'file_id' => 'privacy:metadata:database:tiny_authory_tech_writing_diff:file_id',
            'reconstructed_text' => 'privacy:metadata:database:tiny_authory_tech_writing_diff:reconstructed_text',
            'submitted_text' => 'privacy:metadata:database:tiny_authory_tech_writing_diff:submitted_text',
            'meta' => 'privacy:metadata:database:tiny_authory_tech_writing_diff:meta',
        ], 'privacy:metadata:database:tiny_authory_tech_writing_diff');

        $collection->add_external_location_link('api.authory.tech', [
            'userid' => 'privacy:metadata:database:tiny_authory_tech:userid',
            'courseid' => 'privacy:metadata:api_authory_tech:courseid',
            'cmid' => 'privacy:metadata:api_authory_tech:cmid',
            'content' => 'privacy:metadata:database:tiny_authory_tech:content',
            'original_content' => 'privacy:metadata:database:tiny_authory_tech:original_content',
        ], 'privacy:metadata:api_authory_tech');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist $contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $params = ['contextlevel' => CONTEXT_MODULE, 'userid' => $userid];

        // The statistics and differences tables only hold rows for files, so files and comments cover every context.
        foreach (['tiny_authory_tech_files', 'tiny_authory_tech_comments'] as $table) {
            $sql = "SELECT c.id
                      FROM {context} c
                      JOIN {{$table}} t ON t.cmid = c.instanceid
                     WHERE c.contextlevel = :contextlevel AND t.userid = :userid";
            $contextlist->add_from_sql($sql, $params);
        }

        return $contextlist;
    }

    /**
     * Get the list of users within a specific context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }

        $params = ['cmid' => $context->instanceid];
        $userlist->add_from_sql('userid', "SELECT userid FROM {tiny_authory_tech_files} WHERE cmid = :cmid", $params);
        $userlist->add_from_sql('userid', "SELECT userid FROM {tiny_authory_tech_comments} WHERE cmid = :cmid", $params);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * Each writing session (keystroke file) is exported with its submission text, statistics and
     * writing differences, and its keystroke log as a JSON file. Comments and autosaves are
     * exported per context.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;
        $pluginname = get_string('pluginname', 'tiny_authory_tech');

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $writer = writer::with_context($context);
            $conditions = ['cmid' => $context->instanceid, 'userid' => $userid];

            $files = $DB->get_records('tiny_authory_tech_files', $conditions, 'id');
            foreach ($files as $file) {
                $subcontext = [$pluginname, get_string('privacy:path:session', 'tiny_authory_tech', $file->id)];
                $writer->export_data($subcontext, self::export_session($file));
                if (!empty($file->content)) {
                    $writer->export_custom_file($subcontext, 'keystrokes.json', $file->content);
                }
            }

            $comments = $DB->get_records('tiny_authory_tech_comments', $conditions, 'id');
            if ($comments) {
                $data = (object) ['comments' => array_values(array_map(function ($comment) {
                    return (object) [
                        'modulename' => $comment->modulename,
                        'resourceid' => $comment->resourceid,
                        'questionid' => $comment->questionid,
                        'comment' => $comment->usercomment,
                        'timemodified' => transform::datetime($comment->timemodified),
                    ];
                }, $comments))];
                $writer->export_data([$pluginname, get_string('privacy:path:comments', 'tiny_authory_tech')], $data);
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        if (!$context instanceof context_module) {
            return;
        }
        self::delete_data((int) $context->instanceid);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if (!$context instanceof context_module) {
            return;
        }
        self::delete_data((int) $context->instanceid, $userlist->get_userids());
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_module) {
                self::delete_data((int) $context->instanceid, [$userid]);
            }
        }
    }

    /**
     * Delete the data of a course module from all four tables, for all users or only the given ones.
     *
     * The statistics and differences are deleted first, while the file rows they point at still exist.
     *
     * @param int $cmid The course module ID.
     * @param int[]|null $userids The users to delete data for, or null for all users.
     */
    protected static function delete_data(int $cmid, ?array $userids = null): void {
        global $DB;

        $select = 'cmid = :cmid';
        $params = ['cmid' => $cmid];
        if ($userids !== null) {
            if (empty($userids)) {
                return;
            }
            [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $select .= " AND userid {$usersql}";
            $params += $userparams;
        }

        $fileselect = "file_id IN (SELECT id FROM {tiny_authory_tech_files} WHERE {$select})";
        $DB->delete_records_select('tiny_authory_tech_user_writing', $fileselect, $params);
        $DB->delete_records_select('tiny_authory_tech_writing_diff', $fileselect, $params);
        $DB->delete_records_select('tiny_authory_tech_comments', $select, $params);
        $DB->delete_records_select('tiny_authory_tech_files', $select, $params);
    }

    /**
     * Build the export data for one writing session (keystroke file).
     *
     * @param stdClass $file The tiny_authory_tech_files record.
     * @return stdClass
     */
    protected static function export_session(stdClass $file): stdClass {
        global $DB;

        $data = (object) [
            'modulename' => $file->modulename,
            'resourceid' => $file->resourceid,
            'questionid' => $file->questionid,
            'filename' => $file->filename,
            'submission' => $file->original_content,
            'uploaded' => transform::yesno($file->uploaded),
            'timemodified' => transform::datetime($file->timemodified),
        ];

        $stats = $DB->get_record('tiny_authory_tech_user_writing', ['file_id' => $file->id], '*', IGNORE_MULTIPLE);
        if ($stats) {
            $data->statistics = (object) [
                'total_time_seconds' => $stats->total_time_seconds,
                'key_count' => $stats->key_count,
                'keys_per_minute' => $stats->keys_per_minute,
                'character_count' => $stats->character_count,
                'characters_per_minute' => $stats->characters_per_minute,
                'word_count' => $stats->word_count,
                'words_per_minute' => $stats->words_per_minute,
                'backspace_percent' => $stats->backspace_percent,
                'score' => $stats->score,
                'copy_behavior' => $stats->copy_behavior,
            ];
        }

        $diff = $DB->get_record('tiny_authory_tech_writing_diff', ['file_id' => $file->id], '*', IGNORE_MULTIPLE);
        if ($diff) {
            $data->differences = (object) [
                'reconstructed_text' => self::decode_text($diff->reconstructed_text),
                'submitted_text' => self::decode_text($diff->submitted_text),
                'meta' => $diff->meta,
            ];
        }

        return $data;
    }

    /**
     * Decode a text the type-server stored as base64-encoded JSON, so the export is readable.
     *
     * @param string|null $value The stored value.
     * @return string|null The decoded text, or the value unchanged if it is not in that format.
     */
    protected static function decode_text(?string $value): ?string {
        $decoded = base64_decode((string) $value, true);
        $text = ($decoded === false) ? null : json_decode($decoded);
        return is_string($text) ? $text : $value;
    }
}
