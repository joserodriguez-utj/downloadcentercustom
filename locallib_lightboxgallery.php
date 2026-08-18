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

trait local_downloadcentercustom_lightboxgallery_trait {

    /**
     * Handles the mod type lightboxgallery files.
     *
     * @param mixed $resource The resource object being handled.
     * @param string $resdir The directory where the resource files are saved at the end in the ZIP.
     * @param array $filelist Array of files to be included in the ZIP with its data.
     * @return void
     */

    private function handle_lightboxgallery($resource, $resdir, &$filelist) {
        $context = $resource->context;
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_lightboxgallery', 'gallery_images');

        foreach ($files as $storedfile) {
            if (!$storedfile->is_valid_image()) {
                continue;
            }

            $filename = $resdir . '/' . self::shorten_filename($storedfile->get_filename());
            $filelist[$filename] = $storedfile;
        }
    }
}