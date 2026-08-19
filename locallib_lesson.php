<?php
// This file is part of local_downloadcentercustom for Moodle - http://moodle.org/
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
 * @package       local_downloadcentercustom
 * @author        Simeon Naydenov (moniNaydenov@gmail.com)
 * @copyright     2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */

defined('MOODLE_INTERNAL') || die();

trait local_downloadcentercustom_lesson_trait {

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
        global $CFG, $DB;
        $context = $resource->context;

        if (!has_capability('local/downloadcentercustom:downloadAssignments', $context->get_course_context())) {
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

        if (!$users) {
            return;
        }

        $evidenciadir = $resdir . '/Evidencias';
        $filelist[$evidenciadir] = null;

        foreach ($users as $user) {
            $studentname = fullname($user);
            $html = $this->build_lesson_html($lesson, $cm, $user, $studentname);
            if ($html) {
                $html = self::convert_content_to_html_doc(get_string('lesson_results', 'local_downloadcentercustom') . $studentname, $html);
                $filename = $evidenciadir . '/' . self::shorten_filename(get_string('lesson_results', 'local_downloadcentercustom') . $studentname . '.html');
                $filelist[$filename] = [$html];
            }
        }
    }

    /**
     * Build HTML table with lesson results for one student.
     *
     * @param object $lesson
     * @param object $cm
     * @param object $user
     * @param string $studentname
     * @return string
     */
    private function build_lesson_html($lesson, $cm, $user, $studentname) {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        // Intentos (retries) del alumno: cada retry es un intento de la lección.
        $attempts = $DB->get_records('lesson_attempts', ['lessonid' => $lesson->id, 'userid' => $user->id], 'timeseen ASC');
        if (empty($attempts)) {
            return '';
        }

        // Calificaciones por retry (una fila por intento, ordenada por completed).
        $grades = array_values($DB->get_records('lesson_grades', ['lessonid' => $lesson->id, 'userid' => $user->id], 'completed ASC'));

        // Calificación final del alumno (del libro de calificaciones).
        $finalgrade = '';
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'lesson', 'iteminstance' => $lesson->id]);
        if ($gradeitem) {
            $grade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $user->id]);
            if ($grade && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                $finalgrade = round($grade->finalgrade, 2);
            }
        }

        // Fallback: si no hay calificación en el libro de calificaciones, calcularla de los intentos.
        if ($finalgrade === '' && !empty($grades)) {
            $gradevals = array_map(function($g) {
                return (float)$g->grade;
            }, $grades);
            if (!empty($lesson->usemaxgrade)) {
                $finalgrade = round(max($gradevals), 2);
            } else {
                $finalgrade = round(array_sum($gradevals) / count($gradevals), 2);
            }
        }

        // Páginas y respuestas de la lección.
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

        $h = '<h2>' . get_string('lesson_lesson_results', 'local_downloadcentercustom') . s($studentname) . ' — ' . s($lesson->name) . '</h2>';

        // Tabla resumen de intentos.
        $h .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;margin-bottom:15px;">';
        $h .= '<tr style="background:#f2f2f2;"><th>' . get_string('lesson_attempts', 'local_downloadcentercustom') . '</th><th>' . get_string('lesson_grade', 'local_downloadcentercustom') . '</th><th>'. get_string('lesson_complete', 'local_downloadcentercustom') . '</th><th>' . get_string('lesson_final_grade', 'local_downloadcentercustom') . '</th></tr>';

        $retries = array_keys($attemptsbyretry);
        sort($retries);
        $numretries = count($retries);
        $firstretry = true;
        foreach ($retries as $retry) {
            $gradeval = isset($grades[$retry]) ? round($grades[$retry]->grade, 2) : '-';
            $completed = isset($grades[$retry]) && !empty($grades[$retry]->completed) ? get_string('yes') : get_string('no');
            $h .= '<tr>';
            $h .= '<td style="text-align:center;">' . ($retry + 1) . '</td>';
            $h .= '<td style="text-align:center;">' . $gradeval . '</td>';
            $h .= '<td style="text-align:center;">' . $completed . '</td>';
            if ($firstretry) {
                $h .= '<td style="text-align:center;vertical-align:middle;" rowspan="' . $numretries . '">' . ($finalgrade !== '' ? $finalgrade : '') . '</td>';
                $firstretry = false;
            }
            $h .= '</tr>';
        }
        $h .= '</table>';

        // Detalle por intento.
        foreach ($retries as $retry) {
            $h .= '<h3>' . get_string('lesson_attempt', 'local_downloadcentercustom') . ($retry + 1) . '</h3>';

            $retryattempts = $attemptsbyretry[$retry];
            // Ordenar por timeseen para respetar el orden de navegación.
            usort($retryattempts, function($a, $b) {
                return $a->timeseen <=> $b->timeseen;
            });

            foreach ($retryattempts as $attempt) {
                if (!isset($pages[$attempt->pageid])) {
                    continue;
                }
                $page = $pages[$attempt->pageid];
                $pageanswers = $answersbypage[$attempt->pageid] ?? [];

                $h .= '<div style="border:1px solid #ccc;border-radius:4px;padding:8px;margin-bottom:10px;">';
                $h .= '<div><b>' . get_string('lesson_page_topic', 'local_downloadcentercustom') . '</b> ' . s($page->title) . '</div>';

                // Contenido de la página (pregunta o contenido de ramificación).
                if (!empty($page->contents)) {
                    $h .= '<div><b>' . get_string('lesson_question', 'local_downloadcentercustom') . '</b> ' . format_text($page->contents, $page->contentsformat ?? FORMAT_HTML) . '</div>';
                }

                // Respuesta del alumno.
                $useranswertext = '';
                $correctanswertext = '';
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
                    $h .= '<div><b>' . get_string('lesson_student_answer_essay', 'local_downloadcentercustom') . '</b><br>' . s($useranswertext) . '</div>';
                    if ($score !== '') {
                        $h .= '<div><b>' . get_string('lesson_essay_grade', 'local_downloadcentercustom') . '</b> ' . s($score) . '</div>';
                    }
                } else if ($isbranch) {
                    // Tabla de ramificación: es contenido, no pregunta.
                    $h .= '<div><em>' . get_string('lesson_content_branch_page', 'local_downloadcentercustom') . '</em></div>';
                } else {
                    // Preguntas: opción múltiple, V/F, corta, numérica, relación.
                    $chosen = null;
                    foreach ($pageanswers as $answer) {
                        if ($answer->id == $attempt->answerid) {
                            $chosen = $answer;
                            break;
                        }
                    }
                    if ($chosen) {
                        $useranswertext = $chosen->answer;
                        $correct = !empty($attempt->correct);
                        $h .= '<div><b>' . get_string('lesson_student_answer', 'local_downloadcentercustom') . '</b> ';
                        $h .= $correct ?
                            '<span style="color:#198754;">✔ ' . s($useranswertext) . '</span>' :
                            '<span style="color:#dc3545;">✘ ' . s($useranswertext) . '</span>';
                        $h .= '</div>';
                    }

                    // Respuesta correcta (la de mayor score).
                    $best = null;
                    foreach ($pageanswers as $answer) {
                        if ($best === null || $answer->score > $best->score) {
                            $best = $answer;
                        }
                    }
                    if ($best && !$correct) {
                        $h .= '<div><b>' . get_string('lesson_correct_answer', 'local_downloadcentercustom') . '</b> ' . s($best->answer) . '</div>';
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
                        $h .= '<div><b>' . get_string('lesson_points', 'local_downloadcentercustom') . '</b> ' . s($chosenanswer->score) . '</div>';
                    }
                }

                $h .= '</div>';
            }
        }

        return $h;
    }
}
