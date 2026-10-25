<?php
/**
 * Crea el curso de contenido que lee la batería de pruebas de moodle-driver (falkenslab/moodle-sandbox#5):
 * "mcp-content", con una actividad de cada tipo y datos en ellas (entregas, intentos, respuestas,
 * debates, notas y visitas). La batería lo encuentra por su nombre corto y la tarea por su nombre
 * ("Tarea MCP"), así que los ids pueden variar entre sitios.
 *
 *   docker compose exec webserver php seed-content.php
 *
 * Lo que las pruebas exigen y por qué está así:
 * - La alumna con la que entra la batería es lucia.martin (la primera de `info --json`): está
 *   matriculada solo como alumna, ha entregado "Tarea MCP" y la tiene calificada con su rúbrica,
 *   ha visitado el curso y tiene más de seis entradas en el registro, de más de un minuto (se
 *   retrasan una hora). El administrador no se matricula.
 * - La sección general solo tiene un foro (sin foro de avisos: el primer foro que ve la alumna
 *   tiene debates); la primera página está en la sección 1 y el fichero en la 2, para la copia
 *   de seguridad parcial (una sección entera, una página y un fichero de otras).
 * - La página "Apuntes: bucles" es la primera actividad que se crea: en un sitio recién montado es
 *   el módulo 5, que la batería da por una página.
 * - El feedback es anónimo, con una pregunta de varias opciones respondida con varias; el
 *   questionnaire tiene preguntas de valoración, casillas, desplegable y sí/no, todas respondidas;
 *   un cuestionario tiene intentos terminados de tres alumnos.
 *
 * Lee los usuarios de MOODLE_TEACHER_USERNAME y MOODLE_STUDENT_USERNAME y la contraseña de los
 * alumnos sembrados de MOODLE_SEEDED_STUDENTS_PASSWORD; crea a esos alumnos si no existen (como
 * seed-teacher-activity.php). Si el curso ya existe, no hace nada.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
// El generador de rúbricas pide el generador de datos a testing_util: en 4.5 hay que cargarla; en
// 5.x es core\test\testing_util y la carga Moodle solo.
if (file_exists($CFG->libdir . '/testing/classes/util.php')) {
    require_once($CFG->libdir . '/testing/classes/util.php');
}
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/mod/choice/lib.php');
require_once($CFG->dirroot . '/mod/feedback/lib.php');
// Los generadores de preguntas y de respuestas necesitan las clases de PHPUnit (ver seed-course.php).
require_once(__DIR__ . '/vendor/autoload.php');

use mod_questionnaire\generator\question_response;
use mod_questionnaire\generator\question_response_rank;

$CFG->noemailever = true;

const SHORTNAME = 'mcp-content';

function env(string $name, string $default): string {
    $value = getenv($name);
    return $value !== false && $value !== '' ? $value : $default;
}

if ($DB->record_exists('course', ['shortname' => SHORTNAME])) {
    cli_writeln('El curso "' . SHORTNAME . '" ya existe, no se hace nada.');
    exit(0);
}

$admin = get_admin();
// Como el administrador: la rúbrica, los ficheros y algunos eventos necesitan un usuario.
\core\session\manager::set_user($admin);
$generator = new testing_data_generator();
$transaction = $DB->start_delegated_transaction();

// --- Personas ---
$teacher = $DB->get_record('user', ['username' => env('MOODLE_TEACHER_USERNAME', 'profesor')], '*', MUST_EXIST);
$alumno = $DB->get_record('user', ['username' => env('MOODLE_STUDENT_USERNAME', 'alumno')], '*', MUST_EXIST);
// Los alumnos de seed-teacher-activity.php: si no existen todavía, se crean aquí igual que allí.
$students = [];
foreach ([['lucia.martin', 'Lucía', 'Martín'], ['marcos.lopez', 'Marcos', 'López'], ['sara.gil', 'Sara', 'Gil']] as [$username, $first, $last]) {
    $students[$username] = $DB->get_record('user', ['username' => $username]) ?: $generator->create_user([
        'username' => $username,
        'password' => env('MOODLE_SEEDED_STUDENTS_PASSWORD', 'Sandbox-' . bin2hex(random_bytes(6))),
        'email' => "{$username}@example.com",
        'firstname' => $first,
        'lastname' => $last,
    ]);
}
[$lucia, $marcos, $sara] = array_values($students);

// --- Curso ---
$course = $generator->create_course([
    'fullname' => 'Contenido para moodle-mcp',
    'shortname' => SHORTNAME,
    'summary' => 'Curso de contenido que lee la batería de pruebas de moodle-driver, generado por seed-content.php.',
    'format' => 'topics',
    'numsections' => 3,
    // Sin foro de avisos: sería el primer foro del curso, y sin debates.
    'newsitems' => 0,
    'startdate' => time() - 14 * DAYSECS,
    'enablecompletion' => 1,
    // Las fechas de las actividades en la página del curso (la batería las lee de ahí). El generador
    // deja 0; un curso creado desde la página toma el valor del sitio, 1.
    'showactivitydates' => 1,
]);
$coursecontext = context_course::instance($course->id);

// La página primero: en un sitio recién montado es el módulo 5 (ver arriba).
$page = $generator->create_module('page', ['course' => $course->id, 'section' => 1, 'name' => 'Apuntes: bucles',
    'content' => '<p>Un bucle repite un bloque de código. <code>for</code> recorre una secuencia; ' .
        '<code>while</code> repite mientras se cumple una condición.</p>']);

foreach ([1 => 'Tema 1: bucles', 2 => 'Tema 2: funciones', 3 => 'Paquetes y repaso'] as $number => $name) {
    course_update_section($course, $DB->get_record('course_sections', ['course' => $course->id, 'section' => $number]),
        ['name' => $name]);
}

$generator->enrol_user($teacher->id, $course->id, 'editingteacher');
foreach ([$alumno, $lucia, $marcos, $sara] as $student) {
    $generator->enrol_user($student->id, $course->id, 'student');
}
$group = $generator->create_group(['courseid' => $course->id, 'name' => 'Grupo A']);
foreach ([$alumno, $lucia] as $member) {
    $generator->create_group_member(['groupid' => $group->id, 'userid' => $member->id]);
}

// --- Sección general: un foro con un debate y una respuesta ---
$forumgenerator = $generator->get_plugin_generator('mod_forum');
$intro = $generator->create_module('forum', ['course' => $course->id, 'section' => 0, 'name' => 'Foro de presentación',
    'type' => 'general', 'intro' => 'Preséntate a la clase.']);
$discussion = $forumgenerator->create_discussion(['course' => $course->id, 'forum' => $intro->id, 'userid' => $teacher->id,
    'name' => 'Presentaos', 'message' => 'Contad quiénes sois y qué esperáis del curso.']);
$forumgenerator->create_post(['discussion' => $discussion->id, 'parent' => $discussion->firstpost, 'userid' => $lucia->id,
    'subject' => 'Re: Presentaos', 'message' => 'Hola, soy Lucía y quiero aprender a programar en Python.']);

// --- Sección 1: libro, enlace, etiqueta y la tarea con rúbrica ---
$book = $generator->create_module('book', ['course' => $course->id, 'section' => 1, 'name' => 'Libro: funciones']);
$bookgenerator = $generator->get_plugin_generator('mod_book');
$bookgenerator->create_chapter(['bookid' => $book->id, 'title' => 'Definir funciones',
    'content' => '<p>Una función se define con <code>def</code>.</p>']);
$bookgenerator->create_chapter(['bookid' => $book->id, 'title' => 'Parámetros',
    'content' => '<p>Los parámetros son los valores que recibe la función.</p>']);
$generator->create_module('url', ['course' => $course->id, 'section' => 1, 'name' => 'Enlace: documentación de Python',
    'externalurl' => 'https://docs.python.org/es/3/']);
$generator->create_module('label', ['course' => $course->id, 'section' => 1,
    'intro' => 'Aviso: la práctica se entrega el viernes.']);

$assigninstance = $generator->create_module('assign', ['course' => $course->id, 'section' => 1, 'name' => 'Tarea MCP',
    'intro' => '<p>Escribe un programa que muestre la tabla de multiplicar de un número con un bucle <code>for</code>.</p>',
    'allowsubmissionsfromdate' => time() - 7 * DAYSECS, 'duedate' => time() + 7 * DAYSECS,
    'grade' => 100, 'submissiondrafts' => 0,
    'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 0,
    'assignfeedback_comments_enabled' => 1]);
$assigncm = get_coursemodule_from_instance('assign', $assigninstance->id, $course->id, false, MUST_EXIST);
$assigncontext = context_module::instance($assigncm->id);
$rubric = $generator->get_plugin_generator('gradingform_rubric')->create_instance($assigncontext, 'mod_assign', 'submissions',
    'Rúbrica MCP', 'Cómo se califica la tarea.', [
        'Corrección: el programa funciona' => ['No funciona' => 0, 'Funciona con errores' => 30, 'Funciona bien' => 60],
        'Claridad: el código se entiende' => ['Confuso' => 0, 'Mejorable' => 20, 'Claro' => 40],
    ]);
$assigngenerator = $generator->get_plugin_generator('mod_assign');
foreach ([[$lucia, "for i in range(1, 11):\n    print(n, 'x', i, '=', n * i)"], [$marcos, 'print(n * 1, n * 2, n * 3)']] as [$student, $text]) {
    $assigngenerator->create_submission(['userid' => $student->id, 'cmid' => $assigncm->id,
        'onlinetext' => '<pre>' . s($text) . '</pre>']);
}
// Calificada por el profesor con la rúbrica, como en la página de calificación.
\core\session\manager::set_user($teacher);
$assign = new assign($assigncontext, $assigncm, $course);
$rubricgenerator = $generator->get_plugin_generator('gradingform_rubric');
foreach ([[$lucia, 60, 40, 'Muy bien: el bucle es correcto y el código se lee fácil.'],
        [$marcos, 30, 20, 'Funciona, pero no usa un bucle.']] as [$student, $correctness, $clarity, $comment]) {
    $grade = $assign->get_user_grade($student->id, true);
    $assign->save_grade($student->id, (object) [
        'advancedgrading' => $rubricgenerator->get_submitted_form_data($rubric, $grade->id, [
            'Corrección: el programa funciona' => ['score' => $correctness, 'remark' => ''],
            'Claridad: el código se entiende' => ['score' => $clarity, 'remark' => ''],
        ]),
        'attemptnumber' => -1,
        'addattempt' => 0,
        'applytoall' => 0,
        'workflowstate' => '',
        'assignfeedbackcomments_editor' => ['text' => $comment, 'format' => FORMAT_HTML],
    ]);
}
\core\session\manager::set_user($admin);

// --- Sección 2: fichero, carpeta, cuestionarios, encuestas, foro, H5P, lección y consulta ---
$generator->create_module('resource', ['course' => $course->id, 'section' => 2, 'name' => 'Fichero: enunciado',
    'defaultfilename' => 'enunciado.txt']);
$draftid = file_get_unused_draft_itemid();
$fs = get_file_storage();
foreach (['ejemplo1.py' => "for i in range(3):\n    print(i)\n", 'ejemplo2.py' => "def doble(n):\n    return 2 * n\n"] as $name => $code) {
    $fs->create_file_from_string(['contextid' => context_user::instance($admin->id)->id, 'component' => 'user',
        'filearea' => 'draft', 'itemid' => $draftid, 'filepath' => '/', 'filename' => $name], $code);
}
$generator->create_module('folder', ['course' => $course->id, 'section' => 2, 'name' => 'Carpeta: ejemplos', 'files' => $draftid]);

/** Un cuestionario con preguntas de corrección automática (las de ejemplo de Moodle). */
function seed_quiz(testing_data_generator $generator, stdClass $course, string $name, int $section, array $questions): stdClass {
    $quiz = $generator->create_module('quiz', ['course' => $course->id, 'section' => $section, 'name' => $name,
        'grade' => 10, 'sumgrades' => 0, 'preferredbehaviour' => 'deferredfeedback']);
    $questiongenerator = $generator->get_plugin_generator('core_question');
    $category = $questiongenerator->create_question_category(['contextid' => context_module::instance($quiz->cmid)->id]);
    foreach ($questions as [$qtype, $which, $qname]) {
        $question = $questiongenerator->create_question($qtype, $which, ['category' => $category->id, 'name' => $qname]);
        quiz_add_quiz_question($question->id, $quiz);
    }
    // Sin esto, sumgrades se queda a 0 y Moodle no deja empezar ningún intento (ver seed-course.php).
    \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
    return $quiz;
}
$quiz = seed_quiz($generator, $course, 'Cuestionario MCP', 2, [
    ['truefalse', 'true', 'Un bucle for recorre una secuencia'],
    ['multichoice', 'one_of_four', 'Elige la opción correcta'],
    ['shortanswer', 'frogtoad', 'Respuesta corta'],
    ['numerical', 'pi', 'El valor de pi'],
    ['truefalse', 'true', 'while repite mientras se cumple la condición'],
    ['multichoice', 'one_of_four', 'Otra opción correcta'],
]);
// Intentos terminados de tres alumnos, con aciertos distintos (para resultados y estadísticas).
$quizgenerator = $generator->get_plugin_generator('mod_quiz');
foreach ([[$lucia, ['True', 'One', 'frog', '3.14', 'True', 'One']],
        [$marcos, ['True', 'Two', 'toad', '3.14', 'False', 'One']],
        [$sara, ['False', 'One', 'frog', '3', 'True', 'Three']]] as [$student, $answers]) {
    \core\session\manager::set_user($student);
    $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);
    $responses = [];
    foreach ($answers as $i => $answer) {
        // El resumen de la respuesta, como lo escribe la página de resultados ("True", "One", "frog").
        $responses[$i + 1] = $answer;
    }
    // Lo que hace submit_responses() del generador, que solo se puede usar en los tests (pide el uso
    // de las preguntas con get_question_usage()): aquí se carga por su id.
    $attemptobj = \mod_quiz\quiz_attempt::create($attempt->id);
    $postdata = $generator->get_plugin_generator('core_question')->get_simulated_post_data_for_questions_in_usage(
        question_engine::load_questions_usage_by_activity($attemptobj->get_uniqueid()), $responses, false);
    $attemptobj->process_submitted_actions(time(), false, $postdata);
    if (method_exists($attemptobj, 'process_submit')) {
        // 5.x: entregar y calificar son dos pasos.
        $attemptobj->process_submit(time(), false);
        $attemptobj->process_grade_submission(time());
    } else {
        $attemptobj->process_finish(time(), false);
    }
}
\core\session\manager::set_user($admin);
seed_quiz($generator, $course, 'Cuestionario de bucles', 2, [
    ['truefalse', 'true', 'range(3) da tres números'],
    ['numerical', 'pi', 'Un número decimal'],
    ['shortanswer', 'frogtoad', 'Otra respuesta corta'],
    ['multichoice', 'one_of_four', 'Una opción más'],
]);

