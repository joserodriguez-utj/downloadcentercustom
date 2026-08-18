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

trait local_downloadcentercustom_etherpadlite_trait {

        /**
     * Handles the mod type etherpadlite files.
     *
     * @param mixed $resource The resource object being handled.
     * @param string $resdir The directory where the resource files are saved at the end in the ZIP.
     * @param array $filelist Array of files to be included in the ZIP with its data.
     * @return void
     */

    private function handle_etherpadlite($resource, $resdir, &$filelist) {
        global $CFG;

        require_once($CFG->dirroot . '/mod/etherpadlite/lib.php');
        $etherpadconfig = get_config('etherpadlite');
        $domain = $etherpadconfig->url;
        $padid = $resource->resource->uri;
        // If not working, try $domain.'api' instead.
        $etherpadclient = \mod_etherpadlite\api\client::get_instance($etherpadconfig->apikey, $domain);
        // Handle groups here.
        $groupmode = groups_get_activity_groupmode($resource->cm);
        if ($groupmode) {
            if ($groupmode == VISIBLEGROUPS || has_capability('moodle/course:managegroups', $resource->context)) {
                $htmlcontent = $etherpadclient->get_html($padid);
                if (!empty($htmlcontent)) {
                    $htmlcontent = self::append_etherpadlite_css($htmlcontent->html);
                    $filename = $resdir . '/' . self::shorten_filename($resource->name . '_' .
                        get_string('allparticipants') . '.html');
                    $filelist[$filename] = [$htmlcontent]; // Needs to be array to be saved as file.
                }
            }
            $allgroups = groups_get_activity_allowed_groups($resource->cm);
            foreach ($allgroups as $group) {
                $htmlcontent = $etherpadclient->get_html($padid . $group->id);
                if (!empty($htmlcontent)) {
                    $htmlcontent = self::append_etherpadlite_css($htmlcontent->html);
                    $filename = $resdir . '/' . self::shorten_filename($resource->name . '_' . $group->name . '.html');
                    $filelist[$filename] = [$htmlcontent]; // Needs to be array to be saved as file.
                }
            }
        } else {
            $htmlcontent = $etherpadclient->get_html($padid);
            if (!empty($htmlcontent)) {
                $htmlcontent = self::append_etherpadlite_css($htmlcontent->html);
                $filename = $resdir . '/' . self::shorten_filename($resource->name . '.html');
                $filelist[$filename] = [$htmlcontent]; // Needs to be array to be saved as file.
            }
        }
    }

}
