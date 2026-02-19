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
 * Adhoc task to generate tasks from AI instructions.
 *
 * @package mod_techproject
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_techproject\task;

defined('MOODLE_INTERNAL') || die();

class generate_tasks_adhoc extends \core\task\adhoc_task {

    public function get_name(): string {
        return get_string('aitaskgenerator', 'techproject');
    }

    public function execute() {
        global $CFG, $DB;

        require_once($CFG->dirroot.'/mod/techproject/locallib.php');
        require_once($CFG->dirroot.'/course/lib.php');

        $data = $this->get_custom_data();
        if (empty($data)) {
            return;
        }

        $projectid = !empty($data->projectid) ? (int)$data->projectid : 0;
        $userid = !empty($data->userid) ? (int)$data->userid : 0;
        $instructions = !empty($data->instructions) ? trim($data->instructions) : '';
        if (!$projectid || !$userid || $instructions === '') {
            return;
        }

        $project = $DB->get_record('techproject', ['id' => $projectid]);
        if (!$project) {
            return;
        }

        $cm = get_coursemodule_from_instance('techproject', $project->id, $project->course, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }
        $context = \context_module::instance($cm->id);

        $prompt = $this->build_prompt($instructions);
        $response = $this->call_ai($prompt, $context, $userid);
        $payload = json_decode($response, true);
        if (empty($payload['tasks']) || !is_array($payload['tasks'])) {
            throw new \moodle_exception('aibadresponse', 'techproject');
        }

        $transaction = $DB->start_delegated_transaction();
        foreach ($payload['tasks'] as $taskdata) {
            if (!is_array($taskdata)) {
                continue;
            }
            $parentid = $this->create_task_from_payload($project, $userid, $context, $taskdata, 0);
            if (!$parentid || empty($taskdata['subtasks']) || !is_array($taskdata['subtasks'])) {
                continue;
            }
            foreach ($taskdata['subtasks'] as $subtaskdata) {
                if (!is_array($subtaskdata)) {
                    continue;
                }
                $this->create_task_from_payload($project, $userid, $context, $subtaskdata, $parentid);
            }
        }
        $transaction->allow_commit();
    }

    private function build_prompt(string $instructions): string {
        $schema = "{".
            "\n  \"tasks\": [".
            "\n    {\"title\": \"Task title\", \"description\": \"Optional description\",".
            "\n     \"subtasks\": [".
            "\n       {\"title\": \"Subtask title\", \"description\": \"Optional description\"}".
            "\n     ]".
            "\n    }".
            "\n  ]".
            "\n}";
        $prompt = get_string('aiinstructions_prompt', 'techproject', $schema);

        return $prompt."\n\n".$instructions;
    }

    private function call_ai(string $prompt, \context $context, int $userid): string {
        $actionclass = '\\core_ai\\aiactions\\generate_text';
        if (!class_exists($actionclass)) {
            throw new \moodle_exception('aiproviderunavailable', 'techproject');
        }

        $action = new $actionclass($context->id, $userid, $prompt);
        $manager = new \core_ai\manager();
        $response = $manager->process_action($action);
        if (!$response->get_success()) {
            throw new \moodle_exception('aiproviderunavailable', 'techproject', '', $response->get_errormessage());
        }

        $data = $response->get_response_data();
        $text = $data['generatedcontent'] ?? '';
        if (trim($text) === '') {
            throw new \moodle_exception('aibadresponse', 'techproject');
        }

        return $text;
    }

    private function create_task_from_payload(\stdClass $project, int $userid, \context $context,
            array $taskdata, int $fatherid): int {
        global $DB;

        $title = !empty($taskdata['title']) ? trim((string)$taskdata['title']) : '';
        if ($title === '') {
            return 0;
        }
        $description = !empty($taskdata['description']) ? (string)$taskdata['description'] : '';
        $groupid = 0;

        $record = new \stdClass();
        $record->fatherid = $fatherid;
        $record->ordering = techproject_tree_get_max_ordering($project->id, $groupid, 'techproject_task', true,
            $fatherid) + 1;
        $record->owner = $userid;
        $record->assignee = $userid;
        $record->groupid = $groupid;
        $record->projectid = $project->id;
        $record->userid = $userid;
        $record->created = time();
        $record->modified = time();
        $record->lastuserid = $userid;
        $record->abstract = $title;
        $record->description = $description;
        $record->descriptionformat = FORMAT_HTML;
        $record->worktype = '';
        $record->status = '';
        $record->costrate = 0;
        $record->planned = 0;
        $record->done = 0;
        $record->used = 0;
        $record->quoted = 0;
        $record->spent = 0;
        $record->risk = 0;
        $record->milestoneid = 0;
        $record->taskstartenable = 0;
        $record->taskstart = 0;
        $record->taskendenable = 0;
        $record->taskend = 0;

        $record->id = $DB->insert_record('techproject_task', $record);

        $event = \mod_techproject\event\task_created::create_from_task($project, $context, $record, $groupid);
        $event->trigger();

        if ($fatherid) {
            $dependency = new \stdClass();
            $dependency->projectid = $project->id;
            $dependency->groupid = $groupid;
            $dependency->slave = $fatherid;
            $dependency->master = $record->id;
            $params = ['projectid' => $project->id, 'slave' => $fatherid, 'master' => $record->id];
            if (!$DB->record_exists('techproject_task_dependency', $params)) {
                $DB->insert_record('techproject_task_dependency', $dependency);
            }
            techproject_tree_updateordering($fatherid, 'techproject_task', true);
        }

        return (int)$record->id;
    }
}
