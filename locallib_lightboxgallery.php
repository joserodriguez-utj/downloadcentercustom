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

trait local_centro_de_descarga_de_portafolio_de_evidencias_lightboxgallery_trait {

    /**
     * Handles the mod type lightboxgallery files.
     *
     * @param mixed $resource The resource object being handled.
     * @param string $resdir The directory where the resource files are saved at the end in the ZIP.
     * @param array $filelist Array of files to be included in the ZIP with its data.
     * @return void
     */

    private function handle_lightboxgallery($resource, $resdir, &$filelist) {
        $this->pdfactivityname = $resource->name;
        global $DB;

        if (!has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadAssignments',
                $resource->context->get_course_context())) {
            return;
        }

        $context = $resource->context;
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_lightboxgallery', 'gallery_images', 0, 'filename', false);

        // 1) Descargar las imágenes originales al ZIP, junto al PDF:
        //    Evidencias/ en portafolio, raíz de la actividad en modo normal.
        $imgdir = ($this->portfolio_userid !== null) ? $resdir . '/Evidencias' : $resdir;
        $validfiles = [];
        foreach ($files as $storedfile) {
            if (!$storedfile->is_valid_image() || $storedfile->get_filesize() == 0) {
                continue;
            }
            $filelist[$imgdir] = null;
            $filename = $imgdir . '/' . self::shorten_filename($storedfile->get_filename());
            $filelist[$filename] = $storedfile;
            $validfiles[] = $storedfile;
        }

        if (empty($validfiles)) {
            return;
        }

        // 2) Copiar cada imagen a un archivo temporal para que TCPDF la pueda leer.
        $tmpfiles = [];
        $filepathtoTmp = [];
        foreach ($validfiles as $storedfile) {
            $ext = pathinfo($storedfile->get_filename(), PATHINFO_EXTENSION);
            $tmpbase = tempnam(sys_get_temp_dir(), 'lbg_');
            @unlink($tmpbase);
            $tmp = $tmpbase . '.' . $ext;
            $storedfile->copy_content_to($tmp);
            if (file_exists($tmp)) {
                $tmpfiles[] = $tmp;
                $filepathtoTmp[$storedfile->get_id()] = $tmp;
            }
        }

        // 3) Generar el PDF usando las rutas temporales.
        $body = $this->build_lightboxgallery_pdf_body($resource, $context, $validfiles, $filepathtoTmp);
        $title = get_string('lightboxgallery_results', 'local_centro_de_descarga_de_portafolio_de_evidencias')
            . format_string($resource->name);
        $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);

        if ($this->portfolio_userid !== null) {
            $filename = $resdir . '/Evidencias/' . self::shorten_filename($title . '.pdf');
        } else {
            $filename = $resdir . '/' . self::shorten_filename($title . '.pdf');
        }
        $filelist[$filename] = [$pdf];

        // 4) Borrar los temporales.
        foreach ($tmpfiles as $tmp) {
            @unlink($tmp);
        }
    }

    /**
     * Builds the HTML body for the lightboxgallery PDF.
     *
     * @param object $resource
     * @param object $context
     * @param array $validfiles Array of stored_file (valid images).
     * @param array $filepathtoTmp Map storedfile id => temporal path on disk.
     * @return string
     */
    private function build_lightboxgallery_pdf_body($resource, $context, $validfiles, $filepathtoTmp) {
        $th  = 'style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;text-align:left;font-size:8pt;"';
        $td  = 'style="border:1px solid #adb5bd;padding:2px;vertical-align:top;font-size:8pt;"';
        $hd  = 'style="background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;"';
        $tbl = 'border="1" cellspacing="0" cellpadding="2" style="border-collapse:collapse;width:100%;font-size:8pt;"';

        $h = '<h2 ' . $hd . '>' . s(get_string('lightboxgallery_results', 'local_centro_de_descarga_de_portafolio_de_evidencias'))
            . s(format_string($resource->name)) . '</h2>';

        // Resumen.
        $h .= '<table ' . $tbl . '>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('lightboxgallery_images_count', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . count($validfiles) . '</td>'
            . '</tr>';
        $h .= '</table>';

        // Una imagen por página.
        $index = 0;
        foreach ($validfiles as $storedfile) {
            $tmp = $filepathtoTmp[$storedfile->get_id()] ?? '';
            if ($tmp === '' || !file_exists($tmp)) {
                continue;
            }
            $index++;
            $break = ($index === 1) ? '' : 'page-break-before:always;';
            $filename = self::shorten_filename($storedfile->get_filename());

            $h .= '<h2 style="' . $break . 'background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;">'
                . s($filename) . '</h2>';
            $h .= '<p style="font-size:8pt;margin:0 0 2mm 0;"><em>'
                . s(get_string('lightboxgallery_image', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . ' #' . $index
                . '</em></p>';

            // Ahora sí: la ruta es un archivo real en disco, TCPDF la puede incrustar.
            $h .= '<div style="text-align:center;">'
                . '<img src="' . s($tmp) . '" style="max-width:170mm;" />'
                . '</div>';
        }

        return $h;
    }
}