// Questionnaire con un tipo de pregunta de cada (los que lee la batería: valoración, casillas,
// desplegable y sí/no), respondido por los tres alumnos.
$questionnairegenerator = $generator->get_plugin_generator('mod_questionnaire');
$qrecord = $questionnairegenerator->create_instance(['course' => $course->id, 'section' => 2, 'name' => 'Encuesta MCP']);
$qcm = get_coursemodule_from_instance('questionnaire', $qrecord->id, $course->id, false, MUST_EXIST);
$questionnaire = new questionnaire($course, $qcm, $qrecord->id, null, true);
$questiondefs = [
    ['recomienda', QUESYESNO, '¿Recomendarías el curso?', null],
    ['mejor', QUESTEXT, 'Lo mejor del curso, en una palabra', null],
    ['sugerencias', QUESESSAY, '¿Qué cambiarías?', null],
    ['nivel', QUESRADIO, 'Tu nivel de programación', ['Ninguno', 'Básico', 'Avanzado']],
    ['recursos', QUESCHECK, '¿Qué recursos has usado?', ['Apuntes', 'Vídeos', 'Foros', 'Libro']],
    ['edad', QUESDROP, 'Tu edad', ['Menos de 18', 'De 18 a 25', 'Más de 25']],
    ['valoracion', QUESRATE, 'Valora cada parte', ['Bucles', 'Funciones', 'Prácticas']],
    ['horas', QUESNUMERIC, 'Horas a la semana', null],
];
foreach ($questiondefs as [$qname, $type, $content, $choices]) {
    $questionnairegenerator->create_question($questionnaire, ['surveyid' => $qrecord->sid, 'name' => $qname,
        'type_id' => $type, 'content' => $content, 'required' => 'n'], $choices);
    $questionnaire = new questionnaire($course, $qcm, $qrecord->id, null, true);
}
$questions = [];
foreach ($DB->get_records('questionnaire_question', ['surveyid' => $qrecord->sid], 'position') as $record) {
    $questions[$record->name] = $record;
}
$choicesof = fn(string $qname) => array_values($DB->get_records('questionnaire_quest_choice',
    ['question_id' => $questions[$qname]->id], 'id'));
