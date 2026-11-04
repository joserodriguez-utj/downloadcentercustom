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
 * @package       local_centro_de_descarga_de_portafolio_de_evidencias
 * @author        Simeon Naydenov (moniNaydenov@gmail.com)
 * @copyright     2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */

defined('MOODLE_INTERNAL') || die();

trait local_centro_de_descarga_de_portafolio_de_evidencias_h5p_trait {

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

        if (!has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadAssignments', $context->get_course_context())) {
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
            $body = $this->build_h5p_pdf_body($h5p, $cm, $user, $studentname);
            if ($body) {
                $title = get_string('h5p_filename', 'local_centro_de_descarga_de_portafolio_de_evidencias') . $studentname;
                $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);
                $filename = $evidenciadir . '/' . self::shorten_filename($title . '.pdf');
                $filelist[$filename] = [$pdf];
            }
        }
    }

    /**
     * Builds the HTML body for the H5P activity PDF (one per student).
     *
     * Same information as the previous HTML version, but styled for TCPDF:
     * smaller fonts, consistent header rows, page break between attempts,
     * and sanitised HTML for "content" blocks that come from the H5P renderer
     * (some of them include tables/iframes that TCPDF cannot render).
     *
     * @param object $h5p
     * @param object $cm
     * @param object $user
     * @param string $studentname
     * @return string
    */
    private function build_h5p_pdf_body($h5p, $cm, $user, $studentname) {
        global $DB, $CFG, $PAGE;

        $manager = \mod_h5pactivity\local\manager::create_from_instance($h5p);
        $attempts = $manager->get_user_attempts($user->id);
        if (empty($attempts)) {
            return '';
        }

        $renderer = $PAGE->get_renderer('core');

        $methods = \mod_h5pactivity\local\manager::get_grading_methods();
        $grademethodstr = $methods[$h5p->grademethod] ?? '';

        // Calificación final del libro de calificaciones.
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'h5pactivity', 'iteminstance' => $h5p->id]);
        $finalgrade = '';
        if ($gradeitem) {
            $grade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $user->id]);
            if ($grade && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                $finalgrade = round($grade->finalgrade, 2);
            }
        }

        // Fallback: calcular con el puntaje escalado si no hay calificación en el libro.
        if ($finalgrade === '') {
            $scores = $manager->get_users_scaled_score($user->id);
            $score = $scores ? reset($scores) : null;
            if ($score && isset($score->scaled)) {
                $maxgrade = $gradeitem ? (float)$gradeitem->grademax : (float)$h5p->grade;
                $finalgrade = round($maxgrade * (float)$score->scaled, 2);
            }
        }
        $finalgradedisplay = ($finalgrade === '')
            ? get_string('string_no_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias')
            : $finalgrade;

        // Estilos.
        $th  = 'style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;text-align:left;font-size:8pt;"';
        $td  = 'style="border:1px solid #adb5bd;padding:2px;vertical-align:top;font-size:8pt;"';
        $hd  = 'style="background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;"';
        $tbl = 'border="1" cellspacing="0" cellpadding="2" style="border-collapse:collapse;width:100%;font-size:8pt;"';

        // 1) Datos generales del alumno.
        $h  = '<h2 ' . $hd . '>' . s(get_string('h5p_title', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . s($studentname) . '</h2>';
        $h .= '<table ' . $tbl . '>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('quiz_lastname', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($user->lastname) . '</td>'
            . '<th ' . $th . '>' . s(get_string('quiz_firstname', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($user->firstname) . '</td>'
            . '<th ' . $th . '>' . s(get_string('quiz_email', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($user->email) . '</td></tr>';
        $h .= '</table>';

        // 2) Resumen de intentos.
        $h .= '<h2 ' . $hd . '>' . s(get_string('h5p_attempts_summary', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</h2>';
        $attemptcount = count($attempts);
        $h .= '<table ' . $tbl . '>';
        $h .= '<thead><tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('h5p_sharp', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('h5p_date', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('h5p_score', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('h5p_max_score', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('h5p_duration', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('quiz_grade_method', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('string_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '</tr></thead><tbody>';
        $row = 0;
        foreach ($attempts as $attempt) {
            $row++;
            $h .= '<tr nobr="true">';
            $h .= '<td style="text-align:center;">' . $attempt->get_attempt() . '</td>';
            $h .= '<td>' . s(userdate($attempt->get_timecreated())) . '</td>';
            $h .= '<td style="text-align:center;">' . $attempt->get_rawscore() . '</td>';
            $h .= '<td style="text-align:center;">' . $attempt->get_maxscore() . '</td>';
            $h .= '<td style="text-align:center;">' . s(format_time($attempt->get_duration())) . '</td>';
            if ($row === 1) {
                $h .= '<td rowspan="' . $attemptcount . '" style="text-align:center;">' . s($grademethodstr) . '</td>';
                $h .= '<td rowspan="' . $attemptcount . '" style="text-align:center;">' . s($finalgradedisplay) . '</td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';

        // 3) Detalle de respuestas por intento (una página por intento a partir del 2º).
        $firstattempt = true;
        foreach ($attempts as $attempt) {
            $break = $firstattempt ? '' : 'page-break-before:always;';
            $firstattempt = false;

            $h .= '<h2 style="' . $break . 'background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;">'
                . s(get_string('h5p_attempt', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . ' ' . $attempt->get_attempt() . '</h2>';

            $results = $attempt->get_results();
            if (empty($results)) {
                $h .= '<p><em>' . s(get_string('h5p_no_answer', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</em></p>';
                continue;
            }

            $printed = false;
            foreach ($results as $result) {
                $outputresult = \mod_h5pactivity\output\result::create_from_record($result);
                if (!$outputresult) {
                    continue;
                }
                $printed = true;
                $data = $outputresult->export_for_template($renderer);

                if (!empty($data->description)) {
                    $h .= '<p style="font-size:9pt;"><strong>' . s($data->description) . '</strong></p>';
                }

                if (!empty($data->hasoptions) && !empty($data->options)) {
                    $h .= '<table ' . $tbl . '>';
                    $h .= '<thead><tr nobr="true">'
                        . '<th ' . $th . '>' . s($data->optionslabel ?? '') . '</th>'
                        . '<th ' . $th . '>' . s($data->correctlabel ?? '') . '</th>'
                        . '<th ' . $th . '>' . s($data->answerlabel ?? '') . '</th>'
                        . '</tr></thead><tbody>';
                    foreach ($data->options as $option) {
                        $h .= '<tr nobr="true">';
                        $h .= '<td>' . s($option->description ?? '') . '</td>';
                        $h .= '<td>' . s($option->correctanswer->answer ?? '') . '</td>';
                        $h .= '<td>' . $this->h5p_answer_html($option->useranswer ?? null) . '</td>';
                        $h .= '</tr>';
                    }
                    if (!empty($data->score)) {
                        $h .= '<tr><td colspan="3" style="background:#f9f9f9;font-size:8pt;"><strong>'
                            . s(get_string('score', 'mod_h5pactivity') . ': ' . $data->score)
                            . '</strong></td></tr>';
                    }
                    $h .= '</tbody></table>';
                } else if (!empty($data->content)) {
                    // "content" puede traer tablas/iframes del renderer: sanitizar.
                    $h .= $this->h5p_sanitize_content($data->content);
                }
            }

            // Resultados tipo "compound": no hay detalle, mostrar al menos el puntaje.
            if (!$printed) {
                $h .= '<p>' . s(get_string('string_points', 'local_centro_de_descarga_de_portafolio_de_evidencias'))
                    . ' ' . $attempt->get_rawscore() . ' / ' . $attempt->get_maxscore() . '</p>';
            }
        }

        return $h;
    }

    /**
     * Sanitizes HTML "content" coming from the H5P renderer so it can be fed
     * to TCPDF without breaking the layout.
     *
     * - Removes iframes, videos, audios and scripts (TCPDF cannot render them).
     * - Replaces them with a note + a plain link if there was a src.
     * - Removes inline event handlers (onclick, onload, ...).
     * - Normalises entities and strips Bootstrap classes that add no value.
     *
     * @param string $content
     * @return string
     */
    private function h5p_sanitize_content($content) {
        if ($content === null || $content === '') {
            return '';
        }

        // 1) Reemplazar iframes por un aviso + link (si tiene src).
        $content = preg_replace_callback(
            '/<iframe[^>]*?src="([^"]+)"[^>]*>.*?<\/iframe>/is',
            function ($m) {
                $url = s($m[1]);
                return '<p style="font-size:8pt;"><strong>'
                    . s(get_string('h5p_embedded_link', 'local_centro_de_descarga_de_portafolio_de_evidencias'))
                    . '</strong> <a href="' . $url . '">' . $url . '</a></p>';
            },
            $content
        );
        $content = preg_replace('/<iframe[^>]*>.*?<\/iframe>/is', '', $content);

        // 2) Video/audio: dejar solo el src como link.
        $content = preg_replace_callback(
            '/<(video|audio)[^>]*?src="([^"]+)"[^>]*>.*?<\/\1>/is',
            function ($m) {
                $url = s($m[2]);
                return '<p style="font-size:8pt;"><a href="' . $url . '">' . $url . '</a></p>';
            },
            $content
        );
        $content = preg_replace('/<(video|audio)[^>]*>.*?<\/\1>/is', '', $content);

        // 3) Scripts y styles inline (no aportan al PDF).
        $content = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $content);
        $content = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $content);

        // 4) Quitar manejadores de eventos inline (onclick, onload, ...).
        $content = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $content);
        $content = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', $content);

        // 5) Quitar clases de Bootstrap que no aplican en el PDF.
        $content = preg_replace('/\sclass="[^"]*"/i', '', $content);

        // Nota: no decodificamos entidades porque TCPDF las maneja; decodificar
        // podría reintroducir etiquetas que el renderer había escapado por seguridad.
        return $content;
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
            return '<span style="color:#198754;">[OK] ' . $answer . '</span>';
        }
        if (!empty($useranswer->incorrect) || !empty($useranswer->fail)) {
            return '<span style="color:#dc3545;">[X] ' . $answer . '</span>';
        }
        return $answer;
    }
}