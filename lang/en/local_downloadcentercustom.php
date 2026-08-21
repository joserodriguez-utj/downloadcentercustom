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
 */


defined('MOODLE_INTERNAL') || die;

$string['createzip'] = 'Create ZIP archive';
$string['download'] = 'Download';
$string['downloadcenter:view'] = 'View Download center';
$string['downloadoptions'] = 'Options';
$string['downloadoptions:addnumbering'] = 'Add numbering to files and folders';
$string['downloadoptions:addnumbering_help'] = 'If enabled, course sections, files, and folders will be numbered in the order they appear in the course.';
$string['downloadoptions:filesrealnames'] = 'Download files with original file name';
$string['downloadoptions:filesrealnames_help'] = 'If enabled, file resources will be downloaded with their original file name instead of the visible name in the course.';
$string['eventDOWNLOADEDZIP'] = 'ZIP was downloaded';
$string['eventVIEWED'] = 'Download center viewed';
$string['infomessage_students'] = 'Here you can download single or all available contents of this course in a ZIP archive.';
$string['infomessage_teachers'] = 'Here you can download the evidence submitted by students, the feedback and the available course materials in a ZIP archive.';
$string['infomessage_teachers_nomat'] = 'Here you can download the evidence submitted by students, the feedback provided and the resources available per course activity in a ZIP archive.';
$string['infomessage_download'] = 'If you only download <strong>course materials</strong>, you can skip the group selection.';
$string['infomessage_download_assignment'] = 'If you need to download <strong>student evidence</strong>, make sure to select at least one group.';
$string['note'] = 'Important note:';
$string['no_download_permission'] = 'You do not have permission to download content in this course.';
$string['navigationlink'] = 'Download center custom';
$string['pagetitle'] = 'Download center custom for ';
$string['pluginname'] = 'Download center custom';
$string['downloadcentercustom:view'] = 'View';
$string['downloadcentercustom:downloadMaterials'] = 'Download materials';
$string['downloadcentercustom:downloadAssignments'] = 'Download assignments';
$string['downloadcentercustom:downloadQuiz'] = 'Download quizz';
$string['privacy:null_reason'] = 'This plugin does not store or process any personal information. It presents an interface to download all course files which are manipulated from within the course.';
$string['search:hint'] = 'Type to filter activities and resources...';
$string['search:results'] = 'Search results';
$string['untitled'] = 'Untitled';
$string['zipinprogress'] = 'One moment! We are now downloading your file.';
$string['zipcreating'] = 'The ZIP archive is being created...';
$string['zipready'] = 'The ZIP archive has been successfully created.';
$string['groupfilter'] = 'Filter by groups';
$string['groupfilter_help'] = 'This filter limits downloaded submissions to the selected groups.';
$string['groupfilter_help_help'] = 'Select one or more groups to download only submissions from students in those groups. Leave empty to include all groups.';
$string['all_groups'] = 'All groups';
$string['content_to_download'] = 'CONTENT TO DOWNLOAD';
$string['materials'] = 'Materials Available for Download';
$string['files'] = 'Files';
$string['folders'] = 'Folders';
$string['urls'] = 'URLs';
$string['pages'] = 'Pages';
$string['tasks'] = 'Activities';
$string['assignments'] = 'Assignments';
$string['feedback'] = 'Feedback';
$string['instructions'] = 'Instructions';
$string['resources_item'] = 'Resources';
$string['quiz'] = 'Quiz';
$string['quiz_tries'] = 'Attempts';
$string['no_content'] = 'No content';
$string['select_groups_one_by_one'] = 'Select groups one by one';
$string['selectallgroups_help'] = 'Select all groups';
$string['selectallgroups_help_help'] = 'Check this option to select all available groups.';
$string['selectgroup_required'] = 'You must select at least one group to download tasks.';