foreach ([[$lucia, 'y', 'Bucles', 1, [0, 2], 1, [5, 4, 4], 6],
        [$marcos, 'n', 'Funciones', 0, [1], 2, [3, 2, 4], 3],
        [$sara, 'y', 'Prácticas', 2, [0, 1, 3], 0, [4, 5, 3], 8]] as [$student, $yesno, $word, $level, $resources, $age, $rates, $hours]) {
    $responses = [
        new question_response($questions['mejor']->id, $word),
        new question_response($questions['sugerencias']->id, '<p>Más ejercicios de funciones.</p>'),
        new question_response($questions['nivel']->id, (int) $choicesof('nivel')[$level]->id),
        new question_response($questions['recursos']->id, array_map(fn($i) => (int) $choicesof('recursos')[$i]->id, $resources)),
        new question_response($questions['edad']->id, (int) $choicesof('edad')[$age]->id),
        new question_response($questions['valoracion']->id, array_map(
            fn($i) => new question_response_rank($choicesof('valoracion')[$i], $rates[$i]), [0, 1, 2])),
        new question_response($questions['horas']->id, $hours),
    ];
    $response = $questionnairegenerator->create_response($responses,
        ['questionnaireid' => $qrecord->id, 'userid' => $student->id]);
    // El generador guarda el sí/no como texto; questionnaire lo lee de su propia tabla.
    $DB->insert_record('questionnaire_response_bool', ['response_id' => $response['id'],
        'question_id' => $questions['recomienda']->id, 'choice_id' => $yesno]);
}

