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

/**
 * Tiny authory_tech plugin observer class.
 *
 * @package tiny_authory_tech
 * @copyright  CTI <info@cursivetechnology.com>
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @author eLearningstack
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observers {
    /**
     * Tiny authory_tech plugin update comment logic, run from the process_forum_event ad-hoc task.
     *
     * @param array $eventdata Raw event data from \core\event\base::get_data()
     * @return void
     * @throws \dml_exception
     */
    public static function update_comment($eventdata) {
        global $DB;

        $table      = 'tiny_authory_tech_comments';
        $modulename = self::get_modules_name($eventdata);
        $conditions = [
            "userid"     => $eventdata['userid'],
            "modulename" => $modulename,
            'resourceid' => 0,
            'courseid'   => $eventdata['courseid'],
            'cmid'       => $eventdata['contextinstanceid'],
        ];

        $recs = $DB->get_records($table, $conditions);
        if ($recs) {
            foreach ($recs as $rec) {
                $dataobj             = new \stdClass();
                $dataobj->userid     = $eventdata['userid'];
                $dataobj->id         = $rec->id;
                $dataobj->cmid       = $eventdata['contextinstanceid'];
                $dataobj->courseid   = $eventdata['courseid'];
                $dataobj->resourceid = $eventdata['objectid'];

                $DB->update_record($table, $dataobj, true);
            }
        }
        // Update autosave content as well.
        $conditions['modulename'] = $modulename . "_autosave";
        self::update_autosaved_content($conditions, $table, $eventdata, $eventdata['objectid']);
    }

    /**
     * Tiny authory_tech plugin update authory_tech files logic, run from the process_forum_event
     * ad-hoc task.
     *
     * @param array $eventdata Raw event data from \core\event\base::get_data()
     * @return void
     * @throws \dml_exception
     */
    public static function update_authory_tech_files($eventdata) {

        global $DB;

        if ($eventdata['target'] === "discussion") {
            $discussid = $eventdata['objectid'];
            $postdata  = $DB->get_record('forum_posts', ['discussion' => $discussid]);
            if ($postdata) {
                $eventdata['objectid'] = $postdata->id;
            }
        }

        $table      = 'tiny_authory_tech_files';
        $modulename = self::get_modules_name($eventdata);
        $conditions = [
            "userid"     => $eventdata['userid'],
            "modulename" => $modulename,
            'resourceid' => 0,
            'courseid'   => $eventdata['courseid'],
            'cmid'       => $eventdata['contextinstanceid'],
        ];
        $recs = $DB->get_records($table, $conditions);
        if ($recs) {
            foreach ($recs as $rec) {
                $userid              = $eventdata['userid'];
                $cmid                = $eventdata['contextinstanceid'];
                $resourceid          = $eventdata['objectid'];
                $fname               = $userid . '_' . $resourceid . '_' . $cmid . '_attempt' . '.json';

                $dataobj             = new \stdClass();
                $dataobj->userid     = $userid;
                $dataobj->id         = $rec->id;
                $dataobj->cmid       = $cmid;
                $dataobj->courseid   = $eventdata['courseid'];
                $dataobj->resourceid = $resourceid;
                $dataobj->filename   = $fname;

                $DB->update_record($table, $dataobj, true);
            }
        }
    }

    /**
     * Tiny authory_tech plugin login observer.
     *
     * Queues an ad-hoc task instead of doing the DB work inline, so posting doesn't wait on it.
     *
     * @param \mod_forum\event\post_created $event
     * @return void
     */
    public static function observer_login(\mod_forum\event\post_created $event) {
        self::queue_forum_event('post', $event->get_data());
    }

    /**
     * Tiny authory_tech plugin post updated observer.
     *
     * Queues an ad-hoc task instead of doing the DB work inline, so posting doesn't wait on it.
     *
     * @param \mod_forum\event\post_updated $event
     * @return void
     */
    public static function post_updated(\mod_forum\event\post_updated $event) {
        self::queue_forum_event('post', $event->get_data());
    }

    /**
     * Tiny authory_tech plugin discussion created observer.
     *
     * Queues an ad-hoc task instead of doing the DB work inline, so posting doesn't wait on it.
     *
     * @param \mod_forum\event\discussion_created $event
     * @return void
     */
    public static function discussion_created(\mod_forum\event\discussion_created $event) {
        self::queue_forum_event('discussion', $event->get_data());
    }

    /**
     * Queue an ad-hoc task to process a forum event asynchronously. Event observers should stay
     * lightweight, so all the actual DB work happens in \tiny_authory_tech\task\process_forum_event.
     *
     * @param string $type Either 'post' or 'discussion'
     * @param array $eventdata Raw event data from \core\event\base::get_data()
     * @return void
     */
    private static function queue_forum_event($type, array $eventdata) {
        $task = new \tiny_authory_tech\task\process_forum_event();
        $task->set_custom_data([
            'type' => $type,
            'eventdata' => $eventdata,
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * Process a forum post-created/post-updated event: update comment and file tracking records.
     * Run from the process_forum_event ad-hoc task, not called directly from the event observer.
     *
     * @param array $eventdata Raw event data from \core\event\base::get_data()
     * @return void
     * @throws \dml_exception
     */
    public static function process_post_event(array $eventdata) {
        self::update_comment($eventdata);
        self::update_authory_tech_files($eventdata);
    }

    /**
     * Process a discussion-created event: update comment and file tracking records using the
     * discussion's first post as the resource id. Run from the process_forum_event ad-hoc task,
     * not called directly from the event observer.
     *
     * @param array $eventdata Raw event data from \core\event\base::get_data()
     * @return void
     * @throws \dml_exception
     */
    public static function process_discussion_event(array $eventdata) {
        global $DB;

        $discussionsrec = $DB->get_record('forum_discussions', ['id' => $eventdata['objectid']]);
        if (!$discussionsrec) {
            return;
        }

        $table      = 'tiny_authory_tech_comments';
        $conditions = [
            "userid"     => $eventdata['userid'],
            "modulename" => 'forum',
            'resourceid' => 0,
            'courseid'   => $eventdata['courseid'],
            'cmid'       => $eventdata['contextinstanceid'],
        ];
        $recs = $DB->get_records($table, $conditions);

        if ($recs) {
            foreach ($recs as $rec) {
                $dataobj             = new \stdClass();
                $dataobj->userid     = $eventdata['userid'];
                $dataobj->id         = $rec->id;
                $dataobj->cmid       = $eventdata['contextinstanceid'];
                $dataobj->courseid   = $eventdata['courseid'];
                $dataobj->resourceid = $discussionsrec->firstpost;

                $DB->update_record($table, $dataobj, true);
            }
        }
        $conditions['modulename'] = 'forum_autosave';

        self::update_autosaved_content($conditions, $table, $eventdata, $discussionsrec->firstpost);
        self::update_authory_tech_files($eventdata);
    }

    /**
     * Reset tracking data.
     *
     * @param \core\event\course_reset_ended $event
     * @return void
     * @throws \dml_exception
     */
    public static function reset_tracking_data(\core\event\course_reset_ended $event) {
        global $DB;

        // Get the course ID from the event data.
        $data     = (object) $event->get_data();
        $courseid = $data->courseid;

        // Retrieve all file records related to the course.
        $fileids  = $DB->get_records('tiny_authory_tech_files', ['courseid' => $courseid], '', 'id, filename');

        // Delete records from 'tiny_authory_tech_files' and 'tiny_authory_tech_comments' tables.
        $DB->delete_records('tiny_authory_tech_files', ['courseid' => $courseid]);
        $DB->delete_records('tiny_authory_tech_comments', ['courseid' => $courseid]);

        // Delete associated user writing records and files.
        foreach ($fileids as $file) {
            $DB->delete_records('tiny_authory_tech_user_writing', ['file_id' => $file->id]);
            $DB->delete_records('tiny_authory_tech_writing_diff', ['file_id' => $file->id]);
        }
    }

    /**
     * Best-effort restore of per-course-module Authory.tech settings after a course restore.
     *
     * There is no documented generic backup/restore hook for a "tiny" editor subplugin, so this
     * reads the backup id-mapping table directly while it's still populated for this restore.
     * backup_ids_temp is a database-native temporary table, scoped to the DB connection that ran
     * the restore, and is dropped once that restore finishes cleaning up — so it may legitimately
     * not exist by the time this observer runs (e.g. a different connection, or cleanup already
     * ran). When that happens, restored course modules simply keep the plugin default (enabled,
     * paste allowed) instead of erroring.
     *
     * \core\event\course_restored does not carry the restore id, so mappings are matched purely on
     * newitemid against this course's real (freshly restored) course module ids — those ids are
     * only ever produced by the restore that just ran, so no explicit backupid filter is needed.
     *
     * @param \core\event\course_restored $event
     * @return void
     * @throws \dml_exception
     */
    public static function restore_cm_settings(\core\event\course_restored $event) {
        global $DB;

        if (!$DB->get_manager()->table_exists('backup_ids_temp')) {
            return;
        }

        $eventdata = $event->get_data();
        $courseid  = $eventdata['courseid'];

        $newcmids = $DB->get_fieldset_select('course_modules', 'id', 'course = :courseid', ['courseid' => $courseid]);
        if (!$newcmids) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($newcmids, SQL_PARAMS_NAMED);
        $mappings = $DB->get_records_select(
            'backup_ids_temp',
            "itemname = 'course_module' AND newitemid $insql",
            $inparams,
            '',
            'newitemid, itemid'
        );

        foreach ($mappings as $mapping) {
            if ($DB->record_exists('tiny_authory_tech_cm_settings', ['cmid' => $mapping->newitemid])) {
                continue;
            }

            $source = $DB->get_record('tiny_authory_tech_cm_settings', ['cmid' => $mapping->itemid]);
            if (!$source) {
                continue;
            }

            $DB->insert_record('tiny_authory_tech_cm_settings', (object) [
                'cmid' => $mapping->newitemid,
                'courseid' => $courseid,
                'status' => $source->status,
                'pastesetting' => $source->pastesetting,
                'timemodified' => time(),
            ]);
        }
    }

    /**
     * Get the module name from event data.
     *
     * @param array $eventdata The event data containing component information
     * @return string The module name extracted from the component
     */
    public static function get_modules_name($eventdata) {
        // Use array destructuring to get module name directly from component.
        [, $modulename] = explode('_', $eventdata['component'], 2);
        return $modulename;
    }

    /**
     * Update autosaved content records.
     *
     * @param array $conditions The conditions to find records to update
     * @param string $table The database table name
     * @param array $eventdata The event data containing user, course and context info
     * @param int $postid The post ID to update the records with
     * @return void
     * @throws \dml_exception
     */
    public static function update_autosaved_content($conditions, $table, $eventdata, $postid) {
        global $DB;
        $recs = $DB->get_records($table, $conditions);
        if ($recs) {
            foreach ($recs as $rec) {
                $dataobj             = new \stdClass();
                $dataobj->userid     = $eventdata['userid'];
                $dataobj->id         = $rec->id;
                $dataobj->cmid       = $eventdata['contextinstanceid'];
                $dataobj->courseid   = $eventdata['courseid'];
                $dataobj->resourceid = $postid;

                $DB->update_record($table, $dataobj, true);
            }
        }
    }
}
