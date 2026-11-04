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

trait local_centro_de_descarga_de_portafolio_de_evidencias_forum_trait {

    /**
     * Handle Forum module.
     *
     * @param mixed $resource The resource being handled.
     * @param string $resdir The directory where results are saved.
     * @param array $filelist The array of files to be included in the ZIP.
     * @param int|null $groupid Group ID for filtering students.
     * @return void
    */

    private function handle_forum($resource, $resdir, &$filelist, $groupid = null) {
        $this->pdfactivityname = $resource->name;
        global $CFG, $DB;
        $context = $resource->context;

        if (!has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadAssignments', $context->get_course_context())) {
            return;
        }

        $forum = $DB->get_record('forum', ['id' => $resource->instanceid], '*', MUST_EXIST);
        $cm = $resource->cm;

        $users = get_enrolled_users($context, 'mod/forum:viewdiscussion', $groupid, 'u.*', 'u.lastname');

        // Si el usuario (profesor) no tiene grupos asignados, solo descargar
        // participaciones de estudiantes que NO pertenecen a ningún grupo del curso.
        if ($this->onlyungrouped) {
            $allgroupmemberids = $DB->get_fieldset_sql(
                "SELECT DISTINCT gm.userid FROM {groups_members} gm
                  JOIN {groups} g ON g.id = gm.groupid
                 WHERE g.courseid = ?", [$this->course->id]
            );
            $users = array_filter($users, function($u) use ($allgroupmemberids) {
                return !in_array($u->id, $allgroupmemberids);
            });
        }

        if ($this->portfolio_userid !== null) {
            // Portafolio: solo evidencias del estudiante indicado.
            $users = array_filter($users, function($u) {
                return (int)$u->id === (int)$this->portfolio_userid;
            });
        }

        if (!$users) {
            return;
        }

        $evidenciadir = $resdir . '/Evidencias';
        $filelist[$evidenciadir] = null;

        foreach ($users as $user) {
            $studentname = fullname($user);
            $studentfolder = self::shorten_filename(self::clean_filename_ascii($studentname));
            // En portafolio ya hay carpeta por estudiante arriba: adjuntos directo a Evidencias/Adjuntos.
            $adjbase = ($this->portfolio_userid !== null) ? $evidenciadir : ($evidenciadir . '/' . $studentfolder);
            $adjbasedir = $adjbase . '/Adjuntos';

            $body = $this->build_forum_pdf_body($forum, $cm, $user, $studentname, $adjbasedir, $filelist);
            if ($body) {
                $title = get_string('forum_results', 'local_centro_de_descarga_de_portafolio_de_evidencias') . $studentname;
                $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 8, $title);
                $filename = $evidenciadir . '/' . self::shorten_filename($title . '.pdf');
                $filelist[$filename] = [$pdf];
            }
        }
    }

    /**
     * Quita restos "}}" pegados a URLs de pluginfile (mensajes mal guardados).
     *
     * @param string $html
     * @return string
     */
    private function clean_pluginfile_leftovers(string $html): string {
        // Reemplaza reproductores de audio/video por una nota con el archivo adjunto.
        $html = preg_replace_callback('#<(audio|video)\b[^>]*>(.*?)</\1>#is', function ($m) {
            if (preg_match('#pluginfile\.php/[^"\'\s<>]*/([^/"\'\s<>?]+)#', $m[2], $f)) {
                return '<p><b>' . s(get_string('forum_embedded_media', 'local_centro_de_descarga_de_portafolio_de_evidencias'))
                    . '</b> ' . s(rawurldecode($f[1])) . '</p>';
            }
            return '';
        }, $html);
        // Quita restos "}}" pegados a URLs de pluginfile.
        return preg_replace('#(pluginfile\.php/[^"\'\s<>]*?)\}\}#', '$1', $html);
    }

    /**
 * Builds the HTML body for the forum PDF (one per student).
 *
 * Same information as the previous HTML version, but styled for TCPDF
 * (smaller fonts, tables instead of divs where possible, page breaks
 * between discussions to avoid splitting a thread).
 *
 * @param object $forum
 * @param object $cm
 * @param object $user
 * @param string $studentname
 * @param string $adjbasedir
 * @param array  $filelist
 * @return string
 */
    private function build_forum_pdf_body($forum, $cm, $user, $studentname, $adjbasedir, &$filelist) {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        $fs = get_file_storage();
        $contextid = \context_module::instance($cm->id)->id;
        $context = \context::instance_by_id($contextid);

        // Discusiones del foro.
        $discussions = $DB->get_records('forum_discussions', ['forum' => $forum->id], 'id ASC');
        if (empty($discussions)) {
            return '';
        }
        $discussionids = array_keys($discussions);

        // Todos los posts (no eliminados) de las discusiones del foro.
        [$insql, $inparams] = $DB->get_in_or_equal($discussionids);
        $allposts = $DB->get_records_sql(
            "SELECT id, discussion, parent, userid, subject, message, messageformat, created, attachment
            FROM {forum_posts}
            WHERE discussion $insql AND deleted = 0
        ORDER BY discussion ASC, created ASC, id ASC",
            $inparams
        );

        // Solo los posts del alumno.
        $posts = [];
        foreach ($allposts as $post) {
            if ($post->userid == $user->id) {
                $posts[$post->id] = $post;
            }
        }
        if (empty($posts)) {
            return '';
        }

        // Mapa de posts para resolver padres y calcular profundidad.
        $postmap = [];
        foreach ($allposts as $post) {
            $postmap[$post->id] = $post;
        }

        // Profundidad de cada post (0 = principal).
        $depthcache = [];
        $getdepth = function($postid) use (&$getdepth, &$depthcache, $postmap) {
            if (isset($depthcache[$postid])) {
                return $depthcache[$postid];
            }
            $current = $postmap[$postid] ?? null;
            if (!$current || empty($current->parent) || !isset($postmap[$current->parent])) {
                $depthcache[$postid] = 0;
                return 0;
            }
            $depthcache[$postid] = $getdepth($current->parent) + 1;
            return $depthcache[$postid];
        };

        // Posts padre + autores.
        $parentposts = [];
        $authorids = [$user->id => true];
        foreach ($posts as $post) {
            if (!empty($post->parent) && isset($postmap[$post->parent])) {
                $parentposts[$post->parent] = $postmap[$post->parent];
                $authorids[$post->parent] = true;
            }
        }
        foreach ($parentposts as $pp) {
            $authorids[$pp->userid] = true;
        }
        $authors = $DB->get_records_list(
            'user', 'id', array_keys($authorids), '',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
        );

        // Método de calificación del foro.
        require_once($CFG->dirroot . '/rating/lib.php');
        $aggregatemethods = [
            RATING_AGGREGATE_NONE => get_string('aggregatenone', 'rating'),
            RATING_AGGREGATE_AVERAGE => get_string('aggregateavg', 'rating'),
            RATING_AGGREGATE_COUNT => get_string('aggregatecount', 'rating'),
            RATING_AGGREGATE_MAXIMUM => get_string('aggregatemax', 'rating'),
            RATING_AGGREGATE_MINIMUM => get_string('aggregatemin', 'rating'),
            RATING_AGGREGATE_SUM => get_string('aggregatesum', 'rating'),
        ];
        $grademethodstr = '';
        if (!empty($forum->assessed)) {
            $grademethodstr = $aggregatemethods[$forum->assessed] ?? '';
        }
        if (!empty($forum->grade_forum)) {
            $grademethodstr = $grademethodstr !== ''
                ? ($grademethodstr . ' + ' . get_string('grade_forum_header', 'forum'))
                : get_string('grade_forum_header', 'forum');
        }

        // Calificación final del alumno.
        $finalgrade = '';
        $itemnumber = null;
        if (!empty($forum->assessed)) {
            $itemnumber = 0;
        } else if (!empty($forum->grade_forum)) {
            $itemnumber = 1;
        }
        if ($itemnumber !== null) {
            $gradeitem = \grade_item::fetch([
                'itemtype' => 'mod', 'itemmodule' => 'forum',
                'iteminstance' => $forum->id, 'itemnumber' => $itemnumber,
            ]);
            if ($gradeitem) {
                $grade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $user->id]);
                if ($grade && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                    $finalgrade = round($grade->finalgrade, 2);
                }
            }
        }

        // Nº de ratings recibidos en sus posts.
        $numratings = 0;
        if (!empty($forum->assessed) && !empty($posts)) {
            [$postinsql, $postinparams] = $DB->get_in_or_equal(array_keys($posts));
            $numratings = $DB->count_records_sql(
                "SELECT COUNT(*)
                FROM {rating} r
                JOIN {forum_posts} p ON p.id = r.itemid
                WHERE r.component = 'mod_forum' AND r.ratingarea = 'post'
                    AND r.itemid $postinsql",
                $postinparams
            );
        }

        // Estilos.
        $th  = 'style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;text-align:left;font-size:8pt;"';
        $td  = 'style="border:1px solid #adb5bd;padding:2px;vertical-align:top;font-size:8pt;"';
        $hd  = 'style="background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;"';
        $tbl = 'border="1" cellspacing="0" cellpadding="2" style="border-collapse:collapse;width:100%;font-size:8pt;"';

        // 1) Resumen.
        $h  = '<h2 ' . $hd . '>' . s(get_string('forum_forum_results', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . s($studentname) . ' — ' . s($forum->name) . '</h2>';
        $h .= '<table ' . $tbl . '>';
        $h .= '<thead><tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('forum_discussion', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('forum_participations', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('forum_last_post', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<th ' . $th . '>' . s(get_string('forum_grade_method', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>';
        if (!empty($forum->assessed)) {
            $h .= '<th ' . $th . '>' . s(get_string('forum_number_of_ratings', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>';
        }
        $h .= '<th ' . $th . '>' . s(get_string('forum_final_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '</tr></thead><tbody>';

        $postsbydiscussion = [];
        foreach ($posts as $post) {
            if (!isset($postsbydiscussion[$post->discussion])) {
                $postsbydiscussion[$post->discussion] = [];
            }
            $postsbydiscussion[$post->discussion][] = $post;
        }

        $firstdiscussion = true;
        foreach ($postsbydiscussion as $discussionid => $discussionposts) {
            $discussion = $discussions[$discussionid];
            $owncount = count($discussionposts);
            $lastpost = end($discussionposts);
            $h .= '<tr nobr="true">';
            $h .= '<td>' . s($discussion->name) . '</td>';
            $h .= '<td style="text-align:center;">' . $owncount . '</td>';
            $h .= '<td>' . s(userdate($lastpost->created, '%d de %B de %Y %H:%M')) . '</td>';
            if ($firstdiscussion) {
                $h .= '<td style="text-align:center;">' . s($grademethodstr) . '</td>';
                if (!empty($forum->assessed)) {
                    $h .= '<td style="text-align:center;">' . $numratings . '</td>';
                }
                $h .= '<td style="text-align:center;">' . ($finalgrade !== '' ? $finalgrade : '') . '</td>';
                $firstdiscussion = false;
            } else {
                $h .= '<td></td>';
                if (!empty($forum->assessed)) {
                    $h .= '<td></td>';
                }
                $h .= '<td></td>';
            }
            $h .= '</tr>';
        }
        $h .= '</tbody></table>';

        // 2) Detalle por discusión.
        $firstdisc = true;
        foreach ($postsbydiscussion as $discussionid => $discussionposts) {
            $discussion = $discussions[$discussionid];
            $break = $firstdisc ? '' : 'page-break-before:always;';
            $firstdisc = false;

            $h .= '<h2 style="' . $break . 'background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;">'
                . s(get_string('forum_discussion_topic', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . ' ' . s($discussion->name) . '</h2>';

            foreach ($discussionposts as $post) {
                $depth = $getdepth($post->id);
                $margin = min($depth * 8, 40); // mm a la izquierda (tope 40 mm).

                // Contexto (post padre).
                if (!empty($post->parent) && isset($parentposts[$post->parent])) {
                    $parent = $parentposts[$post->parent];
                    $pauthor = $authors[$parent->userid] ?? null;
                    $paname = $pauthor ? fullname($pauthor) : ('Usuario ' . $parent->userid);
                    $parentdepth = $getdepth($parent->id);
                    $parentmargin = min($parentdepth * 8, 40);

                    $h .= '<table ' . $tbl . ' style="margin-left:' . $parentmargin . 'mm;margin-bottom:3mm;background-color:#f5f5f5;border-collapse:collapse;width:' . (100 - $parentmargin / 2) . '%;">';
                    $h .= '<tr><td style="font-size:8pt;border:1px solid #ccc;padding:4px;background:#f5f5f5;">';
                    $h .= '<b>' . s($paname) . '</b> — ' . s(userdate($parent->created, '%d de %B de %Y %H:%M'));
                    if (!empty($parent->message)) {
                        $parentmessage = file_rewrite_pluginfile_urls(
                            $parent->message, 'pluginfile.php', $contextid, 'mod_forum', 'post', $parent->id
                        );
                        $this->add_embedded_files_from_html($parentmessage, $adjbasedir, $filelist);
                        $parentmessage = $this->clean_pluginfile_leftovers($parentmessage);
                        $h .= '<br>' . format_text($parentmessage, $parent->messageformat, ['context' => $context]);
                    }
                    $h .= '</td></tr></table>';
                }

                // Respuesta del alumno.
                $h .= '<table ' . $tbl . ' style="margin-left:' . $margin . 'mm;margin-bottom:3mm;border:1px solid #198754;border-collapse:collapse;width:' . (100 - $margin / 2) . '%;">';
                $h .= '<tr><td style="border:1px solid #198754;padding:4px;background:#ffffff;">';
                $h .= '<b>' . s(get_string('forum_autor', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</b> '
                    . '<span style="color:#198754;font-weight:bold;">' . s($studentname) . '</span><br>';
                $h .= '<b>' . s(get_string('forum_date', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</b> '
                    . s(userdate($post->created, '%d de %B de %Y %H:%M')) . '<br>';
                if (!empty($post->subject)) {
                    $h .= '<b>' . s(get_string('forum_subject', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</b> '
                        . s($post->subject) . '<br>';
                }
                if (!empty($post->message)) {
                    $postmessage = file_rewrite_pluginfile_urls(
                        $post->message, 'pluginfile.php', $contextid, 'mod_forum', 'post', $post->id
                    );
                    $this->add_embedded_files_from_html($postmessage, $adjbasedir, $filelist);
                    $postmessage = $this->clean_pluginfile_leftovers($postmessage);
                    $h .= '<br>' . format_text($postmessage, $post->messageformat, ['context' => $context]);
                }

                // Adjuntos.
                if (!empty($post->attachment) && $contextid) {
                    $files = $fs->get_area_files($contextid, 'mod_forum', 'attachment', $post->id, 'filename', false);
                    if (!empty($files)) {
                        $h .= '<br><b>' . s(get_string('forum_attachments', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</b> ';
                        $links = [];
                        foreach ($files as $file) {
                            $fname = self::shorten_filename($file->get_filename());
                            $filelist[$adjbasedir] = null;
                            $filelist[$adjbasedir . '/' . $fname] = $file;
                            $links[] = '<a href="' . get_string('forum_attachments_url', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '/' . rawurlencode($fname) . '">' . s($file->get_filename()) . '</a>';
                        }
                        $h .= implode(', ', $links);
                    }
                }
                $h .= '</td></tr></table>';
            }
        }

        return $h;
    }


}
