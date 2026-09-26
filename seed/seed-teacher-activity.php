<?php
/**
 * Convierte el curso de pruebas (seed-course.php) en un aula experimental con trabajo
 * real para un agente de profesor: da contenido coherente a la página, la tarea y el
 * foro (un tema de introducción a la programación), matricula a tres alumnos más, les
 * hace entregar la tarea con calidades distintas y abre dudas en el foro, una sin
 * responder y otra con una respuesta incorrecta de un compañero.
 *
 *   docker compose exec webserver php seed-teacher-activity.php
 *
 * Necesita que seed-course.php se haya ejecutado antes. Si los alumnos extra ya existen,
 * termina sin tocar nada.
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
    cli_error('No existe el curso "sandbox-course": ejecuta antes seed-course.php (npm run seed).');
}
const SUBMITTING_STUDENTS = ['lucia.martin', 'marcos.lopez', 'sara.gil'];

/**
 * Envía para calificar las entregas en borrador de esos alumnos, como lo haría cada uno con
 * "Enviar tarea". Hace falta porque la tarea exige ese paso (submissiondrafts = 1, el valor
 * del generador) y save_submission() deja la entrega en borrador aunque se le pida otro
 * estado: sin esto, el profesor no ve nada pendiente de corregir.
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
 * Pone a los alumnos sembrados la contraseña de MOODLE_SEEDED_STUDENTS_PASSWORD (la guarda
 * manage.mjs en .env y la publica `npm run info`), para poder entrar como cualquiera de ellos.
 * Sin la variable, no toca nada.
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

if ($DB->record_exists('user', ['username' => 'lucia.martin'])) {
    // Sembrada por una versión anterior: entregas en borrador y contraseñas desconocidas.
    set_known_passwords();
    $submitted = submit_drafts($course);
    cli_writeln($submitted > 0
        ? "La actividad ya estaba sembrada; se han enviado {$submitted} entregas que seguían en borrador."
        : 'La actividad del aula experimental ya está sembrada, no se hace nada.');
    exit(0);
}

$generator = new testing_data_generator();
$transaction = $DB->start_delegated_transaction();

// --- Contenido coherente para las actividades que creó seed-course.php ---
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

// --- Alumnos extra ---
$students = [];
foreach ([
    ['lucia.martin', 'Lucía', 'Martín'],
    ['marcos.lopez', 'Marcos', 'López'],
    ['sara.gil', 'Sara', 'Gil'],
] as [$username, $firstname, $lastname]) {
    $user = $generator->create_user([
        'username' => $username,
        'password' => 'Sandbox-' . bin2hex(random_bytes(6)),
        'email' => "{$username}@example.com",
        'firstname' => $firstname,
        'lastname' => $lastname,
    ]);
    $generator->enrol_user($user->id, $course->id, 'student');
    $students[$username] = $user;
}

// --- Entregas de la tarea, de calidad distinta (el alumno "alumno" no entrega) ---
$assigngenerator = $generator->get_plugin_generator('mod_assign');
$submissions = [
    // Completa y correcta.
    'lucia.martin' => '<p>Una variable es un nombre que apunta a un valor guardado en la memoria del ' .
        'ordenador. Sirve para guardar datos y poder usarlos o cambiarlos después, por ejemplo ' .
        '<code>edad = 17</code> y luego <code>edad = edad + 1</code>.</p>' .
        '<p>Los tipos básicos son: int para enteros (<code>42</code>), float para decimales ' .
        '(<code>3.14</code>), str para texto (<code>"hola"</code>) y bool para verdadero o falso ' .
        '(<code>True</code>).</p>' .
        '<p><code>"3" + 3</code> da error porque "3" es un texto y 3 un número, y no se pueden sumar ' .
        'tipos distintos. Se arregla convirtiendo el texto a número: <code>int("3") + 3</code>, que ' .
        'da 6.</p>',
    // Floja: correcta pero incompleta (sin ejemplos, sin el tercer punto).
    'marcos.lopez' => '<p>Una variable es donde se guardan los datos en un programa. Hay varios tipos ' .
        'de datos como números, texto y booleanos. Se usan mucho en programación.</p>',
    // Con un error conceptual (confunde float con texto y la explicación del error).
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

// --- Foro: una duda sin responder y otra con una respuesta incorrecta de un compañero ---
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
