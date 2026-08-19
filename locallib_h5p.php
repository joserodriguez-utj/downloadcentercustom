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
 * Trait con la lógica de descarga de actividades H5P.
 *
 * @package       local_downloadcentercustom
 * @author        Simeon Naydenov (moniNaydenov@gmail.com)
 * @copyright     2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */

defined('MOODLE_INTERNAL') || die();

trait local_downloadcentercustom_h5p_trait {

    /**
     * Handle H5P module.
     *
     * @param mixed $resource The resource being handled.
     * @param string $resdir The directory where results are saved.
     * @param array $filelist The array of files to be included in the ZIP.
     * @param int|null $groupid Group ID for filtering students.
     * @return void
    */

    private function handle_h5pactivity($resource, $resdir, &$filelist, $groupid = null) {
        global $CFG, $DB;
        $context = $resource->context;

        if (!has_capability('local/downloadcentercustom:downloadAssignments', $context->get_course_context())) {
            return;
        }
        
        require_once($CFG->dirroot . '/mod/h5pactivity/classes/local/manager.php');
        require_once($CFG->dirroot . '/mod/h5pactivity/classes/local/attempt.php');
        require_once($CFG->dirroot . '/mod/h5pactivity/classes/output/result.php');

        $h5p = $DB->get_record('h5pactivity', ['id' => $resource->instanceid], '*', MUST_EXIST);
        $cm = $resource->cm;
        
        if ($h5p->grademethod == \mod_h5pactivity\local\manager::GRADEMANUAL) {
            return;
        }

        $users = get_enrolled_users($context, 'mod/h5pactivity:submit', $groupid, 'u.*', 'u.lastname');
        if ($this->onlyungrouped) {
            $allgroupmemberids = $DB->get_fieldset_sql(
                "SELECT DISTINCT gm.userid FROM {groups_members} gm
                    JOIN {groups} g ON g.id = gm.groupid
                WHERE g.courseid = ?", [$this->course->id]
            );
            $users = array_filter($users, function($u) use ($allgroupmemberids) {
                return !in_array($u->id, $allgroupmemberids);
            });
        }
        if (!$users) {
            return;
        }

        $evidenciadir = $resdir . '/Evidencias';
        $filelist[$evidenciadir] = null;

        foreach ($users as $user) {
            $studentname = fullname($user);
            $html = $this->build_h5p_html($h5p, $cm, $user, $studentname);
            if ($html) {
                $html = self::convert_content_to_html_doc(get_string('h5p_filename', 'local_downloadcentercustom') . $studentname, $html);
                $filename = $evidenciadir . '/' . self::shorten_filename(get_string('h5p_filename', 'local_downloadcentercustom') . $studentname . '.html');
                $filelist[$filename] = [$html];
            }
        }
    }

    private function build_h5p_html($h5p, $cm, $user, $studentname) {
        global $DB, $PAGE;

        $manager = \mod_h5pactivity\local\manager::create_from_instance($h5p);
        $attempts = $manager->get_user_attempts($user->id);
        if (empty($attempts)) {
            return '';
        }

        $renderer = $PAGE->get_renderer('core');

        $methods = \mod_h5pactivity\local\manager::get_grading_methods();
        $grademethodstr = $methods[$h5p->grademethod] ?? '';

        $h = '<h2>' . get_string('h5p_title', 'local_downloadcentercustom') . s($studentname) . ' — ' . s($grademethodstr) . '</h2>';

        $h .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;">';
        $h .= '<tr style="background:#f2f2f2;">'.'<th>'. get_string('h5p_sharp', 'local_downloadcentercustom') .'</th>' . '<th>' . get_string('h5p_date', 'local_downloadcentercustom') . '</th>' . '<th>' . get_string('h5p_score', 'local_downloadcentercustom') . '</th>' . '<th>' . get_string('h5p_max_score', 'local_downloadcentercustom') . '</th>' . '<th>' . get_string('h5p_duration', 'local_downloadcentercustom') . '</th>' . '<th>' . get_string('h5p_success', 'local_downloadcentercustom') . '</th>' . '</tr>';
        foreach ($attempts as $attempt) {
            $h .= '<tr>';
            $h .= '<td>' . $attempt->get_attempt() . '</td>';
            $h .= '<td>' . s(userdate($attempt->get_timecreated())) . '</td>';
            $h .= '<td>' . $attempt->get_rawscore() . '</td>';
            $h .= '<td>' . $attempt->get_maxscore() . '</td>';
            $h .= '<td>' . s(format_time($attempt->get_duration())) . '</td>';
            $h .= '<td>' . ($attempt->get_success() ? get_string('h5p_yes', 'local_downloadcentercustom') : get_string('h5p_no', 'local_downloadcentercustom')) . '</td>';
            $h .= '</tr>';
        }
        $h .= '</table>';

        // Detalle de respuestas por intento.
        foreach ($attempts as $attempt) {
            $h .= '<h3>' . get_string('h5p_attempt', 'local_downloadcentercustom') . $attempt->get_attempt() . '</h3>';

            $results = $attempt->get_results();
            if (empty($results)) {
                $h .= '<p><em>' . get_string('h5p_no_answer', 'local_downloadcentercustom') . '</em></p>';
                continue;
            }

            foreach ($results as $result) {
                $outputresult = \mod_h5pactivity\output\result::create_from_record($result);
                if (!$outputresult) {
                    continue;
                }
                $data = $outputresult->export_for_template($renderer);

                if (!empty($data->description)) {
                    $h .= '<p><strong>' . s($data->description) . '</strong></p>';
                }

                if (!empty($data->hasoptions) && !empty($data->options)) {
                    $h .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;margin-bottom:10px;">';
                    $h .= '<tr style="background:#f2f2f2;">';
                    $h .= '<th>' . s($data->optionslabel ?? '') . '</th>';
                    $h .= '<th>' . s($data->correctlabel ?? '') . '</th>';
                    $h .= '<th>' . s($data->answerlabel ?? '') . '</th>';
                    $h .= '</tr>';
                    foreach ($data->options as $option) {
                        $h .= '<tr>';
                        $h .= '<td>' . s($option->description ?? '') . '</td>';
                        $h .= '<td>' . s($option->correctanswer->answer ?? '') . '</td>';
                        $h .= '<td>' . $this->h5p_answer_html($option->useranswer ?? null) . '</td>';
                        $h .= '</tr>';
                    }
                    if (!empty($data->score)) {
                        $h .= '<tr style="background:#f9f9f9;"><td colspan="3"><strong>' . s(get_string('score', 'mod_h5pactivity') . ': ' . $data->score) . '</strong></td></tr>';
                    }
                    $h .= '</table>';
                } else if (!empty($data->content)) {
                    $h .= $data->content;
                }
            }
        }
        return $h;
    }

    /**
     * Renderiza la respuesta del alumno con indicador de correcto/incorrecto.
     *
     * @param stdClass|null $useranswer objeto con la respuesta y su estado.
     * @return string
     */
    private function h5p_answer_html($useranswer) {
        if (empty($useranswer) || !isset($useranswer->answer)) {
            return '-';
        }
        $answer = s($useranswer->answer);
        if (!empty($useranswer->correct) || !empty($useranswer->pass) || !empty($useranswer->checked)) {
            return '<span style="color:#198754;">✔ ' . $answer . '</span>';
        }
        if (!empty($useranswer->incorrect) || !empty($useranswer->fail)) {
            return '<span style="color:#dc3545;">✘ ' . $answer . '</span>';
        }
        return $answer;
    }
}