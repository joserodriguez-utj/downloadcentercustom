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
 * Trait con la lógica de descarga de actividad Base de Datos (data).
 *
 * @package       local_downloadcentercustom
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */

defined('MOODLE_INTERNAL') || die();

trait local_downloadcentercustom_database_trait {

    /**
     * Handle Database (data) module.
     *
     * @param mixed $resource The resource being handled.
     * @param string $resdir The directory where results are saved.
     * @param array $filelist The array of files to be included in the ZIP.
     * @param int|null $groupid Group ID for filtering students.
     * @return void
     */
    private function handle_data($resource, $resdir, &$filelist, $groupid = null) {
        global $CFG, $DB;
        $context = $resource->context;

        if (!has_capability('local/downloadcentercustom:downloadAssignments', $context->get_course_context())) {
            return;
        }

        $data = $DB->get_record('data', ['id' => $resource->instanceid], '*', MUST_EXIST);
        $cm = $resource->cm;

        // Obtener los campos definidos en la base de datos.
        $fields = $DB->get_records('data_fields', ['dataid' => $data->id], 'id ASC');

        if (empty($fields)) {
            return;
        }

        // Obtener los registros (entradas).
        $records = $DB->get_records('data_records', ['dataid' => $data->id], 'timecreated ASC');

        // Filtrado por grupo.
        if ($this->onlyungrouped) {
            $allgroupmemberids = $DB->get_fieldset_sql(
                "SELECT DISTINCT gm.userid FROM {groups_members} gm
                  JOIN {groups} g ON g.id = gm.groupid
                 WHERE g.courseid = ?", [$this->course->id]
            );
            $records = array_filter($records, function($rec) use ($allgroupmemberids) {
                return !in_array($rec->userid, $allgroupmemberids);
            });
        } else if ($groupid) {
            $members = groups_get_members($groupid);
            $memberids = $members ? array_keys($members) : [];
            $records = array_filter($records, function($rec) use ($memberids) {
                return in_array($rec->userid, $memberids);
            });
        }
        if ($this->portfolio_userid !== null) {
            // Portafolio: solo evidencias del estudiante indicado.
            $records = array_filter($records, function($rec) {
                return (int)$rec->userid === (int)$this->portfolio_userid;
            });
        }

        if (empty($records)) {
            return;
        }

        $evidenciadir = $resdir . '/Evidencias';
        $filelist[$evidenciadir] = null;

        // Agrupar registros por usuario.
        $recordsbyuser = [];
        foreach ($records as $record) {
            if (!isset($recordsbyuser[$record->userid])) {
                $recordsbyuser[$record->userid] = [];
            }
            $recordsbyuser[$record->userid][] = $record;
        }

        foreach ($recordsbyuser as $userid => $userrecords) {
            $user = $DB->get_record('user', ['id' => $userid]);
            if (!$user) {
                continue;
            }
            $studentname = fullname($user);
            $html = $this->build_data_html($data, $cm, $fields, $userrecords, $user, $studentname);
            if ($html) {
                $html = self::convert_content_to_html_doc(
                    get_string('data_results', 'local_downloadcentercustom') . $studentname, $html);
                $filename = $evidenciadir . '/' . self::shorten_filename(
                    get_string('data_results', 'local_downloadcentercustom') . $studentname . '.html');
                $filelist[$filename] = [$html];
            }

            // Archivos adjuntos de los campos de tipo archivo e imagen.
            foreach ($userrecords as $record) {
                $this->get_data_attachments($context, $data, $fields, $record, $user, $evidenciadir, $filelist);
            }
        }
    }

