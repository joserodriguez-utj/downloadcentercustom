<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/mod/scorm/locallib.php');

global $DB;

// Simular sesión de admin para poder crear la actividad y los intentos.
$admin = get_admin();
\core\session\manager::set_user($admin);

// ===== CONFIGURACIÓN =====
$coursefullname = 'Desarrollo del Pensamiento y Toma de Decisiones - TEST';
$saulusername = '2125300182'; // Rodriguez Valle Ruben Saul.
$scormname = 'Paquete SCORM de prueba: Evidencias (Saúl)';
// =========================

// Resolver el curso por nombre (fullname o shortname).
$course = $DB->get_record('course', ['fullname' => $coursefullname], '*', IGNORE_MULTIPLE);
if (!$course) {
    $course = $DB->get_record('course', ['shortname' => $coursefullname], '*', IGNORE_MULTIPLE);
}
if (!$course) {
    cli_error("No se encontró el curso '{$coursefullname}'. Revisa el nombre exacto en la configuración del script.");
}
$courseid = $course->id;

// Verificar que el alumno exista.
$saul = $DB->get_record('user', ['username' => $saulusername], '*', MUST_EXIST);
$saulid = $saul->id;

// Verificar que esté enrolado en el curso.
$context = context_course::instance($courseid);
if (!is_enrolled($context, $saulid)) {
    $studentrole = $DB->get_record('role', ['shortname' => 'student']);
    enrol_try_internal_enrol($courseid, $saulid, $studentrole->id);
}

// Reutilizar la actividad si ya existe (idempotencia).
$scorm = $DB->get_record('scorm', ['course' => $courseid, 'name' => $scormname], '*', IGNORE_MULTIPLE);
if (!$scorm) {
    $generator = new \testing_data_generator();
    $scormgen = $generator->get_plugin_generator('mod_scorm');
    $scorm = $scormgen->create_instance([
        'course' => $courseid,
        'name' => $scormname,
        'intro' => 'Paquete SCORM de prueba para la descarga de evidencias.',
        'section' => 1,
        'grademethod' => 1, // Calificación más alta.
        'whatgrade' => 0,   // Intentos: calificación más alta.
        'maxgrade' => 100,
        'maxattempt' => 0,
    ]);
}

// Limpiar intentos previos de Saúl en esta actividad (para re-ejecuciones).
$DB->delete_records_select(
    'scorm_scoes_value',
    "attemptid IN (SELECT id FROM {scorm_attempt} WHERE scormid = ? AND userid = ?)",
    [$scorm->id, $saulid]
);
$DB->delete_records('scorm_attempt', ['scormid' => $scorm->id, 'userid' => $saulid]);

// Localizar un SCO que se pueda lanzar para asociar los intentos.
$scoid = 0;
$scoes = $DB->get_records('scorm_scoes', ['scorm' => $scorm->id], 'sortorder, id');
foreach ($scoes as $sco) {
    if (!empty($sco->launch)) {
        $scoid = (int)$sco->id;
        break;
    }
}
if (!$scoid) {
    cli_error("No se encontró un SCO lanzable para el SCORM id {$scorm->id}.");
}

// Crear intentos (tracking) de Saúl.
$attempts = [
    ['status' => 'completed', 'score' => '80', 'time' => '0000:00:10.00'],
    ['status' => 'passed', 'score' => '90', 'time' => '0000:00:25.50'],
    ['status' => 'failed', 'score' => '70', 'time' => '0000:00:08.75'],
];

$inserted = 0;
$attemptnum = 1;
foreach ($attempts as $attempt) {
    $tracks = [
        'cmi.core.lesson_status' => $attempt['status'],
        'cmi.core.score.raw' => $attempt['score'],
        'cmi.core.score.min' => '0',
        'cmi.core.score.max' => '100',
        'cmi.core.total_time' => $attempt['time'],
        'cmi.core.entry' => 'ab-initio',
        'cmi.core.exit' => 'suspend',
    ];
    foreach ($tracks as $element => $value) {
        scorm_insert_track($saulid, $scorm->id, $scoid, $attemptnum, $element, $value);
        $inserted++;
    }
    $attemptnum++;
}

echo "OK!\n";
echo "Curso: {$course->fullname} (id {$courseid})\n";
echo "SCORM ID: {$scorm->id}\n";
echo "SCO ID: {$scoid}\n";
echo "Intentos creados: " . count($attempts) . "\n";
echo "Filas de tracking insertadas: {$inserted}\n";
echo "Alumno: {$saul->firstname} {$saul->lastname} (id {$saul->id})\n";
