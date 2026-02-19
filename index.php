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
 * Project : Technical Project Manager (IEEE like)
 *
 * This page lists all the instances of project in a particular course.
 *
 * @package mod_techproject
 * @copyright 2024 Your Institution
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot.'/mod/techproject/locallib.php');

// Get context information.

$id = required_param('id', PARAM_INT);   // Course id.

if (!$course = $DB->get_record('course', ['id' => $id])) {
    error("Course ID is incorrect");
}

require_login($course->id);

$context = context_course::instance($course->id);

$event = \mod_techproject\event\course_module_instance_list_viewed::create(['context' => $context]);
$event->add_record_snapshot('course', $course);
$event->trigger();

// Get all required strings.

$strprojects = get_string('modulenameplural', 'techproject');
$strproject  = get_string('modulename', 'techproject');

// Print the header.

if ($course->category) {
    $courseurl = new moodle_url('course/view.php', ['id' => $course->id]);
    $navigation = '<a href="'.$courseurl.'">'.$course->shortname.'</a> ->';
}

$PAGE->set_title("$course->shortname: $strprojects");
$PAGE->set_heading("$course->fullname");
$PAGE->set_focuscontrol("");
$PAGE->set_cacheable(true);
$PAGE->set_button("");

echo $OUTPUT->header();

// Get all the appropriate data.

if (! $projects = get_all_instances_in_course('techproject', $course)) {
    $returnurl = new moodle_url('/course/view.php', ['id' => $course->id]);
    echo $OUTPUT->notification(get_string('noprojects', 'techproject'), $returnurl);
    die;
}

$userdashboardurl = new moodle_url('/mod/techproject/dashboard.php', ['id' => $course->id, 'scope' => 'user']);
$managerdashboardurl = new moodle_url('/mod/techproject/dashboard.php', ['id' => $course->id, 'scope' => 'manager']);
$coursecontext = context_course::instance($course->id);
$canmanagescope = has_capability('moodle/course:update', $coursecontext);
if (!$canmanagescope) {
    foreach ($projects as $aproject) {
        $cmcontext = context_module::instance($aproject->coursemodule);
        $hasgrade = has_capability('mod/techproject:gradeproject', $cmcontext);
        $hasview = has_capability('mod/techproject:viewprojectcontrols', $cmcontext);
        if ($hasgrade || $hasview) {
            $canmanagescope = true;
            break;
        }
    }
}
echo html_writer::start_div('techproject-dashboard-links');
$usertext = get_string('userdashboard', 'techproject');
echo $OUTPUT->single_button($userdashboardurl, $usertext, 'get');
if ($canmanagescope) {
    $managertext = get_string('managerdashboard', 'techproject');
    echo $OUTPUT->single_button($managerdashboardurl, $managertext, 'get');
}
echo html_writer::end_div();

// Print the list of instances (your module will probably extend this).

$timenow = time();
$strname  = get_string('name');
$strgrade  = get_string('grade');
$strprojectend = get_string('projectend', 'techproject');
$strweek  = get_string('week');
$strtopic  = get_string('topic');
$table = new html_table();

if ($course->format == 'weeks') {
    $table->head  = [$strweek, $strname, $strgrade, $strprojectend];
    $table->align = ['center', 'left', 'center', 'center'];
} else if ($course->format == 'topics') {
    $table->head  = [$strtopic, $strname, $strgrade, $strprojectend];
    $table->align = ['center', 'left', 'center', 'center'];
} else {
    $table->head  = [$strname, $strgrade, $strprojectend];
    $table->align = ['left', 'center', 'center'];
}

foreach ($projects as $project) {
    $cmcontext = context_module::instance($project->coursemodule);
    $linkurl = new moodle_url('/mod/techproject/view.php', ['id' => $project->coursemodule]);
    if (!$project->visible) {
        // Show dimmed if the mod is hidden.
        $link = '<a class="dimmed" href="'.$linkurl.'">'.format_string($project->name, true).'</a>';
    } else {
        // Show normal if the mod is visible.
        $link = '<a href="'.$linkurl.'">'.format_string($project->name, true).'</a>';
    }

    if ($project->projectend > $timenow) {
        $due = userdate($project->projectend);
    } else {
        $due = '<font color="red">'.userdate($project->projectend).'</font>';
    }

    if ($course->format == 'weeks' || $course->format == 'topics') {
        if (has_capability('mod/techproject:gradeproject', $cmcontext) || has_capability('moodle/course:update', $context)) {
            $gradevalue = @$project->grade;
        } else {
            // It's a student, show their mean or maximum grade.
            if ($project->usemaxgrade) {
                $sql = "
                    SELECT
                        MAX(grade) AS grade
                    FROM
                        {techproject_grades}
                    WHERE
                        projectid = $project->id AND
                        userid = $USER->id
                    GROUP BY
                        userid
                ";
                $grade = $DB->get_record_sql($sql);
            } else {
                $sql = "
                    SELECT
                        AVG(grade) AS grade
                    FROM
                        {techproject_grades}
                    WHERE
                        projectid = ? AND
                        userid = ?
                    GROUP BY
                        userid
                ";
                $grade = $DB->get_record_sql($sql, [$project->id, $USER->id]);
            }
            if ($grade) {
                // Grades are stored as percentages.
                $gradevalue = number_format($grade->grade * $project->grade / 100, 1);
            } else {
                $gradevalue = 0;
            }
        }
        $table->data[] = [$project->section, $link, $gradevalue, $due];
    } else {
        $table->data[] = [$link, $gradevalue, $due];
    }
}

echo '<br />';

echo html_writer::table($table);

// Finish the page.

echo $OUTPUT->footer($course);
