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
 * Trait con la lógica de descarga de actividades Workshop (taller).
 *
 * @package       local_centro_de_descarga_de_portafolio_de_evidencias
 * @author        Simeon Naydenov (moniNaydenov@gmail.com)
 * @copyright     2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */

defined('MOODLE_INTERNAL') || die();

trait local_centro_de_descarga_de_portafolio_de_evidencias_workshop_trait {

    /**
     * Handle Workshop module.
     *
     * @param mixed $resource The resource being handled.
     * @param string $resdir The directory where results are saved.
     * @param array $filelist The array of files to be included in the ZIP.
     * @param int|null $groupid Group ID for filtering students.
     * @return void
     */
    private function handle_workshop($resource, $resdir, &$filelist, $groupid = null) {
        $this->pdfactivityname = $resource->name;
        global $CFG, $DB;
        $context = $resource->context;

        if (!has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadAssignments', $context->get_course_context())) {
            return;
        }

        $workshop = $DB->get_record('workshop', ['id' => $resource->instanceid], '*', MUST_EXIST);
        $cm = $resource->cm;

        // Entregas.
        $submissions = $DB->get_records('workshop_submissions',
            ['workshopid' => $workshop->id, 'example' => 0], 'timecreated ASC');

        // Filtrado por grupo.
        if ($this->onlyungrouped) {
            $allgroupmemberids = $DB->get_fieldset_sql(
                "SELECT DISTINCT gm.userid FROM {groups_members} gm
                  JOIN {groups} g ON g.id = gm.groupid
                 WHERE g.courseid = ?", [$this->course->id]
            );
            $submissions = array_filter($submissions, function($sub) use ($allgroupmemberids) {
                return !in_array($sub->authorid, $allgroupmemberids);
            });
        } else if ($groupid) {
            $members = groups_get_members($groupid);
            $memberids = $members ? array_keys($members) : [];
            $submissions = array_filter($submissions, function($sub) use ($memberids) {
                return in_array($sub->authorid, $memberids);
            });
        }
        if ($this->portfolio_userid !== null) {
            // Portafolio: solo evidencias del estudiante indicado.
            $submissions = array_filter($submissions, function($sub) {
                return (int)$sub->authorid === (int)$this->portfolio_userid;
            });
        }

        if (empty($submissions)) {
            return;
        }

        $evidenciadir = $resdir . '/Evidencias';
        $filelist[$evidenciadir] = null;

        $fs = get_file_storage();

        foreach ($submissions as $submission) {
            $user = $DB->get_record('user', ['id' => $submission->authorid]);
            if (!$user) {
                continue;
            }
            $studentname = fullname($user);
            $body = $this->build_workshop_pdf_body($workshop, $cm, $user, $submission, $studentname, $evidenciadir, $filelist);
            if ($body) {
                $title = get_string('workshop_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . $studentname;
                $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);
                $filename = $evidenciadir . '/' . self::shorten_filename($title . '.pdf');
                $filelist[$filename] = [$pdf];
            }

            // Archivos adjuntos de la entrega (sin cambios).
            $attachmentfiles = $fs->get_area_files($context->id, 'mod_workshop', 'submission_attachment', $submission->id, 'id', false);
            foreach ($attachmentfiles as $file) {
                if ($file->get_filesize() == 0) {
                    continue;
                }
                $fname = $file->get_filename();
                $filelist[$evidenciadir . '/' . self::shorten_filename($studentname . ' - ' . $fname)] = $file;
            }
        }
    }

