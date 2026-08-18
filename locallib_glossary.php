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

trait local_downloadcentercustom_glossary_trait {

    /**
     * Handles the mod type glossary files.
     *
     * @param mixed $resource The resource object being handled.
     * @param string $resdir The directory where the resource files are saved at the end in the ZIP.
     * @param array $filelist Array of files to be included in the ZIP with its data.
     * @return void
     */

    private function handle_glossary($resource, $resdir, &$filelist) {
        global $CFG, $SITE;
        $fs = get_file_storage();
        $context = $resource->context;
        $hook = 'ALL'; // Setting up default values as taken from mod/glossary/print.php!
        $pivotkey = 'concept';
        $fullpivot = false;
        $currentpivot = '';
        $mode = '';
        $fmtoptions = ['context' => $context];
        $glossary = $resource->resource;
        $displayformat = $glossary->displayformat;
        $course = $this->course;
        $cm = $resource->cm;
        $content = '';
        ob_start();
        $sitename = get_string("site") . ': <span class="strong">' . format_string($SITE->fullname) . '</span>';
        echo html_writer::tag('div', $sitename, ['class' => 'sitename']);

        $coursename = get_string("course") . ': <span class="strong">' .
            format_string($course->fullname) . ' (' . format_string($course->shortname) . ')</span>';
        echo html_writer::tag('div', $coursename, ['class' => 'coursename']);

        $modname = get_string("modulename", "glossary") . ': <span class="strong">' .
            format_string($glossary->name, true) . '</span>';
        echo html_writer::tag('div', $modname, ['class' => 'modname']);

        [$allentries, $count] = glossary_get_entries_by_letter($glossary, $context, 'ALL', 0, null);
        if ($allentries) {
            foreach ($allentries as $entry) {
                $pivot = $entry->{$pivotkey};
                $upperpivot = core_text::strtoupper($pivot);
                $pivottoshow = core_text::strtoupper(format_string($pivot, true, $fmtoptions));

                // Reduce pivot to 1cc if necessary.
                if (!$fullpivot) {
                    $upperpivot = core_text::substr($upperpivot, 0, 1);
                    $pivottoshow = core_text::substr($pivottoshow, 0, 1);
                }

                // If there's a group break.
                if ($currentpivot != $upperpivot) {
                    $currentpivot = $upperpivot;
                    echo html_writer::tag('div', clean_text($pivottoshow), ['class' => 'mdl-align strong']);
                }
                glossary_print_entry($course, $cm, $glossary, $entry, $mode, $hook, 1, $displayformat, true);
            }
            // The all entries value may be a recordset or an array.
            if ($allentries instanceof moodle_recordset) {
                $allentries->close();
            }
        }
        $content .= ob_get_contents();
        ob_end_clean();

        $fileurl = $CFG->wwwroot . '/pluginfile.php/' . $context->id . '/mod_glossary/';
        $content = str_replace($fileurl, 'data/', $content);
        $filename = $resdir . '/' . self::shorten_filename($resource->name . '.html');
           $linkrel = '<style>' .
            '.img-fluid { max-width: 100%; height: auto; } ' .
            'table.glossarypost.dictionary, table.glossarypost.dictionary td.entry { width: 100%; } ' .
            '.attachments { display: flex; align-items: center; gap: .25rem; } ' .
            '.attachments a:first-child { flex: 0 0 auto; } ' .
            '.attachments a:first-child img.icon { width: 24px; height: 24px; flex: 0 0 24px; display: inline-block; } ' .
            '.attachments a + a { flex: 1 1 auto; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }' .
            '</style>';
        $content = '<div class="path-mod-glossary" id="page-mod-glossary-print">' . $content . '</div>';
        $content = self::convert_content_to_html_doc($resource->name, $content, $linkrel);
        $filelist[$filename] = [$content];

        // Handle attachments.
        $fsfiles = $fs->get_area_files(
            $context->id,
            'mod_glossary',
            'attachment'
        );
        if (count($fsfiles) > 0) {
            foreach ($fsfiles as $file) {
                if ($file->get_filesize() == 0) {
                    continue;
                }
                $filename = $resdir . '/data/attachment/' . $file->get_itemid() . '/' . $file->get_filename();
                $filelist[$filename] = $file;
            }
        }
        // Handle entries.
        $fsfiles = $fs->get_area_files(
            $context->id,
            'mod_glossary',
            'entry'
        );
        if (count($fsfiles) > 0) {
            foreach ($fsfiles as $file) {
                if ($file->get_filesize() == 0) {
                    continue;
                }
                $filename = $resdir . '/data/entry/' . $file->get_itemid() . '/' . $file->get_filename();
                $filelist[$filename] = $file;
            }
        }
    }

}
