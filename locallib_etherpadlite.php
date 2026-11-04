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

trait local_centro_de_descarga_de_portafolio_de_evidencias_etherpadlite_trait {

    /**
     * Handles the mod type etherpadlite files.
     *
     * @param mixed $resource The resource object being handled.
     * @param string $resdir The directory where the resource files are saved at the end in the ZIP.
     * @param array $filelist Array of files to be included in the ZIP with its data.
     * @return void
     */
    private function handle_etherpadlite($resource, $resdir, &$filelist) {
        $this->pdfactivityname = $resource->name;
        global $CFG, $DB;

        if (!has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadAssignments',
                $resource->context->get_course_context())) {
            return;
        }
        require_once($CFG->dirroot . '/mod/etherpadlite/lib.php');
        $etherpadconfig = get_config('etherpadlite');
        $domain = $etherpadconfig->url;
        $padid = $resource->resource->uri;
        $etherpadclient = \mod_etherpadlite\api\client::get_instance($etherpadconfig->apikey, $domain);

        $groupmode = groups_get_activity_groupmode($resource->cm);

        if ($this->portfolio_userid !== null) {
            // Portafolio: un PDF con el pad del alumno.
            $user = $DB->get_record('user', ['id' => $this->portfolio_userid]);
            if (!$user) {
                return;
            }
            $studentname = fullname($user);

            // Determinar el pad del alumno (grupo si aplica, todos si no).
            $padidforuser = $padid;
            if ($groupmode == SEPARATEGROUPS || $groupmode == VISIBLEGROUPS) {
                $usergroups = groups_get_user_groups($resource->cm->course, $user->id);
                $groups = $usergroups[0] ?? [];
                if (!empty($groups)) {
                    $padidforuser = $padid . reset($groups);
                }
            }

            $htmlcontent = $etherpadclient->get_html($padidforuser);
            if (empty($htmlcontent)) {
                return;
            }
            $body = $this->build_etherpadlite_student_header($user)
                . $this->sanitize_html_for_pdf($htmlcontent->html);
            $title = get_string('etherpadlite_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . $studentname;
            $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);
            $filelist[$resdir . '/Evidencias'] = null;
            $filename = $resdir . '/Evidencias/' . self::shorten_filename($title . '.pdf');
            $filelist[$filename] = [$pdf];
            return;
        }

        // Modo normal: pad "todos" (si aplica) + un pad por grupo (si aplica).
        if ($groupmode) {
            if ($groupmode == VISIBLEGROUPS || has_capability('moodle/course:managegroups', $resource->context)) {
                $htmlcontent = $etherpadclient->get_html($padid);
                if (!empty($htmlcontent)) {
                    $title = get_string('etherpadlite_results', 'local_centro_de_descarga_de_portafolio_de_evidencias')
                        . $resource->name . ' (' . get_string('allparticipants') . ')';
                    $body = $this->sanitize_html_for_pdf($htmlcontent->html);
                    $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);
                    $filename = $resdir . '/' . self::shorten_filename($resource->name . '_' . get_string('allparticipants') . '.pdf');
                    $filelist[$filename] = [$pdf];
                }
            }
            $allgroups = groups_get_activity_allowed_groups($resource->cm);
            foreach ($allgroups as $group) {
                $htmlcontent = $etherpadclient->get_html($padid . $group->id);
                if (!empty($htmlcontent)) {
                    $title = get_string('etherpadlite_results', 'local_centro_de_descarga_de_portafolio_de_evidencias')
                        . $resource->name . ' (' . $group->name . ')';
                    $body = $this->sanitize_html_for_pdf($htmlcontent->html);
                    $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);
                    $filename = $resdir . '/' . self::shorten_filename($resource->name . '_' . $group->name . '.pdf');
                    $filelist[$filename] = [$pdf];
                }
            }
        } else {
            $htmlcontent = $etherpadclient->get_html($padid);
            if (!empty($htmlcontent)) {
                $title = get_string('etherpadlite_results', 'local_centro_de_descarga_de_portafolio_de_evidencias')
                    . $resource->name;
                $body = $this->sanitize_html_for_pdf($htmlcontent->html);
                $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);
                $filename = $resdir . '/' . self::shorten_filename($resource->name . '.pdf');
                $filelist[$filename] = [$pdf];
            }
        }
    }

    /**
     * Tabla de datos generales del alumno para el PDF del Etherpad.
     *
     * @param object $user
     * @return string
     */
    private function build_etherpadlite_student_header($user): string {
        $th  = 'style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;text-align:left;font-size:8pt;"';
        $td  = 'style="border:1px solid #adb5bd;padding:2px;vertical-align:top;font-size:8pt;"';
        $tbl = 'border="1" cellspacing="0" cellpadding="2" style="border-collapse:collapse;width:100%;font-size:8pt;"';
        $h  = '<table ' . $tbl . '>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('quiz_lastname', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($user->lastname) . '</td>'
            . '<th ' . $th . '>' . s(get_string('quiz_firstname', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($user->firstname) . '</td>'
            . '<th ' . $th . '>' . s(get_string('quiz_email', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($user->email) . '</td>'
            . '</tr>';
        $h .= '</table>';
        return $h;
    }

    /**
     * Sanitizes generic HTML for TCPDF (removes iframes, scripts, handlers).
     *
     * @param string $html
     * @return string
     */
    private function sanitize_html_for_pdf($html) {
        if ($html === null || $html === '') {
            return '';
        }
        $html = preg_replace('/<iframe[^>]*?src="([^"]+)"[^>]*>.*?<\/iframe>/is',
            '<p style="font-size:8pt;"><a href="$1">$1</a></p>', $html);
        $html = preg_replace('/<iframe[^>]*>.*?<\/iframe>/is', '', $html);
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/\son\w+\s*=\s*"[^"]*"/i', '', $html);
        $html = preg_replace("/\son\w+\s*=\s*'[^']*'/i", '', $html);
        return $html;
    }
}