<?php
if (PHP_SAPI !== 'cli') {
    exit;
}
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/testing/generator/lib.php');

global $DB;

$admin = get_admin();
\core\session\manager::set_user($admin);

$courseid = 17;
$saulid = 1108;

$generator = new \testing_data_generator();
$datagen = $generator->get_plugin_generator('mod_data');

// Crear la actividad base de datos con calificación habilitada (assessed = -100 -> escala numérica de 100 pts).
$data = $datagen->create_instance([
    'course' => $courseid,
    'name' => 'Base de datos de prueba: Evidencias (Saúl)',
    'intro' => 'Base de datos de prueba para la descarga de evidencias.',
    'section' => 1,
    'assessed' => -100,
    'scale' => 100,
    'approval' => 0,
]);

$dataid = $data->id;

// Crear un campo de texto.
$field = $datagen->create_field((object) ['type' => 'text', 'name' => 'Título de la evidencia'], $data);

// Crear una entrada de Saúl.
$recordid = $datagen->create_entry($data, [$field->field->id => 'Evidencia de prueba de Saúl'], 0, [], ['approved' => 1], $saulid);

// Calificar la entrada mediante rating (componente mod_data, área post).
$cm = get_coursemodule_from_instance('data', $dataid, $courseid);
$context = context_module::instance($cm->id);

$rating = new stdClass();
$rating->contextid = $context->id;
$rating->component = 'mod_data';
$rating->ratingarea = 'post';
$rating->itemid = $recordid;
$rating->scaleid = -100;
$rating->rating = 90;
$rating->userid = $admin->id;
$rating->timecreated = time();
$rating->timemodified = time();
$rating->id = $DB->insert_record('rating', $rating);

echo "OK!\n";
echo "Data ID: {$dataid}\n";
echo "CM ID: {$cm->id}\n";
echo "Field ID: {$field->field->id}\n";
echo "Record ID: {$recordid}\n";
echo "Rating ID: {$rating->id}\n";
echo "Alumno: {$saulid}\n";
