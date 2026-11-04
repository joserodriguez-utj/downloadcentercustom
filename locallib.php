<?php
// This file is part of local_centro_de_descarga_de_portafolio_de_evidencias for Moodle - http://moodle.org/
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
 * @package       local_centro_de_descarga_de_portafolio_de_evidencias
 * @author        Simeon Naydenov (moniNaydenov@gmail.com)
 * @copyright     2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @modified      2026 José Luis Rodriguez Escobedo (jose.rodriguez@utj.edu.mx)
 *               Universidad Tecnológica de Jalisco — joserodriguez-utj
 */

require_once(__DIR__ . '/locallib_lesson.php');
require_once(__DIR__ . '/locallib_workshop.php');
require_once(__DIR__ . '/locallib_database.php');
require_once(__DIR__ . '/locallib_quiz.php');
require_once(__DIR__ . '/locallib_h5p.php');
require_once(__DIR__ . '/locallib_forum.php');
require_once(__DIR__ . '/locallib_assign.php');
require_once(__DIR__ . '/locallib_publication.php');
require_once(__DIR__ . '/locallib_materials.php');
require_once(__DIR__ . '/locallib_glossary.php');
require_once(__DIR__ . '/locallib_lightboxgallery.php');
require_once(__DIR__ . '/locallib_etherpadlite.php');
require_once(__DIR__ . '/locallib_scorm.php');
require_once(__DIR__ . '/locallib_feedback.php');

class local_centro_de_descarga_de_portafolio_de_evidencias_factory {
    use local_centro_de_descarga_de_portafolio_de_evidencias_lesson_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_workshop_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_quiz_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_h5p_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_forum_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_assign_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_publication_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_materials_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_glossary_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_lightboxgallery_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_etherpadlite_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_database_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_scorm_trait;
    use local_centro_de_descarga_de_portafolio_de_evidencias_feedback_trait;

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
     * @var string
     */
    private $downloadmode = 'normal';
    /**
     * @var array
     */
    private $selectedusers = [];
    /**
     * @var int|null
     */
    private $portfolio_userid = null;
    /**
     * @var array
     */
    private $filehashes = [];
    /**
     * Cache en memoria de PDFs ya generados (clave = hash del contenido).
     * @var array
     */
    private $pdfcache = [];
    /**
     * Nombre de la actividad para la segunda línea del encabezado del PDF.
     * @var string
     */
    private $pdfactivityname = '';
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
        'forum',
        'lesson',
        'workshop',
        'glossary',
        'etherpadlite',
        'subsection',
        'url',
        'label',
        'data',
        'scorm',
        'feedback',
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
     * local_centro_de_descarga_de_portafolio_de_evidencias_factory constructor.
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
     * Get the current download mode.
     *
     * @return string
     */
    public function get_download_mode(): string {
        return $this->downloadmode;
    }

    /**
     * Set the download mode ('normal' or 'portafolio').
     *
     * @param string $mode
     */
    public function set_download_mode(string $mode): void {
        $mode = $mode === 'portafolio' ? 'portafolio' : 'normal';
        $this->downloadmode = $mode;
    }

    /**
     * Set the selected users (students) for the portfolio download.
     *
     * @param array $userids
     */
    public function set_selected_users(array $userids): void {
        $this->selectedusers = array_values(array_filter(array_map('intval', $userids)));
    }

    /**
     * Get the list of selected user IDs for the portfolio download.
     *
     * @return array
     */
    public function get_selected_users(): array {
        return $this->selectedusers;
    }

    /**
     * Get the eligible user IDs of the course that can produce evidence.
     *
     * @return array
     */
    public function get_eligible_user_ids(): array {
        $coursecontext = \context_course::instance($this->course->id);
        // Usuarios inscritos capaces de intentar/entregar actividades.
        $users = get_enrolled_users($coursecontext, 'mod/assign:submit');
        $ids = [];
        foreach ($users as $user) {
            $ids[$user->id] = $user;
        }
        return $ids;
    }

    /**
     * Get the students that will be included in the portfolio download.
     *
     * Uses the shared group filter (selectedgroups): if groups are selected,
     * only the members of those groups (intersected with the selected users
     * when provided) are returned. If no group is selected, all eligible
     * students are returned.
     *
     * @return array
     */
    public function get_portfolio_users(): array {
        global $DB;
        $eligible = $this->get_eligible_user_ids();

        // Grupos seleccionados (el filtro compartido con el modo normal).
        $groupids = $this->selectedgroups;
        $memberids = [];
        if (!empty($groupids)) {
            foreach ($groupids as $gid) {
                $members = groups_get_members((int)$gid, 'u.id');
                if ($members) {
                    foreach ((array)$members as $member) {
                        $memberids[(int)$member->id] = true;
                    }
                }
            }
            if (empty($memberids)) {
                // Los grupos seleccionados no tienen miembros: portafolio vacío.
                return [];
            }
            $ids = array_keys($memberids);
        } else {
            $ids = null;
        }

        if (!empty($this->selectedusers)) {
            $selected = $this->selectedusers;
            // Filtrar seleccionados que sean miembros de los grupos elegidos.
            if ($ids !== null) {
                $memberbyid = array_flip($ids);
                $selected = array_values(array_filter($selected, function ($uid) use ($memberbyid) {
                    return isset($memberbyid[(int)$uid]);
                }));
            }
            $result = [];
            foreach ($selected as $uid) {
                if (isset($eligible[$uid])) {
                    $result[$uid] = $eligible[$uid];
                }
            }
            return $result;
        }

        // Sin selección específica: todos los de los grupos elegidos (o todos los elegibles si no hay grupos).
        if ($ids !== null) {
            $result = [];
            foreach ($ids as $uid) {
                if (isset($eligible[$uid])) {
                    $result[$uid] = $eligible[$uid];
                }
            }
            return $result;
        }

        return $eligible;
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
                $untitled = get_string('untitled', 'local_centro_de_descarga_de_portafolio_de_evidencias');
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
            $sorted['default']->itemid = 0;
            $sorted['default']->visible = true;
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
                        if ($onlytasks && !$solomateriales && !in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity', 'forum', 'lesson', 'workshop', 'data', 'scorm', 'feedback'])) {
                            continue;
                        }
                        if (in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity', 'forum', 'lesson', 'workshop', 'data', 'scorm', 'feedback'])) {
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
                        } else if ($res->modname == 'forum') {
                            $this->handle_forum($res, $resdir, $filelist, $groupid);
                        } else if ($res->modname == 'lesson') {
                            $this->handle_lesson($res, $resdir, $filelist, $groupid);
                        } else if ($res->modname == 'workshop') {
                            $this->handle_workshop($res, $resdir, $filelist, $groupid);
                        } else if ($res->modname == 'data') {
                            $this->handle_data($res, $resdir, $filelist, $groupid);
                        } else if ($res->modname == 'scorm') {
                            $this->handle_scorm($res, $resdir, $filelist, $groupid);
                        } else if ($res->modname == 'feedback') {
                            $this->handle_feedback($res, $resdir, $filelist, $groupid);
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
                    if ($onlytasks && !$solomateriales && !in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity', 'forum', 'lesson', 'workshop', 'data', 'scorm', 'feedback'])) {
                        continue;
                    }
                    if (in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity', 'forum', 'lesson', 'workshop', 'data', 'scorm', 'feedback'])) {
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
                    } else if ($res->modname == 'forum') {
                        $this->handle_forum($res, $resdir, $filelist);
                    } else if ($res->modname == 'lesson') {
                        $this->handle_lesson($res, $resdir, $filelist);
                    } else if ($res->modname == 'workshop') {
                        $this->handle_workshop($res, $resdir, $filelist);
                    } else if ($res->modname == 'data') {
                        $this->handle_data($res, $resdir, $filelist);
                    } else if ($res->modname == 'scorm') {
                        $this->handle_scorm($res, $resdir, $filelist);
                    } else if ($res->modname == 'feedback') {
                        $this->handle_feedback($res, $resdir, $filelist);
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

        // Certificación de descarga (PDF) a nivel de la carpeta del curso.
        $this->pdfactivityname = '';
        $this->pdfactivityname = '';
        $certpdf = $this->build_download_certificate();
        $certfilename = 'Certificación de descarga.pdf';
        $filelist[$coursename . '/' . $certfilename] = [$certpdf];

        // Reporte calificador (PDF, todos los usuarios) en la raíz del ZIP.
        // Depende de la capability "Reporte calificador".
        if (has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:gradebookreport',
                \context_course::instance($this->course->id), $this->user)) {
            $filelist['Reporte calificador.pdf'] = [$this->build_gradebook_export()];
        }

        // "Misceláneos": entregas de tareas de usuarios con rol != student.
        if (has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:miscellaneous',
                \context_course::instance($this->course->id), $this->user)) {
            $this->append_miscellaneous_files($filelist, $coursename);
        }

        $this->embed_html_resources($filelist);

        \core\session\manager::write_close();

        $zipname = sprintf('%s_%s.zip', format_string($this->course->shortname), userdate(time(), '%Y%m%d_%H%M%S', 'America/Mexico_City'));
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
        // Liberar el candado anti doble-descarga: solo debe cubrir la generación.
        set_user_preference('centro_de_descarga_de_portafolio_de_evidencias_ziptime', 0);
        die;
    }

