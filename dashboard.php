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
 * Course level dashboards for techproject.
 *
 * @package    mod_techproject
 * @copyright  2024 Your Institution
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once($CFG->dirroot.'/mod/techproject/locallib.php');

/**
 * Resolve a task status label from project qualifiers.
 *
 * @param int $projectid
 * @param string $statuscode
 * @param array $cache
 * @return string
 */
function techproject_dashboard_get_status_label($projectid, $statuscode, &$cache) {
    if (!array_key_exists($projectid, $cache)) {
        $cache[$projectid] = [];
        $options = techproject_get_options('taskstatus', $projectid);
        if (!empty($options)) {
            foreach ($options as $option) {
                $cache[$projectid][$option->code] = format_string($option->label);
            }
        }
    }

    if (isset($cache[$projectid][$statuscode])) {
        return $cache[$projectid][$statuscode];
    }

    return s($statuscode);
}

/**
 * Render a progress bar with percentage.
 *
 * @param mod_techproject_renderer $renderer
 * @param float $done
 * @return string
 */
function techproject_dashboard_progress($renderer, $done) {
    $done = max(0, min(100, (float)$done));
    return $renderer->bar_graph_over($done, 0, 120, 6).' '.round($done).'%';
}

/**
 * Render a Moodle pie chart if data is available.
 *
 * @param string $title
 * @param array $counts
 * @param core_renderer $output
 * @return string
 */
function techproject_dashboard_pie_chart($title, $counts, $output) {
    if (empty($counts)) {
        return '';
    }

    $pie = new \core\chart_pie();
    $pie->set_title($title);
    $pie->set_labels(array_keys($counts));
    $pie->add_series(new \core\chart_series(get_string('tasks', 'techproject'), array_values($counts)));

    return $output->render($pie);
}

/**
 * Render a Moodle bar chart if data is available.
 *
 * @param string $title
 * @param array $counts
 * @param core_renderer $output
 * @return string
 */
function techproject_dashboard_bar_chart($title, $counts, $output) {
    if (empty($counts)) {
        return '';
    }

    $bar = new \core\chart_bar();
    $bar->set_title($title);
    $bar->set_labels(array_keys($counts));
    $bar->add_series(new \core\chart_series(get_string('tasks', 'techproject'), array_values($counts)));

    return $output->render($bar);
}

/**
 * Tell if a task is considered closed.
 *
 * @param stdClass $task
 * @return bool
 */
function techproject_dashboard_task_is_closed($task) {
    if (isset($task->done) && (float)$task->done >= 100) {
        return true;
    }

    if (empty($task->status)) {
        return false;
    }

    $closedcodes = ['complete', 'completed', 'closed', 'done', 'finished', 'resolved'];
    return in_array(core_text::strtolower((string)$task->status), $closedcodes);
}

$id = required_param('id', PARAM_INT);
$scope = optional_param('scope', 'user', PARAM_ALPHA);
if ($scope !== 'user' && $scope !== 'manager') {
    $scope = 'user';
}

if (!$course = $DB->get_record('course', ['id' => $id])) {
    throw new moodle_exception('invalidcourseid', 'error');
}

require_login($course);

$coursecontext = context_course::instance($course->id);

$assigneeid = optional_param('assigneeid', 0, PARAM_INT);