// Feedback anónimo: una pregunta de varias opciones respondida con varias, y de otros tipos.
$feedback = $generator->create_module('feedback', ['course' => $course->id, 'section' => 2, 'name' => 'Feedback MCP',
    'anonymous' => FEEDBACK_ANONYMOUS_YES, 'multiple_submit' => 0]);
$feedbackgenerator = $generator->get_plugin_generator('mod_feedback');
$items = [
    'gusta' => $feedbackgenerator->create_item_multichoice($feedback, ['name' => '¿Te ha gustado el curso?',
        'label' => 'gusta', 'subtype' => 'r', 'values' => "Mucho\nAlgo\nNada"]),
    'usado' => $feedbackgenerator->create_item_multichoice($feedback, ['name' => '¿Qué has usado?',
        'label' => 'usado', 'subtype' => 'c', 'values' => "Vídeos\nPDF\nForos"]),
    'provincia' => $feedbackgenerator->create_item_multichoice($feedback, ['name' => 'Tu provincia',
        'label' => 'provincia', 'subtype' => 'd', 'values' => "Las Palmas\nSanta Cruz de Tenerife"]),
];
$feedbackgenerator->create_item_pagebreak($feedback);
$items += [
    'valoracion' => $feedbackgenerator->create_item_multichoicerated($feedback, ['name' => 'Valoración global',
        'label' => 'valoracion', 'subtype' => 'r', 'values' => "1####Mala\n2####Regular\n3####Buena"]),
    'palabra' => $feedbackgenerator->create_item_textfield($feedback, ['name' => 'Una palabra', 'label' => 'palabra']),
    'comentarios' => $feedbackgenerator->create_item_textarea($feedback, ['name' => 'Comentarios', 'label' => 'comentarios']),
    'nota' => $feedbackgenerator->create_item_numeric($feedback, ['name' => 'Nota del 0 al 10', 'label' => 'nota',
        'rangefrom' => 0, 'rangeto' => 10]),
];
$feedbackcm = get_coursemodule_from_instance('feedback', $feedback->id, $course->id, false, MUST_EXIST);
foreach ([[$lucia, 1, [1, 3], 2, 3, 'Útil', 9], [$marcos, 2, [2], 1, 2, 'Difícil', 6], [$sara, 1, [1, 2, 3], 2, 3, 'Práctico', 8]]
        as [$student, $gusta, $usado, $provincia, $valoracion, $palabra, $nota]) {
    $answers = [];
    foreach (['gusta' => $gusta, 'usado' => $usado, 'provincia' => $provincia, 'valoracion' => $valoracion,
            'palabra' => $palabra, 'comentarios' => 'Me ha servido para ' . strtolower($palabra) . '.', 'nota' => $nota] as $key => $value) {
        $answers["{$items[$key]->typ}_{$items[$key]->id}"] = $value;
    }
    // Como el generador de feedback, pero con varias opciones en una respuesta (las casillas).
    $completion = new mod_feedback_completion($feedback, $feedbackcm, $course->id, false, null, null, $student->id);
    $completion->save_response_tmp((object) $answers);
    $completion->save_response();
}

