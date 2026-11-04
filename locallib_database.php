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
 * Trait con la lógica de descarga de actividad Base de Datos (data).
 *
 * @package       local_centro_de_descarga_de_portafolio_de_evidencias
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */

defined('MOODLE_INTERNAL') || die();

trait local_centro_de_descarga_de_portafolio_de_evidencias_database_trait {

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
        $this->pdfactivityname = $resource->name;
        global $CFG, $DB;
        $context = $resource->context;

        if (!has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadAssignments', $context->get_course_context())) {
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

            $body = $this->build_data_pdf_body($data, $cm, $fields, $userrecords, $user, $studentname, $evidenciadir, $filelist);
            if ($body) {
                $title = get_string('data_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . $studentname;
                $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);
                $filename = $evidenciadir . '/' . self::shorten_filename($title . '.pdf');
                $filelist[$filename] = [$pdf];
            }

            // Archivos adjuntos de los campos de tipo archivo e imagen (sin cambios).
            foreach ($userrecords as $record) {
                $this->get_data_attachments($context, $data, $fields, $record, $user, $evidenciadir, $filelist);
            }
        }
    }

    /**
     * Builds the HTML body for the database activity PDF (one per student).
     *
     * Same information as the previous HTML version, but styled for TCPDF:
     * smaller fonts, tables with consistent th/td, page break between records,
     * and pictures embedded in the "Recurso" folder next to the PDF.
     *
     * @param object $data
     * @param object $cm
     * @param array $fields
     * @param array $userrecords
     * @param object $user
     * @param string $studentname
     * @param string $evidenciadir
     * @param array $filelist
     * @return string
     */
    private function build_data_pdf_body($data, $cm, $fields, $userrecords, $user, $studentname, $evidenciadir, array &$filelist) {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/rating/lib.php');

        $context = \context_module::instance($cm->id);
        $contextid = $context->id;
        $mediadir = $evidenciadir . '/Recurso';

        // === MÉTODO DE CALIFICACIÓN ===
        $gradingmethod = get_string('data_no_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias');
        if ($data->assessed < 0) {
            $scale = $DB->get_record('scale', ['id' => abs($data->assessed)]);
            $gradingmethod = get_string('data_scale_custom', 'local_centro_de_descarga_de_portafolio_de_evidencias')
                . ($scale ? ': ' . s($scale->name) : '');
        } else if ($data->assessed > 0) {
            $ratingmethods = [
                RATING_AGGREGATE_NONE     => get_string('aggregatenone', 'rating'),
                RATING_AGGREGATE_AVERAGE  => get_string('aggregateavg', 'rating'),
                RATING_AGGREGATE_COUNT    => get_string('aggregatecount', 'rating'),
                RATING_AGGREGATE_MAXIMUM  => get_string('aggregatemax', 'rating'),
                RATING_AGGREGATE_MINIMUM  => get_string('aggregatemin', 'rating'),
                RATING_AGGREGATE_SUM      => get_string('aggregatesum', 'rating'),
            ];
            $gradingmethod = $ratingmethods[$data->assessed] ?? get_string('data_rated', 'local_centro_de_descarga_de_portafolio_de_evidencias');
        }

        // === CALIFICACIÓN FINAL ===
        $finalgrade = '';
        $gradeitem = $DB->get_record('grade_items', [
            'itemtype' => 'mod',
            'itemmodule' => 'data',
            'iteminstance' => $data->id,
            'itemnumber' => 0,
        ]);
        if ($gradeitem) {
            $grade = $DB->get_record('grade_grades', [
                'itemid' => $gradeitem->id,
                'userid' => $user->id,
            ]);
            if ($grade && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                $finalgrade = round($grade->finalgrade, 2);
            }
        }
        if ($finalgrade === '' && $data->assessed != 0) {
            $avgRating = $DB->get_record_sql(
                "SELECT AVG(r.rating) AS avg_rating
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
        $finalgradedisplay = ($finalgrade === '') ? '-' : $finalgrade;

        // Estilos.
        $th  = 'style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;text-align:left;font-size:8pt;"';
        $td  = 'style="border:1px solid #adb5bd;padding:2px;vertical-align:top;font-size:8pt;"';
        $hd  = 'style="background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;"';
        $tbl = 'border="1" cellspacing="0" cellpadding="2" style="border-collapse:collapse;width:100%;font-size:8pt;"';

        // 1) Datos generales del alumno.
        $h  = '<h2 ' . $hd . '>' . s(get_string('data_data_results', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . s($studentname) . '</h2>';
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
            . '<th ' . $th . '>' . s(get_string('data_num_records', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . count($userrecords) . '</td>'
            . '<th ' . $th . '>' . s(get_string('data_grading_method', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($gradingmethod) . '</td>'
            . '</tr>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('data_final_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($finalgradedisplay) . '</td>'
            . '<th ' . $th . '></th>'
            . '<td ' . $td . '></td>'
            . '</tr>';
        $h .= '</table>';

        // 3) Detalle de registros (una página por registro a partir del 2º).
        $firstrecord = true;
        $index = 0;
        foreach ($userrecords as $record) {
            $index++;
            $break = $firstrecord ? '' : 'page-break-before:always;';
            $firstrecord = false;

            $h .= '<h2 style="' . $break . 'background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;">'
                . s(get_string('data_record_title', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . ' #' . $index
                . '</h2>';
            $h .= '<p style="font-size:8pt;margin:0 0 2mm 0;"><em>'
                . s(get_string('data_submitted', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . ': '
                . s(userdate($record->timecreated))
                . '</em></p>';

            $contents = $DB->get_records('data_content', ['recordid' => $record->id], 'id ASC');
            $contentbyfield = [];
            foreach ($contents as $content) {
                $contentbyfield[$content->fieldid] = $content;
            }

            $h .= '<table ' . $tbl . '>';
            foreach ($fields as $field) {
                $h .= '<tr nobr="true">';
                $h .= '<th ' . $th . ' style="width:20%;">' . s($field->name) . '</th>';
                $h .= '<td ' . $td . '>';

                $content = $contentbyfield[$field->id] ?? null;
                if ($content) {
                    $h .= $this->format_data_content_pdf($field, $content, $record, $cm, $context, $mediadir, $filelist);
                } else {
                    $h .= '<em>' . s(get_string('data_empty', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</em>';
                }

                $h .= '</td>';
                $h .= '</tr>';
            }
            $h .= '</table>';
        }

        return $h;
    }

    /**
     * Formats the content of a database field for TCPDF.
     *
     * Differences with the HTML version:
     *  - Pictures are copied to "Recurso/" and linked relatively.
     *  - Files are copied to "Recurso/" and linked relatively.
     *  - Textarea goes through format_text with context and clean_pluginfile_leftovers.
     *
     * @param object $field
     * @param object $content
     * @param object $record
     * @param object $cm
     * @param object $context
     * @param string $mediadir
     * @param array $filelist
     * @return string
    */
    private function format_data_content_pdf($field, $content, $record, $cm, $context, $mediadir, array &$filelist) {
        $value = $content->content;
        $empty = '<em>' . s(get_string('data_empty', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</em>';

        switch ($field->type) {
            case 'text':
                return nl2br(s($value ?? ''));

            case 'textarea':
                $value = file_rewrite_pluginfile_urls(
                    $value ?? '',
                    'pluginfile.php',
                    $context->id,
                    'mod_data',
                    'content',
                    $content->id
                );
                $this->add_embedded_files_from_html($value, $mediadir, $filelist);
                $value = $this->clean_pluginfile_leftovers($value);
                return format_text($value, $content->content1 ?? FORMAT_HTML, ['context' => $context]);

            case 'url':
                $url = $value;
                $text = $content->content1 ?? $url;
                if (!preg_match('~^https?://~i', $url)) {
                    $url = 'http://' . $url;
                }
                return '<a href="' . s($url) . '">' . s($text) . '</a>';

            case 'file':
                $file = $this->copy_data_file_to_recurso($context, $content, $mediadir, $filelist);
                if (!$file) {
                    return $empty;
                }
                return '<a href="Recurso/' . rawurlencode($file) . '">'
                    . s(get_string('data_download_file', 'local_centro_de_descarga_de_portafolio_de_evidencias'))
                    . '</a>';

            case 'picture':
                $file = $this->copy_data_file_to_recurso($context, $content, $mediadir, $filelist);
                if (!$file) {
                    return $empty;
                }
                // En PDF, TCPDF puede renderizar imágenes con <img>.
                return '<img src="Recurso/' . s($file) . '" style="max-width:300px;max-height:300px;" />';

            case 'checkbox':
            case 'multimenu':
                $values = array_filter(array_map('trim', explode('##', $value ?? '')));
                return $values ? s(implode(', ', $values)) : $empty;

            case 'radiobutton':
            case 'menu':
            case 'number':
                return s($value ?? '');

            case 'date':
                if (!empty($value) && is_numeric($value)) {
                    return s(userdate((int)$value, get_string('strftimedatetime', 'langconfig')));
                }
                return s($value ?? '');

            case 'latlong':
                $lat = $content->content;
                $long = $content->content1 ?? '';
                return s(sprintf('Lat: %s, Long: %s', $lat, $long));

            case 'jsignature':
                // No es representable como texto: mostrar constancia.
                return '<em>' . s(get_string('data_recorded', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</em>';

            default:
                return s($value ?? '');
        }
    }

    /**
     * Copies a data field file (file or picture) to the "Recurso" folder.
     *
     * @param object $context
     * @param object $content
     * @param string $mediadir
     * @param array $filelist
     * @return string|null The filename inside "Recurso/" or null if not found.
     */
    private function copy_data_file_to_recurso($context, $content, $mediadir, array &$filelist) {
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_data', 'content', $content->id, 'filename', false);
        foreach ($files as $file) {
            if ($file->is_directory() || $file->get_filesize() == 0) {
                continue;
            }
            $basename = self::shorten_filename(self::clean_filename_ascii($file->get_filename()));
            $info = pathinfo($basename);
            $extension = isset($info['extension']) ? '.' . $info['extension'] : '';
            $stem = isset($info['filename']) ? $info['filename'] : $basename;
            $name = $basename;
            $i = 1;
            while (isset($filelist[$mediadir . '/' . $name])) {
                $name = $stem . '_' . $i . $extension;
                $i++;
            }
            $filelist[$mediadir] = null;
            $filelist[$mediadir . '/' . $name] = $file;
            return $name;
        }
        return null;
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
        $cmcontext = \context_module::instance($cm->id);
        $file = $fs->get_file($cmcontext->id, 'mod_data', 'content', $content->id, '/', $content->content);
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
            return '<em>' . get_string('data_empty', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</em>';
        }
        return '<a href="' . $url . '" target="_blank">' . get_string('data_download_file', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</a>';
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