    /**
     * Build HTML for a database records of a user.
     *
     * @param object $data The database activity.
     * @param object $cm The course module.
     * @param array $fields The fields of the database.
     * @param array $userrecords Array of records of the user.
     * @param object $user The user who created the records.
     * @param string $studentname Full name of the user.
     * @return string HTML content.
     */
    private function build_data_html($data, $cm, $fields, $userrecords, $user, $studentname) {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/rating/lib.php');

        // === MÉTODO DE CALIFICACIÓN ===
        $gradingmethod = get_string('data_no_grade', 'local_downloadcentercustom');

        if ($data->assessed < 0) {
            $scale = $DB->get_record('scale', ['id' => abs($data->assessed)]);
            if ($scale) {
                $gradingmethod = get_string('data_scale_custom', 'local_downloadcentercustom') . ': ' . s($scale->name);
            } else {
                $gradingmethod = get_string('data_scale_custom', 'local_downloadcentercustom');
            }
        } else if ($data->assessed > 0) {
            $ratingmethods = [
                RATING_AGGREGATE_NONE => get_string('aggregatenone', 'rating'),
                RATING_AGGREGATE_AVERAGE => get_string('aggregateavg', 'rating'),
                RATING_AGGREGATE_COUNT => get_string('aggregatecount', 'rating'),
                RATING_AGGREGATE_MAXIMUM => get_string('aggregatemax', 'rating'),
                RATING_AGGREGATE_MINIMUM => get_string('aggregatemin', 'rating'),
                RATING_AGGREGATE_SUM => get_string('aggregatesum', 'rating'),
            ];
            $gradingmethod = $ratingmethods[$data->assessed] ?? get_string('data_rated', 'local_downloadcentercustom');
        }

        // === CALIFICACIÓN FINAL (desde el libro de calificaciones) ===
        $finalgrade = '';
        $gradeitem = $DB->get_record('grade_items', [
            'itemtype' => 'mod',
            'itemmodule' => 'data',
            'iteminstance' => $data->id,
            'itemnumber' => 0
        ]);
        if ($gradeitem) {
            $grade = $DB->get_record('grade_grades', [
                'itemid' => $gradeitem->id,
                'userid' => $user->id
            ]);
            if ($grade && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                $finalgrade = round($grade->finalgrade, 2);
            }
        }

        // === FALLBACK: Si no hay calificación en grade_grades, buscar en ratings ===
        if ($finalgrade === '' && $data->assessed != 0) {
            // Obtener el promedio de ratings del usuario.
            $avgRating = $DB->get_record_sql(
                "SELECT AVG(r.rating) as avg_rating
                FROM {rating} r
                JOIN {data_records} dr ON dr.id = r.itemid
                WHERE r.component = 'mod_data'
                    AND r.ratingarea = 'entry'
                    AND dr.dataid = ?
                    AND dr.userid = ?",
                [$data->id, $user->id]
            );
            if ($avgRating && $avgRating->avg_rating !== null) {
                $finalgrade = round($avgRating->avg_rating, 2);
            }
        }

        // === CONSTRUIR HTML ===
        $h = '<h2>' . get_string('data_data_results', 'local_downloadcentercustom') . s($studentname) . ' — ' . s($data->name) . '</h2>';

        $h .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;margin-bottom:15px;">';
        $h .= '<tr style="background:#f2f2f2;">';
        $h .= '<th>' . get_string('data_student', 'local_downloadcentercustom') . '</th>';
        $h .= '<th>' . get_string('data_num_records', 'local_downloadcentercustom') . '</th>';
        $h .= '<th>' . get_string('data_grading_method', 'local_downloadcentercustom') . '</th>';
        $h .= '<th>' . get_string('data_final_grade', 'local_downloadcentercustom') . '</th>';
        $h .= '</tr>';

        $h .= '<tr>';
        $h .= '<td style="font-weight:bold;">' . htmlspecialchars($studentname) . '</td>';
        $h .= '<td style="text-align:center;">' . count($userrecords) . '</td>';
        $h .= '<td style="text-align:center;">' . s($gradingmethod) . '</td>';
        $h .= '<td style="text-align:center;">' . ($finalgrade !== '' ? $finalgrade : '-') . '</td>';
        $h .= '</tr>';
        $h .= '</table>';

        // === DETALLE DE REGISTROS ===
        foreach ($userrecords as $index => $record) {
            $recordtitle = get_string('data_record_title', 'local_downloadcentercustom') . ' #' . ($index + 1);
            $h .= '<h3>' . $recordtitle . '</h3>';
            $h .= '<p><em>' . get_string('data_submitted', 'local_downloadcentercustom') . ': ' . userdate($record->timecreated) . '</em></p>';

            $contents = $DB->get_records('data_content', ['recordid' => $record->id], 'id ASC');
            $contentbyfield = [];
            foreach ($contents as $content) {
                $contentbyfield[$content->fieldid] = $content;
            }

            $h .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;margin-bottom:15px;">';
            foreach ($fields as $field) {
                $h .= '<tr>';
                $h .= '<td style="font-weight:bold;background:#f2f2f2;width:20%;">' . htmlspecialchars($field->name) . '</td>';
                $h .= '<td>';

                $content = $contentbyfield[$field->id] ?? null;
                if ($content) {
                    $h .= $this->format_data_content($field, $content, $record, $cm);
                } else {
                    $h .= '<em>' . get_string('data_empty', 'local_downloadcentercustom') . '</em>';
                }

                $h .= '</td>';
                $h .= '</tr>';
            }
            $h .= '</table>';
        }

        return $h;
    }