//Locallib_quiz strings
$string['quiz_grade_highest'] = 'Highest grade';
$string['quiz_grade_average'] = 'Grade average';
$string['quiz_attempt_first'] = 'First attempt';
$string['quiz_attempt_last'] = 'Last attempt';
$string['quiz_lastname'] = 'Last name';
$string['quiz_firstname'] = 'Name';
$string['quiz_email'] = 'Email address';
$string['quiz_status'] = 'Status';
$string['quiz_time_start'] = 'Started';
$string['quiz_time_end'] = 'Finished';
$string['quiz_duration'] = 'Duration';
$string['quiz_question'] = 'Question';
$string['quiz_answer'] = 'Answer';
$string['quiz_correct_answer'] = 'Correct answer';
$string['quiz_grade_attempt'] = 'Grade attempt';
$string['quiz_grade_method'] = 'Grade method';
$string['quiz_final_grade'] = 'Final grade';
$string['quiz_results'] = 'Results - ';

//Locallib_h5p strings
$string['h5p_filename'] = 'Results - ';
$string['h5p_title'] = 'Results: ';
$string['h5p_sharp'] = '#';
$string['h5p_date'] = 'Date';
$string['h5p_score'] = 'Score';
$string['h5p_max_score'] = 'Maximum score';
$string['h5p_duration'] = 'Duration';
$string['h5p_success'] = 'Success';
$string['h5p_yes'] = 'Yes';
$string['h5p_no'] = 'No';
$string['h5p_attempt'] = 'Attempt #';
$string['h5p_no_answer'] = 'No answer recorded for this attempt.';

// Locallib_forum strings
$string['forum_results'] = 'Results - ';
$string['forum_forum_results'] = 'Forum results: ';
$string['forum_discussion'] = 'Discussion';
$string['forum_participations'] = 'Participations';
$string['forum_last_post'] = 'Last participation';
$string['forum_grade_method'] = 'Grading method';
$string['forum_number_of_ratings'] = 'Number of ratings';
$string['forum_final_grade'] = 'Final grade';
$string['forum_discussion_topic'] = 'Discussion topic:';
$string['forum_autor'] = 'Author: ';
$string['forum_date'] = 'Date: ';
$string['forum_subject'] = 'Subject: ';
$string['forum_attachments'] = 'Attachments: ';
$string['forum_attachments_url'] = 'Attachments';

// Locallib_lesson strings
$string['lesson_results'] = 'Results - ';
$string['lesson_lesson_results'] = 'Lesson results: ';
$string['lesson_attempts'] = 'Attempt';
$string['lesson_grade'] = 'Grade';
$string['lesson_complete'] = 'Completed';
$string['lesson_final_grade'] = 'Final grade';
$string['lesson_attempt'] = 'Attempt #';
$string['lesson_page_topic'] = 'Page topic: ';
$string['lesson_question'] = 'Question: ';
$string['lesson_student_answer_essay'] = 'Student answer (essay): ';
$string['lesson_essay_grade'] = 'Essay grade: ';
$string['lesson_content_branch_page'] = 'Content/Branch page.';
$string['lesson_student_answer'] = 'Student answer: ';
$string['lesson_correct_answer'] = 'Correct answer: ';
$string['lesson_points'] = 'Points: ';
$string['lesson_grade_method'] = 'Grading method';
$string['lesson_grade_highest'] = 'Highest grade';
$string['lesson_grade_average'] = 'Average grade';

// Locallib_assign strings
$string['string_unknown'] = 'Unknown';
$string['string_feedback_url'] = 'Feedback';
$string['string_student'] = 'Student';
$string['string_feedback'] = 'Feedback';
$string['string_grade'] = 'Grade';
$string['string_points'] = 'Points: ';
$string['string_level'] = 'Level: ';
$string['string_observation'] = 'Comment: ';
$string['string_no_feedback'] = '(no feedback)';
$string['string_no_comment'] = '(no comment)';
$string['string_max'] = '(max {$a})';
$string['string_label'] = 'Label';