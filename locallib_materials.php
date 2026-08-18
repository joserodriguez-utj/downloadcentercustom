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

trait local_downloadcentercustom_materials_trait {

    //RESOURCES

        /**
     * Handles the mod type resource files.
     *
     * @param mixed $resource The resource being handled.
     * @param string $resdir The directory where the resource files are saved at the end in the ZIP.
     * @param array $filelist The array of files to be included in the ZIP with its files.
     * @param string $basedir The base directory for the resource files.
     * @return void
     */

    private function handle_resource($resource, $resdir, &$filelist, $basedir) {
        $fs = get_file_storage();
        $filesrealnames = $this->downloadoptions['filesrealnames'] ?? false;
        $addnumbering = $this->downloadoptions['addnumbering'] ?? false;
        $context = $resource->context;
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
        $file = array_shift($files); // Get only the first file - such are the requirements!

        if ($filesrealnames) {
            $realfilename = $file->get_filename();
            if ($addnumbering) {
                $realfilename = $resource->prefixindex . '_' . $realfilename;
            }
            if ($this->is_subsection_resource($resource)) {
                $filename = $basedir . '/' . $resource->subsectionname . '/' .
                    self::shorten_filename(self::clean_filename_ascii($realfilename));
            } else {
                $filename = $basedir . '/' . self::shorten_filename(self::clean_filename_ascii($realfilename));
            }
        } else {
            $filename = $resdir;
        }
        unset($filelist[$resdir]);

        $currentextension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (empty($currentextension)) {
            $extension = mimeinfo_from_type('extension', $file->get_mimetype());
        } else {
            $filename = mb_substr($filename, 0, -mb_strlen($currentextension) - 1);
            $extension = ".{$currentextension}";
        }
        $fullfilename = $filename . $extension;
        $filei = 1;
        while (isset($filelist[$fullfilename]) && $filei < 200) {
            $fullfilename = $filename . '_' . $filei . $extension;
            $filei++;
        }
        $filelist[$fullfilename] = $file;
    }

    //PAGES

        /**
     * Handles the mod type page files.
     *
     * @param mixed $resource The resource being handled.
     * @param string $resdir The directory where the resource files are saved at the end in the ZIP.
     * @param array $filelist Array of files to be included in the ZIP with its data.
     * @return void
     */

    private function handle_page($resource, $resdir, &$filelist) {
        $fs = get_file_storage();
        $context = $resource->context;
        $fsfiles = $fs->get_area_files($context->id, 'mod_page', 'content');
        if (count($fsfiles) > 0) {
            foreach ($fsfiles as $file) {
                if ($file->get_filesize() == 0) {
                    continue;
                }
                $filename = $resdir . '/data' . $file->get_filepath() . self::shorten_filename($file->get_filename());
                $filelist[$filename] = $file;
            }
        }
        $filename = $resdir . '.html';
        $content = str_replace('@@PLUGINFILE@@', 'data', $resource->resource->content);
        $content = self::convert_content_to_html_doc($resource->name, $content);
        $filelist[$filename] = [$content]; // Needs to be array to be saved as file.
    }

    //URLs

    private function handle_url($resource, $resdir, &$filelist) {
        $url = $resource->resource->externalurl;
        $name = $resource->name;
        $content = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $name . '</title></head><body>';
        $content .= '<h1>' . $name . '</h1>';
        $content .= '<p><a href="' . $url . '" target="_blank">' . $url . '</a></p>';
        $content .= '</body></html>';
        $filename = $resdir . '/' . self::shorten_filename(clean_filename($name)) . '.html';
        $filelist[$filename] = [$content];
    }

    //LABELS

