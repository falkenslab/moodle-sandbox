<?php
/**
 * Creates a demo course with a teacher, a student, a quiz, an assignment and a forum,
 * to test agents (student and teacher) against a real Moodle without using a real
 * institution's. Meant to run once, after the site is installed:
 *
 *   docker compose exec webserver php seed-course.php
 *
 * Reads the teacher's and the student's username/password from environment variables
 * (MOODLE_TEACHER_USERNAME/PASSWORD/EMAIL, MOODLE_STUDENT_USERNAME/PASSWORD/EMAIL),
 * with defaults when they aren't set. It isn't idempotent: if the course
 * (shortname "sandbox-course") already exists, it ends without touching anything.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
// create_question() (below) needs PHPUnit classes (question_test_helper);
// Moodle's normal bootstrap doesn't load Composer's autoloader, so it has to be
// required by hand. vendor/ lives at the checkout's root (next to this script), one
// level above $CFG->dirroot (which in Moodle 5.x points to checkout/public).
require_once(__DIR__ . '/vendor/autoload.php');

// This sandbox has no mail server and needs none: creating users/courses triggers
// notifications which, otherwise, make the script fail when it tries to send them.
$CFG->noemailever = true;

function env(string $name, string $default): string {
    $value = getenv($name);
    return $value !== false && $value !== '' ? $value : $default;
}

$courseshortname = 'sandbox-course';

if ($DB->record_exists('course', ['shortname' => $courseshortname])) {
    cli_writeln('The course "' . $courseshortname . '" already exists, nothing to do.');
    exit(0);
}

$generator = new testing_data_generator();

// All in one transaction: if something fails halfway (e.g. a missing dependency),
// no half-created course must be left behind to confuse the next run (this script's
// only idempotency check is "does the course exist already?").
$transaction = $DB->start_delegated_transaction();

// --- Course ---
$course = $generator->create_course([
    'fullname' => 'Curso de pruebas para agentes',
    'shortname' => $courseshortname,
    'summary' => 'Curso generado automáticamente para probar agentes de alumno y de profesor.',
    'numsections' => 3,
]);

// --- Users ---
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

// --- Notes page (a passive resource) ---
$generator->create_module('page', [
    'course' => $course->id,
    'name' => 'Apuntes del tema 1',
    'content' => 'Contenido de ejemplo del tema 1, generado por seed-course.php.' .
        ' Sirve como recurso de lectura antes de las actividades evaluables.',
]);

// --- Quiz with one question ---
$quiz = $generator->create_module('quiz', [
    'course' => $course->id,
    'name' => 'Quiz de prueba',
]);

$questiongenerator = $generator->get_plugin_generator('core_question');
$category = $questiongenerator->create_question_category(['contextid' => context_module::instance($quiz->cmid)->id]);
$question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
quiz_add_quiz_question($question->id, $quiz);

// Adding a question with quiz_add_quiz_question() doesn't recompute the quiz's total
// grade — without this, sumgrades stays at 0 and Moodle doesn't let any attempt start.
\mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();

// --- Assignment with an online text submission ---
$generator->create_module('assign', [
    'course' => $course->id,
    'name' => 'Tarea de prueba',
    'duedate' => time() + 7 * DAYSECS,
    'assignsubmission_onlinetext_enabled' => 1,
    'assignsubmission_file_enabled' => 0,
]);

// --- Discussion forum (not the announcements one, so the student can post) ---
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
