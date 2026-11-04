<?php
// This file is part of local_centro_de_descarga_de_portafolio_de_evidencias for Moodle - http://moodle.org/
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
 * Trait con la lógica de descarga de la actividad Feedback (encuestas).
 *
 * @package       local_centro_de_descarga_de_portafolio_de_evidencias
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */

defined('MOODLE_INTERNAL') || die();

trait local_centro_de_descarga_de_portafolio_de_evidencias_feedback_trait {

    /**
     * Handle Feedback module.
     *
     * @param mixed $resource
     * @param string $resdir
     * @param array $filelist
     * @param int|null $groupid
     * @return void
     */
    private function handle_feedback($resource, $resdir, &$filelist, $groupid = null) {
        $this->pdfactivityname = $resource->name;
        global $CFG, $DB;
        $context = $resource->context;

        if (!has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadAssignments',
                $context->get_course_context())) {
            return;
        }

        require_once($CFG->dirroot . '/mod/feedback/lib.php');

        $feedback = $DB->get_record('feedback', ['id' => $resource->instanceid], '*', MUST_EXIST);

        $users = get_enrolled_users($context, 'mod/feedback:complete', $groupid, 'u.*', 'u.lastname');

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

        if ($this->portfolio_userid !== null) {
            $users = array_filter($users, function($u) {
                return (int)$u->id === (int)$this->portfolio_userid;
            });
        }

        if (!$users) {
            return;
        }

        $evidenciadir = $resdir . '/Evidencias';
        $filelist[$evidenciadir] = null;

        foreach ($users as $user) {
            $studentname = fullname($user);
            $body = $this->build_feedback_pdf_body($feedback, $user, $studentname);
            if ($body) {
                $title = get_string('feedback_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . $studentname;
                $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);
                $filename = $evidenciadir . '/' . self::shorten_filename($title . '.pdf');
                $filelist[$filename] = [$pdf];
            }
        }
    }

    /**
     * Builds the HTML body for the feedback PDF (one per student).
     *
     * @param object $feedback
     * @param object $user
     * @param string $studentname
     * @return string
     */
    private function build_feedback_pdf_body($feedback, $user, $studentname) {
        global $DB, $CFG;

        $responses = $DB->get_records('feedback_completed', [
            'feedback' => $feedback->id,
            'userid' => $user->id,
        ], 'timemodified ASC');
        if (empty($responses)) {
            return '';
        }

        $items = $DB->get_records('feedback_item', ['feedback' => $feedback->id], 'position ASC');

        $th  = 'style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;text-align:left;font-size:8pt;"';
        $td  = 'style="border:1px solid #adb5bd;padding:2px;vertical-align:top;font-size:8pt;"';
        $hd  = 'style="background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;"';
        $tbl = 'border="1" cellspacing="0" cellpadding="2" style="border-collapse:collapse;width:100%;font-size:8pt;"';

        // 1) Datos generales del alumno.
        $h  = '<h2 ' . $hd . '>' . s(get_string('feedback_results', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . s($studentname) . '</h2>';
        $h .= '<table ' . $tbl . '>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('quiz_lastname', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($user->lastname) . '</td>'
            . '<th ' . $th . '>' . s(get_string('quiz_firstname', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($user->firstname) . '</td>'
            . '<th ' . $th . '>' . s(get_string('quiz_email', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($user->email) . '</td>'
            . '</tr>';
        $h .= '</table>';

        // 2) Resumen.
        $h .= '<table ' . $tbl . '>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('feedback_responses', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . count($responses) . '</td>'
            . '<th ' . $th . '>' . s(get_string('feedback_last_response', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s(userdate(end($responses)->timemodified)) . '</td>'
            . '</tr>';
        $h .= '</table>';

        // 3) Respuestas de cada envío.
        $index = 0;
        foreach ($responses as $response) {
            $index++;
            $break = ($index === 1) ? '' : 'page-break-before:always;';
            $h .= '<h2 style="' . $break . 'background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;">'
                . s(get_string('feedback_response', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . ' #' . $index
                . ' — ' . s(userdate($response->timemodified)) . '</h2>';

            $values = $DB->get_records('feedback_value', ['completed' => $response->id], 'id ASC');
            $valuebyitem = [];
            foreach ($values as $value) {
                $valuebyitem[$value->item] = $value->value;
            }

            $h .= '<table ' . $tbl . '>';
            foreach ($items as $item) {
                if (!in_array($item->typ, ['label', 'pagebreak']) && empty($item->hasvalue)) {
                    continue;
                }
                if (in_array($item->typ, ['label', 'pagebreak'])) {
                    continue;
                }
                $question = trim((string)$item->name);
                if ($question === '') {
                    continue;
                }
                $answer = '-';
                if (array_key_exists($item->id, $valuebyitem)) {
                    $answer = $this->feedback_format_answer($item, $valuebyitem[$item->id]);
                }
                $h .= '<tr><th ' . $th . ' style="width:55%;">' . s($question) . '</th>'
                    . '<td ' . $td . '>' . $answer . '</td></tr>';
            }
            $h .= '</table>';
        }

        return $h;
    }

    /**
     * Formats a feedback answer using the item type's own print value.
     *
     * @param object $item
     * @param string $value
     * @return string HTML-safe answer.
     */
    private function feedback_format_answer($item, $value) {
        global $CFG;
        try {
            $itemclass = feedback_get_item_class($item->typ);
            $print = $itemclass->get_printval($item, (object)['value' => $value]);
            return s((string)$print);
        } catch (\Exception $e) {
            return s((string)$value);
        }
    }
}
