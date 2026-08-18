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

trait local_downloadcentercustom_publication_trait {

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

}