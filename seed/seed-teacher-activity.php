<?php
/**
 * Turns the test course (seed-course.php) into an experimental classroom with real
 * work for a teacher agent: gives the page, the assignment and the forum coherent
 * content (a lesson of an introduction to programming), enrols three more students,
 * has them submit the assignment with different quality and opens questions in the
 * forum, one unanswered and one answered wrongly by a classmate.
 *
 *   docker compose exec webserver php seed-teacher-activity.php
 *
 * Needs seed-course.php to have run first. If the extra students are already in the course,
 * it ends without touching anything. The students may exist already (seed-content.php creates
 * them too): then they're only enrolled.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once(__DIR__ . '/vendor/autoload.php');

$CFG->noemailever = true;

$course = $DB->get_record('course', ['shortname' => 'sandbox-course']);
if (!$course) {
    cli_error('The course "sandbox-course" doesn\'t exist: run seed-course.php first (npm run seed).');
}
const SUBMITTING_STUDENTS = ['lucia.martin', 'marcos.lopez', 'sara.gil'];

/**
 * Submits those students' draft submissions for grading, as each of them would with
 * "Submit assignment". It's needed because the assignment requires that step (submissiondrafts
 * = 1, the generator's value) and save_submission() leaves the submission as a draft even when
 * asked for another status: without this, the teacher sees nothing waiting to be graded.
 */
function submit_drafts(stdClass $course): int {
    global $DB;
    $cm = get_coursemodule_from_instance('assign', $DB->get_field('assign', 'id', ['course' => $course->id]), $course->id, false, MUST_EXIST);
    $assign = new assign(context_module::instance($cm->id), $cm, $course);
    $submitted = 0;
    foreach (SUBMITTING_STUDENTS as $username) {
        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $submission = $assign->get_user_submission($user->id, false);
        if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_DRAFT) {
            continue;
        }
        \core\session\manager::set_user($user);
        if ($assign->submit_for_grading((object) ['userid' => $user->id], [])) {
            $submitted++;
        }
    }
    \core\session\manager::set_user(get_admin());
    return $submitted;
}

/**
 * Gives the seeded students the password in MOODLE_SEEDED_STUDENTS_PASSWORD (manage.mjs keeps it
 * in .env and `npm run info` publishes it), so anyone can log in as any of them.
 * Without the variable, it touches nothing.
 */
function set_known_passwords(): void {
    global $DB;
    $password = getenv('MOODLE_SEEDED_STUDENTS_PASSWORD');
    if ($password === false || $password === '') {
        return;
    }
    foreach (SUBMITTING_STUDENTS as $username) {
        if ($user = $DB->get_record('user', ['username' => $username])) {
            update_internal_user_password($user, $password);
        }
    }
}

$lucia = $DB->get_record('user', ['username' => 'lucia.martin']);
if ($lucia && is_enrolled(context_course::instance($course->id), $lucia)) {
    // Seeded by an earlier version: draft submissions and unknown passwords.
    set_known_passwords();
    $submitted = submit_drafts($course);
    cli_writeln($submitted > 0
        ? "The activity was already seeded; {$submitted} submissions still in draft have been submitted."
        : 'The experimental classroom is already seeded, nothing to do.');
    exit(0);
}

$generator = new testing_data_generator();
$transaction = $DB->start_delegated_transaction();

// --- Coherent content for the activities seed-course.php created ---
$page = $DB->get_record('page', ['course' => $course->id], '*', MUST_EXIST);
$DB->update_record('page', (object) [
    'id' => $page->id,
    'name' => 'Tema 1: variables y tipos de datos',
    'content' => '<h3>Variables</h3>' .
        '<p>Una <strong>variable</strong> es un nombre que hace referencia a un valor guardado en ' .
        'memoria. Se le asigna un valor con el operador <code>=</code> y ese valor puede cambiar ' .
        'durante la ejecución del programa.</p>' .
        "<pre>edad = 17\nedad = edad + 1   # ahora vale 18</pre>" .
        '<h3>Tipos de datos básicos</h3>' .
        '<ul><li><strong>int</strong>: números enteros (<code>42</code>).</li>' .
        '<li><strong>float</strong>: números con decimales (<code>3.14</code>).</li>' .
        '<li><strong>str</strong>: texto, entre comillas (<code>"hola"</code>).</li>' .
        '<li><strong>bool</strong>: verdadero o falso (<code>True</code>, <code>False</code>).</li></ul>' .
        '<p>El tipo determina qué operaciones se pueden hacer: <code>"3" + 3</code> da error porque ' .
        'no se puede sumar un texto y un número sin convertir antes uno de ellos ' .
        '(<code>int("3") + 3</code> vale 6).</p>',
]);

$assigncm = get_coursemodule_from_instance('assign', $DB->get_field('assign', 'id', ['course' => $course->id]), $course->id, false, MUST_EXIST);
$DB->update_record('assign', (object) [
    'id' => $assigncm->instance,
    'name' => 'Tarea 1: explica qué es una variable',
    'intro' => '<p>Explica con tus propias palabras (entre 80 y 200 palabras):</p>' .
        '<ol><li>Qué es una variable y para qué sirve.</li>' .
        '<li>Los cuatro tipos de datos básicos vistos en el tema 1, con un ejemplo de cada uno.</li>' .
        '<li>Por qué <code>"3" + 3</code> da error y cómo se arregla.</li></ol>' .
        '<p>Se evalúa sobre 10: corrección de los conceptos (6), ejemplos (3) y claridad (1).</p>',
    'grade' => 10,
]);