    private function handle_label($resource, $resdir, &$filelist) {
        global $CFG;
        $label = $resource->resource;
        $name = clean_param($label->name, PARAM_TEXT);
        if (empty(trim($name)) || strpos($name, '@@PLUGINFILE@@') !== false) {
            return;
        }
        // Saltar labels con nombre generico "Etiqueta" (separadores).
        $basename = preg_replace('/\s*\(copia\)\s*/', '', $name);
        if (trim($basename) === 'Etiqueta' || trim($basename) === 'Label') {
            return;
        }
        $content = $label->intro;
        $context = $resource->context;
        $fs = get_file_storage();
        if (!empty($content)) {
            $content = str_replace('@@PLUGINFILE@@', 'Archivos', $content);
            $content = self::convert_content_to_html_doc($name, $content);
            $filename = $resdir . '/' . self::shorten_filename(self::clean_filename_ascii($name)) . '.html';
            $filelist[$filename] = [$content];
        }
        // Archivos embebidos: solo imagenes, sin H5P.
        $fsfiles = $fs->get_area_files($context->id, 'mod_label', 'intro', 0, 'id', false);
        foreach ($fsfiles as $file) {
            if ($file->get_filesize() == 0) {
                continue;
            }
            $ext = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
            if (in_array($ext, ['h5p', 'hvp'])) {
                continue;
            }
            $hash = $file->get_contenthash();
            if (isset($this->filehashes[$hash])) {
                continue;
            }
            $this->filehashes[$hash] = true;
            $filename = $resdir . '/Archivos/' . self::shorten_filename($file->get_filename());
            $filelist[$filename] = $file;
        }
    }

    //BOOKs

        /**
     * Handles the mod type book files.
     *
     * @param mixed $resource The resource object being handled.
     * @param string $resdir The directory where the resource files are saved at the end in the ZIP.
     * @param array $filelist Array of files to be included in the ZIP with its data.
     * @return void
     */

    private function handle_book($resource, $resdir, &$filelist) {
        global $PAGE, $OUTPUT, $DB, $CFG;
        $fs = get_file_storage();
        $bookrenderer = $PAGE->get_renderer('booktool_print');
        $book = $resource->resource;
        $cm = $resource->cm;
        $chapters = book_preload_chapters($book);
        $context = $resource->context;

        $fsfiles = $fs->get_area_files($context->id, 'mod_book', 'chapter');
        if (count($fsfiles) > 0) {
            foreach ($fsfiles as $file) {
                if ($file->get_filesize() == 0) {
                    continue;
                }
                $filename = $resdir . '/data' . $file->get_filepath() . self::shorten_filename($file->get_filename());
                $filelist[$filename] = $file;
            }
        }
        $filename = $resdir . '.html';

        // Taken from mod/book/tool/print/index.php!
        $allchapters = $DB->get_records('book_chapters', ['bookid' => $book->id], 'pagenum');

        $book->intro = str_replace('@@PLUGINFILE@@', 'data', $book->intro);
        $content = '<a name="top"></a>';
        $content .= $OUTPUT->heading(format_string($book->name, true, ['context' => $context]), 1);
        $content .= '<p class="book_summary">' .
            format_text($book->intro, $book->introformat, ['noclean' => true, 'context' => $context])  .
            '</p>';

        $toc = $bookrenderer->render_print_book_toc($chapters, $book, $cm);
        $content .= $toc;
        // Chapters!
        $link1 = $CFG->wwwroot . '/mod/book/view.php?id=' . $this->course->id . '&chapterid=';
        $link2 = $CFG->wwwroot . '/mod/book/view.php?id=' . $this->course->id;
        foreach ($chapters as $ch) {
            $chapter = $allchapters[$ch->id];
            if ($chapter->hidden) {
                continue;
            }
            $content .= '<div class="book_chapter"><a name="ch' . $ch->id . '"></a>';
            $title = book_get_chapter_title($chapter->id, $chapters, $book, $context);
            if (!$book->customtitles) {
                if (!$chapter->subchapter) {
                    $content .= $OUTPUT->heading($title);
                } else {
                    $content .= $OUTPUT->heading($title, 3);
                }
            }
            $chaptercontent = str_replace($link1, '#ch', $chapter->content);
            $chaptercontent = str_replace($link2, '#top', $chaptercontent);

            $chaptercontent = str_replace('@@PLUGINFILE@@', 'data', $chaptercontent);
            $content .= format_text(
                $chaptercontent,
                $chapter->contentformat,
                ['noclean' => true, 'context' => $context]
            );
            $content .= '</div>';
            $content .= '<a href="#toc">&uarr; ' . get_string('top', 'mod_book') . '</a>';
        }
        $content = self::convert_content_to_html_doc($resource->name, $content);
        $filelist[$filename] = [$content]; // Needs to be array to be saved as file.
    }

}