    /**
     * Format the content of a database field.
     *
     * @param object $field The field definition.
     * @param object $content The content of the field.
     * @param object $record The record entry.
     * @param object $cm The course module.
     * @return string Formatted content.
     */
    private function format_data_content($field, $content, $record, $cm) {
        $value = $content->content;

        switch ($field->type) {
            case 'text':
                return nl2br(htmlspecialchars($value ?? ''));

            case 'textarea':
                return format_text($value, $content->content1 ?? FORMAT_HTML);

            case 'url':
                $url = $value;
                $text = $content->content1 ?? $url;
                if (!preg_match('~^https?://~i', $url)) {
                    $url = 'http://' . $url;
                }
                return '<a href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars($text) . '</a>';

            case 'file':
                return $this->get_data_file_link($cm, $record, $field, $content);

            case 'picture':
                $url = $this->get_data_file_url($cm, $record, $field, $content);
                return $url ? '<img src="' . $url . '" style="max-width:300px;max-height:300px;" />' :
                    '<em>' . get_string('data_empty', 'local_downloadcentercustom') . '</em>';

            case 'checkbox':
                $values = explode('##', $value ?? '');
                $values = array_filter(array_map('trim', $values));
                return $values ? htmlspecialchars(implode(', ', $values)) :
                    '<em>' . get_string('data_empty', 'local_downloadcentercustom') . '</em>';

            case 'multimenu':
                $values = explode('##', $value ?? '');
                $values = array_filter(array_map('trim', $values));
                return $values ? htmlspecialchars(implode(', ', $values)) :
                    '<em>' . get_string('data_empty', 'local_downloadcentercustom') . '</em>';

            case 'radiobutton':
            case 'menu':
                return htmlspecialchars($value ?? '');

            case 'number':
                return htmlspecialchars($value ?? '');

            case 'date':
                if (!empty($value) && is_numeric($value)) {
                    return userdate((int)$value, get_string('strftimedatetime', 'langconfig'));
                }
                return htmlspecialchars($value ?? '');

            case 'latlong':
                $lat = $content->content;
                $long = $content->content1 ?? '';
                return sprintf('Lat: %s, Long: %s', htmlspecialchars($lat), htmlspecialchars($long));

            default:
                return htmlspecialchars($value ?? '');
        }
    }

    /**
     * Get the URL of a file attached to a database record.
     *
     * @param object $cm The course module.
     * @param object $record The record entry.
     * @param object $field The field definition.
     * @param object $content The content of the field.
     * @return string The file URL (empty if not found).
     */
    private function get_data_file_url($cm, $record, $field, $content) {
        $fs = get_file_storage();
        $file = $fs->get_file($cm->context->id, 'mod_data', 'content', $content->id, '/', $content->content);
        if (!$file) {
            return '';
        }
        $fileurl = moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename()
        );
        return $fileurl->out();
    }

    /**
     * Get a link to a file attached to a database record.
     *
     * @param object $cm The course module.
     * @param object $record The record entry.
     * @param object $field The field definition.
     * @param object $content The content of the field.
     * @return string HTML link.
     */
    private function get_data_file_link($cm, $record, $field, $content) {
        $url = $this->get_data_file_url($cm, $record, $field, $content);
        if (!$url) {
            return '<em>' . get_string('data_empty', 'local_downloadcentercustom') . '</em>';
        }
        return '<a href="' . $url . '" target="_blank">' . get_string('data_download_file', 'local_downloadcentercustom') . '</a>';
    }

    /**
     * Get attachments (files and images) from database records.
     *
     * @param object $context The context of the activity.
     * @param object $data The database activity.
     * @param array $fields The fields of the database.
     * @param object $record The record entry.
     * @param object $user The user who created the record.
     * @param string $evidenciadir The directory where files are saved.
     * @param array $filelist The array of files to be included in the ZIP.
     * @return void
     */
    private function get_data_attachments($context, $data, $fields, $record, $user, $evidenciadir, &$filelist) {
        global $DB;
        $fs = get_file_storage();
        $studentname = fullname($user);
        $studentfolder = self::shorten_filename(self::clean_filename_ascii($studentname));

        // Buscar campos de tipo file o picture.
        $filefields = array_filter($fields, function($field) {
            return in_array($field->type, ['file', 'picture']);
        });

        foreach ($filefields as $field) {
            // El data_content guarda el nombre del archivo; el itemid del archivo es el id de data_content.
            $content = $DB->get_record('data_content', ['fieldid' => $field->id, 'recordid' => $record->id]);
            if (!$content) {
                continue;
            }
            $files = $fs->get_area_files($context->id, 'mod_data', 'content', $content->id, 'itemid, filepath, filename', false);
            foreach ($files as $file) {
                if ($file->get_filesize() == 0) {
                    continue;
                }
                $fname = $file->get_filename();
                // En portafolio ya hay carpeta por estudiante arriba: adjuntos directo a Evidencias.
                $subdir = ($this->portfolio_userid !== null) ? '' : ($studentfolder . '/');
                $filename = $evidenciadir . '/' . $subdir . self::shorten_filename($field->name . ' - ' . $fname);
                $filelist[$filename] = $file;
            }
        }
    }
}
