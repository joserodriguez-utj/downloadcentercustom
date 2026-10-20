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

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');
require_once(__DIR__ . '/download_form.php');

// Raise timelimit as this could take a while for big archives.
core_php_time_limit::raise();
raise_memory_limit(MEMORY_HUGE);

$courseid = required_param('courseid', PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

require_course_login($course);

$context = context_course::instance($course->id);

require_capability('local/centro_de_descarga_de_portafolio_de_evidencias:view', $context);

// Al cargar la página (GET), liberar bloqueo de descarga anterior.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_user_preference('centro_de_descarga_de_portafolio_de_evidencias_ziptime', 0);
}

$PAGE->set_url(new moodle_url('/local/centro_de_descarga_de_portafolio_de_evidencias/index.php', ['courseid' => $course->id]));

$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('limitedwidth');
$PAGE->requires->css('/local/centro_de_descarga_de_portafolio_de_evidencias/styles.css');

$downloadcenter = new local_centro_de_descarga_de_portafolio_de_evidencias_factory($course, $USER);

$userresources = $downloadcenter->get_resources_for_user();

$PAGE->requires->js_call_amd('local_centro_de_descarga_de_portafolio_de_evidencias/modfilter', 'init', $downloadcenter->get_js_modnames());

$downloadform = new local_centro_de_descarga_de_portafolio_de_evidencias_download_form(
    null,
    ['res' => $userresources],
    'post',
    '',
    ['data-double-submit-protection' => 'off']
);

$PAGE->set_title(get_string('navigationlink', 'local_centro_de_descarga_de_portafolio_de_evidencias') . ': ' . $course->fullname);
$PAGE->set_heading($course->fullname);

if ($data = $downloadform->get_data()) {
    // Verificar que el usuario tenga capacidad para algún modo de descarga.
    $canmodenormal = has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadnormal', $context);
    $canmodeportfolio = $canmodenormal
        || has_capability('local/centro_de_descarga_de_portafolio_de_evidencias:downloadportfolio', $context);
    if (!$canmodenormal && !$canmodeportfolio) {
        \core\notification::error(get_string('no_download_permission', 'local_centro_de_descarga_de_portafolio_de_evidencias'));
        redirect(new moodle_url('/local/centro_de_descarga_de_portafolio_de_evidencias/index.php', ['courseid' => $course->id]));
        die;
    }

    // Protección contra doble descarga (5 minutos de bloqueo).
    $locktime = get_user_preferences('centro_de_descarga_de_portafolio_de_evidencias_ziptime', 0);
    if ($locktime && (time() - $locktime) < 300) {
        \core\notification::error(get_string('zipinprogress', 'local_centro_de_descarga_de_portafolio_de_evidencias'));
        redirect(new moodle_url('/local/centro_de_descarga_de_portafolio_de_evidencias/index.php', ['courseid' => $course->id]));
        die;
    }
    set_user_preference('centro_de_descarga_de_portafolio_de_evidencias_ziptime', time());

    $event = \local_centro_de_descarga_de_portafolio_de_evidencias\event\zip_downloaded::create([
        'objectid' => $PAGE->course->id,
        'context' => $PAGE->context,
    ]);
    $event->add_record_snapshot('course', $PAGE->course);
    $event->trigger();

    $downloadcenter->parse_form_data($data);
    // No imprimir mensajes de debugging antes de las cabeceras del ZIP (las
    // corromperían). Los errores siguen yendo al log de Moodle.
    $debugdisplay = $CFG->debugdisplay;
    $CFG->debugdisplay = 0;
    if ($downloadcenter->get_download_mode() === 'portafolio') {
        $downloadcenter->create_portfolio_zip();
    } else {
        $hash = $downloadcenter->create_zip();
    }
    $CFG->debugdisplay = $debugdisplay;
} else if ($downloadform->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $course->id]));
    die;
} else {
    $event = \local_centro_de_descarga_de_portafolio_de_evidencias\event\plugin_viewed::create([
        'objectid' => $PAGE->course->id,
        'context' => $PAGE->context,
    ]);
    $event->add_record_snapshot('course', $PAGE->course);
    $event->trigger();
    echo $OUTPUT->header();
}

echo $OUTPUT->heading(get_string('navigationlink', 'local_centro_de_descarga_de_portafolio_de_evidencias'), 1);

$downloadform->display();

echo $OUTPUT->footer();
