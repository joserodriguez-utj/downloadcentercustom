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
            $html = $this->build_workshop_html($workshop, $cm, $user, $submission, $studentname);
            if ($html) {
                $html = self::convert_content_to_html_doc(
                    get_string('workshop_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . $studentname, $html);
                $filename = $evidenciadir . '/' . self::shorten_filename(
                    get_string('workshop_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . $studentname . '.html');
                $filelist[$filename] = [$html];
            }

            // Archivos adjuntos de la entrega.
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
     * Build HTML with workshop submission info for one student.
     *
     * @param object $workshop
     * @param object $cm
     * @param object $user
     * @param object $submission
     * @param string $studentname
     * @return string
     */
    private function build_workshop_html($workshop, $cm, $user, $submission, $studentname) {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        // === OBTENER ESTRATEGIA DE CALIFICACIÓN ===
        $strategy = $workshop->strategy ?? 'accumulative';
        $strategyname = '';
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

        // === 1. FUENTE PRINCIPAL: workshop_grades (promedio de evaluaciones) ===
        $finalgrade = '';
        $assessmentgrade = $DB->get_record_sql(
            "SELECT AVG(wg.grade) as avg_grade 
            FROM {workshop_grades} wg
            JOIN {workshop_assessments} wa ON wa.id = wg.assessmentid
            WHERE wa.submissionid = ?",
            [$submission->id]
        );
        if ($assessmentgrade && $assessmentgrade->avg_grade !== null) {
            $finalgrade = round($assessmentgrade->avg_grade, 2);
        }

        // === 2. FALLBACK: workshop_submissions.grade ===
        if ($finalgrade === '') {
            if (isset($submission->grade) && $submission->grade !== null && $submission->grade !== '') {
                $finalgrade = round((float)$submission->grade, 2);
            }
        }

        // === 3. FALLBACK FINAL: grade_items + grade_grades ===
        if ($finalgrade === '') {
            $gradeitems = $DB->get_records('grade_items', [
                'itemtype' => 'mod',
                'itemmodule' => 'workshop',
                'iteminstance' => $workshop->id
            ]);

            if ($gradeitems) {
                foreach ($gradeitems as $gradeitem) {
                    $grade = $DB->get_record('grade_grades', [
                        'itemid' => $gradeitem->id,
                        'userid' => $user->id
                    ]);
                    if ($grade && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                        $finalgrade = round($grade->finalgrade, 2);
                        break;
                    }
                }
            }
        }

        // === OBTENER EVALUACIONES RECIBIDAS (similar a "ratings" en forum) ===
        $numassessments = $DB->count_records('workshop_assessments', ['submissionid' => $submission->id]);

        // === CONSTRUIR HTML ===
        $h = '<h2>' . get_string('workshop_workshop_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . s($studentname) . ' — ' . s($workshop->name) . '</h2>';

        // Tabla resumen (similar a la de forum).
        $h .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;margin-bottom:15px;">';
        $h .= '<tr style="background:#f2f2f2;">';
        $h .= '<th>' . get_string('workshop_student', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>';
        $h .= '<th>' . get_string('workshop_title', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>';
        $h .= '<th>' . get_string('workshop_submitted', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>';
        $h .= '<th>' . get_string('workshop_grading_method', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>';
        $h .= '<th>' . get_string('workshop_number_assessments', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>';
        $h .= '<th>' . get_string('workshop_final_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>';
        $h .= '</tr>';

        $h .= '<tr>';
        $h .= '<td style="font-weight:bold;">' . htmlspecialchars($studentname) . '</td>';
        $h .= '<td>' . htmlspecialchars($submission->title ?? '') . '</td>';
        $h .= '<td style="text-align:center;">' . userdate($submission->timecreated) . '</td>';
        $h .= '<td style="text-align:center;">' . s($strategyname) . '</td>';
        $h .= '<td style="text-align:center;">' . $numassessments . '</td>';
        $h .= '<td style="text-align:center;">' . ($finalgrade !== '' ? $finalgrade : '-') . '</td>';
        $h .= '</tr>';
        $h .= '</table>';

        // === DETALLE: Contenido de la entrega ===
        $content = $submission->content ?? '';
        if (!empty(trim($content))) {
            $h .= '<h3>' . get_string('workshop_content', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</h3>';
            $content = file_rewrite_pluginfile_urls(
                $content, 'pluginfile.php', $cm->id, 'mod_workshop', 'submission_content', $submission->id);
            $h .= '<div style="border:1px solid #ccc;border-radius:4px;padding:10px;margin-bottom:15px;">';
            $h .= format_text($content, $submission->contentformat ?? FORMAT_HTML);
            $h .= '</div>';
        }

        // === DETALLE: Feedback del autor ===
        $feedbackauthor = $submission->feedbackauthor ?? '';
        if (!empty(trim($feedbackauthor))) {
            $h .= '<h3>' . get_string('workshop_feedback', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</h3>';
            $h .= '<div style="border:1px solid #ccc;border-radius:4px;padding:10px;background:#f9f9f9;">';
            $h .= format_text($feedbackauthor, $submission->feedbackauthorformat ?? FORMAT_HTML);
            $h .= '</div>';
        }

        // === DETALLE: Evaluaciones recibidas (similar a posts en forum) ===
        $assessments = $DB->get_records('workshop_assessments', ['submissionid' => $submission->id], 'timecreated ASC');
        if (!empty($assessments)) {
            $h .= '<h3>' . get_string('workshop_assessments_received', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</h3>';
            foreach ($assessments as $assessment) {
                $reviewer = $DB->get_record('user', ['id' => $assessment->reviewerid]);
                $reviewername = $reviewer ? fullname($reviewer) : get_string('string_unknown', 'local_centro_de_descarga_de_portafolio_de_evidencias');

                $h .= '<div style="border:1px solid #0d6efd;border-radius:4px;padding:8px;margin-bottom:10px;">';
                $h .= '<div><b>' . get_string('workshop_reviewer', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</b> ' . s($reviewername) . '</div>';
                // === NUEVO: Mostrar fecha de la evaluación ===
                $h .= '<div><b>' . get_string('workshop_assessment_date', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</b> ' . userdate($assessment->timecreated) . '</div>';
                $h .= '<div><b>' . get_string('workshop_assessment_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</b> ' . ($assessment->grade !== null ? round($assessment->grade, 2) : '-') . '</div>';
                if (!empty($assessment->feedbackauthor)) {
                    $h .= '<div><b>' . get_string('workshop_feedback', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</b> ' . format_text($assessment->feedbackauthor, FORMAT_HTML) . '</div>';
                }
                $h .= '</div>';
            }
        }

        return $h;
    }
}