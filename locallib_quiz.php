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
 * Trait con la lógica de descarga de actividades Quiz.
 *
 * @package       local_centro_de_descarga_de_portafolio_de_evidencias
 * @author        Simeon Naydenov (moniNaydenov@gmail.com)
 * @copyright     2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */

defined('MOODLE_INTERNAL') || die();

trait local_centro_de_descarga_de_portafolio_de_evidencias_quiz_trait {

/**
     * Handle quiz module.
     *
     * @param mixed $resource The resource being handled.
     * @param string $resdir The directory where results are saved.
     * @param array $filelist The array of files to be included in the ZIP.
     * @param int|null $groupid Group ID for filtering students.
     * @return void
     */

    private function handle_quiz($resource, $resdir, &$filelist, $groupid = null) {
        $this->pdfactivityname = $resource->name;
        global $CFG, $DB;
        $context = $resource->context;
        if (!has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadQuiz', $context->get_course_context())) {
            return;
        }
        // La descarga de exámenes depende del checkbox "Intentos".
        if (empty($this->downloadoptions['quiztries'])) {
            return;
        }
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $quiz = $DB->get_record('quiz', ['id' => $resource->instanceid], '*', MUST_EXIST);
        $cm = $resource->cm;
        $grademethod = $quiz->grademethod;
        $attemptsallowed = $quiz->attempts;

        $users = get_enrolled_users($context, 'mod/quiz:attempt', $groupid, 'u.*', 'u.lastname');

        // Si el usuario (profesor) no tiene grupos asignados, solo descargar
        // intentos de estudiantes que NO pertenecen a ningún grupo del curso.
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
            // Portafolio: solo evidencias del estudiante indicado.
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
            $attempts = $this->get_quiz_attempts_for_user($quiz, $user->id, $grademethod, $attemptsallowed);
            if (empty($attempts)) {
                continue;
            }
            $title = get_string('quiz_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . $studentname;
            $body = $this->build_quiz_pdf_body($quiz, $cm, $user, $studentname, $attempts, $grademethod);
            if ($body) {
                $footerleft = $title;
                $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 6.5, $footerleft);
                $filename = $evidenciadir . '/' . self::shorten_filename($title . '.pdf');
                $filelist[$filename] = [$pdf];
            }
        }
    }

    /**
     * Get quiz attempts for a user based on grading method.
     *
     * @param object $quiz
     * @param int $userid
     * @param int $grademethod
     * @param int $attemptsallowed
     * @return array
     */
    private function get_quiz_attempts_for_user($quiz, $userid, $grademethod, $attemptsallowed) {
        global $DB;
        $allattempts = $DB->get_records('quiz_attempts', [
            'quiz' => $quiz->id, 'userid' => $userid, 'state' => 'finished',
        ], 'attempt ASC');
        if (empty($allattempts)) {
            return [];
        }
        return array_values($allattempts);
    }

    /**
     * Build HTML table with quiz results for one student.
     */
    private function build_quiz_pdf_body($quiz, $cm, $user, $studentname, $attempts, $grademethod) {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id]);
        $finalgrade = '';
        if ($gradeitem) {
            $grade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $user->id]);
            if ($grade && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                $finalgrade = round($grade->finalgrade, 2);
            }
        }
        if ($finalgrade === '') {
            $grades = array_column($attempts, 'sumgrades');
            if ($grades) {
                switch ($grademethod) {
                    case QUIZ_GRADEHIGHEST: $finalgrade = round(max($grades), 2); break;
                    case QUIZ_GRADEAVERAGE: $finalgrade = round(array_sum($grades) / count($grades), 2); break;
                    case QUIZ_ATTEMPTFIRST: $finalgrade = round(reset($grades), 2); break;
                    case QUIZ_ATTEMPTLAST: $finalgrade = round(end($grades), 2); break;
                    default: $finalgrade = round(reset($grades), 2);
                }
            }
        }

        $methodnames = [
            QUIZ_GRADEHIGHEST => get_string('quiz_grade_highest', 'local_centro_de_descarga_de_portafolio_de_evidencias'),
            QUIZ_GRADEAVERAGE => get_string('quiz_grade_average', 'local_centro_de_descarga_de_portafolio_de_evidencias'),
            QUIZ_ATTEMPTFIRST => get_string('quiz_attempt_first', 'local_centro_de_descarga_de_portafolio_de_evidencias'),
            QUIZ_ATTEMPTLAST => get_string('quiz_attempt_last', 'local_centro_de_descarga_de_portafolio_de_evidencias'),
        ];
        $grademethodstr = $methodnames[$grademethod] ?? '';

        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot ASC');

        $th = 'valign="middle" style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;text-align:left;vertical-align:middle;font-size:6.5pt;"';
        $td = 'valign="middle" style="border:1px solid #adb5bd;padding:2px;vertical-align:middle;"';
        $hd = 'style="background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;"';
        $tbl = 'border="1" cellspacing="0" cellpadding="2" style="border-collapse:collapse;width:100%;font-size:6.5pt;"';

        // Datos generales.
        $h = '<h2 ' . $hd . '>' . get_string('quiz_general_data', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</h2>';
        $h .= '<table ' . $tbl . '>';
        $h .= '<tr nobr="true"><th ' . $th . '>' . get_string('quiz_lastname', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
            . '<td ' . $td . '>' . s($user->lastname) . '</td>'
            . '<th ' . $th . '>' . get_string('quiz_firstname', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
            . '<td ' . $td . '>' . s($user->firstname) . '</td>'
            . '<th ' . $th . '>' . get_string('quiz_email', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
            . '<td ' . $td . '>' . s($user->email) . '</td></tr>';
        $h .= '</table>';

        $attemptindex = 0;
        foreach ($attempts as $attempt) {
            $attemptindex++;
            $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);

            $attemptstyle = $hd;
            if ($attemptindex > 1) {
                // Cada intento empieza en una página nueva.
                $attemptstyle = 'style="background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;page-break-before:always;"';
            }
            $h .= '<h2 ' . $attemptstyle . '>' . get_string('quiz_intento', 'local_centro_de_descarga_de_portafolio_de_evidencias') . ' ' . $attemptindex . '</h2>';

            $h .= '<table ' . $tbl . '>';
            $h .= '<tr nobr="true">'
                . '<th ' . $th . '>' . get_string('quiz_status', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
                . '<td ' . $td . '>' . s(get_string('state' . $attempt->state, 'quiz')) . '</td>'
                . '<th ' . $th . '>' . get_string('quiz_time_start', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
                . '<td ' . $td . '>' . s(userdate($attempt->timestart, '%d/%m/%Y %H:%M')) . '</td>'
                . '<th ' . $th . '>' . get_string('quiz_time_end', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
                . '<td ' . $td . '>' . s(userdate($attempt->timefinish, '%d/%m/%Y %H:%M')) . '</td>'
                . '<th ' . $th . '>' . get_string('quiz_duration', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
                . '<td ' . $td . '>' . s(format_time($attempt->timefinish - $attempt->timestart)) . '</td>'
                . '<th ' . $th . '>' . get_string('quiz_grade_attempt', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
                . '<td ' . $td . '>' . round($attempt->sumgrades, 2) . '</td>'
                . '</tr>';
            $h .= '</table>';

            $h .= '<table ' . $tbl . '>';
            $h .= '<thead><tr nobr="true">'
                . '<th ' . $th . ' style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;width:5%;">#</th>'
                . '<th ' . $th . ' style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;width:37%;">' . get_string('quiz_question', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
                . '<th ' . $th . ' style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;width:29%;">' . get_string('quiz_answer', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
                . '<th ' . $th . ' style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;width:29%;">' . get_string('quiz_correct_answer', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
                . '</tr></thead><tbody>';

            $qnum = 0;
            foreach ($slots as $slot) {
                $qnum++;
                $qa = $quba->get_question_attempt($slot->slot);
                $question = $qa->get_question();
                $h .= '<tr nobr="true">';
                $h .= '<td ' . $td . ' style="border:1px solid #adb5bd;padding:2px;text-align:center;width:5%;">' . $qnum . '</td>';
                $h .= '<td ' . $td . ' style="border:1px solid #adb5bd;padding:2px;width:37%;">' . $this->quiz_question_cell($qa, $question) . '</td>';
                $h .= '<td ' . $td . ' style="border:1px solid #adb5bd;padding:2px;width:29%;">' . $this->quiz_clean_text($qa->get_response_summary()) . '</td>';
                $h .= '<td ' . $td . ' style="border:1px solid #adb5bd;padding:2px;width:29%;">' . $this->quiz_clean_text($this->quiz_correct_answer($qa, $question)) . '</td>';
                $h .= '</tr>';
            }
            $h .= '</tbody></table>';
        }

        $h .= '<h2 ' . $hd . '>' . get_string('quiz_summary', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</h2>';
        $h .= '<table ' . $tbl . '>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . get_string('quiz_grade_method', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
            . '<td ' . $td . '>' . s($grademethodstr) . '</td>'
            . '<th ' . $th . '>' . get_string('quiz_final_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
            . '<td ' . $td . '>' . ($finalgrade !== '' ? $finalgrade : '') . '</td>'
            . '</tr>';
        $h .= '</table>';

        return $h;
    }

    /**
     * Convierte texto HTML de una pregunta/respuesta a texto plano seguro con saltos de línea.
     *
     * @param string|null $text
     * @return string
     */
    private function quiz_clean_text($text) {
        if ($text === null || $text === '') {
            return '-';
        }
        return nl2br(s(trim(html_to_text($text, 0, false))));
    }

    /**
     * Construye la celda de la pregunta incluyendo el enunciado y las opciones (multichoice).
     *
     * @param object $question
     * @return string
     */
    private function quiz_question_cell($qa, $question) {
        $text = trim(html_to_text($question->questiontext, 0, false));
        if ($question instanceof \qtype_multichoice_base) {
            $order = $question->get_order($qa);
            $ids = $order ? $order : array_keys($question->answers);
            $options = [];
            $i = 0;
            foreach ($ids as $aid) {
                if (!isset($question->answers[$aid])) {
                    continue;
                }
                $prefix = ($i === 0) ? ':' : ';';
                $options[] = $prefix . ' ' . trim(html_to_text($question->answers[$aid]->answer, 0, false));
                $i++;
            }
            if ($options) {
                $text .= "\n" . implode("\n", $options);
            }
        }
        return nl2br(s($text));
    }

    /**
     * Obtiene la respuesta correcta en texto plano según el tipo de pregunta.
     *
     * @param object $qa
     * @param object $question
     * @return string
     */
    private function quiz_correct_answer($qa, $question) {
        if ($question instanceof \qtype_multichoice_base) {
            $order = $question->get_order($qa);
            $ids = $order ? $order : array_keys($question->answers);
            $parts = [];
            foreach ($ids as $aid) {
                if (!isset($question->answers[$aid])) {
                    continue;
                }
                $answer = $question->answers[$aid];
                if ($answer->fraction > 0) {
                    $parts[] = trim(html_to_text($answer->answer, 0, false));
                }
            }
            return implode('; ', $parts);
        }
        if ($question instanceof \qtype_shortanswer) {
            foreach ($question->answers as $aid => $answer) {
                if ($answer->fraction > 0) {
                    return trim(html_to_text($answer->answer, 0, false));
                }
            }
            return '';
        }
        if ($question instanceof \qtype_match) {
            $parts = [];
            foreach ($question->subquestions as $sq) {
                $parts[] = trim(html_to_text($sq->questiontext, 0, false)) . ' → ' . trim(html_to_text($sq->answertext, 0, false));
            }
            return implode("\n", $parts);
        }
        $ras = $qa->get_right_answer_summary();
        return $ras !== null ? $ras : '';
    }

}