$pageurl = new moodle_url('/mod/techproject/dashboard.php', ['id' => $course->id, 'scope' => $scope, 'assigneeid' => $assigneeid]);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('projectdashboards', 'techproject'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();

$projects = get_all_instances_in_course('techproject', $course);
if (empty($projects)) {
    echo $OUTPUT->notification(get_string('noprojects', 'techproject'));
    echo $OUTPUT->footer($course);
    die();
}

$canmanagescope = has_capability('moodle/course:update', $coursecontext);
if (!$canmanagescope) {
    foreach ($projects as $aproject) {
        $cmcontext = context_module::instance($aproject->coursemodule);
        $hasgradeproject = has_capability('mod/techproject:gradeproject', $cmcontext);
        $hasviewcontrols = has_capability('mod/techproject:viewprojectcontrols', $cmcontext);
        if ($hasgradeproject || $hasviewcontrols) {
            $canmanagescope = true;
            break;
        }
    }
}

if ($scope === 'manager' && !$canmanagescope) {
    throw new required_capability_exception($coursecontext, 'mod/techproject:gradeproject', 'nopermissions', '');
}

$userurl = new moodle_url('/mod/techproject/dashboard.php', ['id' => $course->id, 'scope' => 'user']);
$managerurl = new moodle_url('/mod/techproject/dashboard.php', ['id' => $course->id, 'scope' => 'manager']);

echo html_writer::start_div('techproject-dashboard-switch');
echo $OUTPUT->single_button($userurl, get_string('userdashboard', 'techproject'), 'get');
if ($canmanagescope) {
    echo $OUTPUT->single_button($managerurl, get_string('managerdashboard', 'techproject'), 'get');
}
echo html_writer::end_div();

$modinfo = get_fast_modinfo($course);
$projectmeta = [];
$projectids = [];
foreach ($projects as $project) {
    $projectids[] = $project->id;
    $clientname = get_string('nogroup', 'techproject');
    if (isset($modinfo->cms[$project->coursemodule])) {
        $cminfo = $modinfo->get_cm($project->coursemodule);
        $sectioninfo = $modinfo->get_section_info($cminfo->sectionnum);
        if (!empty($sectioninfo)) {
            $clientname = get_section_name($course, $sectioninfo);
        }
    }
    $projectmeta[$project->id] = (object)[
        'name' => format_string($project->name),
        'client' => $clientname,
        'cmid' => $project->coursemodule,
    ];
}

list($insql, $inparams) = $DB->get_in_or_equal($projectids, SQL_PARAMS_NAMED, 'tp');

$renderer = $PAGE->get_renderer('mod_techproject');
$statuslabelcache = [];

if ($scope === 'user') {
    $sql = "
        SELECT
            t.id,
            t.projectid,
            t.abstract,
            t.status,
            t.done,
            t.taskend,
            t.taskendenable,
            t.assignee,
            u.firstname,
            u.lastname
        FROM
            {techproject_task} t
        JOIN
            {user} u
        ON
            u.id = t.assignee
        WHERE
            t.projectid $insql AND
            t.assignee = :userid
        ORDER BY
            u.lastname,
            u.firstname,
            t.projectid,
            t.ordering
    ";
    $params = $inparams;
    $params['userid'] = $USER->id;
    $tasks = $DB->get_records_sql($sql, $params);

    echo $OUTPUT->heading(get_string('userdashboard', 'techproject'), 3);

    $totaltasks = count($tasks);
    $sumdone = 0;
    $overdue = 0;
    $statuscounts = [];

    foreach ($tasks as $task) {
        $sumdone += $task->done;
        if (!empty($task->taskendenable) && !empty($task->taskend) && $task->taskend < time() && $task->done < 100) {
            $overdue++;
        }
        $statuslabel = techproject_dashboard_get_status_label($task->projectid, $task->status, $statuslabelcache);
        if (empty($statuscounts[$statuslabel])) {
            $statuscounts[$statuslabel] = 0;
        }
        $statuscounts[$statuslabel]++;
    }

    $avgdone = ($totaltasks) ? round($sumdone / $totaltasks, 1) : 0;

    $summary = new html_table();
    $summary->head = [
        get_string('assignedtasks', 'techproject'),
        get_string('completionrate', 'techproject'),
        get_string('overdue', 'techproject'),
    ];
    $summary->data[] = [
        $totaltasks,
        techproject_dashboard_progress($renderer, $avgdone),
        $overdue,
    ];
    echo html_writer::table($summary);

    if (!empty($statuscounts)) {
        $graphtable = new html_table();
        $statustitle = get_string('status', 'techproject');
        $taskstitle = get_string('tasks', 'techproject');
        $graphtitle = get_string('graph', 'techproject');
        $graphtable->head = [$statustitle, $taskstitle, $graphtitle];
        foreach ($statuscounts as $statuslabel => $count) {
            $ratio = ($totaltasks) ? ($count * 100 / $totaltasks) : 0;
            $graphtable->data[] = [$statuslabel, $count, techproject_dashboard_progress($renderer, $ratio)];
        }
        echo html_writer::table($graphtable);
    }

    if (empty($tasks)) {
        echo $OUTPUT->notification(get_string('notaskassigned', 'techproject'));
    } else {
        $table = new html_table();
        $table->head = [
            get_string('client', 'techproject'),
            get_string('project', 'techproject'),
            get_string('task', 'techproject'),
            get_string('status', 'techproject'),
            get_string('completionrate', 'techproject'),
            get_string('detail', 'techproject'),
        ];
        foreach ($tasks as $task) {
            if (empty($projectmeta[$task->projectid])) {
                continue;
            }
            $meta = $projectmeta[$task->projectid];
            $statuslabel = techproject_dashboard_get_status_label($task->projectid, $task->status, $statuslabelcache);
            $detailurlparams = ['id' => $meta->cmid, 'view' => 'view_detail', 'objectClass' => 'task', 'objectId' => $task->id];
            $detailurl = new moodle_url('/mod/techproject/view.php', $detailurlparams);
            $table->data[] = [
                format_string($meta->client),
                $meta->name,
                format_string($task->abstract),
                $statuslabel,
                techproject_dashboard_progress($renderer, $task->done),
                html_writer::link($detailurl, get_string('seedetail', 'techproject')),
            ];
        }
        echo html_writer::table($table);
    }
} else {
    $filtersql = "
        SELECT DISTINCT
            u.id,
            u.firstname,
            u.lastname
        FROM
            {techproject_task} t
        JOIN
            {user} u
        ON
            u.id = t.assignee
        WHERE
            t.projectid $insql AND
            t.assignee > 0
        ORDER BY
            u.lastname,
            u.firstname
    ";
    $workers = $DB->get_records_sql($filtersql, $inparams);
    $workeroptions = [0 => get_string('allworkers', 'techproject')];
    foreach ($workers as $worker) {
        $workeroptions[$worker->id] = fullname($worker);
    }

    $filterurl = new moodle_url('/mod/techproject/dashboard.php', ['id' => $course->id, 'scope' => 'manager']);
    echo html_writer::start_div('techproject-dashboard-filter');
    echo html_writer::tag('strong', get_string('assignee', 'techproject').' : ');
    $workerselect = new single_select($filterurl, 'assigneeid', $workeroptions, $assigneeid, null, 'managerassignee');
    echo $OUTPUT->render($workerselect);
    echo html_writer::end_div();

    $sql = "
        SELECT
            t.id,
            t.projectid,
            t.abstract,
            t.status,
            t.done,
            t.modified,
            t.assignee,
            u.firstname,
            u.lastname
        FROM
            {techproject_task} t
        JOIN
            {user} u
        ON
            u.id = t.assignee
        WHERE
            t.projectid $insql AND
            t.assignee > 0
        ORDER BY
            u.lastname,
            u.firstname,
            t.projectid,
            t.ordering
    ";
    $params = $inparams;
    if (!empty($assigneeid)) {
        $sql = str_replace('ORDER BY', 'AND t.assignee = :assigneeid ORDER BY', $sql);
        $params['assigneeid'] = $assigneeid;
    }
    $tasks = $DB->get_records_sql($sql, $params);

    echo $OUTPUT->heading(get_string('managerdashboard', 'techproject'), 3);

    $totaltasks = count($tasks);
    $sumdone = 0;
    $statuscounts = [];
    $clientstats = [];
    $clienttaskcounts = [];
    $closedbyworkday = [];
    $workdaylabels = [];

    foreach ($tasks as $task) {
        if (empty($projectmeta[$task->projectid])) {
            continue;
        }
        $meta = $projectmeta[$task->projectid];
        $client = $meta->client;
        $sumdone += $task->done;

        $statuslabel = techproject_dashboard_get_status_label($task->projectid, $task->status, $statuslabelcache);
        if (empty($statuscounts[$statuslabel])) {
            $statuscounts[$statuslabel] = 0;
        }
        $statuscounts[$statuslabel]++;

        if (!isset($clientstats[$client])) {
            $clientstats[$client] = (object)[
                'tasks' => 0,
                'done' => 0,
                'open' => 0,
            ];
            $clienttaskcounts[$client] = 0;
        }
        $clientstats[$client]->tasks++;
        $clientstats[$client]->done += $task->done;
        if ($task->done < 100) {
            $clientstats[$client]->open++;
        }
        $clienttaskcounts[$client]++;

        if (techproject_dashboard_task_is_closed($task) && !empty($task->modified)) {
            $weekdaynumber = (int)date('N', $task->modified);
            if ($weekdaynumber <= 5) {
                $daykey = date('Y-m-d', $task->modified);
                if (empty($workdaylabels[$daykey])) {
                    $workdaylabels[$daykey] = userdate($task->modified, '%a %d %b');
                }
                if (empty($closedbyworkday[$daykey])) {
                    $closedbyworkday[$daykey] = 0;
                }
                $closedbyworkday[$daykey]++;
            }
        }
    }

    if (!empty($assigneeid) && isset($workeroptions[$assigneeid])) {
        echo $OUTPUT->heading($workeroptions[$assigneeid], 4);
    }

    $statuschart = techproject_dashboard_pie_chart(get_string('status', 'techproject'), $statuscounts, $OUTPUT);
    $clientchart = techproject_dashboard_pie_chart(get_string('client', 'techproject'), $clienttaskcounts, $OUTPUT);

    $weekstart = strtotime('monday this week', time());
    $weeksql = "
        SELECT
            t.assignee,
            t.status,
            t.done,
            t.modified,
            u.firstname,
            u.lastname
        FROM
            {techproject_task} t
        JOIN
            {user} u
        ON
            u.id = t.assignee
        WHERE
            t.projectid $insql AND
            t.assignee > 0 AND
            t.modified >= :weekstart
        ORDER BY
            u.lastname,
            u.firstname
    ";
    $weekparams = $inparams;
    $weekparams['weekstart'] = $weekstart;
    $weekclosed = $DB->get_records_sql($weeksql, $weekparams);

    $closedbyworkerweek = [];
    foreach ($weekclosed as $row) {
        if (!techproject_dashboard_task_is_closed($row)) {
            continue;
        }
        $workername = fullname($row);
        if (empty($closedbyworkerweek[$workername])) {
            $closedbyworkerweek[$workername] = 0;
        }
        $closedbyworkerweek[$workername]++;
    }

    $workdaychartdata = [];
    if (!empty($closedbyworkday)) {
        ksort($closedbyworkday);
        foreach ($closedbyworkday as $daykey => $count) {
            $workdaychartdata[$workdaylabels[$daykey]] = $count;
        }
    }

    $workdaystr = get_string('closedtasksworkday', 'techproject');
    $workdaychart = techproject_dashboard_bar_chart($workdaystr, $workdaychartdata, $OUTPUT);
    $workerweekstr = get_string('closedtasksworkerweek', 'techproject');
    $workerweekchart = techproject_dashboard_bar_chart($workerweekstr, $closedbyworkerweek, $OUTPUT);

    echo html_writer::start_div('techproject-dashboard-graphs');
    echo html_writer::start_div('techproject-dashboard-graph');
    if (!empty($statuschart)) {
        echo $statuschart;
    } else {
        echo $OUTPUT->notification(get_string('emptyproject', 'techproject'));
    }
    echo html_writer::end_div();
    echo html_writer::start_div('techproject-dashboard-graph');
    if (!empty($clientchart)) {
        echo $clientchart;
    } else {
        echo $OUTPUT->notification(get_string('emptyproject', 'techproject'));
    }
    echo html_writer::end_div();
    echo html_writer::start_div('techproject-dashboard-graph');
    if (!empty($workdaychart)) {
        echo $workdaychart;
    } else {
        echo $OUTPUT->notification(get_string('noclosedtasksworkday', 'techproject'));
    }
    echo html_writer::end_div();
    echo html_writer::start_div('techproject-dashboard-graph');
    if (!empty($workerweekchart)) {
        echo $workerweekchart;
    } else {
        echo $OUTPUT->notification(get_string('noclosedtasksworkerweek', 'techproject'));
    }
    echo html_writer::end_div();
    echo html_writer::end_div();

    $avgdone = ($totaltasks) ? round($sumdone / $totaltasks, 1) : 0;
    $globaltable = new html_table();
    $globaltable->head = [
        get_string('alltasks', 'techproject'),
        get_string('completionrate', 'techproject'),
    ];
    $globaltable->data[] = [
        $totaltasks,
        techproject_dashboard_progress($renderer, $avgdone),
    ];
    echo html_writer::table($globaltable);

    if (!empty($clientstats)) {
        $clienttable = new html_table();
        $clienttable->head = [
            get_string('client', 'techproject'),
            get_string('tasks', 'techproject'),
            get_string('completionrate', 'techproject'),
            get_string('todo', 'techproject'),
        ];
        foreach ($clientstats as $client => $stats) {
            $clientdone = ($stats->tasks) ? $stats->done / $stats->tasks : 0;
            $clienttable->data[] = [
                format_string($client),
                $stats->tasks,
                techproject_dashboard_progress($renderer, $clientdone),
                $stats->open,
            ];
        }
        echo html_writer::table($clienttable);
    }

    if (!empty($statuscounts)) {
        $statustable = new html_table();
        $statustitle = get_string('status', 'techproject');
        $taskstitle = get_string('tasks', 'techproject');
        $graphtitle = get_string('graph', 'techproject');
        $statustable->head = [$statustitle, $taskstitle, $graphtitle];
        foreach ($statuscounts as $statuslabel => $count) {
            $ratio = ($totaltasks) ? ($count * 100 / $totaltasks) : 0;
            $statustable->data[] = [$statuslabel, $count, techproject_dashboard_progress($renderer, $ratio)];
        }
        echo html_writer::table($statustable);
    }

    if (empty($tasks)) {
        echo $OUTPUT->notification(get_string('emptyproject', 'techproject'));
    } else {
        $table = new html_table();
        $table->head = [
            get_string('assignee', 'techproject'),
            get_string('client', 'techproject'),
            get_string('project', 'techproject'),
            get_string('task', 'techproject'),
            get_string('status', 'techproject'),
            get_string('completionrate', 'techproject'),
        ];
        foreach ($tasks as $task) {
            if (empty($projectmeta[$task->projectid])) {
                continue;
            }
            $meta = $projectmeta[$task->projectid];
            $statuslabel = techproject_dashboard_get_status_label($task->projectid, $task->status, $statuslabelcache);
            $table->data[] = [
                fullname($task),
                format_string($meta->client),
                $meta->name,
                format_string($task->abstract),
                $statuslabel,
                techproject_dashboard_progress($renderer, $task->done),
            ];
        }
        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer($course);