$forum = $DB->get_record('forum', ['course' => $course->id, 'type' => 'general'], '*', MUST_EXIST);
$DB->update_record('forum', (object) [
    'id' => $forum->id,
    'intro' => '<p>Plantea aquí tus dudas sobre el temario. Antes de preguntar, mira si otro ' .
        'compañero ya ha preguntado lo mismo.</p>',
]);
rebuild_course_cache($course->id, true);

// --- Extra students ---
$students = [];
foreach ([
    ['lucia.martin', 'Lucía', 'Martín'],
    ['marcos.lopez', 'Marcos', 'López'],
    ['sara.gil', 'Sara', 'Gil'],
] as [$username, $firstname, $lastname]) {
    // It may exist already, seeded by seed-content.php.
    $user = $DB->get_record('user', ['username' => $username]) ?: $generator->create_user([
        'username' => $username,
        'password' => 'Sandbox-' . bin2hex(random_bytes(6)),
        'email' => "{$username}@example.com",
        'firstname' => $firstname,
        'lastname' => $lastname,
    ]);
    $generator->enrol_user($user->id, $course->id, 'student');
    $students[$username] = $user;
}

// --- Assignment submissions, of different quality (the student "alumno" doesn't submit) ---
$assigngenerator = $generator->get_plugin_generator('mod_assign');
$submissions = [
    // Complete and correct.
    'lucia.martin' => '<p>Una variable es un nombre que apunta a un valor guardado en la memoria del ' .
        'ordenador. Sirve para guardar datos y poder usarlos o cambiarlos después, por ejemplo ' .
        '<code>edad = 17</code> y luego <code>edad = edad + 1</code>.</p>' .
        '<p>Los tipos básicos son: int para enteros (<code>42</code>), float para decimales ' .
        '(<code>3.14</code>), str para texto (<code>"hola"</code>) y bool para verdadero o falso ' .
        '(<code>True</code>).</p>' .
        '<p><code>"3" + 3</code> da error porque "3" es un texto y 3 un número, y no se pueden sumar ' .
        'tipos distintos. Se arregla convirtiendo el texto a número: <code>int("3") + 3</code>, que ' .
        'da 6.</p>',
    // Thin: correct but incomplete (no examples, no third point).
    'marcos.lopez' => '<p>Una variable es donde se guardan los datos en un programa. Hay varios tipos ' .
        'de datos como números, texto y booleanos. Se usan mucho en programación.</p>',
    // With a conceptual mistake (confuses float with text, and the explanation of the error).
    'sara.gil' => '<p>Una variable es una caja donde metes un valor, y el valor ya no puede cambiar una ' .
        'vez asignado.</p>' .
        '<p>Tipos: int (<code>5</code>), float, que es texto con decimales (<code>"3.5"</code>), str ' .
        '(<code>"casa"</code>) y bool (<code>True</code>).</p>' .
        '<p><code>"3" + 3</code> da error porque las comillas no se pueden sumar. Se arregla quitando ' .
        'las comillas al escribirlo.</p>',
];
foreach ($submissions as $username => $text) {
    $assigngenerator->create_submission([
        'userid' => $students[$username]->id,
        'cmid' => $assigncm->id,
        'onlinetext' => $text,
    ]);
}
submit_drafts($course);
set_known_passwords();

// --- Forum: one question unanswered and another with a classmate's wrong answer ---
$forumgenerator = $generator->get_plugin_generator('mod_forum');
$forumgenerator->create_discussion([
    'course' => $course->id,
    'forum' => $forum->id,
    'userid' => $students['lucia.martin']->id,
    'name' => 'Duda sobre el tipo bool',
    'message' => '<p>Hola, no entiendo para qué sirve el tipo bool si solo puede valer True o False. ' .
        '¿Me podéis poner un ejemplo de cuándo se usa?</p>',
]);
$discussion = $forumgenerator->create_discussion([
    'course' => $course->id,
    'forum' => $forum->id,
    'userid' => $students['marcos.lopez']->id,
    'name' => '¿Se puede cambiar el valor de una variable?',
    'message' => '<p>Si hago <code>edad = 17</code>, ¿luego puedo cambiarle el valor o tengo que ' .
        'crear otra variable?</p>',
]);
$forumgenerator->create_post([
    'discussion' => $discussion->id,
    'parent' => $DB->get_field('forum_discussions', 'firstpost', ['id' => $discussion->id], MUST_EXIST),
    'userid' => $students['sara.gil']->id,
    'subject' => 'Re: ¿Se puede cambiar el valor de una variable?',
    'message' => '<p>No se puede, una vez que le das un valor se queda fijo. Tienes que crear otra ' .
        'variable, por ejemplo <code>edad2 = 18</code>.</p>',
]);

$transaction->allow_commit();

cli_writeln('OK');
cli_writeln('course_id=' . $course->id);
cli_writeln('assign_cmid=' . $assigncm->id);
cli_writeln('students=' . implode(',', array_keys($students)));
