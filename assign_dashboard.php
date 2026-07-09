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
 * Authory.tech teacher dashboard for assignment view.
 *
 * @package tiny_authory_tech
 * @copyright  2026 SEPTUM QA <info@authory.tech>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../../../config.php');

use tiny_authory_tech\constants;

require_login(null, false);

if (isguestuser()) {
    redirect($CFG->wwwroot);
}

$cmid = required_param('id', PARAM_INT);

$cm      = get_coursemodule_from_id(false, $cmid, 0, false, MUST_EXIST);
$course  = get_course($cm->course);
$context = context_module::instance($cmid);

$url = new moodle_url('/lib/editor/tiny/plugins/authory_tech/assign_dashboard.php', ['id' => $cmid]);

$PAGE->set_context(context_system::instance());
$PAGE->set_url($url);
$PAGE->set_title(get_string('pluginname', 'tiny_authory_tech') . ' - Dashboard');
$PAGE->set_heading($course->fullname);

$PAGE->navbar->add($course->shortname, new moodle_url('/course/view.php', ['id' => $course->id]));
$PAGE->navbar->add($cm->name, new moodle_url('/mod/assign/view.php', ['id' => $cmid]));
$PAGE->navbar->add('Authory Writing Dashboard');

if (!has_capability('tiny/authory_tech:view', $context)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('accessdenied', 'admin'), 'error');
    echo $OUTPUT->footer();
    exit;
}

$PAGE->requires->js_call_amd('tiny_authory_tech/assign_dashboard', 'init', [$cmid, (bool) constants::enable_replay()]);
$PAGE->requires->css('/lib/editor/tiny/plugins/authory_tech/styles/assign_dashboard.css');

echo $OUTPUT->header();

$templatecontext = [
    'assignment_name' => $cm->name,
    'course_name'     => $course->fullname,
    'course_url'      => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
    'assign_url'      => (new moodle_url('/mod/assign/view.php', ['id' => $cmid]))->out(false),
    'cmid'            => $cmid,
];

echo $OUTPUT->render_from_template('tiny_authory_tech/assign_dashboard', $templatecontext);

echo $OUTPUT->footer();
