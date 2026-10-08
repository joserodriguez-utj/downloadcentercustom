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
 * Trait con la lógica de descarga de actividades SCORM.
 *
 * @package       local_downloadcentercustom
 * @author        Simeon Naydenov (moniNaydenov@gmail.com)
 * @copyright     2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */

defined('MOODLE_INTERNAL') || die();

trait local_downloadcentercustom_scorm_trait {

    /**
     * Handle SCORM module.
     *
     * @param mixed $resource The resource being handled.
     * @param string $resdir The directory where results are saved.
     * @param array $filelist The array of files to be included in the ZIP.
     * @param int|null $groupid Group ID for filtering students.
     * @return void
     */
    private function handle_scorm($resource, $resdir, &$filelist, $groupid = null) {
        global $CFG, $DB;

        if (!has_capability('local/downloadcentercustom:downloadAssignments', $resource->context->get_course_context())) {
            return;
        }

        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $scorm = $DB->get_record('scorm', ['id' => $resource->instanceid], '*', MUST_EXIST);

        $users = get_enrolled_users($resource->context, 'mod/scorm:savetrack', $groupid, 'u.*', 'u.lastname');

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
            $html = $this->build_scorm_html($scorm, $user, $studentname);
            if ($html) {
                $html = self::convert_content_to_html_doc(
                    get_string('scorm_results', 'local_downloadcentercustom') . $studentname, $html);
                $filename = $evidenciadir . '/' . self::shorten_filename(
                    get_string('scorm_results', 'local_downloadcentercustom') . $studentname . '.html');
                $filelist[$filename] = [$html];
            }
        }
    }

    /**
     * Build HTML table with SCORM attempts/results for one student.
     *
     * @param object $scorm The SCORM instance.
     * @param object $user The student user.
     * @param string $studentname Full name of the student.
     * @return string HTML content (empty if the student has no attempts).
     */
    private function build_scorm_html($scorm, $user, $studentname) {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        // Números de intento del alumno.
        $attempts = scorm_get_all_attempts($scorm->id, $user->id);
        if (empty($attempts)) {
            return '';
        }

        // Localizar un SCO que se pueda lanzar para leer su tracking.
        $scoid = 0;
        $scoes = $DB->get_records('scorm_scoes', ['scorm' => $scorm->id], 'sortorder, id');
        foreach ($scoes as $sco) {
            if (!empty($sco->launch)) {
                $scoid = (int)$sco->id;
                break;
            }
        }

        // Método de calificación y método de calificación de intentos.
        $grademethods = scorm_get_grade_method_array();
        $grademethodstr = $grademethods[$scorm->grademethod] ?? '';
        $whatgrades = scorm_get_what_grade_array();
        $whatgradestr = $whatgrades[$scorm->whatgrade] ?? '';

        // Calificación final del libro de calificaciones.
        $finalgrade = '';
        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'scorm', 'iteminstance' => $scorm->id]);
        if ($gradeitem) {
            $grade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $user->id]);
            if ($grade && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                $finalgrade = round($grade->finalgrade, 2);
            }
        }

        // Fallback: cálculo de la calificación del propio módulo SCORM.
        if ($finalgrade === '') {
            $calc = scorm_grade_user($scorm, $user->id);
            if ($calc !== null && $calc !== '') {
                $finalgrade = round((float)$calc, 2);
            }
        }

        $h = '<h2>' . get_string('scorm_scorm_results', 'local_downloadcentercustom') . s($studentname)
            . ' — ' . s($scorm->name) . '</h2>';
        $h .= '<p><strong>' . get_string('scorm_grade_method', 'local_downloadcentercustom') . ':</strong> '
            . s($grademethodstr)
            . ' &nbsp;|&nbsp; <strong>' . get_string('scorm_what_grade', 'local_downloadcentercustom') . ':</strong> '
            . s($whatgradestr)
            . ' &nbsp;|&nbsp; <strong>' . get_string('scorm_max_score', 'local_downloadcentercustom') . ':</strong> '
            . s($scorm->maxgrade) . '</p>';

        $h .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;margin-bottom:15px;">';
        $h .= '<tr style="background:#f2f2f2;">';
        $h .= '<th>' . get_string('scorm_attempt', 'local_downloadcentercustom') . '</th>';
        $h .= '<th>' . get_string('scorm_status', 'local_downloadcentercustom') . '</th>';
        $h .= '<th>' . get_string('scorm_score', 'local_downloadcentercustom') . '</th>';
        $h .= '<th>' . get_string('scorm_time', 'local_downloadcentercustom') . '</th>';
        $h .= '<th>' . get_string('scorm_start', 'local_downloadcentercustom') . '</th>';
        $h .= '<th>' . get_string('scorm_finish', 'local_downloadcentercustom') . '</th>';
        $h .= '<th>' . get_string('scorm_grade_method', 'local_downloadcentercustom') . '</th>';
        $h .= '<th>' . get_string('scorm_final_grade', 'local_downloadcentercustom') . '</th>';
        $h .= '</tr>';

        $numattempts = count($attempts);
        $row = 0;
        foreach ($attempts as $attemptnum) {
            $status = '-';
            $score = '-';
            $totaltime = '-';
            if ($scoid && ($track = scorm_get_tracks($scoid, $user->id, $attemptnum))) {
                $status = !empty($track->status) ? $track->status : '-';
                $score = ($track->score_raw !== '') ? $track->score_raw : '-';
                $totaltime = !empty($track->total_time) ? $track->total_time : '-';
            }

            $start = '-';
            $finish = '-';
            if ($scoid) {
                $runtime = scorm_get_sco_runtime($scorm->id, $scoid, $user->id, $attemptnum);
                if (!empty($runtime->start)) {
                    $start = userdate($runtime->start, '%d de %B de %Y %H:%M');
                }
                if (!empty($runtime->finish)) {
                    $finish = userdate($runtime->finish, '%d de %B de %Y %H:%M');
                }
            }

            $h .= '<tr>';
            $h .= '<td style="text-align:center;">' . ((int)$attemptnum) . '</td>';
            $h .= '<td style="text-align:center;">' . s($this->scorm_status_label($status)) . '</td>';
            $h .= '<td style="text-align:center;">' . s($score) . '</td>';
            $h .= '<td style="text-align:center;">' . s($this->scorm_format_time($totaltime)) . '</td>';
            $h .= '<td style="text-align:center;">' . s($start) . '</td>';
            $h .= '<td style="text-align:center;">' . s($finish) . '</td>';
            if ($row === 0) {
                $h .= '<td style="text-align:center;vertical-align:middle;" rowspan="' . $numattempts . '">' . s($grademethodstr) . '</td>';
                $h .= '<td style="text-align:center;vertical-align:middle;" rowspan="' . $numattempts . '">' . ($finalgrade !== '' ? $finalgrade : '-') . '</td>';
            }
            $h .= '</tr>';
            $row++;
        }
        $h .= '</table>';

        return $h;
    }

    /**
     * Translate a SCORM CMI status value into a human readable, localized label.
     *
     * @param string $status Raw SCORM status (e.g. completed, passed, failed).
     * @return string Localized label, or the original value if unknown.
     */
    private function scorm_status_label($status) {
        $map = [
            'passed' => 'scorm_status_passed',
            'completed' => 'scorm_status_completed',
            'failed' => 'scorm_status_failed',
            'incomplete' => 'scorm_status_incomplete',
            'browsed' => 'scorm_status_browsed',
            'notattempted' => 'scorm_status_notattempted',
            'not attempted' => 'scorm_status_notattempted',
            'unknown' => 'scorm_status_unknown',
        ];
        $key = strtolower(trim((string)$status));
        if (isset($map[$key])) {
            return get_string($map[$key], 'local_downloadcentercustom');
        }
        return (string)$status;
    }

    /**
     * Formats a raw SCORM time value using Moodle's human readable format (format_time).
     *
     * @param string $raw Raw time (SCORM 1.2 "HHHH:MM:SS.ss" or SCORM 2004 "PT#H#M#S").
     * @return string Formatted time, or the original value if it could not be parsed.
     */
    private function scorm_format_time($raw) {
        $seconds = $this->scorm_time_to_seconds($raw);
        if ($seconds === null) {
            return (string)$raw;
        }
        return format_time($seconds);
    }

    /**
     * Converts a SCORM time value to seconds.
     *
     * Supports the SCORM 1.2 format (HHHH:MM:SS.ss) and the SCORM 2004 ISO 8601
     * duration format (e.g. PT1H30M10S).
     *
     * @param string $raw Raw time value.
     * @return int|null Seconds, or null if the value could not be parsed.
     */
    private function scorm_time_to_seconds($raw) {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }

        // SCORM 1.2: HHHH:MM:SS or HHHH:MM:SS.ss.
        if (preg_match('/^(\d+):(\d{2}):(\d{2})(?:\.\d+)?$/', $raw, $m)) {
            return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + ((int)$m[3]);
        }

        // SCORM 2004 / ISO 8601 duration: PnDTnHnMnS.
        if (preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/i', $raw, $m)) {
            $days = ($m[1] ?? '') !== '' ? (int)$m[1] : 0;
            $hours = ($m[2] ?? '') !== '' ? (int)$m[2] : 0;
            $minutes = ($m[3] ?? '') !== '' ? (int)$m[3] : 0;
            $seconds = ($m[4] ?? '') !== '' ? (float)$m[4] : 0;
            return (int)round($days * 86400 + $hours * 3600 + $minutes * 60 + $seconds);
        }

        return null;
    }

}
