<?php
/**
 * Crea un curso vacío para que un agente de profesor lo construya entero (p. ej. el
 * comando /teacher-agent:build-course), con el profesor como editingteacher y los alumnos
 * sembrados (alumno y, si existen, los de seed-teacher-activity.php) matriculados.
 *
 *   docker compose exec webserver env COURSE_SHORTNAME=... COURSE_FULLNAME=... php seed-empty-course.php
 *
 * Si ya existe un curso con ese nombre corto, no lo toca: devuelve su id. Imprime una
 * sola línea JSON: {"id":..,"shortname":..,"fullname":..,"created":true|false}.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once(__DIR__ . '/vendor/autoload.php');

$CFG->noemailever = true;

$shortname = trim((string) getenv('COURSE_SHORTNAME'));
$fullname = trim((string) getenv('COURSE_FULLNAME')) ?: $shortname;
if ($shortname === '') {
    cli_error('Falta COURSE_SHORTNAME.');
}

$existing = $DB->get_record('course', ['shortname' => $shortname]);
if ($existing) {
    echo json_encode(['id' => (int) $existing->id, 'shortname' => $shortname, 'fullname' => $existing->fullname, 'created' => false],
        JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$generator = new testing_data_generator();
$transaction = $DB->start_delegated_transaction();

$course = $generator->create_course([
    'fullname' => $fullname,
    'shortname' => $shortname,
    'summary' => '',
    'numsections' => 1,
]);

$teacherusername = getenv('MOODLE_TEACHER_USERNAME') ?: 'profesor';
$teacher = $DB->get_record('user', ['username' => $teacherusername], '*', MUST_EXIST);
$generator->enrol_user($teacher->id, $course->id, 'editingteacher');

$studentusernames = [getenv('MOODLE_STUDENT_USERNAME') ?: 'alumno', 'lucia.martin', 'marcos.lopez', 'sara.gil'];
foreach ($studentusernames as $username) {
    if ($student = $DB->get_record('user', ['username' => $username])) {
        $generator->enrol_user($student->id, $course->id, 'student');
    }
}

$transaction->allow_commit();

echo json_encode(['id' => (int) $course->id, 'shortname' => $shortname, 'fullname' => $fullname, 'created' => true],
    JSON_UNESCAPED_UNICODE) . "\n";
