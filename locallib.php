<?php
// This file is part of local_downloadcentercustom for Moodle - http://moodle.org/
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
 * Download center plugin
 *
 * @package       local_downloadcentercustom
 * @author        Simeon Naydenov (moniNaydenov@gmail.com)
 * @copyright     2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */
class local_downloadcentercustom_factory {
    /**
     * @var mixed|object
     */
    private $course;
    /**
     * @var mixed|object
     */
    private $user;
    /**
     * @var array
     */
    private $sortedresources;
    /**
     * @var array
     */
    private $filteredresources;
    /**
     * @var array
     */
    private $downloadoptions;
    /**
     * @var array
     */
    private $selectedgroups = [];
    /**
     * @var bool
     */
    private $onlyungrouped = false;
    /**
     * @var array
     */
    private $filehashes = [];
    /**
     * @var array
     */
     private $availableresources = [
        'resource',
        'folder',
        'publication',
        'page',
        'book',
        'lightboxgallery',
        'assign',
        'quiz',
        'h5pactivity',
        'glossary',
        'etherpadlite',
        'subsection',
        'url',
        'label',
    ];
    /**
     * @var array
     */
    private $jsnames = [];
    /**
     * Array to keep track of the path duplicates to ensure unique paths.
     * This is needed when numbering is not used, so that different sections, or subsections with the same
     * name do not land in the same folder. Also that activites with the same name do not get overwritten.
     * @var array
     */
    private $pathcount = [];

    /**
     * local_downloadcentercustom_factory constructor.
     * @param mixed|object $course
     * @param mixed|object $user
     */
    public function __construct($course, $user) {
        $this->course = $course;
        $this->user = $user;
        $this->downloadoptions = [
            'filesrealnames' => false,
            'addnumbering' => false,
        ];
    }

    /**
     * Set the selected groups to filter submissions.
     *
     * @param array $groupids
     */
    public function set_selected_groups(array $groupids): void {
        $this->selectedgroups = $groupids;
    }

    /**
     * Returns an array of all the resources for the download center of the course for the user.
     *
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function get_resources_for_user() {
        global $DB, $CFG;

        // Only downloadable resources should be shown!
        if (!empty($this->sortedresources)) {
            return $this->sortedresources;
        }

        $modinfo = get_fast_modinfo($this->course);
        $usesections = course_format_uses_sections($this->course->format);
        $canviewhiddensections = has_capability(
            'moodle/course:viewhiddensections',
            context_course::instance($this->course->id)
        );
        $canviewhiddenactivities = has_capability(
            'moodle/course:viewhiddenactivities',
            context_course::instance($this->course->id)
        );
        $sorted = [];
        if ($usesections) {
            $sections = $DB->get_records('course_sections', ['course' => $this->course->id], 'section');
            // Thanks to https://github.com/marinaglancy for the fix!
            $max = course_get_format($this->course)->get_format_options()['numsections'] ?? count($sections);
            $unnamedsections = [];
            $namedsections = [];
            foreach ($sections as $section) {
                if (intval($section->section) > $max) {
                    break;
                }
                if (!isset($sorted[$section->section]) && ($section->visible || $canviewhiddensections)) {
                    $sorted[$section->section] = new stdClass();
                    $title = trim(get_section_name($this->course, $section->section));
                    $title = self::shorten_filename($title);
                    $sorted[$section->section]->title = $title;
                    $sorted[$section->section]->visible = $section->visible;
                    // Item id is needed to find the corresponding subsection.
                    $sorted[$section->section]->itemid = $section->itemid;
                    if (empty($title)) {
                        $unnamedsections[] = $section->section;
                    } else {
                        $namedsections[$title] = true;
                    }
                    $sorted[$section->section]->res = [];
                }
            }

            foreach ($unnamedsections as $sectionid) {
                $untitled = get_string('untitled', 'local_downloadcentercustom');
                $title = $untitled;
                $i = 1;
                while (isset($namedsections[$title])) {
                    $title = $untitled . ' ' . strval($i);
                    $i++;
                }
                $namedsections[$title] = true;
                $sorted[$sectionid]->title = $title;
            }
        } else {
            $sorted['default'] = new stdClass();
            $sorted['default']->title = '0';
            $sorted['default']->res = [];
            $sorted['default']->itemid = -1;
        }
        $cms = [];
        $resources = [];
        foreach ($modinfo->cms as $cm) {
            if (!in_array($cm->modname, $this->availableresources)) {
                continue;
            }
            if (!$cm->uservisible && $cm->modname != 'subsection') {
                continue;
            }
            if ($cm->modname == 'label' && (strpos($cm->name, '@@PLUGINFILE@@') !== false ||
                preg_match('/^(Etiqueta|Label)(\s*\(copia\)\s*)*$/i', trim($cm->name)))) {
                continue; // Saltar labels H5P o separadores genericos.
            }
            if (!$cm->has_view() && $cm->modname != 'folder' && $cm->modname != 'subsection' && $cm->modname != 'label') {
                // Exclude label and similar!
                continue;
            }
            $cms[$cm->id] = $cm;
            $resources[$cm->modname][] = $cm->instance;
        }

        // Preload instances!
        foreach ($resources as $modname => $instances) {
            $resources[$modname] = $DB->get_records_list($modname, 'id', $instances, 'id');
        }
        $availablesections = array_keys($sorted);
        $currentsection = '';
        foreach ($cms as $cm) {
            if (!isset($resources[$cm->modname][$cm->instance])) {
                continue;
            }
            $resource = $resources[$cm->modname][$cm->instance];

            if ($usesections) {
                if ($cm->sectionnum !== $currentsection) {
                    $currentsection = $cm->sectionnum;
                }
                if (!in_array($currentsection, $availablesections)) {
                    continue;
                }
            } else {
                $currentsection = 'default';
            }

            if ($cm->is_stealth() &&  !$canviewhiddenactivities) {
                continue; // Don't allow stealth activities for students!
            }

            $cmcontext = context_module::instance($cm->id);
            if ($cm->modname == 'glossary') {
                if (!has_capability('mod/glossary:manageentries', $cmcontext) && !$resource->allowprintview) {
                    continue;
                }
            }

            if (!isset($this->jsnames[$cm->modname]) && $cm->modname != 'subsection') {
                $this->jsnames[$cm->modname] = get_string('modulenameplural', 'mod_' . $cm->modname);
            }

            $icon = '<img src="' . $cm->get_icon_url() . '" class="activityicon" alt="' . $cm->get_module_type_name() . '" /> ';
            $res = new stdClass();
            $res->icon = $icon;
            $res->cmid = $cm->id;
            $res->name = $cm->get_formatted_name();
            $res->modname = $cm->modname;
            $res->instanceid = $cm->instance;
            $res->resource = $resource;
            $res->cm = $cm;
            $res->visible = $cm->visible;
            $res->isstealth = $cm->is_stealth();
            $res->context = $cmcontext;
            $sorted[$currentsection]->res[] = $res;
        }

        $this->replace_subsection_resources($sorted);

        // Filter out subsections.
        $filtered = [];
        foreach ($sorted as $section) {
            if (empty($section->itemid)) {
                $filtered[] = $section;
            }
        }

        $this->sortedresources = $filtered;

        return $filtered;
    }

    /**
     * Replaces the subsection resource with the actual resources from the subsection.
     *
     * @param array $sections All sections with the resources.
     */
    private function replace_subsection_resources(&$sections) {
        foreach ($sections as $section) {
            $resources = $section->res;
            $newresources = [];
            foreach ($resources as $resource) {
                if ($resource->modname == 'subsection') {
                    $subsectionresources = $this->get_resources_from_subsection($sections, $resource->instanceid);
                    foreach ($subsectionresources as $subresource) {
                        $subresource->issubresource = true;
                        $subresource->subsectionname = $resource->name;
                        $subresource->subsectioncmid = $resource->cmid;
                        $newresources[] = $subresource;
                    }
                } else {
                    $newresources[] = $resource;
                }
            }
            $section->res = $newresources;
        }
    }

    /**
     * Returns all the resources from a subsection.
     *
     * @param array $allsections
     * @param int $sectionitemid
     * @return array
     */
    private function get_resources_from_subsection($allsections, $sectionitemid) {
        $subsection = $this->get_subsection_from_sections($allsections, $sectionitemid);
        return $subsection->res;
    }

    /**
     * Returns a subsection from all sections based on the section item id.
     *
     * @param array $allsections
     * @param int $sectionitemid
     * @return stdClass|null
     */
    private function get_subsection_from_sections($allsections, $sectionitemid) {
        foreach ($allsections as $section) {
            if ($section->itemid == $sectionitemid) {
                return $section;
            }
        }
        return null;
    }

    /**
     * Returns the module names for the JS.
     *
     * @return array
     */
    public function get_js_modnames() {
        return [$this->jsnames];
    }

    /**
     * Checks if the resource is in a subsection.
     *
     * @param mixed $resource
     * @return bool
     */
    public function is_subsection_resource($resource) {
        return !empty($resource->issubresource);
    }

    /**
     * Filters out empty sections from the resource list.
     *
     * @return array Containing only the sections with resources.
     */
    private function filter_empty_sections() {
        $sections = [];
        $filteredresources = $this->filteredresources;
        foreach ($filteredresources as $section) {
            if (!empty($section->res)) {
                $sections[] = $section;
            }
        }
        return $sections;
    }

    /**
     * Builds a dictionary of section base directory names that have duplicates.
     * Key is the cleaned section title, value is 1 if duplicate, 0 otherwise.
     * Needed to preprocess the section names (to avoid overwriting duplicates).
     *
     * @param array $sections
     * @return array
     */
    private function return_duplicates_dictionary($sections) {
        $titlecounts = [];
        // Count occurrences of each cleaned section title.
        foreach ($sections as $section) {
            if (!isset($section->title)) {
                continue;
            }
            $title = html_entity_decode($section->title);
            $basedir = self::clean_filename_ascii($title);
            if (!isset($titlecounts[$basedir])) {
                $titlecounts[$basedir] = 0;
            }
            $titlecounts[$basedir]++;
        }
        // Build the dictionary: 1 if duplicate, 0 otherwise.
        $duplicates = [];
        foreach ($titlecounts as $basedir => $count) {
            $duplicates[$basedir] = $count > 1 ? 1 : 0;
        }
        return $duplicates;
    }

