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
 * Version metadata for the repository_pluginname plugin.
 *
 * @package   repository_pluginname
 * @copyright 2026, author_fullname <author_link>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

trait local_downloadcentercustom_assign_trait {

    /**
     * Handles the mod type assign files.
     *
     * @param mixed $resource The resource object being handled.
     * @param string $resdir The directory where the resource files are saved at the end in the ZIP.
     * @param array $filelist Array of files to be included in the ZIP with its data.
     * @return void
     */

    private function handle_assign($resource, $resdir, &$filelist, $groupid = null, $fileprefix = '', $includefeedback = true) {
        global $CFG, $DB, $USER;
        $context = $resource->context;
        // Portón: si no tiene permiso, no procesa nada de esta tarea.
        if (!has_capability('local/downloadcentercustom:downloadAssignments', $context->get_course_context())) {
            return;
        }
        $fs = get_file_storage();
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/externallib.php');

        // Precargar datos de rúbrica si la actividad la tiene.
        $hasrubric = false;
        $rubriccriteria = [];
        $rubricareaid = null;
        $area = $DB->get_record_sql(
            "SELECT gra.id FROM {course_modules} cm
              JOIN {context} con ON cm.id = con.instanceid AND con.contextlevel = ?
              JOIN {grading_areas} gra ON gra.contextid = con.id
             WHERE cm.id = ? AND gra.activemethod = ?",
            [CONTEXT_MODULE, $resource->cm->id, 'rubric']
        );
        if ($area) {
            $hasrubric = true;
            $rubricareaid = $area->id;
            $criteria = $DB->get_records_sql(
                "SELECT crit.id, crit.description, MAX(lev.score) AS max_score
                   FROM {grading_definitions} def
              LEFT JOIN {gradingform_rubric_criteria} crit ON crit.definitionid = def.id
              LEFT JOIN {gradingform_rubric_levels} lev ON lev.criterionid = crit.id
                  WHERE def.areaid = ?
               GROUP BY crit.id, crit.description, crit.sortorder
               ORDER BY crit.sortorder",
                [$rubricareaid]
            );
            foreach ($criteria as $c) {
                $rubriccriteria[$c->id] = $c;
            }
        }

        $includeinstructions = $this->downloadoptions['includeinstructions'] ?? true;
        $includeresources = $this->downloadoptions['includeresources'] ?? true;
        $onlytasks = $this->downloadoptions['onlytasks'] ?? false;

        // instrucciones/ - contenido HTML + archivos del area intro.
        if ($includeinstructions) {
            $instruccionesdir = $resdir . '/Instrucciones';
            $filelist[$instruccionesdir] = null;
            $introcontent = $resource->resource->intro;
            if (!empty(trim($introcontent))) {
                $introcontent = str_replace('@@PLUGINFILE@@', '.', $introcontent);
                $introcontent = self::convert_content_to_html_doc(
                    get_string('instructions', 'local_downloadcentercustom'),
                    $introcontent
                );
                $filelist[$instruccionesdir . '/instrucciones.html'] = [$introcontent];
            }
            $introfiles = $fs->get_area_files($context->id, 'mod_assign', 'intro', 0, 'id', false);
            foreach ($introfiles as $file) {
                if ($file->get_filesize() == 0) { continue; }
                $fname = $file->get_filename();
                // Solo archivos referenciados en el HTML; huerfanos se omiten.
                if (strpos($introcontent ?? '', $fname) !== false) {
                    $filelist[$instruccionesdir . '/' . self::shorten_filename($fname)] = $file;
                }
            }
        }
        // Archivos adjuntos de la descripción van a recursos/.
        if ($includeresources) {
            $filelist[$resdir . '/Recursos'] = null;
            $fsfiles = $fs->get_area_files($context->id, 'mod_assign', 'introattachment', 0, 'id', false);
            foreach ($fsfiles as $file) {
                if ($file->get_filesize() == 0) {
                    continue;
                }
                $filelist[$resdir . '/Recursos' . $file->get_filepath() . self::shorten_filename($file->get_filename())] = $file;
            }
        }

        $submissionsstr = get_string('gradeitem:submissions', 'assign');
        $assign = new assign($context, null, null);
        $assignplugins = $assign->get_submission_plugins();
        $feedbackplugins = $assign->get_feedback_plugins();

        $params = ['assignment' => $resource->instanceid];
        $submissions = $DB->get_records('assign_submission', $params, 'attemptnumber ASC');
        if ($this->onlyungrouped) {
            // Solo entregas de usuarios que NO pertenecen a ningún grupo del curso.
            $allgroupmemberids = $DB->get_fieldset_sql(
                "SELECT DISTINCT gm.userid FROM {groups_members} gm
                  JOIN {groups} g ON g.id = gm.groupid
                 WHERE g.courseid = ?", [$this->course->id]
            );
            $submissions = array_filter($submissions, function($sub) use ($allgroupmemberids) {
                return $sub->userid != 0 && !in_array($sub->userid, $allgroupmemberids);
            });
        } else if ($groupid) {
            $members = groups_get_members($groupid);
            $memberids = $members ? array_keys($members) : [];
            $submissions = array_filter($submissions, function($sub) use ($memberids) {
                return $sub->userid != 0 && in_array($sub->userid, $memberids);
            });
        }
        $evidenciadir = $resdir . '/Evidencias';
        $filelist[$evidenciadir] = null;
        $soloevidencias = $onlytasks && !$includefeedback && !$includeinstructions && !$includeresources;
        foreach ($submissions as $submission) {
            $user = null;
            $group = null;
            if ($submission->userid != 0) {
                $user = $DB->get_record('user', ['id' => $submission->userid]);
                $fullname = $soloevidencias ? $evidenciadir : $evidenciadir . '/' . self::shorten_filename(fullname($user));
            } else if ($submission->groupid != 0) {
                $group = $DB->get_record('groups', ['id' => $submission->groupid]);
                $groupname = get_string('group', 'group') . ': ' . $group->name;
                $fullname = $soloevidencias ? $evidenciadir : $evidenciadir . '/' . self::shorten_filename($groupname);
            } else {
                $groupname = get_string('group', 'group') . ': ' . get_string('defaultteam', 'assign');
                $fullname = $soloevidencias ? $evidenciadir : $evidenciadir . '/' . self::shorten_filename($groupname);
            }

            // Submission!
            foreach ($assignplugins as $assignplugin) {
                if (!$assignplugin->is_enabled() || !$assignplugin->is_visible()) {
                    continue;
                }

                // Subtype is 'assignsubmission', type is currently 'file' or 'onlinetext'.
                $component = $assignplugin->get_subtype() . '_' . $assignplugin->get_type();
                $fileareas = $assignplugin->get_file_areas();
                foreach ($fileareas as $filearea => $name) {
                    $areafiles = $fs->get_area_files(
                        $context->id,
                        $component,
                        $filearea,
                        $submission->id,
                        'itemid, filepath, filename',
                        false
                    );
                    if ($areafiles) {
                        foreach ($areafiles as $file) {
                            $originalname = $file->get_filename();
                            $studentname = $user ? fullname($user) : 'desconocido';
                            $newname = $fileprefix . ' - ' . $studentname . ' - ' . $originalname;
                            $filename = $fullname . $file->get_filepath() .
                                self::shorten_filename($newname);
                            $filelist[$filename] = $file;
                        }
                    }
                }
                if ($assignplugin->get_type() == 'onlinetext') {
                    $onlinetext = $assignplugin->get_editor_text('onlinetext', $submission->id);
                    $onlinetext = str_replace('@@PLUGINFILE@@/', '', $onlinetext);
                    if (mb_strlen(trim($onlinetext)) > 0) {
                        $studentname = $user ? fullname($user) : 'desconocido';
                        $htmlname = $fileprefix . ' - ' . $studentname . ' - ' . $assignplugin->get_name();
                        $onlinetext = self::convert_content_to_html_doc($htmlname, $onlinetext);
                        $filename = $fullname . '/' . self::shorten_filename($htmlname . '.html');
                        $filelist[$filename] = [$onlinetext];
                    }
                }
            }

            // Feedback (opcional)!
            if (!$includefeedback) {
                continue;
            }
            if (empty($user)) {
                continue;
            }
            $grade = $assign->get_user_grade($user->id, false);
            if ($grade) {
                $fullname .= '/Retroalimentaci' . "\xC3\xB3n";

                foreach ($feedbackplugins as $feedbackplugin) {
                    if (!$feedbackplugin->is_enabled() || !$feedbackplugin->is_visible()) {
                        continue;
                    }
                    $component = $feedbackplugin->get_subtype() . '_' . $feedbackplugin->get_type();
                    $fileareas = $feedbackplugin->get_file_areas();
                    foreach ($fileareas as $filearea => $name) {
                        $areafiles = $fs->get_area_files(
                            $context->id,
                            $component,
                            $filearea,
                            $grade->id,
                            'itemid, filepath, filename',
                            false
                        );
                        if ($areafiles) {
                            foreach ($areafiles as $file) {
                                $fname = $file->get_filename();
                                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                                if ($ext !== 'pdf') {
                                    continue;
                                }
                                $filename = $fullname . $file->get_filepath() .
                                    self::shorten_filename($fname);
                                $filelist[$filename] = $file;
                            }
                        }
                    }

                    if ($feedbackplugin->get_type() == 'comments') {
                        $comments = $feedbackplugin->get_editor_text('comments', $grade->id);
                        $comments = str_replace('@@PLUGINFILE@@/', '', $comments);
                    }
                }

                // Generar HTML de rúbrica/retroalimentación por alumno.
                $studentname = $user ? fullname($user) : 'desconocido';
                $gradeval = $grade->grade ?? '';
                if (is_numeric($gradeval)) {
                    $gradeval = number_format((float)$gradeval, 1, '.', '');
                }
                if ($hasrubric) {
                    $rubrich = self::build_rubric_html(
                        $studentname,
                        $rubriccriteria,
                        $resource->cm->id,
                        $rubricareaid,
                        $user->id,
                        $comments ?? '',
                        $gradeval
                    );
                } else {
                    $rubrich = self::build_feedback_html($studentname, $comments ?? '', $gradeval);
                }
                $htmlname = 'Retroalimentaci' . "\xC3\xB3n" . ' - ' . $studentname;
                $rubrich = self::convert_content_to_html_doc($htmlname, $rubrich);
                $filename = $fullname . '/' . self::shorten_filename($htmlname . '.html');
                $filelist[$filename] = [$rubrich];
            }
        }
    }