    /**
     * Creates a zip file with the evidence of each selected student for the portfolio download.
     *
     * Structure: Curso/Estudiante/Actividad/Evidencias/... (sin carpeta de grupo)
     *
     * @return void
     */
    public function create_portfolio_zip() {
        global $CFG;

        if (file_exists($CFG->dirroot . '/mod/publication/locallib.php')) {
            require_once($CFG->dirroot . '/mod/publication/locallib.php');
        } else {
            define('PUBLICATION_MODE_UPLOAD', 0);
            define('PUBLICATION_MODE_IMPORT', 1);
        }

        $fs = get_file_storage();
        $filelist = [];
        $coursename = self::shorten_filename(self::clean_filename_ascii(format_string($this->course->shortname)));
        $addnumbering = $this->downloadoptions['addnumbering'];
        $fileprefix = $coursename;
        $includefeedback = $this->downloadoptions['includefeedback'] ?? true;
        $pathlist = $this->section_pathnames();

        $students = $this->get_portfolio_users();
        $filelist[$coursename] = null;

        // Si se descargan varios grupos, se separan los alumnos por carpeta de grupo:
        //   Curso/Grupo/Estudiante/Sección/Actividad/...
        // Con un solo grupo (o ninguno) se mantiene la estructura plana:
        //   Curso/Estudiante/...
        $groupids = array_values(array_filter(array_map('intval', $this->selectedgroups)));
        $certdirs = [];
        if (count($groupids) >= 2) {
            $groupfolder = [];
            $membergroup = [];
            foreach ($groupids as $gid) {
                $group = groups_get_group($gid);
                if (!$group) {
                    continue;
                }
                $foldername = self::shorten_filename(self::clean_filename_ascii($group->name));
                $base = $foldername;
                $i = 1;
                while (in_array($foldername, $groupfolder, true)) {
                    $foldername = $base . '_' . $i;
                    $i++;
                }
                $groupfolder[$gid] = $foldername;
                foreach ((array)groups_get_members($gid, 'u.id') as $member) {
                    if (!isset($membergroup[(int)$member->id])) {
                        $membergroup[(int)$member->id] = $gid;
                    }
                }
            }
            $bygroup = [];
            foreach ($students as $student) {
                $gid = $membergroup[(int)$student->id] ?? 0;
                $bygroup[$gid][] = $student;
            }
            foreach ($bygroup as $gid => $groupstudents) {
                $foldername = ($gid && isset($groupfolder[$gid]))
                    ? $groupfolder[$gid]
                    : get_string('no_group', 'local_centro_de_descarga_de_portafolio_de_evidencias');
                $groupdir = $coursename . '/' . $foldername;
                $filelist[$groupdir] = null;
                $certdirs[] = $groupdir;
                $this->append_portfolio_students(
                    $groupstudents, $groupdir, $pathlist, $addnumbering, $filelist, null, $fileprefix, $includefeedback
                );
            }
        } else {
            $certdirs[] = $coursename;
            $this->append_portfolio_students(
                $students, $coursename, $pathlist, $addnumbering, $filelist, null, $fileprefix, $includefeedback
            );
        }

        // Certificación de descarga (PDF) a nivel de la carpeta del alumno:
        // Curso/Certificación de descarga.pdf  (o Curso/Grupo/Certificación...).
        $certpdf = $this->build_download_certificate();
        $certfilename = 'Certificación de descarga.pdf';
        foreach (array_unique($certdirs) as $certdir) {
            $filelist[$certdir . '/' . $certfilename] = [$certpdf];
        }

        // Reporte calificador (PDF, todos los usuarios) en la raíz del ZIP.
        // Depende de la capability "Reporte calificador".
        if (has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:gradebookreport',
                \context_course::instance($this->course->id), $this->user)) {
            $filelist['Reporte calificador.pdf'] = [$this->build_gradebook_export()];
        }

