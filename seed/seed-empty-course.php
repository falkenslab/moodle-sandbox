<?php
/**
 * Creates an empty course for a teacher agent to build from scratch (e.g. the
 * /teacher-agent:build-course command), with the teacher as editingteacher and the seeded
 * students (alumno and, if they exist, those of seed-teacher-activity.php) enrolled.
 *
 *   docker compose exec webserver env COURSE_SHORTNAME=... COURSE_FULLNAME=... php seed-empty-course.php
 *
 * If a course with that short name already exists, it leaves it alone and returns its id.
 * Prints a single JSON line: {"id":..,"shortname":..,"fullname":..,"created":true|false}.
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
    cli_error('COURSE_SHORTNAME is missing.');
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
