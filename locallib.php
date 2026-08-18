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

require_once(__DIR__ . '/locallib_lesson.php');
require_once(__DIR__ . '/locallib_quiz.php');
require_once(__DIR__ . '/locallib_h5p.php');
require_once(__DIR__ . '/locallib_forum.php');
require_once(__DIR__ . '/locallib_assign.php');
require_once(__DIR__ . '/locallib_publication.php');
require_once(__DIR__ . '/locallib_materials.php');
require_once(__DIR__ . '/locallib_glossary.php');
require_once(__DIR__ . '/locallib_lightboxgallery.php');
require_once(__DIR__ . '/locallib_etherpadlite.php');

class local_downloadcentercustom_factory {
    use local_downloadcentercustom_lesson_trait;
    use local_downloadcentercustom_quiz_trait;
    use local_downloadcentercustom_h5p_trait;
    use local_downloadcentercustom_forum_trait;
    use local_downloadcentercustom_assign_trait;
    use local_downloadcentercustom_publication_trait;
    use local_downloadcentercustom_materials_trait;
    use local_downloadcentercustom_glossary_trait;
    use local_downloadcentercustom_lightboxgallery_trait;
    use local_downloadcentercustom_etherpadlite_trait;

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
        'forum',
        'lesson',
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
                        if ($onlytasks && !$solomateriales && !in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity', 'forum', 'lesson'])) {
                            continue;
                        }
                        if (in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity', 'forum', 'lesson'])) {
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
                    if ($onlytasks && !$solomateriales && !in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity', 'forum', 'lesson'])) {
                        continue;
                    }
                    if (in_array($res->modname, ['assign', 'publication', 'quiz', 'h5pactivity', 'forum', 'lesson'])) {
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
}
