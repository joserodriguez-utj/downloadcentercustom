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

trait local_centro_de_descarga_de_portafolio_de_evidencias_glossary_trait {

    /**
     * Handles the mod type glossary files.
     *
     * @param mixed $resource The resource object being handled.
     * @param string $resdir The directory where the resource files are saved at the end in the ZIP.
     * @param array $filelist Array of files to be included in the ZIP with its data.
     * @return void
     */
    private function handle_glossary($resource, $resdir, &$filelist) {
        $this->pdfactivityname = $resource->name;
        global $CFG, $DB;
        $context = $resource->context;

        if (!has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadAssignments',
                $context->get_course_context())) {
            return;
        }

        $glossary = $resource->resource;
        $cm = $resource->cm;

        if ($this->portfolio_userid !== null) {
            // Portafolio: un PDF por alumno con solo sus entradas.
            $user = $DB->get_record('user', ['id' => $this->portfolio_userid]);
            if (!$user) {
                return;
            }
            $studentname = fullname($user);
            $mediadir = $resdir . '/Evidencias/Recurso';
            $body = $this->build_glossary_pdf_body($glossary, $cm, $user, $mediadir, $filelist);
            if ($body) {
                $title = get_string('glossary_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . $studentname;
                $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);
                $filelist[$resdir . '/Evidencias'] = null;
                $filename = $resdir . '/Evidencias/' . self::shorten_filename($title . '.pdf');
                $filelist[$filename] = [$pdf];
            }
        } else {
            // Modo normal: un PDF global con todas las entradas.
            $mediadir = $resdir . '/Recurso';
            $body = $this->build_glossary_pdf_body($glossary, $cm, null, $mediadir, $filelist);
            if ($body) {
                $title = get_string('glossary_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . format_string($glossary->name);
                $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);
                $filename = $resdir . '/' . self::shorten_filename($glossary->name . '.pdf');
                $filelist[$filename] = [$pdf];
            }
        }

        // Adjuntos globales del glosario (attachment + entry).
        $fs = get_file_storage();
        $fsfiles = $fs->get_area_files($context->id, 'mod_glossary', 'attachment');
        foreach ($fsfiles as $file) {
            if ($file->get_filesize() == 0) {
                continue;
            }
            $filename = $resdir . '/data/attachment/' . $file->get_itemid() . '/' . $file->get_filename();
            $filelist[$filename] = $file;
        }
        $fsfiles = $fs->get_area_files($context->id, 'mod_glossary', 'entry');
        foreach ($fsfiles as $file) {
            if ($file->get_filesize() == 0) {
                continue;
            }
            $filename = $resdir . '/data/entry/' . $file->get_itemid() . '/' . $file->get_filename();
            $filelist[$filename] = $file;
        }
    }

    /**
     * Builds the HTML body for the glossary PDF.
     *
     * @param object $glossary
     * @param object $cm
     * @param object|null $user If set, only this user's entries are shown (portfolio mode).
     * @param string $mediadir Directory in the ZIP where embedded files go.
     * @param array $filelist Reference to the ZIP file list.
     * @return string
     */
    private function build_glossary_pdf_body($glossary, $cm, $user = null, $mediadir = '', array &$filelist = null) {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/glossary/locallib.php');
        require_once($CFG->libdir . '/filelib.php');

        $context = \context_module::instance($cm->id);
        $contextid = $context->id;

        // Filtro de entradas (por alumno o todas).
        $conditions = ['glossaryid' => $glossary->id, 'approved' => 1];
        if ($user) {
            $conditions['userid'] = $user->id;
        }
        $entries = $DB->get_records('glossary_entries', $conditions, 'concept ASC');
        if (empty($entries)) {
            return '';
        }

        // Estilos.
        $th  = 'style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;text-align:left;font-size:8pt;"';
        $td  = 'style="border:1px solid #adb5bd;padding:2px;vertical-align:top;font-size:8pt;"';
        $hd  = 'style="background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;"';
        $tbl = 'border="1" cellspacing="0" cellpadding="2" style="border-collapse:collapse;width:100%;font-size:8pt;"';

        // Título.
        $title = ($user)
            ? get_string('glossary_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . fullname($user)
            : get_string('glossary_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . format_string($glossary->name);
        $h = '<h2 ' . $hd . '>' . s($title) . '</h2>';

        // Datos generales del alumno (solo si hay alumno).
        if ($user) {
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
        }

        // Resumen: número de entradas.
        $h .= '<h2 ' . $hd . '>' . s(get_string('glossary_entries_count', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</h2>';
        $h .= '<table ' . $tbl . '>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('glossary_entries_count', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . count($entries) . '</td>'
            . '</tr>';
        $h .= '</table>';

        // Detalle por entrada.
        $index = 0;
        $firstentry = true;
        foreach ($entries as $entry) {
            $index++;
            $break = $firstentry ? '' : 'page-break-before:always;';
            $firstentry = false;

            $author = $DB->get_record('user', ['id' => $entry->userid]);
            $authorname = $author ? fullname($author) : '';

            $h .= '<h2 style="' . $break . 'background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;">'
                . s(format_string($entry->concept)) . '</h2>';

            $h .= '<table ' . $tbl . '>';
            // Autor y fecha.
            if ($authorname !== '') {
                $h .= '<tr nobr="true">'
                    . '<th ' . $th . '>' . s(get_string('glossary_author', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                    . '<td ' . $td . '>' . s($authorname) . '</td>'
                    . '</tr>';
            }
            $h .= '<tr nobr="true">'
                . '<th ' . $th . '>' . s(get_string('glossary_created', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                . '<td ' . $td . '>' . s(userdate($entry->timecreated)) . '</td>'
                . '</tr>';
            // Categoría.
            if (!empty($entry->categoryid)) {
                $category = $DB->get_record('glossary_categories', ['id' => $entry->categoryid]);
                if ($category) {
                    $h .= '<tr nobr="true">'
                        . '<th ' . $th . '>' . s(get_string('glossary_category', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                        . '<td ' . $td . '>' . s(format_string($category->name)) . '</td>'
                        . '</tr>';
                }
            }
            // Definición.
            $definition = file_rewrite_pluginfile_urls(
                $entry->definition, 'pluginfile.php', $contextid, 'mod_glossary', 'entry', $entry->id
            );
            if ($filelist !== null && $mediadir !== '') {
                $this->add_embedded_files_from_html($definition, $mediadir, $filelist);
            }
            $definition = $this->clean_pluginfile_leftovers($definition);
            $h .= '<tr><th ' . $th . '>' . s(get_string('glossary_definition', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
                . '<td ' . $td . '>' . format_text($definition, $entry->definitionformat, ['context' => $context]) . '</td></tr>';
            $h .= '</table>';
        }

        return $h;
    }
}