    /**
     * Returns an array of a dictionary with the section path names with cleaned duplicates.
     * The keys are the cleaned section titles, and the values are the resource arrays.
     *
     * @return array
     */
    private function section_pathnames() {
        $pathlist = [];
        $sections = $this->filter_empty_sections();
        $duplicates = $this->return_duplicates_dictionary($sections);

        $addnumbering = $this->downloadoptions['addnumbering'];
        $topicprefixid = 1;
        $topicscount = count($sections);
        $topicprefixformat = '%0' . strlen($topicscount) . 'd';
        foreach ($sections as $section) {
            $title = html_entity_decode($section->title);
            $basedir = self::clean_filename_ascii($title);
            if ($addnumbering) {
                $basedir = sprintf($topicprefixformat, $topicprefixid) . '_' . $basedir;
                $topicprefixid++;
            } else if (!$addnumbering) {
                if ($duplicates[$basedir] > 0) {
                    $basedir .= $duplicates[$basedir]++;
                }
            }
            $basedir = self::shorten_filename($basedir);
            $pathlist[$basedir] = $section->res;
        }
        return $pathlist;
    }

    /**
     * Preprocesses resource names for subsections, handling duplicate names and optional prefix numbering.
     *
     * If $addprefixnumbering is false: Finds all resources that are in a subsection and adds suffix numbering to resource
     * names that are duplicate.
     *
     * If $addprefixnumbering is true: Adds a numeric prefix to all subsection names and resource names, regardless of duplicates.
     *
     * Returns the modified $resources array, with updated name and subsectionname properties.
     *
     * @param array $resources Array of resource objects.
     * @param bool $addprefixnumbering If true, all names get a numeric prefix; if false, only duplicates get a suffix.
     * @return array The modified array of resource objects, with updated name and subsectionname properties.
     */
    private function preprocess_resource_names($resources, $addprefixnumbering) {
        if (!$addprefixnumbering) {
            $result = [];
            $duplicateids = [];
            foreach ($resources as $res) {
                if ($this->is_subsection_resource($res)) {
                    $name = $res->subsectionname;
                    $id = $res->subsectioncmid;
                    if (!isset($duplicateids[$name])) {
                        $duplicateids[$name] = [];
                    }
                    if (!in_array($id, $duplicateids[$name])) {
                        $duplicateids[$name][] = $id;
                    }
                }
            }
            // Original logic: only add suffix for duplicates, unique names as-is.
            foreach ($duplicateids as $name => $ids) {
                if (count($ids) > 1) {
                    $index = 1;
                    foreach ($ids as $id) {
                        $result[$id] = $name . $index;
                        $index++;
                    }
                } else {
                    $result[$ids[0]] = $name;
                }
            }
            // Update resource names; only touch subsection properties when applicable.
            foreach ($resources as $res) {
                $res->name = html_entity_decode($res->name);
                if ($this->is_subsection_resource($res) && isset($res->subsectioncmid)) {
                    $subsecid = $res->subsectioncmid;
                    if (isset($result[$subsecid])) {
                        $res->subsectionname = $result[$subsecid];
                    }
                }
            }
        } else if ($addprefixnumbering) {
            $resourceindex = 0;
            $subresourceindex = 1;
            $currentsubseccmid = -1;
            $count = count($resources);
            $prefixformat = '%0' . strlen($count) . 'd';
            foreach ($resources as $res) {
                if ($this->is_subsection_resource($res)) {
                    if ($currentsubseccmid != $res->subsectioncmid) {
                        $currentsubseccmid = $res->subsectioncmid;
                        $subresourceindex = 1;
                        $resourceindex++;
                    }
                    $res->subsectionname = sprintf($prefixformat, $resourceindex) . '_' . $res->subsectionname;
                    $res->name = sprintf($prefixformat, $subresourceindex) . '_' . $res->name;
                    $res->prefixindex = sprintf($prefixformat, $subresourceindex);
                    $subresourceindex++;
                } else {
                    $resourceindex++;
                    $res->name = sprintf($prefixformat, $resourceindex) . '_' . $res->name;
                    $res->prefixindex = sprintf($prefixformat, $resourceindex);
                }
            }
        }
        return $resources;
    }

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
        $filesrealnames = $this->downloadoptions['filesrealnames'];
        $addnumbering = $this->downloadoptions['addnumbering'];
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

    /**
     * Get the user IDs of members from the selected groups.
     *
     * @return array
     */
    private function get_group_member_ids(): array {
        global $DB;
        if (empty($this->selectedgroups)) {
            return [];
        }
        [$gsql, $gparams] = $DB->get_in_or_equal($this->selectedgroups);
        $members = $DB->get_records_sql(
            "SELECT DISTINCT userid FROM {groups_members} WHERE groupid $gsql", $gparams
        );
        return array_keys($members);
    }