    /**
     * Build HTML table with rubric criteria, feedback and grade for a student.
     *
     * @param string $studentname
     * @param array $rubriccriteria Criterion id => {description, max_score}
     * @param int $cmid Course module ID
     * @param int $areaid Grading area ID
     * @param int $userid
     * @param string $feedbacktext General feedback text
     * @param string $gradeval Final grade
     * @return string HTML content
     */
    public static function build_rubric_html($studentname, $rubriccriteria, $cmid, $areaid, $userid, $feedbacktext, $gradeval) {
        global $DB;

        // Obtener fillings de rúbrica para este usuario.
        $fillingsrs = $DB->get_recordset_sql(
            "SELECT fill.criterionid, fill.levelid, fill.remark,
                    lev.score, lev.definition AS leveldef
               FROM {course_modules} cm
               JOIN {context} con ON cm.id = con.instanceid AND con.contextlevel = ?
               JOIN {grading_areas} gra ON gra.contextid = con.id
               JOIN {grading_definitions} def ON def.areaid = gra.id
               JOIN {grading_instances} inst ON inst.definitionid = def.id
               JOIN {gradingform_rubric_fillings} fill ON fill.instanceid = inst.id
           LEFT JOIN {gradingform_rubric_levels} lev ON lev.id = fill.levelid
              WHERE cm.id = ? AND gra.id = ?
                AND inst.itemid IN (
                    SELECT act.id FROM {assign_grades} act WHERE act.assignment = cm.instance AND act.userid = ?
                )
           ORDER BY fill.criterionid",
            [CONTEXT_MODULE, $cmid, $areaid, $userid]
        );
        $fillings = [];
        foreach ($fillingsrs as $f) {
            // Keep the latest filling per criterion (highest criterionid+levelid combo).
            $fillings[$f->criterionid] = $f;
        }
        $fillingsrs->close();

        // Construir tabla HTML.
        $h = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;">';

        // Fila 1: encabezados de criterios.
        $criterianames = [];
        foreach ($rubriccriteria as $c) {
            $criterianames[] = htmlspecialchars($c->description) . '<br>(máx ' . round($c->max_score, 2) . ')';
        }
        $h .= '<tr style="background:#f2f2f2;font-weight:bold;"><td>Estudiante</td>';
        foreach ($rubriccriteria as $c) {
            $h .= '<td>' . htmlspecialchars($c->description) . '<br>(máx ' . round($c->max_score, 2) . ')</td>';
        }
        $h .= '<td>Retroalimentación</td><td>Calificación</td></tr>';

        // Fila 2: puntuación y nivel.
        $h .= '<tr><td rowspan="2" style="vertical-align:top;font-weight:bold;">' . htmlspecialchars($studentname) . '</td>';
        foreach ($rubriccriteria as $cid => $c) {
            $found = false;
            foreach ($fillings as $f) {
                if ($f->criterionid == $cid) {
                    $score = isset($f->score) ? round($f->score, 2) : 0;
                    $level = $f->leveldef ?? '';
                    $h .= '<td style="vertical-align:top;">Puntuación: ' . $score;
                    if ($level) {
                        $h .= '<br><em>Nivel: ' . htmlspecialchars($level) . '</em>';
                    }
                    $h .= '</td>';
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $h .= '<td style="vertical-align:top;color:#999;">-</td>';
            }
        }
        $fb = trim(strip_tags($feedbacktext ?? ''));
        $h .= '<td rowspan="2" style="vertical-align:top;">' . nl2br(htmlspecialchars($fb)) . '</td>';
        $h .= '<td rowspan="2" style="vertical-align:top;text-align:center;">' . htmlspecialchars($gradeval) . '</td>';
        $h .= '</tr>';

        // Fila 3: observaciones por criterio.
        $h .= '<tr>';
        foreach ($rubriccriteria as $cid => $c) {
            $obs = '';
            foreach ($fillings as $f) {
                if ($f->criterionid == $cid && !empty(trim($f->remark ?? ''))) {
                    $obs = $f->remark;
                    break;
                }
            }
            if ($obs) {
                $h .= '<td style="vertical-align:top;background:#fffde7;">Observación: ' . htmlspecialchars($obs) . '</td>';
            } else {
                $h .= '<td style="vertical-align:top;color:#999;">(sin observación)</td>';
            }
        }
        $h .= '</tr>';

        $h .= '</table>';
        return $h;
    }

    /**
     * Build simple HTML table with feedback and grade (no rubric).
     *
     * @param string $studentname
     * @param string $feedbacktext
     * @param string $gradeval
     * @return string HTML content
     */
    public static function build_feedback_html($studentname, $feedbacktext, $gradeval) {
        $fb = trim(strip_tags($feedbacktext ?? ''));
        $h = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;">';
        $h .= '<tr style="background:#f2f2f2;font-weight:bold;"><td>Estudiante</td><td>Retroalimentación</td><td>Calificación</td></tr>';
        $h .= '<tr>';
        $h .= '<td style="font-weight:bold;">' . htmlspecialchars($studentname) . '</td>';
        $h .= '<td>' . nl2br(htmlspecialchars($fb ?: '-')) . '</td>';
        $h .= '<td style="text-align:center;">' . htmlspecialchars($gradeval) . '</td>';
        $h .= '</tr>';
        $h .= '</table>';
        return $h;
    }

}