$forum = $generator->create_module('forum', ['course' => $course->id, 'section' => 2, 'name' => 'Foro MCP', 'type' => 'general',
    'intro' => 'Dudas sobre bucles y funciones.']);
$threads = [
    [$lucia, '¿for o while?', '¿Cuándo uso un for y cuándo un while?', $teacher, 'for cuando sabes cuántas veces; while cuando depende de una condición.'],
    [$marcos, 'range no incluye el final', '¿Por qué range(1, 10) no llega al 10?', $sara, 'Porque el final no se incluye: usa range(1, 11).'],
    [$sara, 'Bucle infinito', 'Mi while no termina nunca.', $teacher, 'Revisa que la condición cambie dentro del bucle.'],
    [$lucia, 'return o print', '¿Qué diferencia hay entre return y print?', $marcos, 'return devuelve el valor; print solo lo muestra.'],
    [$alumno, 'Parámetros por defecto', '¿Se puede dar un valor por defecto a un parámetro?', $teacher, 'Sí: def saludo(nombre="mundo").'],
    [$marcos, 'Funciones dentro de bucles', '¿Puedo llamar a una función dentro de un for?', $lucia, 'Claro, como cualquier otra instrucción.'],
    [$teacher, 'Entrega de la tarea', 'Recordad que la tarea se entrega el viernes.', $sara, '¡Gracias por avisar!'],
    [$sara, 'break y continue', '¿Para qué sirve continue?', $teacher, 'Salta al siguiente paso del bucle sin terminarlo.'],
];
foreach ($threads as [$author, $subject, $message, $replier, $reply]) {
    $thread = $forumgenerator->create_discussion(['course' => $course->id, 'forum' => $forum->id, 'userid' => $author->id,
        'name' => $subject, 'message' => $message]);
    $forumgenerator->create_post(['discussion' => $thread->id, 'parent' => $thread->firstpost, 'userid' => $replier->id,
        'subject' => "Re: $subject", 'message' => $reply]);
}

