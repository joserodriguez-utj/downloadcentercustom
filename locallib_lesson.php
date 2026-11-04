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
 * Trait con la lógica de descarga de actividades Lesson.
 *
 * @package       local_centro_de_descarga_de_portafolio_de_evidencias
 * @author        Simeon Naydenov (moniNaydenov@gmail.com)
 * @copyright     2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */

defined('MOODLE_INTERNAL') || die();

trait local_centro_de_descarga_de_portafolio_de_evidencias_lesson_trait {

    /**
     * Handle Lesson module.
     *
     * @param mixed $resource The resource being handled.
     * @param string $resdir The directory where results are saved.
     * @param array $filelist The array of files to be included in the ZIP.
     * @param int|null $groupid Group ID for filtering students.
     * @return void
     */
    private function handle_lesson($resource, $resdir, &$filelist, $groupid = null) {
        $this->pdfactivityname = $resource->name;
        global $CFG, $DB;
        $context = $resource->context;

        if (!has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadAssignments', $context->get_course_context())) {
            return;
        }

        $lesson = $DB->get_record('lesson', ['id' => $resource->instanceid], '*', MUST_EXIST);
        $cm = $resource->cm;

        $users = get_enrolled_users($context, 'mod/lesson:view', $groupid, 'u.*', 'u.lastname');

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
            $body = $this->build_lesson_pdf_body($lesson, $cm, $user, $studentname, $evidenciadir, $filelist);
            if ($body) {
                $title = get_string('lesson_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . $studentname;
                $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);
                $filename = $evidenciadir . '/' . self::shorten_filename($title . '.pdf');
                $filelist[$filename] = [$pdf];
            }
        }
    }

    /**
     * Builds the HTML body for the Lesson PDF (one per student).
     *
     * Same information as the previous HTML version, but styled for TCPDF:
     * smaller fonts, page break between retries, cleaner layout for
     * "branch table" pages and essay answers.
     *
     * @param object $lesson
     * @param object $cm
     * @param object $user
     * @param string $studentname
     * @return string
     */
    private function build_lesson_pdf_body($lesson, $cm, $user, $studentname, $evidenciadir, array &$filelist) {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');
        require_once($CFG->libdir . '/filelib.php');

        $context = \context_module::instance($cm->id);
        $contextid = $context->id;
        $mediadir = $evidenciadir . '/Recurso';

        // Intentos (retries) del alumno.
        $attempts = $DB->get_records('lesson_attempts', [
            'lessonid' => $lesson->id,
            'userid' => $user->id,
        ], 'timeseen ASC');
        if (empty($attempts)) {
            return '';
        }

        // Calificaciones por retry.
        $grades = array_values($DB->get_records('lesson_grades', [
            'lessonid' => $lesson->id,
            'userid' => $user->id,
        ], 'completed ASC'));

        // Calificación final del alumno.
        $finalgrade = '';
        $gradeitem = \grade_item::fetch([
            'itemtype' => 'mod', 'itemmodule' => 'lesson', 'iteminstance' => $lesson->id,
        ]);
        if ($gradeitem) {
            $grade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $user->id]);
            if ($grade && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                $finalgrade = round($grade->finalgrade, 2);
            }
        }

        // Fallback: calcular desde los intentos.
        if ($finalgrade === '' && !empty($grades)) {
            $gradevals = array_map(function($g) { return (float)$g->grade; }, $grades);
            if (!empty($lesson->usemaxgrade)) {
                $finalgrade = round(max($gradevals), 2);
            } else {
                $finalgrade = round(array_sum($gradevals) / count($gradevals), 2);
            }
        }
        $finalgradedisplay = ($finalgrade === '')
            ? get_string('string_no_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias')
            : $finalgrade;

        // Páginas y respuestas.
        $pages = $DB->get_records('lesson_pages', ['lessonid' => $lesson->id], 'id ASC');
        $answers = $DB->get_records('lesson_answers', ['lessonid' => $lesson->id], 'id ASC');
        $answersbypage = [];
        foreach ($answers as $answer) {
            $answersbypage[$answer->pageid][] = $answer;
        }

        // Agrupar intentos por retry.
        $attemptsbyretry = [];
        foreach ($attempts as $attempt) {
            $attemptsbyretry[$attempt->retry][] = $attempt;
        }

        // Método de calificación.
        $grademethod = !empty($lesson->usemaxgrade)
            ? get_string('lesson_grade_highest', 'local_centro_de_descarga_de_portafolio_de_evidencias')
            : get_string('lesson_grade_average', 'local_centro_de_descarga_de_portafolio_de_evidencias');

        // Estilos.
        $th  = 'style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;text-align:left;font-size:8pt;"';
        $td  = 'style="border:1px solid #adb5bd;padding:2px;vertical-align:top;font-size:8pt;"';
        $hd  = 'style="background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;"';
        $tbl = 'border="1" cellspacing="0" cellpadding="2" style="border-collapse:collapse;width:100%;font-size:8pt;"';

        // 1) Datos generales del alumno.
        $h  = '<h2 ' . $hd . '>' . s(get_string('lesson_lesson_results', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . s($studentname) . '</h2>';
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

        // 2) Resumen de intentos.
        $h .= '<h2 ' . $hd . '>' . s(get_string('lesson_attempts', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</h2>';

        $retries = array_keys($attemptsbyretry);
        sort($retries);
        $numretries = count($retries);

        $h .= '<table ' . $tbl . '>';
        $h .= '<thead><tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('lesson_attempts', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('lesson_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('lesson_complete', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('lesson_grade_method', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('lesson_final_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '</tr></thead><tbody>';

        $firstretry = true;
        foreach ($retries as $retry) {
            $gradeval = isset($grades[$retry]) ? round($grades[$retry]->grade, 2) : '-';
            $completed = isset($grades[$retry]) && !empty($grades[$retry]->completed) ? get_string('yes') : get_string('no');
            $h .= '<tr nobr="true">';
            $h .= '<td style="text-align:center;">' . ($retry + 1) . '</td>';
            $h .= '<td style="text-align:center;">' . $gradeval . '</td>';
            $h .= '<td style="text-align:center;">' . s($completed) . '</td>';
            if ($firstretry) {
                $h .= '<td rowspan="' . $numretries . '" style="text-align:center;">' . s($grademethod) . '</td>';
                $h .= '<td rowspan="' . $numretries . '" style="text-align:center;">' . s($finalgradedisplay) . '</td>';
                $firstretry = false;
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';

        // 3) Detalle por intento (una página por retry a partir del 2º).
        $firstretryblock = true;
        foreach ($retries as $retry) {
            $break = $firstretryblock ? '' : 'page-break-before:always;';
            $firstretryblock = false;

            $h .= '<h2 style="' . $break . 'background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;">'
                . s(get_string('lesson_attempt', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . ' ' . ($retry + 1)
                . '</h2>';

            $retryattempts = $attemptsbyretry[$retry];
            usort($retryattempts, function($a, $b) {
                return $a->timeseen <=> $b->timeseen;
            });

            foreach ($retryattempts as $attempt) {
                if (!isset($pages[$attempt->pageid])) {
                    continue;
                }
                $page = $pages[$attempt->pageid];
                $pageanswers = $answersbypage[$attempt->pageid] ?? [];

                // Bloque de la página.
                $h .= '<table ' . $tbl . '>';
                $h .= '<tr><th ' . $th . '>' . s(get_string('lesson_page_topic', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                    . '<td ' . $td . '>' . s($page->title) . '</td></tr>';

                // Contenido/pregunta.
                if (!empty($page->contents)) {
                    $contents = file_rewrite_pluginfile_urls(
                        $page->contents, 'pluginfile.php', $contextid, 'mod_lesson', 'page_contents', $page->id
                    );
                    $this->add_embedded_files_from_html($contents, $mediadir, $filelist);
                    $contents = $this->clean_pluginfile_leftovers($contents);
                    $contents = format_text($contents, $page->contentsformat ?? FORMAT_HTML, ['context' => $context]);
                    $h .= '<tr><th ' . $th . '>' . s(get_string('lesson_question', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                        . '<td ' . $td . '>' . $contents . '</td></tr>';
                }

                $isessay = ((int)$page->qtype == 10);
                $isbranch = ((int)$page->qtype == 20);

                if ($isessay) {
                    // Ensayo: respuesta de texto del alumno + calificación manual.
                    $useranswerobj = @unserialize_object($attempt->useranswer);
                    if (is_object($useranswerobj)) {
                        $useranswertext = $useranswerobj->answer ?? '';
                        $score = $useranswerobj->score ?? '';
                    } else {
                        $useranswertext = (string)$attempt->useranswer;
                        $score = '';
                    }
                    $h .= '<tr><th ' . $th . '>' . s(get_string('lesson_student_answer_essay', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                        . '<td ' . $td . '>' . nl2br(s($useranswertext)) . '</td></tr>';
                    if ($score !== '') {
                        $h .= '<tr><th ' . $th . '>' . s(get_string('lesson_essay_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                            . '<td ' . $td . '>' . s($score) . '</td></tr>';
                    }
                } else if ($isbranch) {
                    $h .= '<tr><th ' . $th . '>' . s(get_string('lesson_page_topic', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                        . '<td ' . $td . '><em>' . s(get_string('lesson_content_branch_page', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</em></td></tr>';
                } else {
                    // Preguntas: opción múltiple, V/F, corta, numérica, relación.
                    $chosen = null;
                    foreach ($pageanswers as $answer) {
                        if ($answer->id == $attempt->answerid) {
                            $chosen = $answer;
                            break;
                        }
                    }
                    $correct = !empty($attempt->correct);
                    if ($chosen) {
                        $useranswertext = $chosen->answer;
                        $answerhtml = $correct
                            ? '<span style="color:#198754;">[OK] ' . s($useranswertext) . '</span>'
                            : '<span style="color:#dc3545;">[X] ' . s($useranswertext) . '</span>';
                        $h .= '<tr><th ' . $th . '>' . s(get_string('lesson_student_answer', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                            . '<td ' . $td . '>' . $answerhtml . '</td></tr>';
                    }

                    // Respuesta correcta (la de mayor score).
                    $best = null;
                    foreach ($pageanswers as $answer) {
                        if ($best === null || $answer->score > $best->score) {
                            $best = $answer;
                        }
                    }
                    if ($best && !$correct) {
                        $h .= '<tr><th ' . $th . '>' . s(get_string('lesson_correct_answer', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                            . '<td ' . $td . '>' . s($best->answer) . '</td></tr>';
                    }
                }

                // Puntos si es modo personalizado.
                if (!empty($lesson->custom)) {
                    $chosenanswer = null;
                    foreach ($pageanswers as $answer) {
                        if ($answer->id == $attempt->answerid) {
                            $chosenanswer = $answer;
                            break;
                        }
                    }
                    if ($chosenanswer) {
                        $h .= '<tr><th ' . $th . '>' . s(get_string('lesson_points', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                            . '<td ' . $td . '>' . s($chosenanswer->score) . '</td></tr>';
                    }
                }

                $h .= '</table>';
                // Separación entre bloques de páginas.
                $h .= '<p style="margin:2mm 0;"></p>';
            }
        }

        return $h;
    }
    
}
