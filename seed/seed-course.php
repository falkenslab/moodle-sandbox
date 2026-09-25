<?php
/**
 * Crea un curso de demostración con profesor, alumno, un quiz, una tarea y un foro,
 * para poder probar agentes (alumno y profesor) contra un Moodle real sin usar una
 * institución de verdad. Pensado para ejecutarse una sola vez tras instalar el sitio:
 *
 *   docker compose exec webserver php seed-course.php
 *
 * Lee usuario/contraseña de profesor y alumno de variables de entorno
 * (MOODLE_TEACHER_USERNAME/PASSWORD/EMAIL, MOODLE_STUDENT_USERNAME/PASSWORD/EMAIL),
 * con valores por defecto si no están definidas. No es idempotente: si el curso
 * (shortname "sandbox-course") ya existe, termina sin tocar nada.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
// create_question() (más abajo) necesita clases de PHPUnit (question_test_helper);
// el bootstrap normal de Moodle no carga el autoloader de Composer, así que hay que
// requerirlo a mano. vendor/ vive en la raíz del checkout (junto a este script), un
// nivel por encima de $CFG->dirroot (que en Moodle 5.x apunta a checkout/public).
require_once(__DIR__ . '/vendor/autoload.php');

// Este sandbox no tiene ni necesita servidor de correo: crear usuarios/cursos
// dispara notificaciones que si no, hacen fallar el script al intentar enviarlas.
$CFG->noemailever = true;

function env(string $name, string $default): string {
    $value = getenv($name);
    return $value !== false && $value !== '' ? $value : $default;
}

$courseshortname = 'sandbox-course';

if ($DB->record_exists('course', ['shortname' => $courseshortname])) {
    cli_writeln('El curso "' . $courseshortname . '" ya existe, no se hace nada.');
    exit(0);
}

$generator = new testing_data_generator();

// Todo en una única transacción: si algo falla a mitad (p.ej. una dependencia que
// falta), no debe quedar un curso a medio crear que confunda la próxima ejecución
// (el único chequeo de idempotencia de este script es "¿existe ya el curso?").
$transaction = $DB->start_delegated_transaction();

// --- Curso ---
$course = $generator->create_course([
    'fullname' => 'Curso de pruebas para agentes',
    'shortname' => $courseshortname,
    'summary' => 'Curso generado automáticamente para probar agentes de alumno y de profesor.',
    'numsections' => 3,
]);

// --- Usuarios ---
$teacher = $generator->create_user([
    'username' => env('MOODLE_TEACHER_USERNAME', 'profesor'),
    'password' => env('MOODLE_TEACHER_PASSWORD', 'Profesor123!'),
    'email' => env('MOODLE_TEACHER_EMAIL', 'profesor@example.com'),
    'firstname' => 'Profesor',
    'lastname' => 'Demo',
]);
$student = $generator->create_user([
    'username' => env('MOODLE_STUDENT_USERNAME', 'alumno'),
    'password' => env('MOODLE_STUDENT_PASSWORD', 'Alumno123!'),
    'email' => env('MOODLE_STUDENT_EMAIL', 'alumno@example.com'),
    'firstname' => 'Alumno',
    'lastname' => 'Demo',
]);

$generator->enrol_user($teacher->id, $course->id, 'editingteacher');
$generator->enrol_user($student->id, $course->id, 'student');

// --- Página de apuntes (recurso pasivo) ---
$generator->create_module('page', [
    'course' => $course->id,
    'name' => 'Apuntes del tema 1',
    'content' => 'Contenido de ejemplo del tema 1, generado por seed-course.php.' .
        ' Sirve como recurso de lectura antes de las actividades evaluables.',
]);

// --- Quiz con una pregunta ---
$quiz = $generator->create_module('quiz', [
    'course' => $course->id,
    'name' => 'Quiz de prueba',
]);

$questiongenerator = $generator->get_plugin_generator('core_question');
$category = $questiongenerator->create_question_category(['contextid' => context_module::instance($quiz->cmid)->id]);
$question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
quiz_add_quiz_question($question->id, $quiz);

// Añadir una pregunta con quiz_add_quiz_question() no recalcula la nota total del
// quiz — sin esto, sumgrades se queda a 0 y Moodle no deja empezar ningún intento.
\mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();

// --- Tarea con entrega de texto en línea ---
$generator->create_module('assign', [
    'course' => $course->id,
    'name' => 'Tarea de prueba',
    'duedate' => time() + 7 * DAYSECS,
    'assignsubmission_onlinetext_enabled' => 1,
    'assignsubmission_file_enabled' => 0,
]);

// --- Foro de debate (no el de anuncios, para que el alumno pueda publicar) ---
$generator->create_module('forum', [
    'course' => $course->id,
    'name' => 'Foro de dudas',
    'type' => 'general',
    'intro' => 'Foro general del curso, generado por seed-course.php.',
]);

$transaction->allow_commit();

cli_writeln('OK');
cli_writeln('course_id=' . $course->id);
cli_writeln('teacher_id=' . $teacher->id);
cli_writeln('student_id=' . $student->id);