        // "Misceláneos": entregas de tareas de usuarios con rol != student.
        if (has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:miscellaneous',
                \context_course::instance($this->course->id), $this->user)) {
            $this->append_miscellaneous_files($filelist, $coursename);
        }

        // Incluir archivos embebidos (audio/video/imágenes) en Recurso/ junto al HTML.
        $this->embed_html_resources($filelist);

        // Eliminar carpetas que quedaron vacías (sin evidencias).
        $this->prune_empty_dirs($filelist);

        \core\session\manager::write_close();

        $zipname = sprintf('%s_%s_portafolio.zip', format_string($this->course->shortname), userdate(time(), '%Y%m%d_%H%M%S', 'America/Mexico_City'));
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
        // Liberar el candado anti doble-descarga: solo debe cubrir la generación.
        set_user_preference('centro_de_descarga_de_portafolio_de_evidencias_ziptime', 0);
        die;
    }

    /**
     * Appends the portfolio evidence of a set of students to the file list.
     *
     * @param array $students
     * @param string $basedir
     * @param array $pathlist
     * @param bool $addnumbering
     * @param array $filelist
     * @param int|null $gid
     * @param string $fileprefix
     * @param bool $includefeedback
     * @return void
     */
    private function append_portfolio_students($students, $basedir, $pathlist, $addnumbering, &$filelist, $gid, $fileprefix, $includefeedback) {
        foreach ($students as $student) {
            $studentname = self::shorten_filename(self::clean_filename_ascii(fullname($student)));
            $studentdir = $basedir . '/' . $studentname;
            $filelist[$studentdir] = null;
            $this->portfolio_userid = $student->id;

            foreach ($pathlist as $sectionname => $sectionresources) {
                $sectionresources = $this->preprocess_resource_names($sectionresources, $addnumbering);
                // Carpeta de la sección del curso (Presentacion, Unidad 1, Remedial, ...).
                $sectionsubdir = '';
                foreach ($sectionresources as $res) {
                    $res->name = html_entity_decode($res->name);
                    if (!in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity', 'forum', 'lesson', 'workshop', 'data', 'scorm', 'feedback'])) {
                        continue;
                    }
                    if ($sectionsubdir === '') {
                        // Solo se crea si hay alguna actividad soportada en la sección.
                        $sectionsubdir = $studentdir . '/' . $sectionname;
                        $filelist[$sectionsubdir] = null;
                    }
                    $activityname = self::shorten_filename(self::clean_filename_ascii($res->name));
                    $resdir = $sectionsubdir . '/' . $activityname;

                    if ($res->modname == 'assign') {
                        $this->handle_assign($res, $resdir, $filelist, $gid, $fileprefix, $includefeedback);
                    } else if ($res->modname == 'quiz') {
                        $this->handle_quiz($res, $resdir, $filelist, $gid);
                    } else if ($res->modname == 'h5pactivity') {
                        $this->handle_h5pactivity($res, $resdir, $filelist, $gid);
                    } else if ($res->modname == 'forum') {
                        $this->handle_forum($res, $resdir, $filelist, $gid);
                    } else if ($res->modname == 'lesson') {
                        $this->handle_lesson($res, $resdir, $filelist, $gid);
                    } else if ($res->modname == 'workshop') {
                        $this->handle_workshop($res, $resdir, $filelist, $gid);
                    } else if ($res->modname == 'data') {
                        $this->handle_data($res, $resdir, $filelist, $gid);
                    } else if ($res->modname == 'scorm') {
                        $this->handle_scorm($res, $resdir, $filelist, $gid);
                    } else if ($res->modname == 'feedback') {
                        $this->handle_feedback($res, $resdir, $filelist, $gid);
                    } else {
                        $this->handle_publication($res, $resdir, $filelist, $gid);
                    }

                    // Portafolio: si el alumno no generó ninguna evidencia, dejar constancia.
                    // Solo la evidencia cuenta: instrucciones/retroalimentación no
                    // deben impedir el aviso de "Sin realizar".
                    if ($this->portfolio_userid !== null && !$this->has_files_under($filelist, $resdir . '/Evidencias')) {
                        // $notdone = get_string('string_not_done', 'local_centro_de_descarga_de_portafolio_de_evidencias');
                        // $html = self::convert_content_to_html_doc($notdone, '<p>' . $notdone . '</p>');
                        // $filelist[$resdir . '/' . self::shorten_filename($notdone . '.html')] = [$html];

                        $notdone = get_string('string_not_done', 'local_centro_de_descarga_de_portafolio_de_evidencias');
                        $this->pdfactivityname = $res->name;
                        $body = $this->build_not_done_pdf_body($student, $res->name, $sectionname);
                        $title = $notdone;
                        $footerleft = $notdone . ' - ' . $studentname;
                        $pdf = $this->build_pdf_document($title, $body, 'P', 'A4', 6.5, $footerleft);
                        $filelist[$resdir . '/' . self::shorten_filename($notdone . '.pdf')] = [$pdf];
                    }
                }
            }

            $this->portfolio_userid = null;

            // Portafolio: reporte de calificaciones del alumno (al mismo nivel de las carpetas de actividades).
            $reporthtml = $this->build_grade_report_html($student);
            if ($reporthtml !== '') {
                $reportname = get_string('string_grade_report', 'local_centro_de_descarga_de_portafolio_de_evidencias') . ' - ' . $studentname;
                $this->pdfactivityname = '';
                $pdf = $this->build_pdf_document($reportname, $reporthtml, 'P', 'A4', 7, $reportname);
                $filelist[$studentdir . '/' . self::shorten_filename($reportname . '.pdf')] = [$pdf];
            }
        }
    }

    /**
     * Builds the HTML content of the user grade report (reporte de usuario de calificaciones).
     *
     * Reuses the real Moodle user report (\gradereport_user\report\user) so that the grades
     * shown match the gradebook. The rendered table data is parsed and rebuilt as a clean,
     * self-contained HTML table with a fixed set of columns: grade item, grade, maximum
     * grade and feedback (the site/user report settings in Moodle are ignored).
     *
     * @param object $student
     * @return string The HTML fragment (may be empty if the course has no grade items).
     */
    private function build_grade_report_html($student) {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');
        require_once($CFG->dirroot . '/grade/report/user/lib.php');

        $gpr = new grade_plugin_return([
            'type' => 'report',
            'plugin' => 'user',
            'courseid' => $this->course->id,
            'userid' => $student->id,
        ]);
        $context = context_course::instance($this->course->id);

        $report = new \gradereport_user\report\user($this->course->id, $gpr, $context, $student->id, false);
        // Force a fixed set of columns regardless of the site/report user settings.
        $report->showweight = false;
        $report->showgrade = true;
        $report->showrange = true; // Used to derive the maximum grade.
        $report->showpercentage = false;
        $report->showfeedback = true;
        $report->showcontributiontocoursetotal = false;
        $report->showlettergrade = false;
        $report->showrank = false;
        $report->showaverage = false;
        $report->fill_table();

        $rows = $this->parse_grade_report_table($report->tabledata);
        if (empty($rows)) {
            return '';
        }

        // Datos generales del estudiante (mismo patron que los demas reportes).
        $hd  = 'style="background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;"';
        $th  = 'style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;text-align:left;font-size:8pt;"';
        $td  = 'style="border:1px solid #adb5bd;padding:2px;vertical-align:top;font-size:8pt;"';
        $tblh = 'border="1" cellspacing="0" cellpadding="2" style="border-collapse:collapse;width:100%;font-size:8pt;"';

        $h  = '<h2 ' . $hd . '>' . s(get_string('quiz_general_data', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</h2>';
        $h .= '<table ' . $tblh . '>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . s(get_string('quiz_lastname', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($student->lastname) . '</td>'
            . '<th ' . $th . '>' . s(get_string('quiz_firstname', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($student->firstname) . '</td>'
            . '<th ' . $th . '>' . s(get_string('quiz_email', 'local_centro_de_descarga_de_portafolio_de_evidencias')) . '</th>'
            . '<td ' . $td . '>' . s($student->email) . '</td>'
            . '</tr>';
        $h .= '</table>';

        $h .= '<table border="1" cellpadding="2" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:7pt;">';
        $h .= '<tr>';
        $headers = [
            get_string('string_grade_report_item', 'local_centro_de_descarga_de_portafolio_de_evidencias'),
            get_string('string_grade_report_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias'),
            get_string('string_grade_report_max', 'local_centro_de_descarga_de_portafolio_de_evidencias'),
            get_string('string_grade_report_feedback', 'local_centro_de_descarga_de_portafolio_de_evidencias'),
        ];
        foreach ($headers as $head) {
            $h .= '<th style="background-color:#dbe4f0;border:1px solid #8a99b0;padding:2px;text-align:left;font-size:7pt;">' . s($head) . '</th>';
        }
        $h .= '</tr>';

        foreach ($rows as $row) {
            if ($row['type'] === 'category') {
                // Skip the category row that repeats the course name (grade tree root).
                if ($row['name'] === trim($this->course->fullname)) {
                    continue;
                }
                $h .= '<tr nobr="true"><td colspan="' . count($headers) . '" style="background:#e8e8e8;font-weight:bold;">'
                    . s($row['name']) . '</td></tr>';
                continue;
            }

            $itemstyle = '';
            // Aggregation totals (e.g. "Cálculo total (agregación)"): italic + semibold.
            if ($row['itemtype'] === get_string('aggregation', 'core_grades')) {
                $itemstyle = 'font-style:italic;font-weight:600;';
            }
            // Calculated grades (e.g. "Calificación calculada"): fully bold.
            if ($row['itemtype'] === get_string('calculatedgrade', 'grades')) {
                $itemstyle = 'font-weight:bold;background:#f7f7f7;';
            }
            $nametext = s($row['itemtype']) . ': ' . s($row['name']);
            $h .= '<tr nobr="true">'
                . '<td style="' . $itemstyle . '">' . $nametext . '</td>'
                . '<td style="' . $itemstyle . 'text-align:center;">' . s($row['grade']) . '</td>'
                . '<td style="' . $itemstyle . 'text-align:center;">' . s($row['max']) . '</td>'
                . '<td style="' . $itemstyle . '">' . s($row['feedback']) . '</td>'
                . '</tr>';
        }

        $h .= '</table>';
        return $h;
    }

    /**
     * Parses the table data produced by \gradereport_user\report\user::fill_table() into a
     * flat list of clean rows (categories and grade items) with plain-text cell values.
     *
     * @param array $tabledata
     * @return array[] Each row: ['type' => 'category'|'item', 'name', 'itemtype',
     *                 'grade', 'max', 'feedback'].
     */
    private function parse_grade_report_table(array $tabledata) {
        $rows = [];
        foreach ($tabledata as $row) {
            if (!isset($row['itemname'])) {
                continue;
            }
            $content = $row['itemname']['content'];

            if (strpos($content, 'category-content') !== false) {
                preg_match('/category-content">.*?<span>([^<]*)<\/span>/s', $content, $m);
                $rows[] = ['type' => 'category', 'name' => trim($m[1] ?? '')];
                continue;
            }

            preg_match('/text-uppercase small[^>]*title="([^"]*)"/', $content, $t);
            $itemtype = trim($t[1] ?? '');
            preg_match('/<div class="rowtitle">(?:<[^>]*>)*([^<]+)/', $content, $n);
            $name = trim(html_entity_decode($n[1] ?? ''));
            if ($name === '') {
                continue;
            }

            $rows[] = [
                'type' => 'item',
                'itemtype' => $itemtype,
                'name' => $name,
                'grade' => $this->grade_report_grade_cell($row['grade']['content'] ?? '-'),
                'max' => $this->grade_report_max_cell($row['range']['content'] ?? '-'),
                'feedback' => $this->grade_report_plain_cell($row['feedback']['content'] ?? ''),
            ];
        }
        return $rows;
    }

    /**
     * Cleans the grade cell: graded items keep the numeric grade, anything else falls
     * back to the plain text content of the cell.
     *
     * @param string $content
     * @return string
     */
    private function grade_report_grade_cell($content) {
        if (preg_match('/d-flex align-items-center"><div>(.*?)<\/div>/s', $content, $m)) {
            $v = trim(html_entity_decode(strip_tags($m[1])));
            if ($v !== '') {
                return $v;
            }
        }
        return $this->grade_report_plain_cell($content);
    }

    /**
     * Extracts the maximum grade from the formatted range cell (e.g. "0&ndash;10" -> "10").
     *
     * @param string $content
     * @return string
     */
    private function grade_report_max_cell($content) {
        $pos = strpos($content, '&ndash;');
        if ($pos === false) {
            return $this->grade_report_plain_cell($content);
        }
        $max = trim(html_entity_decode(strip_tags(substr($content, $pos + strlen('&ndash;')))));
        return $max === '' ? '-' : $max;
    }

    /**
     * Strips markup from a cell and decodes entities, normalising any empty or
     * non-breaking space only content to an empty string.
     *
     * @param string $content
     * @return string
     */
    private function grade_report_plain_cell($content) {
        $c = trim(html_entity_decode(strip_tags(str_replace(['&nbsp;', '&nbsp'], ' ', $content))));
        return $c === '' ? '' : $c;
    }

    /**
     * Checks whether at least one file (non directory placeholder) exists under the given path.
     *
     * @param array $filelist
     * @param string $dir
     * @return bool
     */
    private function has_files_under(array $filelist, string $dir): bool {
        $prefix = $dir . '/';
        foreach ($filelist as $path => $file) {
            if ($file !== null && strpos($path, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Removes directory placeholder entries that contain no file descendants.
     *
     * @param array $filelist
     * @return void
     */
    private function prune_empty_dirs(&$filelist) {
        $keepdirs = [];
        foreach ($filelist as $path => $file) {
            if ($file !== null) {
                $dir = $path;
                while (($pos = strrpos($dir, '/')) !== false) {
                    $dir = substr($dir, 0, $pos);
                    $keepdirs[$dir] = true;
                }
            }
        }
        foreach ($filelist as $path => $file) {
            if ($file === null && !isset($keepdirs[$path])) {
                unset($filelist[$path]);
            }
        }
    }

    /**
     * Copies files referenced by pluginfile.php URLs inside generated HTML into a
     * sibling "Recurso" folder and rewrites those URLs to relative paths.
     *
     * This makes embedded audio/video/images in exported evidence work offline.
     *
     * @param array $filelist
     * @return void
     */
    /**
     * Agrega al ZIP los archivos referenciados con pluginfile.php en un HTML
     * (audio/video/imágenes embebidos) bajo $dir.
     *
     * @param string $html
     * @param string $dir
     * @param array $filelist
     * @return void
     */
    protected function add_embedded_files_from_html(string $html, string $dir, array &$filelist): void {
        if (strpos($html, 'pluginfile.php') === false) {
            return;
        }
        global $DB;
        $fs = get_file_storage();
        if (!preg_match_all(
                '#https?://[^"\'\s<>]*?/pluginfile\.php/(\d+)/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/(\d+)/([^"\'\s<>?]+)#',
                $html, $matches, PREG_SET_ORDER)) {
            return;
        }
        foreach ($matches as $m) {
            $contextid = (int) $m[1];
            $component = $m[2];
            $filearea = $m[3];
            $itemid = (int) $m[4];
            $filepathname = rawurldecode($m[5]);
            $slash = strrpos($filepathname, '/');
            $filename = ($slash === false) ? $filepathname : substr($filepathname, $slash + 1);
            $filepath = ($slash === false) ? '/' : '/' . substr($filepathname, 0, $slash + 1);
            try {
                $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
            } catch (\Exception $e) {
                continue;
            }
            if (!$file || $file->is_directory()) {
                continue;
            }
            $name = self::shorten_filename(self::clean_filename_ascii($file->get_filename()));
            $filelist[$dir] = null;
            $filelist[$dir . '/' . $name] = $file;
        }
    }

    private function embed_html_resources(&$filelist) {
        $fs = get_file_storage();
        $sourcemap = [];

        foreach ($filelist as $path => $value) {
            if (!is_array($value)) {
                continue;
            }
            $html = reset($value);
            if (!is_string($html) || strpos($html, 'pluginfile.php') === false) {
                continue;
            }

            $dir = dirname($path);
            $recursodir = ($dir === '.' || $dir === '') ? 'Recurso' : $dir . '/Recurso';
            $usednames = [];
            $changed = false;

            $newhtml = preg_replace_callback(
                '#https?://[^"\'\s<>]*?/pluginfile\.php/(\d+)/([A-Za-z0-9_]+)/([A-Za-z0-9_]+)/(\d+)/([^"\'\s<>?]+)#',
                function ($matches) use ($fs, &$filelist, $recursodir, &$usednames, &$sourcemap, &$changed) {
                    $contextid = (int) $matches[1];
                    $component = $matches[2];
                    $filearea = $matches[3];
                    $itemid = (int) $matches[4];
                    $filepathname = rawurldecode($matches[5]);
                    $slash = strrpos($filepathname, '/');
                    $filename = ($slash === false) ? $filepathname : substr($filepathname, $slash + 1);
                    $filepath = ($slash === false) ? '/' : '/' . substr($filepathname, 0, $slash + 1);

                    // Deduplicar: la misma fuente puede aparecer varias veces en el HTML.
                    $sourcekey = $recursodir . '|' . $contextid . '|' . $component . '|' . $filearea . '|' .
                        $itemid . '|' . $filepath . '|' . $filename;
                    if (isset($sourcemap[$sourcekey])) {
                        return 'Recurso/' . rawurlencode($sourcemap[$sourcekey]);
                    }

                    try {
                        $file = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
                    } catch (\Exception $e) {
                        return $matches[0];
                    }
                    if (!$file || $file->is_directory()) {
                        return $matches[0];
                    }

                    $basename = self::shorten_filename(self::clean_filename_ascii($file->get_filename()));
                    $info = pathinfo($basename);
                    $extension = isset($info['extension']) ? '.' . $info['extension'] : '';
                    $stem = isset($info['filename']) ? $info['filename'] : $basename;
                    $name = $basename;
                    $i = 1;
                    while (isset($usednames[$name]) || isset($filelist[$recursodir . '/' . $name])) {
                        $name = $stem . '_' . $i . $extension;
                        $i++;
                    }
                    $usednames[$name] = true;
                    $sourcemap[$sourcekey] = $name;

                    $filelist[$recursodir] = null;
                    $filelist[$recursodir . '/' . $name] = $file;
                    $changed = true;

                    return 'Recurso/' . rawurlencode($name);
                },
                $html
            );

            if ($changed) {
                $filelist[$path] = [$newhtml];
            }
        }
    }


    /** 
     * Builds the HTML content of the "not done" PDF for a student and activity.
     * 
     * Used in portfolio mode whrn a student has no evidence for supported
     * activity: instead of leaving an empty folder, a small PDF is generated
     * so the reviewer can see the activity was expected but not delivered.
     * 
     * @param Object $student The student user record.
     * @param string $activityname The display name  of the activity.
     * @param string $sectiondir The section directory (used to derive context).
     * @return string HTML frament ready for build:pdf_document(). 
     * **/

    private function build_not_done_pdf_body($student, $activityname, $sectiondir) {
        $notdone = get_string('string_not_done', 'local_centro_de_descarga_de_portafolio_de_evidencias');

        // Estilos consistentes con build_quiz_pdf_body().
        $th  = 'style="background-color:#dbe4f0;font-weight:bold;border:1px solid #8a99b0;padding:2px;text-align:left;font-size:8pt;"';
        $td  = 'style="border:1px solid #adb5bd;padding:2px;vertical-align:top;font-size:8pt;"';
        $hd  = 'style="background-color:#e9ecef;border:1px solid #adb5bd;padding:5px;font-size:10pt;font-weight:bold;margin-top:10px;"';
        $tbl = 'border="1" cellspacing="0" cellpadding="2" style="border-collapse:collapse;width:100%;font-size:8pt;"';

        // 1) Datos del estudiante (arriba, mismo formato que quiz).
        $h  = '<h2 ' . $hd . '>' . s($notdone) . '</h2>';
        $h .= '<table ' . $tbl . '>';
        $h .= '<tr nobr="true">'
            . '<th ' . $th . '>' . get_string('quiz_lastname', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
            . '<td ' . $td . '>' . s($student->lastname) . '</td>'
            . '<th ' . $th . '>' . get_string('quiz_firstname', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
            . '<td ' . $td . '>' . s($student->firstname) . '</td>'
            . '<th ' . $th . '>' . get_string('quiz_email', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</th>'
            . '<td ' . $td . '>' . s($student->email) . '</td></tr>';
        $h .= '</table>';

        return $h;
    }


    /**
     * Builds the "Certificación de descarga" PDF for the portfolio ZIP.
     *
     * @return string Raw PDF content.
     */
    private function build_download_certificate(): string {
        global $CFG;

        require_once($CFG->libdir . '/pdflib.php');

        $context = \context_course::instance($this->course->id);

        $rolenames = [];
        foreach (get_user_roles($context, $this->user->id, true) as $role) {
            $rolenames[] = role_get_name($role, $context);
        }
        if (!$rolenames) {
            // Sin rol en el curso: usar el rol a nivel de plataforma (sistema).
            $systemcontext = \context_system::instance();
            foreach (get_user_roles($systemcontext, $this->user->id, true) as $role) {
                $rolenames[] = role_get_name($role, $systemcontext);
            }
        }
        if (!$rolenames && is_siteadmin($this->user)) {
            // Administrador del sitio (no tiene un rol asignado explícitamente).
            $rolenames[] = get_string('siteadmin', 'local_centro_de_descarga_de_portafolio_de_evidencias');
        }
        $rolestr = $rolenames ? implode(', ', array_unique($rolenames)) : '-';

        $rows = [
            get_string('certificate_user', 'local_centro_de_descarga_de_portafolio_de_evidencias') => fullname($this->user),
            get_string('certificate_url', 'local_centro_de_descarga_de_portafolio_de_evidencias')
                => $CFG->wwwroot . '/course/view.php?id=' . $this->course->id,
            get_string('certificate_course', 'local_centro_de_descarga_de_portafolio_de_evidencias')
                => format_string($this->course->fullname),
            get_string('certificate_date', 'local_centro_de_descarga_de_portafolio_de_evidencias')
                => userdate(time(), '%d/%m/%Y %H:%M:%S', 'America/Mexico_City') . ' (GMT-6)',
            get_string('certificate_role', 'local_centro_de_descarga_de_portafolio_de_evidencias') => $rolestr,
        ];

        $html = '<h1 style="text-align:center;">' .
            get_string('certificate_title', 'local_centro_de_descarga_de_portafolio_de_evidencias') . '</h1>';
        $html .= '<table border="1" cellpadding="6" cellspacing="0" width="100%">';
        foreach ($rows as $label => $value) {
            $html .= '<tr><td width="35%"><b>' . s($label) . '</b></td><td>' . s($value) . '</td></tr>';
        }
        $html .= '</table>';

        $pdf = new \pdf();
        $pdf->SetTitle(get_string('certificate_title', 'local_centro_de_descarga_de_portafolio_de_evidencias'));
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('certificacion.pdf', 'S');
    }

    /**
     * Builds the gradebook export (XLSX) including all users (not only active).
     *
     * Replicates grade_export_xls::print_grades() but captures the output instead
     * of sending it to the browser.
     *
     * @return string Raw XLSX content.
     */
    /**
     * Devuelve los IDs de usuario a incluir en el Reporte calificador según el
     * filtro del formulario (alumno(s) y/o grupo(s)). Devuelve null si no hay
     * filtro (es decir, todos los usuarios).
     *
     * @return int[]|null
     */
    private function get_gradebook_user_filter(): ?array {
        if ($this->portfolio_userid !== null) {
            return [(int) $this->portfolio_userid];
        }

        $ids = null;
        if (!empty($this->selectedgroups)) {
            $members = [];
            foreach ($this->selectedgroups as $gid) {
                foreach ((array) groups_get_members((int) $gid, 'u.id') as $member) {
                    $members[(int) $member->id] = true;
                }
            }
            $ids = array_keys($members);
            if (empty($ids)) {
                return [];
            }
        }

        if (!empty($this->selectedusers)) {
            $selected = array_values(array_unique(array_map('intval', $this->selectedusers)));
            if ($ids !== null) {
                $selected = array_values(array_intersect($selected, $ids));
            }
            return $selected;
        }

        return $ids;
    }

    private function build_gradebook_export(): string {
        global $CFG;

        require_once($CFG->dirroot . '/grade/export/lib.php');
        require_once($CFG->dirroot . '/grade/export/xls/grade_export_xls.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/pdflib.php');

        // graded_users_iterator lanza gradesneedregrading si el curso lo necesita.
        if (grade_needs_regrade_final_grades($this->course->id)) {
            grade_regrade_final_grades($this->course->id);
        }

        $formdata = new \stdClass();
        $formdata->itemids = null; // Todos los ítems de calificación.
        $formdata->decimals = 2;
        $formdata->export_letters = false;
        $formdata->export_feedback = false;
        // Incluir a todos los usuarios (no excluir suspendidos).
        $formdata->export_onlyactive = false;
        $formdata->display = ['real' => GRADE_DISPLAY_TYPE_REAL];
        $formdata->updatedgradesonly = false;

        $export = new \grade_export_xls($this->course, 0, $formdata);

        // Orden como el "Calificador": solo ítems calificables, sin "último
        // descargado", y con la calificación final (ítem del curso) al final.
        $columns = [];
        $courseitem = null;
        foreach ($export->columns as $itemid => $item) {
            if ((int) $item->gradetype === GRADE_TYPE_NONE) {
                continue;
            }
            if ($item->itemtype === 'course') {
                $courseitem = $item;
                continue;
            }
            $columns[$itemid] = $item;
        }
        if ($courseitem) {
            $columns[$courseitem->id] = $courseitem;
        }

        $profilefields = \grade_helper::get_user_profile_fields($this->course->id, false);

        $headers = [];
        foreach ($profilefields as $field) {
            $headers[] = $field->fullname;
        }
        foreach ($columns as $grade_item) {
            foreach ($export->displaytype as $gradedisplayname => $gradedisplayconst) {
                if ($grade_item->itemtype === 'course') {
                    $headers[] = get_string('final_grade', 'local_centro_de_descarga_de_portafolio_de_evidencias');
                } else {
                    $headers[] = $export->format_column_name($grade_item, false, $gradedisplayname);
                }
            }
        }

        $rows = [];
        $filterids = $this->get_gradebook_user_filter();
        $allowed = $filterids === null ? null : array_flip($filterids);
        $gui = new \graded_users_iterator($this->course, $export->columns, 0);
        $gui->require_active_enrolment($export->onlyactive);
        $gui->allow_user_custom_fields(false);
        $gui->init();
        while ($userdata = $gui->next_user()) {
            $user = $userdata->user;
            if ($allowed !== null && !isset($allowed[(int) $user->id])) {
                continue;
            }
            $row = [];
            foreach ($profilefields as $field) {
                $row[] = \grade_helper::get_user_field_value($user, $field);
            }
            foreach ($columns as $itemid => $grade_item) {
                $grade = $userdata->grades[$itemid] ?? null;
                foreach ($export->displaytype as $gradedisplayconst) {
                    $row[] = $export->format_grade($grade, $gradedisplayconst);
                }
            }
            $rows[] = $row;
        }
        $gui->close();

        // Quitar columnas sin datos (ID number, Institution, etc.). Se conserva
        // siempre la última columna (calificación final).
        $lastindex = count($headers) - 1;
        $keep = [];
        foreach ($headers as $i => $header) {
            if ($i === $lastindex) {
                $keep[$i] = true;
                continue;
            }
            foreach ($rows as $row) {
                if (isset($row[$i]) && trim((string) $row[$i]) !== '') {
                    $keep[$i] = true;
                    break;
                }
            }
        }
        $newheaders = [];
        foreach ($headers as $i => $header) {
            if (isset($keep[$i])) {
                $newheaders[] = $header;
            }
        }
        $newrows = [];
        foreach ($rows as $row) {
            $newrow = [];
            foreach ($row as $i => $cell) {
                if (isset($keep[$i])) {
                    $newrow[] = $cell;
                }
            }
            $newrows[] = $newrow;
        }
        $headers = $newheaders;
        $rows = $newrows;

        $pdf = new \pdf('L', 'mm', 'A3', true, 'UTF-8');
        $pdf->SetTitle(get_string('gradebook_report', 'local_centro_de_descarga_de_portafolio_de_evidencias'));
        $pdf->setPrintHeader(false);
        $pdf->SetFont('helvetica', '', 6);
        $pdf->AddPage();

        $title = '<h2 style="text-align:center;">' .
            get_string('gradebook_report', 'local_centro_de_descarga_de_portafolio_de_evidencias') . ': ' .
            s(format_string($this->course->fullname)) . '</h2>';

        $thead = '<table border="1" cellpadding="2" cellspacing="0" style="font-size:6pt;">';
        $thead .= '<thead><tr>';
        foreach ($headers as $header) {
            $thead .= '<th style="background-color:#e9ecef;font-weight:bold;">' . s($header) . '</th>';
        }
        $thead .= '</tr></thead>';

        // Paginar manualmente: una tabla completa por página evita que TCPDF
        // parta una fila (y estire la anterior hasta el borde de la hoja).
        // Se usa un bloque fijo conservador para garantizar que siempre quepa.
        $rowsperpage = 8;
        $chunks = array_chunk($rows, $rowsperpage);
        if (!$chunks) {
            $chunks = [[]];
        }
        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                $pdf->AddPage();
            }
            $html = ($index === 0 ? $title : '') . $thead . '<tbody>';
            foreach ($chunk as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>' . s($cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            $pdf->writeHTML($html, true, false, true, false, '');
        }

        return $pdf->Output('reporte_calificador.pdf', 'S');
    }

    /**
     * Adds assignment submissions made by non-student users under
     * Curso/Misceláneos/<Actividad>/<Usuario>/.
     *
     * @param array $filelist
     * @param string $coursename
     * @return void
     */
    private function append_miscellaneous_files(&$filelist, $coursename) {
        global $DB;

        $coursecontext = \context_course::instance($this->course->id);
        $fs = get_file_storage();

        $sql = "SELECT cm.id AS cmid, a.id AS assignid, a.name AS name
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                  JOIN {assign} a ON a.id = cm.instance
                 WHERE cm.course = :courseid AND cm.deletioninprogress = 0
              ORDER BY a.name";
        $assigns = $DB->get_records_sql($sql, ['courseid' => $this->course->id]);
        if (!$assigns) {
            return;
        }

        $miscdir = $coursename . '/Misceláneos';
        $added = false;

        foreach ($assigns as $assign) {
            $cmcontext = \context_module::instance($assign->cmid);
            $activityname = self::shorten_filename(self::clean_filename_ascii($assign->name));

            $submissions = $DB->get_records('assign_submission', [
                'assignment' => $assign->assignid,
                'latest' => 1,
            ]);
            foreach ($submissions as $submission) {
                if ($submission->status !== 'submitted') {
                    continue;
                }
                $isstudent = false;
                foreach (get_user_roles($coursecontext, $submission->userid, false) as $role) {
                    if ($role->shortname === 'student') {
                        $isstudent = true;
                        break;
                    }
                }
                if ($isstudent) {
                    continue;
                }
                $user = $DB->get_record('user', ['id' => $submission->userid], '*', IGNORE_MISSING);
                if (!$user || $user->deleted) {
                    continue;
                }
                $username = self::shorten_filename(self::clean_filename_ascii(fullname($user)));

                $files = $fs->get_area_files(
                    $cmcontext->id, 'assignsubmission_file', 'submission_files', $submission->id,
                    'itemid, filepath, filename', false
                );
                foreach ($files as $file) {
                    if ($file->is_directory()) {
                        continue;
                    }
                    $dir = $miscdir . '/' . $activityname . '/' . $username;
                    $filelist[$dir] = null;
                    $filename = $dir . '/' . self::shorten_filename(self::clean_filename_ascii($file->get_filename()));
                    $filelist[$filename] = $file;
                    $added = true;
                }
            }
        }

        if ($added) {
            $filelist[$miscdir] = null;
        }
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

        // Modo de descarga: normal o portafolio. Se valida contra las capacidades
        // del usuario (no se confía en el formulario). 'downloadnormal' es
        // superconjunto: habilita normal y portafolio; 'downloadportfolio' solo portafolio.
        $coursecontext = \context_course::instance($this->course->id);
        $canmodenormal = has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadnormal', $coursecontext, $this->user);
        $canmodeportfolio = $canmodenormal
            || has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadportfolio', $coursecontext, $this->user);
        $requestedmode = $data['downloadmode'] ?? 'normal';
        if ($requestedmode === 'normal' && !$canmodenormal) {
            $requestedmode = 'portafolio';
        }
        if ($requestedmode === 'portafolio' && !$canmodeportfolio) {
            $requestedmode = 'normal';
        }
        $this->set_download_mode($requestedmode);

        // Portafolio: filtro "Todos mis grupos asignados" vs "Por alumno".
        // En "Todos mis grupos asignados" se descargan todos los alumnos de los grupos
        // marcados en el filtro de grupos; en "Por alumno" además se respeta la
        // selección individual de alumnos.
        $portfolioselect = $data['portfolioselect'] ?? 'allmygroups';
        if ($this->downloadmode === 'portafolio') {
            if ($portfolioselect === 'allmygroups') {
                $this->set_selected_users([]);
            } else {
                $selectedusers = $data['selectedstudents'] ?? [];
                if (is_string($selectedusers)) {
                    $selectedusers = array_filter(array_map('trim', explode(',', $selectedusers)));
                }
                $this->set_selected_users((array)$selectedusers);
            }
        }

        // Determinar cuántos grupos tiene el usuario en el curso.
        $usergroups = groups_get_user_groups($this->course->id, $this->user->id);
        $usergroupids = $usergroups[0] ?? [];
        // Quien tiene downloadMaterials (manager) o accessallgroups ve todos los grupos.
        $coursecontext = \context_course::instance($this->course->id);
        $canaccessall = has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadMaterials', $coursecontext, $this->user)
            || has_capability('moodle/site:accessallgroups', $coursecontext, $this->user);

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
        } else if (count($usergroupids) >= 2) {
            // 2+ grupos: usa la selección del formulario; si no hay, todos sus grupos.
            $this->selectedgroups = !empty($data['selectedgroups']) ? $data['selectedgroups'] : $usergroupids;
        } else {
            // 0 o 1 grupo: el docente ve todo el curso (sin restringir por grupo), para
            // contemplar cursos donde los alumnos van sin grupo y solo existe un grupo
            // tipo "Remedial".
            $this->selectedgroups = [];
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
    /**
     * Construye un PDF con estilo de reporte (título, secciones y tablas).
     *
     * @param string $title
     * @param string $bodyhtml Cuerpo HTML apto para TCPDF.
     * @param string $orientation 'P' o 'L'.
     * @param string $format 'A4', 'A3', ...
     * @param int $fontsize
     * @return string Bytes del PDF.
     */
    /**
     * TCPDF ignora vertical-align en tablas HTML. Para aproximar el centrado
     * vertical, rellena con <br> las celdas más cortas de cada fila hasta
     * alinear su contenido al centro de la fila.
     *
     * @param \pdf $pdf Documento (con la fuente del cuerpo ya establecida).
     * @param string $html Cuerpo HTML.
     * @param float $defaultfontsize Tamaño de fuente por defecto.
     * @return string
     */
    private function pdf_center_table_cells($pdf, string $html, float $defaultfontsize): string {
        if (stripos($html, '<tr') === false) {
            return $html;
        }

        $margins = $pdf->getMargins();
        $usable = $pdf->getPageWidth() - $margins['left'] - $margins['right'];

        $result = preg_replace_callback('#(<tr\b[^>]*>)(.*?)(</tr>)#is', function ($row) use ($pdf, $usable, $defaultfontsize) {
            $open = $row[1];
            $inner = $row[2];
            $close = $row[3];

            if (!preg_match_all('#<(td|th)\b([^>]*)>(.*?)</\1>#is', $inner, $matches,
                    PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                return $row[0];
            }

            $cells = [];
            $explicitpct = 0.0;
            $implicit = 0;
            foreach ($matches as $m) {
                $attrs = $m[2][0];
                if (preg_match('/\b(?:colspan|rowspan)\s*=/i', $attrs)) {
                    return $row[0];
                }
                $pct = null;
                if (preg_match('/width\s*:\s*([0-9.]+)\s*%/i', $attrs, $wm)) {
                    $pct = (float) $wm[1];
                    $explicitpct += $pct;
                } else {
                    $implicit++;
                }
                $fontsize = $defaultfontsize;
                if (preg_match('/font-size\s*:\s*([0-9.]+)\s*pt/i', $attrs, $fm)) {
                    $fontsize = (float) $fm[1];
                }
                $cells[] = [
                    'tag' => $m[1][0],
                    'attrs' => $attrs,
                    'content' => $m[3][0],
                    'start' => $m[0][1],
                    'length' => strlen($m[0][0]),
                    'pct' => $pct,
                    'fontsize' => $fontsize,
                ];
            }

            $remaining = max(0.0, 100.0 - $explicitpct) / max(1, $implicit);
            foreach ($cells as &$cell) {
                $pct = $cell['pct'] ?? $remaining;
                $cell['width'] = max(1.0, $usable * $pct / 100.0);
                $raw = preg_replace('#<br\s*/?>#i', "\n", $cell['content']);
                $text = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)));
                $cell['text'] = $text;
                if ($text === '') {
                    $cell['approx'] = 1;
                    continue;
                }
                $charsperline = max(1.0, $cell['width'] / (0.1764 * max(1.0, $cell['fontsize'])));
                $cell['approx'] = max(1, (int) ceil(mb_strlen($text) / $charsperline));
            }
            unset($cell);

            $approxs = array_column($cells, 'approx');
            $maxapprox = max($approxs);
            if ($maxapprox <= 1 || $maxapprox === min($approxs)) {
                return $row[0];
            }

            foreach ($cells as &$cell) {
                if ($cell['text'] === '') {
                    $cell['lines'] = 1;
                    continue;
                }
                $pdf->SetFont('helvetica', '', $cell['fontsize']);
                $cell['lines'] = max(1, (int) $pdf->getNumLines($cell['text'], $cell['width']));
            }
            unset($cell);

            $maxlines = 1;
            foreach ($cells as $cell) {
                $maxlines = max($maxlines, $cell['lines']);
            }

            $rebuilt = '';
            $cursor = 0;
            foreach ($cells as $cell) {
                $rebuilt .= substr($inner, $cursor, $cell['start'] - $cursor);
                $pad = intdiv($maxlines - $cell['lines'], 2);
                $rebuilt .= '<' . $cell['tag'] . $cell['attrs'] . '>'
                    . ($pad > 0 ? str_repeat('<br>', $pad) : '')
                    . $cell['content']
                    . '</' . $cell['tag'] . '>';
                $cursor = $cell['start'] + $cell['length'];
            }
            $rebuilt .= substr($inner, $cursor);

            return $open . $rebuilt . $close;
        }, $html);

        $pdf->SetFont('helvetica', '', $defaultfontsize);
        return $result;
    }

    private function build_pdf_document($title, $bodyhtml, $orientation = 'P', $format = 'A4', $fontsize = 9, $footerleft = ''): string {
        global $CFG;

        require_once($CFG->libdir . '/pdflib.php');

        $cachekey = md5(implode('|', [$title, $bodyhtml, $orientation, $format, $fontsize, $footerleft, $this->pdfactivityname]));
        if (isset($this->pdfcache[$cachekey])) {
            return $this->pdfcache[$cachekey];
        }

        $pdf = new \local_centro_de_descarga_de_portafolio_de_evidencias_pdf($orientation, 'mm', $format, true, 'UTF-8');
        $footer = trim((string) $footerleft);
        if ($this->pdfactivityname !== '') {
            $activity = format_string($this->pdfactivityname);
            if ($footer === '') {
                $footer = $activity;
            } else if (mb_stripos($footer, $activity) === false) {
                $footer .= ' - ' . $activity;
            }
        }
        $pdf->footerleft = $footer;
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->SetFont('helvetica', '', $fontsize);
        $pdf->AddPage();

        $html = '<h1 style="text-align:center;font-size:16pt;font-weight:bold;margin:0 0 2mm 0;">' . s($title) . '</h1>';
        $subtitle = s(format_string($this->course->fullname));
        if ($this->pdfactivityname !== '') {
            $subtitle .= ' - ' . s(format_string($this->pdfactivityname));
        }
        $html .= '<p style="text-align:center;font-size:9pt;color:#495057;margin:0 0 5mm 0;">' . $subtitle . '</p>';
        $bodyhtml = $this->pdf_center_table_cells($pdf, $bodyhtml, (float) $fontsize);
        $html .= $bodyhtml;

        $pdf->writeHTML($html, true, false, true, false, '');
        $this->pdfcache[$cachekey] = $pdf->Output('report.pdf', 'S');
        return $this->pdfcache[$cachekey];
    }

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
}

/**
 * PDF con pie de página: izquierda (carrera + alumno), derecha (página).
 */
require_once($CFG->libdir . '/pdflib.php');

class local_centro_de_descarga_de_portafolio_de_evidencias_pdf extends \pdf {
    /** @var string Texto del pie izquierdo. */
    public $footerleft = '';

    /**
     * Pie de página.
     */
    public function Footer() {
        $this->SetY(-12);
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(73, 80, 87);

        $pagination = get_string('pagination', 'local_centro_de_descarga_de_portafolio_de_evidencias', [
            'page' => $this->getAliasNumPage(),
            'total' => $this->getAliasNbPages(),
        ]);

        $margins = $this->getMargins();
        $rightwidth = $this->GetStringWidth($pagination) + 4;
        $leftwidth = $this->getPageWidth() - $margins['left'] - $margins['right'] - $rightwidth;

        $left = (string) $this->footerleft;
        if ($left !== '' && $this->GetStringWidth($left) > $leftwidth) {
            while ($left !== '' && $this->GetStringWidth($left . '...') > $leftwidth) {
                $left = mb_substr($left, 0, mb_strlen($left) - 1);
            }
            $left = rtrim($left) . '...';
        }

        $this->Cell($leftwidth, 5, $left, 0, 0, 'L');
        $this->Cell($rightwidth, 5, $pagination, 0, 0, 'R');
    }
}