    /**
     * Handle quiz module.
     *
     * @param mixed $resource The resource being handled.
     * @param string $resdir The directory where results are saved.
     * @param array $filelist The array of files to be included in the ZIP.
     * @param int|null $groupid Group ID for filtering students.
     * @return void
     */
    private function handle_quiz($resource, $resdir, &$filelist, $groupid = null) {
        global $CFG, $DB;
        $context = $resource->context;
        if (!has_capability('local/downloadcentercustom:downloadQuizz', $context->get_course_context())) {
            return;
        }
        // La descarga de exámenes depende del checkbox "Intentos".
        if (empty($this->downloadoptions['quiztries'])) {
            return;
        }
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $quiz = $DB->get_record('quiz', ['id' => $resource->instanceid], '*', MUST_EXIST);
        $cm = $resource->cm;
        $grademethod = $quiz->grademethod;
        $attemptsallowed = $quiz->attempts;

        $users = get_enrolled_users($context, 'mod/quiz:attempt', $groupid, 'u.*', 'u.lastname');

        // Si el usuario (profesor) no tiene grupos asignados, solo descargar
        // intentos de estudiantes que NO pertenecen a ningún grupo del curso.
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
            $attempts = $this->get_quiz_attempts_for_user($quiz, $user->id, $grademethod, $attemptsallowed);
            if (empty($attempts)) {
                continue;
            }
            $html = $this->build_quiz_html($quiz, $cm, $user, $studentname, $attempts, $grademethod);
            if ($html) {
                $html = self::convert_content_to_html_doc('Resultados - ' . $studentname, $html);
                $filename = $evidenciadir . '/' . self::shorten_filename('Resultados - ' . $studentname . '.html');
                $filelist[$filename] = [$html];
            }
        }
    }

    /**
     * Get quiz attempts for a user based on grading method.
     *
     * @param object $quiz
     * @param int $userid
     * @param int $grademethod
     * @param int $attemptsallowed
     * @return array
     */
    private function get_quiz_attempts_for_user($quiz, $userid, $grademethod, $attemptsallowed) {
        global $DB;
        $allattempts = $DB->get_records('quiz_attempts', [
            'quiz' => $quiz->id, 'userid' => $userid, 'state' => 'finished',
        ], 'attempt ASC');
        if (empty($allattempts)) {
            return [];
        }
        return array_values($allattempts);
    }

    /**
     * Build HTML table with quiz results for one student.
     */
    private function build_quiz_html($quiz, $cm, $user, $studentname, $attempts, $grademethod) {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_item.php');
        require_once($CFG->libdir . '/grade/grade_grade.php');

        $gradeitem = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id]);
        $finalgrade = '';
        if ($gradeitem) {
            $grade = \grade_grade::fetch(['itemid' => $gradeitem->id, 'userid' => $user->id]);
            if ($grade && isset($grade->finalgrade) && $grade->finalgrade !== null) {
                $finalgrade = round($grade->finalgrade, 2);
            }
        }

        // Fallback: si no hay calificación en el libro de calificaciones, calcularla de los intentos.
        if ($finalgrade === '') {
            $grades = array_column($attempts, 'sumgrades');
            if ($grades) {
                switch ($grademethod) {
                    case QUIZ_GRADEHIGHEST:
                        $finalgrade = round(max($grades), 2);
                        break;
                    case QUIZ_GRADEAVERAGE:
                        $finalgrade = round(array_sum($grades) / count($grades), 2);
                        break;
                    case QUIZ_ATTEMPTFIRST:
                        $finalgrade = round(reset($grades), 2);
                        break;
                    case QUIZ_ATTEMPTLAST:
                        $finalgrade = round(end($grades), 2);
                        break;
                    default:
                        $finalgrade = round(reset($grades), 2);
                }
            }
        }

        $methodnames = [
            QUIZ_GRADEHIGHEST => 'Calificación más alta',
            QUIZ_GRADEAVERAGE => 'Promedio de calificaciones',
            QUIZ_ATTEMPTFIRST => 'Primer intento',
            QUIZ_ATTEMPTLAST => 'Último intento',
        ];
        $grademethodstr = $methodnames[$grademethod] ?? '';

        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot ASC');

        $h = '<table border="1" cellspacing="0" cellpadding="3" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:12px;">';
        $h .= '<tr>';
        $h .= '<th>Apellido(s)</th><th>Nombre</th><th>Dirección Email</th><th>Estado</th>';
        $h .= '<th>Iniciado</th><th>Finalizado</th><th>Duración</th>';
        $qnum = 0;
        foreach ($slots as $slot) {
            $qnum++;
            $h .= '<th>Pregunta ' . $qnum . '</th>';
            $h .= '<th>Respuesta ' . $qnum . '</th>';
            $h .= '<th>Respuesta correcta ' . $qnum . '</th>';
        }
        $h .= '<th>Calificación intento</th><th>Método de calificación</th><th>Calificación final</th>';
        $h .= '</tr>';

        $numattempts = count($attempts);
        $attemptindex = 0;
        foreach ($attempts as $attempt) {
            $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);

            $h .= '<tr>';
            $h .= '<td>' . s($user->lastname) . '</td>';
            $h .= '<td>' . s($user->firstname) . '</td>';
            $h .= '<td>' . s($user->email) . '</td>';
            $h .= '<td>' . s(get_string('state' . $attempt->state, 'quiz')) . '</td>';
            $h .= '<td>' . s(userdate($attempt->timestart, '%d de %B de %Y %H:%M')) . '</td>';
            $h .= '<td>' . s(userdate($attempt->timefinish, '%d de %B de %Y %H:%M')) . '</td>';
            $h .= '<td>' . s(format_time($attempt->timefinish - $attempt->timestart)) . '</td>';

            foreach ($slots as $slot) {
                $qa = $quba->get_question_attempt($slot->slot);
                $question = $qa->get_question();

                $h .= '<td>' . $this->quiz_question_cell($qa, $question) . '</td>';
                $h .= '<td>' . $this->quiz_clean_text($qa->get_response_summary()) . '</td>';
                $h .= '<td>' . $this->quiz_clean_text($this->quiz_correct_answer($qa, $question)) . '</td>';
            }

            $h .= '<td style="text-align:center;">' . round($attempt->sumgrades, 2) . '</td>';

            // Método de calificación y calificación final se muestran una sola vez,
            // combinadas en las celdas que abarcan todos los intentos.
            if ($attemptindex === 0) {
                $h .= '<td style="text-align:center;vertical-align:middle;" rowspan="' . $numattempts . '">' . s($grademethodstr) . '</td>';
                $h .= '<td style="text-align:center;vertical-align:middle;" rowspan="' . $numattempts . '">' . ($finalgrade !== '' ? $finalgrade : '') . '</td>';
            }

            $h .= '</tr>';
            $attemptindex++;
        }

        $h .= '</table>';
        return $h;
    }

    /**
     * Convierte texto HTML de una pregunta/respuesta a texto plano seguro con saltos de línea.
     *
     * @param string|null $text
     * @return string
     */
    private function quiz_clean_text($text) {
        if ($text === null || $text === '') {
            return '-';
        }
        return nl2br(s(trim(html_to_text($text, 0, false))));
    }

    /**
     * Construye la celda de la pregunta incluyendo el enunciado y las opciones (multichoice).
     *
     * @param object $question
     * @return string
     */
    private function quiz_question_cell($qa, $question) {
        $text = trim(html_to_text($question->questiontext, 0, false));
        if ($question instanceof \qtype_multichoice_base) {
            $order = $question->get_order($qa);
            $ids = $order ? $order : array_keys($question->answers);
            $options = [];
            $i = 0;
            foreach ($ids as $aid) {
                if (!isset($question->answers[$aid])) {
                    continue;
                }
                $prefix = ($i === 0) ? ':' : ';';
                $options[] = $prefix . ' ' . trim(html_to_text($question->answers[$aid]->answer, 0, false));
                $i++;
            }
            if ($options) {
                $text .= "\n" . implode("\n", $options);
            }
        }
        return nl2br(s($text));
    }

    /**
     * Obtiene la respuesta correcta en texto plano según el tipo de pregunta.
     *
     * @param object $qa
     * @param object $question
     * @return string
     */
    private function quiz_correct_answer($qa, $question) {
        if ($question instanceof \qtype_multichoice_base) {
            $order = $question->get_order($qa);
            $ids = $order ? $order : array_keys($question->answers);
            $parts = [];
            foreach ($ids as $aid) {
                if (!isset($question->answers[$aid])) {
                    continue;
                }
                $answer = $question->answers[$aid];
                if ($answer->fraction > 0) {
                    $parts[] = trim(html_to_text($answer->answer, 0, false));
                }
            }
            return implode('; ', $parts);
        }
        if ($question instanceof \qtype_shortanswer) {
            foreach ($question->answers as $aid => $answer) {
                if ($answer->fraction > 0) {
                    return trim(html_to_text($answer->answer, 0, false));
                }
            }
            return '';
        }
        if ($question instanceof \qtype_match) {
            $parts = [];
            foreach ($question->subquestions as $sq) {
                $parts[] = trim(html_to_text($sq->questiontext, 0, false)) . ' → ' . trim(html_to_text($sq->answertext, 0, false));
            }
            return implode("\n", $parts);
        }
        $ras = $qa->get_right_answer_summary();
        return $ras !== null ? $ras : '';
    }

    /**
     * Handle H5P module.
     *
     * @param mixed $resource The resource being handled.
     * @param string $resdir The directory where results are saved.
     * @param array $filelist The array of files to be included in the ZIP.
     * @param int|null $groupid Group ID for filtering students.
     * @return void
    */

    private function handle_h5pactivity($resource, $resdir, &$filelist, $groupid = null) {
        global $CFG, $DB;
        $context = $resource->context;

        if (!has_capability('local/downloadcentercustom:downloadAssignments', $context->get_course_context())) {
            return;
        }
        
        require_once($CFG->dirroot . '/mod/h5pactivity/classes/local/manager.php');
        require_once($CFG->dirroot . '/mod/h5pactivity/classes/local/attempt.php');
        require_once($CFG->dirroot . '/mod/h5pactivity/classes/output/result.php');

        $h5p = $DB->get_record('h5pactivity', ['id' => $resource->instanceid], '*', MUST_EXIST);
        $cm = $resource->cm;
        
        if ($h5p->grademethod == \mod_h5pactivity\local\manager::GRADEMANUAL) {
            return;
        }

        $users = get_enrolled_users($context, 'mod/h5pactivity:submit', $groupid, 'u.*', 'u.lastname');
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
            $html = $this->build_h5p_html($h5p, $cm, $user, $studentname);
            if ($html) {
                $html = self::convert_content_to_html_doc('Resultados - ' . $studentname, $html);
                $filename = $evidenciadir . '/' . self::shorten_filename('Resultados - ' . $studentname . '.html');
                $filelist[$filename] = [$html];
            }
        }
    }

    private function build_h5p_html($h5p, $cm, $user, $studentname) {
        global $DB, $PAGE;

        $manager = \mod_h5pactivity\local\manager::create_from_instance($h5p);
        $attempts = $manager->get_user_attempts($user->id);
        if (empty($attempts)) {
            return '';
        }

        $renderer = $PAGE->get_renderer('core');

        $methods = \mod_h5pactivity\local\manager::get_grading_methods();
        $grademethodstr = $methods[$h5p->grademethod] ?? '';

        $h = '<h2>Resultados: ' . s($studentname) . ' — ' . s($grademethodstr) . '</h2>';

        $h .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;">';
        $h .= '<tr style="background:#f2f2f2;"><th>#</th><th>Fecha</th><th>Puntaje</th><th>Puntaje máximo</th><th>Duración</th><th>Éxito</th></tr>';
        foreach ($attempts as $attempt) {
            $h .= '<tr>';
            $h .= '<td>' . $attempt->get_attempt() . '</td>';
            $h .= '<td>' . s(userdate($attempt->get_timecreated())) . '</td>';
            $h .= '<td>' . $attempt->get_rawscore() . '</td>';
            $h .= '<td>' . $attempt->get_maxscore() . '</td>';
            $h .= '<td>' . s(format_time($attempt->get_duration())) . '</td>';
            $h .= '<td>' . ($attempt->get_success() ? 'Sí' : 'No') . '</td>';
            $h .= '</tr>';
        }
        $h .= '</table>';

        // Detalle de respuestas por intento.
        foreach ($attempts as $attempt) {
            $h .= '<h3>Intento #' . $attempt->get_attempt() . '</h3>';

            $results = $attempt->get_results();
            if (empty($results)) {
                $h .= '<p><em>Sin respuestas registradas para este intento.</em></p>';
                continue;
            }

            foreach ($results as $result) {
                $outputresult = \mod_h5pactivity\output\result::create_from_record($result);
                if (!$outputresult) {
                    continue;
                }
                $data = $outputresult->export_for_template($renderer);

                if (!empty($data->description)) {
                    $h .= '<p><strong>' . s($data->description) . '</strong></p>';
                }

                if (!empty($data->hasoptions) && !empty($data->options)) {
                    $h .= '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px;margin-bottom:10px;">';
                    $h .= '<tr style="background:#f2f2f2;">';
                    $h .= '<th>' . s($data->optionslabel ?? '') . '</th>';
                    $h .= '<th>' . s($data->correctlabel ?? '') . '</th>';
                    $h .= '<th>' . s($data->answerlabel ?? '') . '</th>';
                    $h .= '</tr>';
                    foreach ($data->options as $option) {
                        $h .= '<tr>';
                        $h .= '<td>' . s($option->description ?? '') . '</td>';
                        $h .= '<td>' . s($option->correctanswer->answer ?? '') . '</td>';
                        $h .= '<td>' . $this->h5p_answer_html($option->useranswer ?? null) . '</td>';
                        $h .= '</tr>';
                    }
                    if (!empty($data->score)) {
                        $h .= '<tr style="background:#f9f9f9;"><td colspan="3"><strong>' . s(get_string('score', 'mod_h5pactivity') . ': ' . $data->score) . '</strong></td></tr>';
                    }
                    $h .= '</table>';
                } else if (!empty($data->content)) {
                    $h .= $data->content;
                }
            }
        }
        return $h;
    }

    /**
     * Renderiza la respuesta del alumno con indicador de correcto/incorrecto.
     *
     * @param stdClass|null $useranswer objeto con la respuesta y su estado.
     * @return string
     */
    private function h5p_answer_html($useranswer) {
        if (empty($useranswer) || !isset($useranswer->answer)) {
            return '-';
        }
        $answer = s($useranswer->answer);
        if (!empty($useranswer->correct) || !empty($useranswer->pass) || !empty($useranswer->checked)) {
            return '<span style="color:#198754;">✔ ' . $answer . '</span>';
        }
        if (!empty($useranswer->incorrect) || !empty($useranswer->fail)) {
            return '<span style="color:#dc3545;">✘ ' . $answer . '</span>';
        }
        return $answer;
    }

    /**
     * Handles the mod type publication files.
     *
     * @param mixed $resource The resource being handled.
     * @param string $resdir The directory where the resource files are saved at the end in the ZIP.
     * @param array $filelist The array of files to be included in the ZIP with its files.
     * @return void
     */
    private function handle_publication($resource, $resdir, &$filelist, $groupid = null) {
        global $DB, $USER, $CFG;
        $userfields = \core_user\fields::for_userpic();
        $context = $resource->context;
        // Portón: si no tiene permiso, no procesa nada de esta publicación.
        if (!has_capability('local/downloadcentercustom:downloadAssignments', $context->get_course_context())) {
            return;
        }
        $fs = get_file_storage();

        $cm = $resource->cm;

        $conditions = [];
        $conditions['publication'] = $resource->instanceid;

        // Find out current groups mode.
        $currentgroup = groups_get_activity_group($cm, true);

        // Get all ppl that are allowed to submit assignments.
        [$esql, $params] = get_enrolled_sql($context, 'mod/publication:view', $currentgroup);
        $showall = false;

        if (
            has_capability('mod/publication:approve', $context) ||
            has_capability('mod/publication:grantextension', $context)
        ) {
            $showall = true;
        }

        if ($showall) {
            $sql = 'SELECT u.id FROM {user} u ' .
                'LEFT JOIN (' . $esql . ') eu ON eu.id=u.id ' .
                'WHERE u.deleted = 0 AND eu.id=u.id';
        } else {
            $sql = 'SELECT u.id FROM {user} u ' .
                'LEFT JOIN (' . $esql . ') eu ON eu.id=u.id ' .
                'LEFT JOIN {publication_file} files ON (u.id = files.userid) ' .
                'WHERE u.deleted = 0 AND eu.id=u.id ' .
                'AND files.publication = ' . $resource->instanceid . ' ';

            $where = [];

            if ($resource->resource->obtainteacherapproval) {
                // Need teacher approval.
                $where[] = 'files.teacherapproval = 1';
            }
            if ($resource->resource->obtainstudentapproval) {
                $where[] = 'files.studentapproval = 1';
            }

            if (!empty($where)) {
                $sql .= ' AND ' . implode(' AND ', $where) . ' ';
            }
            $sql .= 'GROUP BY u.id';
        }

        $users = $DB->get_records_sql($sql, $params);

        if (!empty($users)) {
            $users = array_keys($users);
        }

        // Filter by selected groups if any.
        if ($this->onlyungrouped && !empty($users)) {
            // Solo usuarios que NO pertenecen a ningún grupo del curso.
            global $DB;
            $allgroupmemberids = $DB->get_fieldset_sql(
                "SELECT DISTINCT gm.userid FROM {groups_members} gm
                  JOIN {groups} g ON g.id = gm.groupid
                 WHERE g.courseid = ?", [$this->course->id]
            );
            $users = array_diff($users, $allgroupmemberids);
        } else if (!empty($this->selectedgroups) && !empty($users)) {
            $groupmemberids = $this->get_group_member_ids();
            $users = array_intersect($users, $groupmemberids);
        }

        // If groupmembersonly used, remove users who are not in any group.
        if ($users && !empty($CFG->enablegroupmembersonly) && $cm->groupmembersonly) {
            if ($groupingusers = groups_get_grouping_members($cm->groupingid, 'u.id', 'u.id')) {
                $users = array_intersect($users, array_keys($groupingusers));
            }
        }

        $userfields = [];
        foreach (\core_user\fields::get_name_fields() as $field) {
            $userfields[$field] = $field;
        }
        $userfields['id'] = 'id';
        $userfields['username'] = 'username';
        $userfields = implode(', ', $userfields);

        $viewfullnames = has_capability('moodle/site:viewfullnames', $context);

        // Get all files from each user.
        foreach ($users as $uploader) {
            $auserid = $uploader;
            $groupfolder = '';
            if (!empty($this->selectedgroups) && isset($groupmap[$auserid])) {
                $groupfolder = '/' . self::shorten_filename($groupmap[$auserid]);
            }

            $conditions['userid'] = $uploader;
            $records = $DB->get_records('publication_file', $conditions);

            // Get user firstname/lastname.
            $auser = $DB->get_record('user', ['id' => $auserid], $userfields);

            foreach ($records as $record) {
                $hasteacherapproval = !$resource->resource->obtainteacherapproval || $record->teacherapproval == 1;
                $hasstudentapproval = !$resource->resource->obtainstudentapproval || $record->studentapproval == 1;
                $haspermission = $auser->id == $USER->id || $hasteacherapproval && $hasstudentapproval;

                if (has_capability('mod/publication:approve', $context) || $haspermission) {
                    // Is teacher or file is public.

                    $file = $fs->get_file_by_id($record->fileid);

                    // Get files new name.
                    $fileext = strstr($file->get_filename(), '.');
                    $fileoriginal = str_replace($fileext, '', $file->get_filename());
                    $fileforzipname = self::clean_filename_ascii(($viewfullnames ? (fullname($auser) . '_') : '') .
                        $fileoriginal . '_' . $auserid . $fileext);
                    $fileforzipname = $resdir . '/Evidencias/' . self::shorten_filename($fileforzipname);
                    // Save file name to array for zipping.
                    $filelist[$fileforzipname] = $file;
                }
            }
        } // End of foreach.
    }

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

    /**
     * Handles the mod type assign files.
     *
     * @param mixed $resource The resource object being handled.
     * @param string $resdir The directory where the resource files are saved at the end in the ZIP.
     * @param array $filelist Array of files to be included in the ZIP with its data.
     * @return void
     */
    private function handle_assign($resource, $resdir, &$filelist, $groupid = null, $fileprefix = '', $includefeedback = true) {
        global $CFG, $DB, $USER;
        $context = $resource->context;
        // Portón: si no tiene permiso, no procesa nada de esta tarea.
        if (!has_capability('local/downloadcentercustom:downloadAssignments', $context->get_course_context())) {
            return;
        }
        $fs = get_file_storage();
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/externallib.php');

        // Precargar datos de rúbrica si la actividad la tiene.
        $hasrubric = false;
        $rubriccriteria = [];
        $rubricareaid = null;
        $area = $DB->get_record_sql(
            "SELECT gra.id FROM {course_modules} cm
              JOIN {context} con ON cm.id = con.instanceid AND con.contextlevel = ?
              JOIN {grading_areas} gra ON gra.contextid = con.id
             WHERE cm.id = ? AND gra.activemethod = ?",
            [CONTEXT_MODULE, $resource->cm->id, 'rubric']
        );
        if ($area) {
            $hasrubric = true;
            $rubricareaid = $area->id;
            $criteria = $DB->get_records_sql(
                "SELECT crit.id, crit.description, MAX(lev.score) AS max_score
                   FROM {grading_definitions} def
              LEFT JOIN {gradingform_rubric_criteria} crit ON crit.definitionid = def.id
              LEFT JOIN {gradingform_rubric_levels} lev ON lev.criterionid = crit.id
                  WHERE def.areaid = ?
               GROUP BY crit.id, crit.description, crit.sortorder
               ORDER BY crit.sortorder",
                [$rubricareaid]
            );
            foreach ($criteria as $c) {
                $rubriccriteria[$c->id] = $c;
            }
        }

        $includeinstructions = $this->downloadoptions['includeinstructions'] ?? true;
        $includeresources = $this->downloadoptions['includeresources'] ?? true;
        $onlytasks = $this->downloadoptions['onlytasks'] ?? false;

        // instrucciones/ - contenido HTML + archivos del area intro.
        if ($includeinstructions) {
            $instruccionesdir = $resdir . '/Instrucciones';
            $filelist[$instruccionesdir] = null;
            $introcontent = $resource->resource->intro;
            if (!empty(trim($introcontent))) {
                $introcontent = str_replace('@@PLUGINFILE@@', '.', $introcontent);
                $introcontent = self::convert_content_to_html_doc(
                    get_string('instructions', 'local_downloadcentercustom'),
                    $introcontent
                );
                $filelist[$instruccionesdir . '/instrucciones.html'] = [$introcontent];
            }
            $introfiles = $fs->get_area_files($context->id, 'mod_assign', 'intro', 0, 'id', false);
            foreach ($introfiles as $file) {
                if ($file->get_filesize() == 0) { continue; }
                $fname = $file->get_filename();
                // Solo archivos referenciados en el HTML; huerfanos se omiten.
                if (strpos($introcontent ?? '', $fname) !== false) {
                    $filelist[$instruccionesdir . '/' . self::shorten_filename($fname)] = $file;
                }
            }
        }
        // Archivos adjuntos de la descripción van a recursos/.
        if ($includeresources) {
            $filelist[$resdir . '/Recursos'] = null;
            $fsfiles = $fs->get_area_files($context->id, 'mod_assign', 'introattachment', 0, 'id', false);
            foreach ($fsfiles as $file) {
                if ($file->get_filesize() == 0) {
                    continue;
                }
                $filelist[$resdir . '/Recursos' . $file->get_filepath() . self::shorten_filename($file->get_filename())] = $file;
            }
        }

        $submissionsstr = get_string('gradeitem:submissions', 'assign');
        $assign = new assign($context, null, null);
        $assignplugins = $assign->get_submission_plugins();
        $feedbackplugins = $assign->get_feedback_plugins();

        $params = ['assignment' => $resource->instanceid];
        $submissions = $DB->get_records('assign_submission', $params, 'attemptnumber ASC');
        if ($this->onlyungrouped) {
            // Solo entregas de usuarios que NO pertenecen a ningún grupo del curso.
            $allgroupmemberids = $DB->get_fieldset_sql(
                "SELECT DISTINCT gm.userid FROM {groups_members} gm
                  JOIN {groups} g ON g.id = gm.groupid
                 WHERE g.courseid = ?", [$this->course->id]
            );
            $submissions = array_filter($submissions, function($sub) use ($allgroupmemberids) {
                return $sub->userid != 0 && !in_array($sub->userid, $allgroupmemberids);
            });
        } else if ($groupid) {
            $members = groups_get_members($groupid);
            $memberids = $members ? array_keys($members) : [];
            $submissions = array_filter($submissions, function($sub) use ($memberids) {
                return $sub->userid != 0 && in_array($sub->userid, $memberids);
            });
        }
        $evidenciadir = $resdir . '/Evidencias';
        $filelist[$evidenciadir] = null;
        $soloevidencias = $onlytasks && !$includefeedback && !$includeinstructions && !$includeresources;
        foreach ($submissions as $submission) {
            $user = null;
            $group = null;
            if ($submission->userid != 0) {
                $user = $DB->get_record('user', ['id' => $submission->userid]);
                $fullname = $soloevidencias ? $evidenciadir : $evidenciadir . '/' . self::shorten_filename(fullname($user));
            } else if ($submission->groupid != 0) {
                $group = $DB->get_record('groups', ['id' => $submission->groupid]);
                $groupname = get_string('group', 'group') . ': ' . $group->name;
                $fullname = $soloevidencias ? $evidenciadir : $evidenciadir . '/' . self::shorten_filename($groupname);
            } else {
                $groupname = get_string('group', 'group') . ': ' . get_string('defaultteam', 'assign');
                $fullname = $soloevidencias ? $evidenciadir : $evidenciadir . '/' . self::shorten_filename($groupname);
            }

            // Submission!
            foreach ($assignplugins as $assignplugin) {
                if (!$assignplugin->is_enabled() || !$assignplugin->is_visible()) {
                    continue;
                }

                // Subtype is 'assignsubmission', type is currently 'file' or 'onlinetext'.
                $component = $assignplugin->get_subtype() . '_' . $assignplugin->get_type();
                $fileareas = $assignplugin->get_file_areas();
                foreach ($fileareas as $filearea => $name) {
                    $areafiles = $fs->get_area_files(
                        $context->id,
                        $component,
                        $filearea,
                        $submission->id,
                        'itemid, filepath, filename',
                        false
                    );
                    if ($areafiles) {
                        foreach ($areafiles as $file) {
                            $originalname = $file->get_filename();
                            $studentname = $user ? fullname($user) : 'desconocido';
                            $newname = $fileprefix . ' - ' . $studentname . ' - ' . $originalname;
                            $filename = $fullname . $file->get_filepath() .
                                self::shorten_filename($newname);
                            $filelist[$filename] = $file;
                        }
                    }
                }
                if ($assignplugin->get_type() == 'onlinetext') {
                    $onlinetext = $assignplugin->get_editor_text('onlinetext', $submission->id);
                    $onlinetext = str_replace('@@PLUGINFILE@@/', '', $onlinetext);
                    if (mb_strlen(trim($onlinetext)) > 0) {
                        $studentname = $user ? fullname($user) : 'desconocido';
                        $htmlname = $fileprefix . ' - ' . $studentname . ' - ' . $assignplugin->get_name();
                        $onlinetext = self::convert_content_to_html_doc($htmlname, $onlinetext);
                        $filename = $fullname . '/' . self::shorten_filename($htmlname . '.html');
                        $filelist[$filename] = [$onlinetext];
                    }
                }
            }

            // Feedback (opcional)!
            if (!$includefeedback) {
                continue;
            }
            if (empty($user)) {
                continue;
            }
            $feedback = $assign->get_assign_feedback_status_renderable($user);
            if ($feedback && $feedback->grade) {
                $fullname .= '/Retroalimentaci' . "\xC3\xB3n";

                foreach ($feedbackplugins as $feedbackplugin) {
                    if (!$feedbackplugin->is_enabled() || !$feedbackplugin->is_visible()) {
                        continue;
                    }
                    $component = $feedbackplugin->get_subtype() . '_' . $feedbackplugin->get_type();
                    $fileareas = $feedbackplugin->get_file_areas();
                    foreach ($fileareas as $filearea => $name) {
                        $areafiles = $fs->get_area_files(
                            $context->id,
                            $component,
                            $filearea,
                            $feedback->grade->id,
                            'itemid, filepath, filename',
                            false
                        );
                        if ($areafiles) {
                            foreach ($areafiles as $file) {
                                $fname = $file->get_filename();
                                $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
                                if ($ext !== 'pdf') {
                                    continue;
                                }
                                $filename = $fullname . $file->get_filepath() .
                                    self::shorten_filename($fname);
                                $filelist[$filename] = $file;
                            }
                        }
                    }

                    if ($feedbackplugin->get_type() == 'comments') {
                        $comments = $feedbackplugin->get_editor_text('comments', $feedback->grade->id);
                        $comments = str_replace('@@PLUGINFILE@@/', '', $comments);
                    }
                }

                // Generar HTML de rúbrica/retroalimentación por alumno.
                $studentname = $user ? fullname($user) : 'desconocido';
                $gradeval = $feedback->grade->grade ?? '';
                if (is_numeric($gradeval)) {
                    $gradeval = number_format((float)$gradeval, 1, '.', '');
                }
                if ($hasrubric) {
                    $rubrich = self::build_rubric_html(
                        $studentname,
                        $rubriccriteria,
                        $resource->cm->id,
                        $rubricareaid,
                        $user->id,
                        $comments ?? '',
                        $gradeval
                    );
                } else {
                    $rubrich = self::build_feedback_html($studentname, $comments ?? '', $gradeval);
                }
                $htmlname = 'Retroalimentaci' . "\xC3\xB3n" . ' - ' . $studentname;
                $rubrich = self::convert_content_to_html_doc($htmlname, $rubrich);
                $filename = $fullname . '/' . self::shorten_filename($htmlname . '.html');
                $filelist[$filename] = [$rubrich];
            }
        }
    }

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

    /**
     * Creates a zip file with all the resources that the user wants to download and downloads it.
     *
     * @return string
     * @throws coding_exception
     * @throws dml_exception
     */
    public function create_zip() {
        global $CFG, $DB;

        if (file_exists($CFG->dirroot . '/mod/publication/locallib.php')) {
            require_once($CFG->dirroot . '/mod/publication/locallib.php');
        } else {
            define('PUBLICATION_MODE_UPLOAD', 0);
            define('PUBLICATION_MODE_IMPORT', 1);
        }

        $modbookmissing = true;
        if (file_exists($CFG->dirroot . '/mod/book/locallib.php')) {
            require_once($CFG->dirroot . '/mod/book/locallib.php');
            $modbookmissing = false;
        }

        $fs = get_file_storage();
        $filelist = [];
        $coursename = self::shorten_filename(self::clean_filename_ascii(format_string($this->course->shortname)));
        $addnumbering = $this->downloadoptions['addnumbering'];
        $fileprefix = $coursename;
        $onlytasks = $this->downloadoptions['onlytasks'] ?? false;
        $includefiles = $this->downloadoptions['includefiles'] ?? true;
        $includefolders = $this->downloadoptions['includefolders'] ?? true;
        $includeurls = $this->downloadoptions['includeurls'] ?? true;
        $includepages = $this->downloadoptions['includepages'] ?? true;
        $includeinstructions = $this->downloadoptions['includeinstructions'] ?? true;
        $includeresources = $this->downloadoptions['includeresources'] ?? true;
        $includefeedback = $this->downloadoptions['includefeedback'] ?? true;
        $solomateriales = $includefiles || $includefolders || $includeurls;
        $haymateriales = $includefiles || $includefolders || $includepages;

        // Obtener recursos organizados por seccion.
        $pathlist = $this->section_pathnames();

        if (!empty($this->selectedgroups)) {
            foreach ($this->selectedgroups as $groupid) {
                $group = $DB->get_record('groups', ['id' => $groupid]);
                if (!$group) { continue; }
                $groupname = self::shorten_filename(self::clean_filename_ascii($group->name));
                $filelist[$coursename] = null;
                $filelist[$coursename . '/' . $groupname] = null;

                // Procesar cada seccion.
                foreach ($pathlist as $sectionresources) {
                    $sectionresources = $this->preprocess_resource_names($sectionresources, $addnumbering);

                    // Separar assignments de materiales.
                    $assignitems = [];
                    $materialitems = [];
                    foreach ($sectionresources as $res) {
                        $res->name = html_entity_decode($res->name);
                        if ($onlytasks && !$solomateriales && !in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity'])) {
                            continue;
                        }
                        if (in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity'])) {
                            $assignitems[] = $res;
                        } else {
                            $materialitems[] = $res;
                        }
                    }

                    // Procesar assignments.
                    foreach ($assignitems as $res) {
                        $activityname = self::shorten_filename(self::clean_filename_ascii($res->name));
                        $resdir = $coursename . '/' . $groupname . '/' . $activityname;
                        $filelist[$resdir] = null;

                        if ($res->modname == 'assign') {
                            $this->handle_assign($res, $resdir, $filelist, $groupid, $fileprefix, $includefeedback);
                        } else if ($res->modname == 'quiz') {
                            $this->handle_quiz($res, $resdir, $filelist, $groupid);
                        } else if ($res->modname == 'h5pactivity') {
                            $this->handle_h5pactivity($res, $resdir, $filelist, $groupid);
                        } else {
                            $this->handle_publication($res, $resdir, $filelist, $groupid);
                        }

                        // Materiales van a nivel del curso, no dentro de la actividad ni del grupo.
                        $matdir = $coursename . '/Materiales';
                        foreach ($materialitems as $m) {
                            $itemname = self::shorten_filename(self::clean_filename_ascii($m->name));
                            $itempath = $matdir . '/' . $itemname;
                            $filelist[$matdir] = null;

                            if ($m->modname == 'resource') {
                                $this->handle_resource($m, $itempath, $filelist, $matdir);
                            } else if ($m->modname == 'folder') {
                                $folder = $fs->get_area_tree($m->context->id, 'mod_folder', 'content', 0);
                                $this->add_folder_contents($filelist, $folder, $itempath);
                            } else if ($m->modname == 'page') {
                                $pagedir2 = $itempath; $filelist[$pagedir2] = null;
                                $pintro2 = $m->resource->intro ?? '';
                                $pintro2 = str_replace('@@PLUGINFILE@@', 'Recursos', $pintro2);
                                $pintro2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?youtube\.com\/embed\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = str_replace('https://www.youtube.com/embed/', 'https://www.youtube.com/watch?v=', $pintro2);
                                $pintro2 = preg_replace('/<div[^>]*>[\s\S]*?<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>[\s\S]*?<\/div>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = preg_replace('/<iframe[^>]*?src="(https:\/\/[^\/]*genially\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = str_replace('/view?embed', '', $pintro2);
                                $pcontent2 = str_replace('@@PLUGINFILE@@', 'Recursos', $m->resource->content);
                                $pcontent2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?youtube\.com\/embed\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = str_replace('https://www.youtube.com/embed/', 'https://www.youtube.com/watch?v=', $pcontent2);
                                $pcontent2 = str_replace('/view?embed', '', $pcontent2);
                                $pcontent2 = preg_replace('/<div[^>]*>[\s\S]*?<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>[\s\S]*?<\/div>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = preg_replace('/<iframe[^>]*?src="(https:\/\/[^\/]*genially\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = self::convert_content_to_html_doc($m->name, $pintro2 . $pcontent2);
                                $filelist[$pagedir2 . '/' . basename($itempath) . '.html'] = [$pcontent2];
                                $filelist[$pagedir2 . '/Recursos'] = null;
                                $pfs2 = $fs->get_area_files($m->context->id, 'mod_page', 'content');
                                foreach ($pfs2 as $pf2) { if ($pf2->get_filesize() == 0) continue; if (strpos($pintro2 . $pcontent2, 'Recursos/' . rawurlencode($pf2->get_filename())) === false && strpos($pintro2 . $pcontent2, 'Recursos/' . $pf2->get_filename()) === false) continue; $filelist[$pagedir2 . '/Recursos/' . self::shorten_filename($pf2->get_filename())] = $pf2; }
                                $pfs2 = $fs->get_area_files($m->context->id, 'mod_page', 'intro');
                                foreach ($pfs2 as $pf2) { if ($pf2->get_filesize() == 0) continue; if (strpos($pintro2 . $pcontent2, 'Recursos/' . rawurlencode($pf2->get_filename())) === false && strpos($pintro2 . $pcontent2, 'Recursos/' . $pf2->get_filename()) === false) continue; $filelist[$pagedir2 . '/Recursos/' . self::shorten_filename($pf2->get_filename())] = $pf2; }
                            } else if ($m->modname == 'book' && !$modbookmissing)  {
                                $this->handle_book($m, $itempath, $filelist);
                            } else if ($m->modname == 'lightboxgallery') {
                                $this->handle_lightboxgallery($m, $itempath, $filelist);
                            } else if ($m->modname == 'glossary') {
                                $this->handle_glossary($m, $itempath, $filelist);
                            } else if ($m->modname == 'etherpadlite') {
                                $this->handle_etherpadlite($m, $itempath, $filelist);
                            } else if ($m->modname == 'url') {
                                $this->handle_url($m, $matdir, $filelist);
                            } else if ($m->modname == 'label') {
                                $this->handle_label($m, $matdir, $filelist);
                            }
                        }
                    }

                    // Si la seccion NO tiene assignment, los materiales van a curso/Materiales/.
                    if (empty($assignitems)) {
                        foreach ($materialitems as $m) {
                            $matdir = $coursename . '/Materiales';
                            $filelist[$matdir] = null;
                            $itemname = self::shorten_filename(self::clean_filename_ascii($m->name));
                            $itempath = $matdir . '/' . $itemname;

                            if ($m->modname == 'resource') {
                                $this->handle_resource($m, $itempath, $filelist, $matdir);
                            } else if ($m->modname == 'folder') {
                                $folder = $fs->get_area_tree($m->context->id, 'mod_folder', 'content', 0);
                                $this->add_folder_contents($filelist, $folder, $itempath);
                            } else if ($m->modname == 'page') {
                                $pagedir2 = $itempath; $filelist[$pagedir2] = null;
                                $pintro2 = $m->resource->intro ?? '';
                                $pintro2 = str_replace('@@PLUGINFILE@@', 'Recursos', $pintro2);
                                $pintro2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?youtube\.com\/embed\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = str_replace('https://www.youtube.com/embed/', 'https://www.youtube.com/watch?v=', $pintro2);
                                $pintro2 = preg_replace('/<div[^>]*>[\s\S]*?<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>[\s\S]*?<\/div>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = preg_replace('/<iframe[^>]*?src="(https:\/\/[^\/]*genially\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = str_replace('/view?embed', '', $pintro2);
                                $pcontent2 = str_replace('@@PLUGINFILE@@', 'Recursos', $m->resource->content);
                                $pcontent2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?youtube\.com\/embed\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = str_replace('https://www.youtube.com/embed/', 'https://www.youtube.com/watch?v=', $pcontent2);
                                $pcontent2 = str_replace('/view?embed', '', $pcontent2);
                                $pcontent2 = preg_replace('/<div[^>]*>[\s\S]*?<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>[\s\S]*?<\/div>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = preg_replace('/<iframe[^>]*?src="(https:\/\/[^\/]*genially\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = self::convert_content_to_html_doc($m->name, $pintro2 . $pcontent2);
                                $filelist[$pagedir2 . '/' . basename($itempath) . '.html'] = [$pcontent2];
                                $filelist[$pagedir2 . '/Recursos'] = null;
                                $pfs2 = $fs->get_area_files($m->context->id, 'mod_page', 'content');
                                foreach ($pfs2 as $pf2) { if ($pf2->get_filesize() == 0) continue; if (strpos($pintro2 . $pcontent2, 'Recursos/' . rawurlencode($pf2->get_filename())) === false && strpos($pintro2 . $pcontent2, 'Recursos/' . $pf2->get_filename()) === false) continue; $filelist[$pagedir2 . '/Recursos/' . self::shorten_filename($pf2->get_filename())] = $pf2; }
                                $pfs2 = $fs->get_area_files($m->context->id, 'mod_page', 'intro');
                                foreach ($pfs2 as $pf2) { if ($pf2->get_filesize() == 0) continue; if (strpos($pintro2 . $pcontent2, 'Recursos/' . rawurlencode($pf2->get_filename())) === false && strpos($pintro2 . $pcontent2, 'Recursos/' . $pf2->get_filename()) === false) continue; $filelist[$pagedir2 . '/Recursos/' . self::shorten_filename($pf2->get_filename())] = $pf2; }
                            } else if ($m->modname == 'book' && !$modbookmissing)  {
                                $this->handle_book($m, $itempath, $filelist);
                            } else if ($m->modname == 'lightboxgallery') {
                                $this->handle_lightboxgallery($m, $itempath, $filelist);
                            } else if ($m->modname == 'glossary') {
                                $this->handle_glossary($m, $itempath, $filelist);
                            } else if ($m->modname == 'etherpadlite') {
                                $this->handle_etherpadlite($m, $itempath, $filelist);
                            } else if ($m->modname == 'url') {
                                $this->handle_url($m, $matdir, $filelist);
                            } else if ($m->modname == 'label') {
                                $this->handle_label($m, $matdir, $filelist);
                            }
                        }
                    }
                }
            }
        } else {
            // Sin grupos.
            foreach ($pathlist as $sectionresources) {
                $sectionresources = $this->preprocess_resource_names($sectionresources, $addnumbering);
                $assignitems = [];
                $materialitems = [];
                foreach ($sectionresources as $res) {
                    $res->name = html_entity_decode($res->name);
                    if ($onlytasks && !$solomateriales && !in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity'])) {
                        continue;
                    }
                    if (in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity'])) {
                        $assignitems[] = $res;
                    } else {
                        $materialitems[] = $res;
                    }
                }
                foreach ($assignitems as $res) {
                    $activityname = self::shorten_filename(self::clean_filename_ascii($res->name));
                    $resdir = $coursename . '/' . $activityname;
                    $filelist[$coursename] = null;
                    $filelist[$resdir] = null;
                    if ($res->modname == 'assign') {
                        $this->handle_assign($res, $resdir, $filelist, null, $fileprefix, $includefeedback);
                    } else if ($res->modname == 'quiz') {
                        $this->handle_quiz($res, $resdir, $filelist);
                    } else if ($res->modname == 'h5pactivity') {
                        $this->handle_h5pactivity($res, $resdir, $filelist);
                    } else {
                        $this->handle_publication($res, $resdir, $filelist);
                    }
                    foreach ($materialitems as $m) {
                        $itemname = self::shorten_filename(self::clean_filename_ascii($m->name));
                         $matdir = $coursename . '/Materiales';
                         $itempath = $matdir . '/' . $itemname;
                         $filelist[$matdir] = null;
                         if ($m->modname == 'resource') {
                             $this->handle_resource($m, $itempath, $filelist, $matdir);
                         } else if ($m->modname == 'folder') {
                             $folder = $fs->get_area_tree($m->context->id, 'mod_folder', 'content', 0);
                             $this->add_folder_contents($filelist, $folder, $itempath);
                         } else if ($m->modname == 'page') {
                                $pagedir2 = $itempath; $filelist[$pagedir2] = null;
                                $pintro2 = $m->resource->intro ?? '';
                                $pintro2 = str_replace('@@PLUGINFILE@@', 'Recursos', $pintro2);
                                $pintro2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?youtube\.com\/embed\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = str_replace('https://www.youtube.com/embed/', 'https://www.youtube.com/watch?v=', $pintro2);
                                $pintro2 = preg_replace('/<div[^>]*>[\s\S]*?<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>[\s\S]*?<\/div>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = preg_replace('/<iframe[^>]*?src="(https:\/\/[^\/]*genially\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = str_replace('/view?embed', '', $pintro2);
                                $pcontent2 = str_replace('@@PLUGINFILE@@', 'Recursos', $m->resource->content);
                                $pcontent2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?youtube\.com\/embed\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = str_replace('https://www.youtube.com/embed/', 'https://www.youtube.com/watch?v=', $pcontent2);
                                $pcontent2 = str_replace('/view?embed', '', $pcontent2);
                                $pcontent2 = preg_replace('/<div[^>]*>[\s\S]*?<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>[\s\S]*?<\/div>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = preg_replace('/<iframe[^>]*?src="(https:\/\/[^\/]*genially\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = self::convert_content_to_html_doc($m->name, $pintro2 . $pcontent2);
                                $filelist[$pagedir2 . '/' . basename($itempath) . '.html'] = [$pcontent2];
                                $filelist[$pagedir2 . '/Recursos'] = null;
                                $pfs2 = $fs->get_area_files($m->context->id, 'mod_page', 'content');
                                foreach ($pfs2 as $pf2) { if ($pf2->get_filesize() == 0) continue; if (strpos($pintro2 . $pcontent2, 'Recursos/' . rawurlencode($pf2->get_filename())) === false && strpos($pintro2 . $pcontent2, 'Recursos/' . $pf2->get_filename()) === false) continue; $filelist[$pagedir2 . '/Recursos/' . self::shorten_filename($pf2->get_filename())] = $pf2; }
                                $pfs2 = $fs->get_area_files($m->context->id, 'mod_page', 'intro');
                                foreach ($pfs2 as $pf2) { if ($pf2->get_filesize() == 0) continue; if (strpos($pintro2 . $pcontent2, 'Recursos/' . rawurlencode($pf2->get_filename())) === false && strpos($pintro2 . $pcontent2, 'Recursos/' . $pf2->get_filename()) === false) continue; $filelist[$pagedir2 . '/Recursos/' . self::shorten_filename($pf2->get_filename())] = $pf2; }
                         } else if ($m->modname == 'book' && !$modbookmissing)  {
                             $this->handle_book($m, $itempath, $filelist);
                         } else if ($m->modname == 'lightboxgallery') {
                             $this->handle_lightboxgallery($m, $itempath, $filelist);
                         } else if ($m->modname == 'glossary') {
                             $this->handle_glossary($m, $itempath, $filelist);
                         } else if ($m->modname == 'etherpadlite') {
                             $this->handle_etherpadlite($m, $itempath, $filelist);
                         } else if ($m->modname == 'url') {
                             $this->handle_url($m, $matdir, $filelist);
                         } else if ($m->modname == 'label') {
                             $this->handle_label($m, $matdir, $filelist);
                         }
                     }
                }
                if (empty($assignitems)) {
                    foreach ($materialitems as $m) {
                         $matdir = $coursename . '/Materiales';
                         $filelist[$matdir] = null;
                         $itemname = self::shorten_filename(self::clean_filename_ascii($m->name));
                         $itempath = $matdir . '/' . $itemname;
                         if ($m->modname == 'resource') {
                             $this->handle_resource($m, $itempath, $filelist, $matdir);
                         } else if ($m->modname == 'folder') {
                             $folder = $fs->get_area_tree($m->context->id, 'mod_folder', 'content', 0);
                             $this->add_folder_contents($filelist, $folder, $itempath);
                         } else if ($m->modname == 'page') {
                                $pagedir2 = $itempath; $filelist[$pagedir2] = null;
                                $pintro2 = $m->resource->intro ?? '';
                                $pintro2 = str_replace('@@PLUGINFILE@@', 'Recursos', $pintro2);
                                $pintro2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?youtube\.com\/embed\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = str_replace('https://www.youtube.com/embed/', 'https://www.youtube.com/watch?v=', $pintro2);
                                $pintro2 = preg_replace('/<div[^>]*>[\s\S]*?<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>[\s\S]*?<\/div>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = preg_replace('/<iframe[^>]*?src="(https:\/\/[^\/]*genially\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pintro2);
                                $pintro2 = str_replace('/view?embed', '', $pintro2);
                                $pcontent2 = str_replace('@@PLUGINFILE@@', 'Recursos', $m->resource->content);
                                $pcontent2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?youtube\.com\/embed\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = str_replace('https://www.youtube.com/embed/', 'https://www.youtube.com/watch?v=', $pcontent2);
                                $pcontent2 = str_replace('/view?embed', '', $pcontent2);
                                $pcontent2 = preg_replace('/<div[^>]*>[\s\S]*?<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>[\s\S]*?<\/div>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = preg_replace('/<iframe[^>]*?src="(https:\/\/(www\.)?canva\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = preg_replace('/<iframe[^>]*?src="(https:\/\/[^\/]*genially\.com\/[^"]+)"[^>]*>[\s\S]*?<\/iframe>/i', '<p><strong>Nota importante sobre el enlace:</strong></p><p>Este enlace ha sido integrado por el creador del recurso. Si experimentas problemas para acceder, por favor contacta directamente con la persona que configuró la actividad, ya que la plataforma no gestiona los permisos de este sitio externo.</p><span class="nolink">$1</span>', $pcontent2);
                                $pcontent2 = self::convert_content_to_html_doc($m->name, $pintro2 . $pcontent2);
                                $filelist[$pagedir2 . '/' . basename($itempath) . '.html'] = [$pcontent2];
                                $filelist[$pagedir2 . '/Recursos'] = null;
                                $pfs2 = $fs->get_area_files($m->context->id, 'mod_page', 'content');
                                foreach ($pfs2 as $pf2) { if ($pf2->get_filesize() == 0) continue; if (strpos($pintro2 . $pcontent2, 'Recursos/' . rawurlencode($pf2->get_filename())) === false && strpos($pintro2 . $pcontent2, 'Recursos/' . $pf2->get_filename()) === false) continue; $filelist[$pagedir2 . '/Recursos/' . self::shorten_filename($pf2->get_filename())] = $pf2; }
                                $pfs2 = $fs->get_area_files($m->context->id, 'mod_page', 'intro');
                                foreach ($pfs2 as $pf2) { if ($pf2->get_filesize() == 0) continue; if (strpos($pintro2 . $pcontent2, 'Recursos/' . rawurlencode($pf2->get_filename())) === false && strpos($pintro2 . $pcontent2, 'Recursos/' . $pf2->get_filename()) === false) continue; $filelist[$pagedir2 . '/Recursos/' . self::shorten_filename($pf2->get_filename())] = $pf2; }
                         } else if ($m->modname == 'book' && !$modbookmissing)  {
                             $this->handle_book($m, $itempath, $filelist);
                         } else if ($m->modname == 'lightboxgallery') {
                            $this->handle_lightboxgallery($m, $itempath, $filelist);
                        } else if ($m->modname == 'glossary') {
                            $this->handle_glossary($m, $itempath, $filelist);
                        } else if ($m->modname == 'etherpadlite') {
                            $this->handle_etherpadlite($m, $itempath, $filelist);
                        } else if ($m->modname == 'url') {
                            $this->handle_url($m, $matdir, $filelist);
                        } else if ($m->modname == 'label') {
                            $this->handle_label($m, $matdir, $filelist);
                        }
                    }
                }
            }
        }

        \core\session\manager::write_close();

        $zipname = sprintf('%s_%s.zip', format_string($this->course->shortname), userdate(time(), '%Y%m%d_%H%M'));
        $zipwriter = \core_files\archive_writer::get_stream_writer($zipname, \core_files\archive_writer::ZIP_WRITER);

        foreach ($filelist as $pathinzip => $file) {
            if ($file instanceof \stored_file) {
                $zipwriter->add_file_from_stored_file($pathinzip, $file);
            } else if (is_array($file)) {
                $content = reset($file);
                $zipwriter->add_file_from_string($pathinzip, $content);
            } else if (is_string($file)) {
                $zipwriter->add_file_from_filepath($pathinzip, $file);
            }
        }

        $zipwriter->finish();
        die;
    }

    /**
     * Ensures unique file paths in the zip by tracking and renaming duplicates.
     *
     * If the given $filepath has already been used, appends a number to the path to make it unique.
     * If this is the first duplicate, also renames any existing keys in $filelist to start with suffix 1.
     *
     * @param string $filepath The file path to check and possibly rename for uniqueness.
     * @param array $filelist Reference to the array of file paths (keys) and files (values) being added to the zip.
     * @return string The unique file path, possibly with a numeric suffix appended.
     */
    private function get_and_update_filepath($filepath, &$filelist) {
        $countnumber = '';
        if (array_key_exists($filepath, $this->pathcount)) {
            if ($this->pathcount[$filepath] == 1) {
                $matchingpaths = preg_grep('/^' . preg_quote($filepath, '/') . '/', array_keys($filelist));
                foreach ($matchingpaths as $key) {
                    $newkey = $filepath . '1' . substr($key, strlen($filepath));
                    $filelist[$newkey] = $filelist[$key];
                    unset($filelist[$key]);
                }
            }
            $this->pathcount[$filepath]++;
            $countnumber = $this->pathcount[$filepath];
        } else {
            $this->pathcount[$filepath] = 1;
        }
        $filepath .= $countnumber;
        return $filepath;
    }

    /**
     * Adds the contents of a folder to the filelist.
     *
     * @param array $filelist
     * @param array $folder
     * @param string $path
     */
    private function add_folder_contents(&$filelist, $folder, $path) {
        if (!empty($folder['subdirs'])) {
            foreach ($folder['subdirs'] as $foldername => $subfolder) {
                $foldername = self::shorten_filename($foldername);
                $this->add_folder_contents($filelist, $subfolder, $path . '/' . $foldername);
            }
        }
        foreach ($folder['files'] as $filename => $file) {
            $filelist[$path . '/' . self::shorten_filename($filename)] = $file;
        }
    }

    /**
     * Parse the data from the form where the user selects the resources to download and the options.
     *
     * @param stdClass|null $data
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function parse_form_data($data) {
        $data = (array)$data;
        $filtered = [];

        // Determinar cuántos grupos tiene el usuario en el curso.
        $usergroups = groups_get_user_groups($this->course->id, $this->user->id);
        $usergroupids = $usergroups[0] ?? [];
        // Quien tiene downloadMaterials (manager) o accessallgroups ve todos los grupos.
        $coursecontext = \context_course::instance($this->course->id);
        $canaccessall = has_capability('local/downloadcentercustom:downloadMaterials', $coursecontext)
            || has_capability('moodle/site:accessallgroups', $coursecontext);

        if ($canaccessall) {
            // Admins/managers: usan la selección del formulario directamente.
            if (!empty($data['selectedgroups'])) {
                $this->selectedgroups = $data['selectedgroups'];
            } else if (!empty($data['selectallgroups'])) {
                global $DB;
                $allgroups = $DB->get_records_menu('groups', ['courseid' => $data['courseid'] ?? $this->course->id], '', 'id,id');
                $this->selectedgroups = array_keys($allgroups);
            } else {
                $this->selectedgroups = [];
            }
        } else if (count($usergroupids) === 0) {
            // 0 grupos: solo alumnos sin grupo.
            $this->selectedgroups = [];
            $this->onlyungrouped = true;
        } else if (count($usergroupids) === 1) {
            // 1 grupo: auto-asignado a ese único grupo.
            $this->selectedgroups = [$usergroupids[0]];
        } else {
            // 2+ grupos: usa la selección del formulario (o vacío si deseleccionó todos).
            if (!empty($data['selectedgroups'])) {
                $this->selectedgroups = $data['selectedgroups'];
            } else if (!empty($data['selectallgroups'])) {
                $this->selectedgroups = $usergroupids;
            } else {
                $this->selectedgroups = [];
            }
        }

        $sortedresources = $this->get_resources_for_user();

        foreach ($sortedresources as $sectionid => $info) {
            $hassectioncheck = isset($data['item_topic_' . $sectionid]);
            $hasitems = false;
            $sectionres = [];
            foreach ($info->res as $res) {
                $name = 'item_' . $res->modname . '_' . $res->instanceid;
                if (isset($data[$name])) {
                    $hasitems = true;
                    $sectionres[] = $res;
                }
            }
            if ($hassectioncheck || $hasitems) {
                $filtered[$sectionid] = new stdClass();
                $filtered[$sectionid]->title = $info->title;
                $filtered[$sectionid]->res = $sectionres;
            }
        }

        $this->downloadoptions['includefiles'] = !empty($data['includefiles']);
        $this->downloadoptions['includefolders'] = !empty($data['includefolders']);
        $this->downloadoptions['includepages'] = !empty($data['includepages']);
        $this->downloadoptions['includeurls'] = !empty($data['includeurls']);
        $this->downloadoptions['includeinstructions'] = !empty($data['includeinstructions']);
        $this->downloadoptions['includeresources'] = !empty($data['includeresources']);
        $this->downloadoptions['includefeedback'] = !empty($data['includefeedback']);
        $this->downloadoptions['onlytasks'] = !empty($data['onlytasks']);
        $this->downloadoptions['quiztries'] = !empty($data['quiztries']);
        $this->filteredresources = $filtered;
        $this->downloadoptions['filesrealnames'] = isset($data['filesrealnames']);
        $this->downloadoptions['addnumbering'] = isset($data['addnumbering']);
    }

    /**
     * Replace slash with underscore and shorten the filename based on the maxlength.
     *
     * @param string $filename
     * @param int $maxlength
     * @return string
     */
    public static function clean_filename_ascii($filename) {
        $chars = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N', 'ü' => 'u', 'Ü' => 'U',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
        ];
        $filename = strtr($filename, $chars);
        return clean_filename($filename);
    }

    public static function shorten_filename($filename, $maxlength = 100) {
        $filename = (string)$filename;
        $filename = str_replace('/', '_', $filename);
        if (mb_strlen($filename) <= $maxlength) {
            return $filename;
        }
        $limit = round($maxlength / 2) - 1;
        return mb_substr($filename, 0, $limit) . '___' . mb_substr($filename, (1 - $limit));
    }

    /**
     * Converts content to a full HTML document.
     *
     * @param string $title
     * @param string $content
     * @param string $additionalhead
     * @return string
     */
    public static function convert_content_to_html_doc($title, $content, $additionalhead = '') {
        return <<<HTML
<!doctype html>
<html>
<head>
    <title>$title</title>
    <meta charset="utf-8">
    $additionalhead
</head>
<body>
$content
</body>
</html>
HTML;
    }

    /**
     * Appends CSS to the HTML content of an EtherpadLite document.
     *
     * @param string $htmlcontent
     * @return string
     */
    public static function append_etherpadlite_css($htmlcontent) {
        $csscontent = <<<CSS
<style>
ol {
  counter-reset: item;
}

ol > li {
  counter-increment: item;
}

ol ol > li {
  display: block;
}

ol > li {
  display: block;
}

ol > li:before {
  content: counters(item, ".") ". ";
}

ol ol > li:before {
  content: counters(item, ".") ". ";
  margin-left: -20px;
}

ul.indent {
  list-style-type: none;
}


</style>
</body>
CSS;
        return str_replace('</body>', $csscontent, $htmlcontent);
    }

    /**
     * Build HTML table with rubric criteria, feedback and grade for a student.
     *
     * @param string $studentname
     * @param array $rubriccriteria Criterion id => {description, max_score}
     * @param int $cmid Course module ID
     * @param int $areaid Grading area ID
     * @param int $userid
     * @param string $feedbacktext General feedback text
     * @param string $gradeval Final grade
     * @return string HTML content
     */
    public static function build_rubric_html($studentname, $rubriccriteria, $cmid, $areaid, $userid, $feedbacktext, $gradeval) {
        global $DB;

        // Obtener fillings de rúbrica para este usuario.
        $fillingsrs = $DB->get_recordset_sql(
            "SELECT fill.criterionid, fill.levelid, fill.remark,
                    lev.score, lev.definition AS leveldef
               FROM {course_modules} cm
               JOIN {context} con ON cm.id = con.instanceid AND con.contextlevel = ?
               JOIN {grading_areas} gra ON gra.contextid = con.id
               JOIN {grading_definitions} def ON def.areaid = gra.id
               JOIN {grading_instances} inst ON inst.definitionid = def.id
               JOIN {gradingform_rubric_fillings} fill ON fill.instanceid = inst.id
          LEFT JOIN {gradingform_rubric_levels} lev ON lev.id = fill.levelid
              WHERE cm.id = ? AND gra.id = ?
                AND inst.itemid IN (
                    SELECT act.id FROM {assign_grades} act WHERE act.assignment = cm.instance AND act.userid = ?
                )
           ORDER BY fill.criterionid",
            [CONTEXT_MODULE, $cmid, $areaid, $userid]
        );
        $fillings = [];
        foreach ($fillingsrs as $f) {
            // Keep the latest filling per criterion (highest criterionid+levelid combo).
            $fillings[$f->criterionid] = $f;
        }
        $fillingsrs->close();

        // Construir tabla HTML.
        $h = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;">';

        // Fila 1: encabezados de criterios.
        $criterianames = [];
        foreach ($rubriccriteria as $c) {
            $criterianames[] = htmlspecialchars($c->description) . '<br>(máx ' . round($c->max_score, 2) . ')';
        }
        $h .= '<tr style="background:#f2f2f2;font-weight:bold;"><td>Estudiante</td>';
        foreach ($rubriccriteria as $c) {
            $h .= '<td>' . htmlspecialchars($c->description) . '<br>(máx ' . round($c->max_score, 2) . ')</td>';
        }
        $h .= '<td>Retroalimentación</td><td>Calificación</td></tr>';

        // Fila 2: puntuación y nivel.
        $h .= '<tr><td rowspan="2" style="vertical-align:top;font-weight:bold;">' . htmlspecialchars($studentname) . '</td>';
        foreach ($rubriccriteria as $cid => $c) {
            $found = false;
            foreach ($fillings as $f) {
                if ($f->criterionid == $cid) {
                    $score = isset($f->score) ? round($f->score, 2) : 0;
                    $level = $f->leveldef ?? '';
                    $h .= '<td style="vertical-align:top;">Puntuación: ' . $score;
                    if ($level) {
                        $h .= '<br><em>Nivel: ' . htmlspecialchars($level) . '</em>';
                    }
                    $h .= '</td>';
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $h .= '<td style="vertical-align:top;color:#999;">-</td>';
            }
        }
        $fb = trim(strip_tags($feedbacktext ?? ''));
        $h .= '<td rowspan="2" style="vertical-align:top;">' . nl2br(htmlspecialchars($fb)) . '</td>';
        $h .= '<td rowspan="2" style="vertical-align:top;text-align:center;">' . htmlspecialchars($gradeval) . '</td>';
        $h .= '</tr>';

        // Fila 3: observaciones por criterio.
        $h .= '<tr>';
        foreach ($rubriccriteria as $cid => $c) {
            $obs = '';
            foreach ($fillings as $f) {
                if ($f->criterionid == $cid && !empty(trim($f->remark ?? ''))) {
                    $obs = $f->remark;
                    break;
                }
            }
            if ($obs) {
                $h .= '<td style="vertical-align:top;background:#fffde7;">Observación: ' . htmlspecialchars($obs) . '</td>';
            } else {
                $h .= '<td style="vertical-align:top;color:#999;">(sin observación)</td>';
            }
        }
        $h .= '</tr>';

        $h .= '</table>';
        return $h;
    }

    /**
     * Build simple HTML table with feedback and grade (no rubric).
     *
     * @param string $studentname
     * @param string $feedbacktext
     * @param string $gradeval
     * @return string HTML content
     */
    public static function build_feedback_html($studentname, $feedbacktext, $gradeval) {
        $fb = trim(strip_tags($feedbacktext ?? ''));
        $h = '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-family:Arial,sans-serif;font-size:13px;">';
        $h .= '<tr style="background:#f2f2f2;font-weight:bold;"><td>Estudiante</td><td>Retroalimentación</td><td>Calificación</td></tr>';
        $h .= '<tr>';
        $h .= '<td style="font-weight:bold;">' . htmlspecialchars($studentname) . '</td>';
        $h .= '<td>' . nl2br(htmlspecialchars($fb ?: '-')) . '</td>';
        $h .= '<td style="text-align:center;">' . htmlspecialchars($gradeval) . '</td>';
        $h .= '</tr>';
        $h .= '</table>';
        return $h;
    }
}