$generator->create_module('h5pactivity', ['course' => $course->id, 'section' => 2, 'name' => 'H5P MCP',
    'packagefilepath' => 'h5p/tests/fixtures/multiple-choice-2-6.h5p']);

$lesson = $generator->create_module('lesson', ['course' => $course->id, 'section' => 2, 'name' => 'Lección MCP']);
$lessongenerator = $generator->get_plugin_generator('mod_lesson');
$lessongenerator->create_content($lesson, ['title' => 'Introducción']);
$lessongenerator->create_question_truefalse($lesson, ['title' => 'Pregunta']);

$choice = $generator->create_module('choice', ['course' => $course->id, 'section' => 2, 'name' => 'Consulta MCP',
    'option' => ['for', 'while', 'do-while']]);
$choicecm = get_coursemodule_from_instance('choice', $choice->id, $course->id, false, MUST_EXIST);
$options = array_values($DB->get_records('choice_options', ['choiceid' => $choice->id], 'id'));
foreach ([[$lucia, 0], [$marcos, 1], [$sara, 0]] as [$student, $option]) {
    choice_user_submit_response($options[$option]->id, choice_get_choice($choice->id), $student->id, $course, $choicecm);
}

// --- Sección 3: paquetes SCORM (los de ejemplo de Moodle) y un banco de preguntas (5.x) ---
$generator->create_module('scorm', ['course' => $course->id, 'section' => 3, 'name' => 'SCORM MCP (prueba)']);
$generator->create_module('scorm', ['course' => $course->id, 'section' => 3, 'name' => 'SCORM: repaso']);
if (core_component::get_component_directory('mod_qbank')) {
    $generator->create_module('qbank', ['course' => $course->id, 'name' => 'Banco de preguntas MCP']);
}

