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

trait local_downloadcentercustom_forum_trait {

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
        global $CFG, $DB;
        $context = $resource->context;

        if (!has_capability('local/downloadcentercustom:downloadAssignments', $context->get_course_context())) {
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

        if (!$users) {
            return;
        }

        $evidenciadir = $resdir . '/Evidencias';
        $filelist[$evidenciadir] = null;

        foreach ($users as $user) {
            $studentname = fullname($user);
            $studentfolder = self::shorten_filename(self::clean_filename_ascii($studentname));
            $adjbasedir = $evidenciadir . '/' . $studentfolder . '/Adjuntos';
            $html = $this->build_forum_html($forum, $cm, $user, $studentname, $adjbasedir, $filelist);
            if ($html) {
                $html = self::convert_content_to_html_doc('Resultados - ' . $studentname, $html);
                $filename = $evidenciadir . '/' . self::shorten_filename('Resultados - ' . $studentname . '.html');
                $filelist[$filename] = [$html];
            }
        }
    }

    private function build_forum_html($forum, $cm, $user, $studentname, $adjbasedir, &$filelist) {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        $fs = get_file_storage();
        $contextid = \context_module::instance($cm->id)->id;

        // Discusiones del foro.
        $discussions = $DB->get_records('forum_discussions', ['forum' => $forum->id], 'id ASC');
        if (empty($discussions)) {
            return '';
        }

        $discussionids = array_keys($discussions);

        // Todos los posts (no eliminados) de las discusiones del foro.
        // Se cargan completos para poder calcular el nivel de indentación del hilo.
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

        // Calcular el nivel (profundidad) de cada post: 0 = post principal, 1 = respuesta, 2 = respuesta a respuesta...
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

        // Posts a los que el alumno respondió directamente (contexto).
        $parentposts = [];
        $authorids = [$user->id => true];
        foreach ($posts as $post) {
            if (!empty($post->parent) && isset($postmap[$post->parent])) {
                $parentposts[$post->parent] = $postmap[$post->parent];
                $authorids[$post->parent] = true;
            }
        }
        foreach ($allposts as $ap) {
            if (isset($authorids[$ap->userid])) {
                $authorids[$ap->userid] = true;
            }
        }
        // Asegurar autores de todos los posts padre.
        foreach ($parentposts as $pp) {
            $authorids[$pp->userid] = true;
        }

        // Nombres de autores.
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
            $grademethodstr = $grademethodstr !== '' ?
                ($grademethodstr . ' + ' . get_string('grade_forum_header', 'forum')) :
                get_string('grade_forum_header', 'forum');
        }

        // Calificación final del alumno (del libro de calificaciones).
        // Si el foro usa ratings, la nota está en itemnumber=0; si usa whole forum, en itemnumber=1.
        $finalgrade = '';
        $itemnumber = null;
        if (!empty($forum->assessed)) {
            $itemnumber = 0; // Rating.
        } else if (!empty($forum->grade_forum)) {
            $itemnumber = 1; // Whole forum.
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

        // Número de evaluaciones (ratings) que recibió el alumno en sus posts.
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

        $h = '<h2>Resultados del foro: ' . s($studentname) . ' — ' . s($forum->name) . '</h2>';

        // Tabla resumen.
        $h .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;margin-bottom:15px;">';
        $h .= '<tr style="background:#f2f2f2;"><th>Discusión</th><th>Participaciones</th><th>Última participación</th><th>Método de calificación</th>';
        if (!empty($forum->assessed)) {
            $h .= '<th>Número de evaluaciones (Rating)</th>';
        }
        $h .= '<th>Calificación final</th></tr>';

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
            $h .= '<tr>';
            $h .= '<td><b>Tema de discución:</b> ' . s($discussion->name) . '</td>';
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
        $h .= '</table>';

        // Detalle por discusión: cada respuesta del alumno con el contexto de lo que respondió.
        foreach ($postsbydiscussion as $discussionid => $discussionposts) {
            $discussion = $discussions[$discussionid];
            $h .= '<h3><b>Tema de discución:</b> ' . s($discussion->name) . '</h3>';

            foreach ($discussionposts as $post) {
                // Nivel de indentación según la profundidad en el hilo.
                $depth = $getdepth($post->id);
                $margin = $depth * 30;

                // Contexto: el post al que respondió (si existe).
                if (!empty($post->parent) && isset($parentposts[$post->parent])) {
                    $parent = $parentposts[$post->parent];
                    $pauthor = $authors[$parent->userid] ?? null;
                    $paname = $pauthor ? fullname($pauthor) : ('Usuario ' . $parent->userid);
                    $parentdepth = $getdepth($parent->id);
                    $parentmargin = $parentdepth * 30;
                    $h .= '<div style="border:1px solid #ddd;background:#f5f5f5;border-radius:4px;padding:8px;margin-bottom:4px;margin-left:' . $parentmargin . 'px;">';
                    $h .= '<div><b>' . s($paname) . '</b> — ' . s(userdate($parent->created, '%d de %B de %Y %H:%M')) . '</div>';
                    if (!empty($parent->message)) {
                        $h .= '<div>' . format_text($parent->message, $parent->messageformat) . '</div>';
                    }
                    $h .= '</div>';
                }

                // Respuesta del alumno.
                $h .= '<div style="border:1px solid #198754;border-radius:4px;padding:8px;margin-bottom:12px;margin-left:' . $margin . 'px;">';
                $h .= '<div><b>Autor:</b> <span style="color:#198754;font-weight:bold;">' . s($studentname) . '</span></div>';
                $h .= '<div><b>Fecha:</b> ' . s(userdate($post->created, '%d de %B de %Y %H:%M')) . '</div>';
                if (!empty($post->subject)) {
                    $h .= '<div><b>Asunto:</b> ' . s($post->subject) . '</div>';
                }
                if (!empty($post->message)) {
                    $h .= '<div>' . format_text($post->message, $post->messageformat) . '</div>';
                }

                // Adjuntos del post del alumno.
                if (!empty($post->attachment) && $contextid) {
                    $files = $fs->get_area_files($contextid, 'mod_forum', 'attachment', $post->id, 'filename', false);
                    if (!empty($files)) {
                        $h .= '<div><b>Adjuntos:</b> ';
                        $links = [];
                        foreach ($files as $file) {
                            $fname = self::shorten_filename($file->get_filename());
                            $filelist[$adjbasedir] = null;
                            $filelist[$adjbasedir . '/' . $fname] = $file;
                            $links[] = '<a href="Adjuntos/' . rawurlencode($fname) . '">' . s($file->get_filename()) . '</a>';
                        }
                        $h .= implode(', ', $links);
                        $h .= '</div>';
                    }
                }
                $h .= '</div>';
            }
        }

        return $h;
    }


}