    /**
     * Builds the HTML body for the workshop PDF (one per submission).
     *
     * Same information as the previous HTML version, but styled for TCPDF:
     * smaller fonts, tables with consistent th/td, and page break between
     * sections (content / feedback / assessments) when needed.
     *
     * @param object $workshop
     * @param object $cm
     * @param object $user
     * @param object $submission
     * @param string $studentname
     * @return string
     */
    private function build_workshop_pdf_body($workshop, $cm, $user, $submission, $studentname, $evidenciadir, array &$filelist) {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/filelib.php');

        $context = \context_module::instance($cm->id);
        $contextid = $context->id;
        $mediadir = $evidenciadir . '/Recurso';

        // === ESTRATEGIA DE CALIFICACIÓN ===
        $strategy = $workshop->strategy ?? 'accumulative';
        switch ($strategy) {
            case 'accumulative':
                $strategyname = get_string('workshop_accumulatuve', 'local_centro_de_descarga_de_portafolio_de_evidencias');
                break;
            case 'rubric':
                $strategyname = get_string('workshop_rubric', 'local_centro_de_descarga_de_portafolio_de_evidencias');
                break;
            case 'numerrors':
                $strategyname = get_string('workshop_numerrors', 'local_centro_de_descarga_de_portafolio_de_evidencias');
                break;
            case 'comments':
                $strategyname = get_string('workshop_comments', 'local_centro_de_descarga_de_portafolio_de_evidencias');
                break;
            default:
                $strategyname = $strategy;
        }

        // === CALIFICACIÓN FINAL ===
        $finalgrade = '';

        // 1) Promedio de workshop_grades.
        $assessmentgrade = $DB->get_record_sql(
            "SELECT AVG(wg.grade) AS avg_grade
            FROM {workshop_grades} wg
            JOIN {workshop_assessments} wa ON wa.id = wg.assessmentid
            WHERE wa.submissionid = ?",
            [$submission->id]
        );
        if ($assessmentgrade && $assessmentgrade->avg_grade !== null) {
            $finalgrade = round($assessmentgrade->avg_grade, 2);
        }

        // 2) Fallback: workshop_submissions.grade.
        if ($finalgrade === '') {
            if (isset($submission->grade) && $submission->grade !== null && $submission->grade !== '') {
                $finalgrade = round((float)$submission->grade, 2);
            }
        }

        // 3) Fallback final: grade_items + grade_grades.
        if ($finalgrade === '') {
            $gradeitems = $DB->get_records('grade_items', [
                'itemtype' => 'mod',
                'itemmodule' => 'workshop',
                'iteminstance' => $workshop->id,
            ]);
            if ($gradeitems) {
                foreach ($gradeitems as $gradeitem) {
                    $grade = $DB->get_record('grade_grades', [
                        'itemid' => $gradeitem->id,
                        'userid' => $user->id,
                    ]);
                    if ($grade && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                        $finalgrade = round($grade->finalgrade, 2);
                        break;
                    }
                }
            }
        }
        $finalgradedisplay = ($finalgrade === '') ? '-' : $finalgrade;

        $numassessments = $DB->count_records('workshop_assessments', ['submissionid' => $submission->id]);

        // Estilos.
        $th  = 'style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;text-align:left;font-size:8pt;"';
        $td  = 'style="border:1px solid #adb5bd;padding:2px;vertical-align:top;font-size:8pt;"';
        $hd  = 'style="background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;"';
        $tbl = 'border="1" cellspacing="0" cellpadding="2" style="border-collapse:collapse;width:100%;font-size:8pt;"';

        // 1) Datos generales del alumno.
        $h  = '<h2 ' . $hd . '>' . s(get_string('workshop_workshop_results', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . s($studentname) . '</h2>';
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

        // 2) Resumen de la entrega.
        $h .= '<table ' . $tbl . '>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('workshop_title', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($submission->title ?? '') . '</td>'
            . '<th ' . $th . '>' . s(get_string('workshop_submitted', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s(userdate($submission->timecreated)) . '</td>'
            . '</tr>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('workshop_grading_method', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($strategyname) . '</td>'
            . '<th ' . $th . '>' . s(get_string('workshop_number_assessments', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . $numassessments . '</td>'
            . '</tr>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('workshop_final_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($finalgradedisplay) . '</td>'
            . '<th ' . $th . '></th>'
            . '<td ' . $td . '></td>'
            . '</tr>';
        $h .= '</table>';

        // 3) Contenido de la entrega.
        $content = $submission->content ?? '';
        if (!empty(trim($content))) {
            $h .= '<h2 ' . $hd . '>' . s(get_string('workshop_content', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</h2>';
            $content = file_rewrite_pluginfile_urls(
                $content, 'pluginfile.php', $contextid, 'mod_workshop', 'submission_content', $submission->id
            );
            $this->add_embedded_files_from_html($content, $mediadir, $filelist);
            $content = $this->clean_pluginfile_leftovers($content);
            $h .= '<div style="border:1px solid #adb5bd;padding:4px;font-size:8pt;">';
            $h .= format_text($content, $submission->contentformat ?? FORMAT_HTML, ['context' => $context]);
            $h .= '</div>';
        }

        // 4) Feedback del autor.
        $feedbackauthor = $submission->feedbackauthor ?? '';
        if (!empty(trim($feedbackauthor))) {
            $h .= '<h2 ' . $hd . '>' . s(get_string('workshop_feedback', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</h2>';
            $h .= '<div style="border:1px solid #adb5bd;padding:4px;background:#f9f9f9;font-size:8pt;">';
            $h .= format_text($feedbackauthor, $submission->feedbackauthorformat ?? FORMAT_HTML, ['context' => $context]);
            $h .= '</div>';
        }

        // 5) Evaluaciones recibidas.
        $assessments = $DB->get_records('workshop_assessments', ['submissionid' => $submission->id], 'timecreated ASC');
        if (!empty($assessments)) {
            $h .= '<h2 ' . $hd . '>' . s(get_string('workshop_assessments_received', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</h2>';
            $first = true;
            foreach ($assessments as $assessment) {
                $break = $first ? '' : 'page-break-before:always;';
                $first = false;

                $reviewer = $DB->get_record('user', ['id' => $assessment->reviewerid]);
                $reviewername = $reviewer
                    ? fullname($reviewer)
                    : get_string('string_unknown', 'local_centro_de_descarga_de_portafolio_de_evidencias');

                $h .= '<h3 style="' . $break . 'background-color:#dbe4f0;border:1px solid #8a99b0;padding:3px;font-size:9pt;font-weight:bold;margin-top:8px;">'
                    . s(get_string('workshop_reviewer', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . ' ' . s($reviewername) . '</h3>';

                $h .= '<table ' . $tbl . '>';
                $h .= '<tr nobr="true">'
                    . '<th ' . $th . '>' . s(get_string('workshop_assessment_date', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                    . '<td ' . $td . '>' . s(userdate($assessment->timecreated)) . '</td>'
                    . '<th ' . $th . '>' . s(get_string('workshop_assessment_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                    . '<td ' . $td . '>' . ($assessment->grade !== null ? round($assessment->grade, 2) : '-') . '</td>'
                    . '</tr>';
                if (!empty($assessment->feedbackauthor)) {
                    $feedback = format_text($assessment->feedbackauthor, FORMAT_HTML, ['context' => $context]);
                    $h .= '<tr><th ' . $th . '>' . s(get_string('workshop_feedback', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                        . '<td ' . $td . ' colspan="3">' . $feedback . '</td></tr>';
                }
                $h .= '</table>';
            }
        }

        return $h;
    }
}