// --- Visitas: el registro de lo que han visto (informes de actividad y de participación) ---
/** Dispara el evento de "ha visto la actividad" como ese usuario. */
function seed_view(stdClass $user, string $modname, int $instanceid, int $cmid): void {
    \core\session\manager::set_user($user);
    $class = "\\mod_{$modname}\\event\\course_module_viewed";
    $class::create(['objectid' => $instanceid, 'context' => context_module::instance($cmid)])->trigger();
}
$views = [
    // La alumna de la batería: el curso y varias actividades (más de seis entradas).
    [$lucia, [['page', $page], ['page', $page], ['page', $page], ['assign', $assigninstance], ['quiz', $quiz],
        ['forum', $forum], ['book', $book]]],
    [$marcos, [['page', $page], ['page', $page], ['forum', $forum]]],
    [$sara, [['page', $page], ['page', $page], ['quiz', $quiz]]],
    [$teacher, [['assign', $assigninstance]]],
];
foreach ($views as [$user, $activities]) {
    \core\session\manager::set_user($user);
    \core\event\course_viewed::create(['context' => $coursecontext])->trigger();
    foreach ($activities as [$modname, $instance]) {
        seed_view($user, $modname, $instance->id, $instance->cmid);
    }
}
\core\session\manager::set_user($admin);

$transaction->allow_commit();

// Después de guardar: el registro de lo de arriba, una hora antes (la batería pide entradas de más
// de un minuto), y el último acceso al curso de la alumna y el profesor.
$hourago = time() - HOURSECS;
// El registro guarda sus eventos en un búfer hasta el final del script: se escriben ya.
get_log_manager()->dispose();
$DB->execute('UPDATE {logstore_standard_log} SET timecreated = timecreated - ? WHERE courseid = ?', [HOURSECS, $course->id]);
foreach ([$lucia, $marcos, $sara, $teacher] as $user) {
    if ($access = $DB->get_record('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id])) {
        $DB->set_field('user_lastaccess', 'timeaccess', $hourago, ['id' => $access->id]);
    } else {
        $DB->insert_record('user_lastaccess', ['userid' => $user->id, 'courseid' => $course->id, 'timeaccess' => $hourago]);
    }
}
rebuild_course_cache($course->id, true);

cli_writeln('OK');
cli_writeln('course_id=' . $course->id);
cli_writeln('assign_cmid=' . $assigncm->id);
cli_writeln('page_cmid=' . $page->cmid);
