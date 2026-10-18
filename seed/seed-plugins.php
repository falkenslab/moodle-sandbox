<?php
/**
 * Siembra contenido que usa los plugins de la imagen (docker/plugins.txt), para probar programas
 * contra ellos como en un campus real:
 *
 * - en el curso de pruebas, un cuestionario con una pregunta de cada tipo de pregunta instalado
 *   (CodeRunner, Formulas, Gapfill, Drag-and-drop matching): las de ejemplo de cada plugin (los
 *   ayudantes de sus tests);
 * - un curso por formato de curso instalado (Grid, Collapsed Topics, Onetopic, Tiles), con secciones
 *   con nombre y actividades, y el profesor y los alumnos sembrados matriculados.
 *
 *   docker compose exec webserver php seed-plugins.php
 *
 * Necesita el curso de pruebas (seed-course.php). Lee los usuarios del profesor y el alumno de
 * MOODLE_TEACHER_USERNAME y MOODLE_STUDENT_USERNAME. Lo que ya existe no se toca, y un plugin que
 * la imagen no tenga se salta, así que se puede ejecutar cuantas veces haga falta.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
// create_question() necesita las clases de PHPUnit (ver seed-course.php).
require_once(__DIR__ . '/vendor/autoload.php');

$CFG->noemailever = true;
// Como el administrador: algunos módulos crean eventos del calendario, y sin usuario no hay permiso.
\core\session\manager::set_user(get_admin());

function env(string $name, string $default): string {
    $value = getenv($name);
    return $value !== false && $value !== '' ? $value : $default;
}

$course = $DB->get_record('course', ['shortname' => 'sandbox-course']);
if (!$course) {
    cli_error('No existe el curso "sandbox-course": siémbralo antes (seed-course.php).');
}
$generator = new testing_data_generator();

/** El cuestionario con una pregunta de cada tipo de pregunta de los plugins. */
function seed_questions(testing_data_generator $generator, stdClass $course): void {
    global $DB;
    $quizname = 'Cuestionario de plugins';
    // Tipo de pregunta => la pregunta de ejemplo de su ayudante de tests.
    $questions = [
        'coderunner' => 'sqr',
        'formulas' => 'testsinglenum',
        'gapfill' => 'catmat',
        'ddmatch' => 'foursubq',
    ];
    if ($DB->record_exists('quiz', ['course' => $course->id, 'name' => $quizname])) {
        cli_writeln("El cuestionario \"$quizname\" ya existe.");
        return;
    }
    $installed = array_filter(array_keys($questions), fn($qtype) => question_bank::is_qtype_installed($qtype));
    if (!$installed) {
        cli_writeln('La imagen no tiene ninguno de los tipos de pregunta: no hay cuestionario de plugins.');
        return;
    }

    $transaction = $DB->start_delegated_transaction();
    $quiz = $generator->create_module('quiz', [
        'course' => $course->id,
        'name' => $quizname,
        'intro' => 'Una pregunta de cada tipo de pregunta que no viene con Moodle, generado por seed-plugins.php.',
        // Como en un examen de programación: cada pregunta se comprueba al momento (CodeRunner la
        // corrige al pulsar "Comprobar").
        'preferredbehaviour' => 'adaptive',
    ]);
    // Como en seed-course.php, una categoría del propio cuestionario.
    $questiongenerator = $generator->get_plugin_generator('core_question');
    $category = $questiongenerator->create_question_category(['contextid' => context_module::instance($quiz->cmid)->id]);
    foreach ($installed as $qtype) {
        $question = $questiongenerator->create_question($qtype, $questions[$qtype], ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);
        cli_writeln("pregunta=$qtype:{$questions[$qtype]} id={$question->id}");
    }
    // Sin esto, sumgrades se queda a 0 y Moodle no deja empezar ningún intento (ver seed-course.php).
    \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
    $transaction->allow_commit();
    cli_writeln("quiz_cmid={$quiz->cmid}");
}

/** Un curso por formato de curso de los plugins, con el mismo contenido en todos. */
function seed_formats(testing_data_generator $generator): void {
    global $DB;
    // Formato => su nombre, para el curso.
    $formats = [
        'grid' => 'Grid',
        'topcoll' => 'Collapsed Topics',
        'onetopic' => 'Onetopic',
        'tiles' => 'Tiles',
    ];
    $installed = core_component::get_plugin_list('format');
    $sections = ['Variables y tipos', 'Condicionales', 'Bucles', 'Funciones'];

    foreach ($formats as $format => $name) {
        $shortname = "formato-$format";
        if (!isset($installed[$format])) {
            cli_writeln("La imagen no tiene el formato $format: sin curso.");
            continue;
        }
        if ($existing = $DB->get_record('course', ['shortname' => $shortname])) {
            cli_writeln("El curso \"$shortname\" ya existe.");
            enrol_seeded_users($generator, $existing);
            continue;
        }
        $transaction = $DB->start_delegated_transaction();
        $course = $generator->create_course([
            'fullname' => "Curso en formato $name",
            'shortname' => $shortname,
            'summary' => "Curso con el formato $name (format_$format), generado por seed-plugins.php.",
            'format' => $format,
            'numsections' => count($sections),
        ]);
        // Secciones con nombre y, en cada una, apuntes, una tarea y, en la última, un foro.
        foreach ($sections as $i => $title) {
            $section = $i + 1;
            course_update_section($course, $DB->get_record('course_sections', ['course' => $course->id, 'section' => $section]),
                ['name' => $title, 'summary' => "Tema $section: $title.", 'summaryformat' => FORMAT_HTML]);
            $generator->create_module('page', ['course' => $course->id, 'section' => $section,
                'name' => "Apuntes: $title", 'content' => "Apuntes del tema $section ($title)."]);
            $generator->create_module('assign', ['course' => $course->id, 'section' => $section,
                'name' => "Ejercicios: $title", 'duedate' => time() + ($section + 1) * WEEKSECS,
                'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 0]);
        }
        $generator->create_module('forum', ['course' => $course->id, 'section' => 0,
            'name' => 'Foro de dudas', 'type' => 'general']);
        enrol_seeded_users($generator, $course);
        $transaction->allow_commit();
        cli_writeln("curso=$shortname id={$course->id}");
    }
}

/**
 * Un curso con una actividad de cada módulo de los plugins (los que traen generador de datos) y
 * sus bloques en la página del curso. Cada actividad y cada bloque se crea si falta; devuelve los
 * que fallan.
 */
function seed_activities(testing_data_generator $generator): array {
    global $DB;
    // Módulo => nombre de la actividad. Sin generador (y sin actividad sembrada): evoting, hotpot,
    // hvp (necesita un paquete H5P) y offlinequiz en 4.5.
    $modules = [
        'attendance' => 'Asistencia',
        'board' => 'Tablero de ideas',
        'checklist' => 'Lista de comprobación',
        'choicegroup' => 'Elección de grupo',
        'collabora' => 'Documento colaborativo (sin servidor de Collabora)',
        'game' => 'Juego del ahorcado',
        'jitsi' => 'Videoconferencia (sin servidor de Jitsi)',
        'journal' => 'Diario',
        'lightboxgallery' => 'Galería de imágenes',
        'offlinequiz' => 'Cuestionario en papel',
        'publication' => 'Carpeta de entregas',
        'chat' => 'Chat',
        'survey' => 'Encuesta predefinida',
        'questionnaire' => 'Encuesta de satisfacción',
    ];
    // Lo que el generador de un módulo no pone por su cuenta.
    $extra = [
        // Un documento de texto nuevo (de su plantilla), no uno subido.
        'collabora' => ['format' => 'wordprocessor'],
    ];
    $blocks = ['completion_progress', 'xp', 'grade_me', 'quickmail', 'configurable_reports'];
    $failed = [];

    $course = $DB->get_record('course', ['shortname' => 'plugins-actividades']);
    if (!$course) {
        $course = $generator->create_course([
            'fullname' => 'Curso con los plugins de actividades y bloques',
            'shortname' => 'plugins-actividades',
            'summary' => 'Una actividad de cada módulo y los bloques que no vienen con Moodle, generado por seed-plugins.php.',
            'numsections' => 1,
            'enablecompletion' => 1,
        ]);
        cli_writeln("curso=plugins-actividades id={$course->id}");
    }
    enrol_seeded_users($generator, $course);

    $installed = core_component::get_plugin_list('mod');
    foreach ($modules as $module => $name) {
        $hasgenerator = isset($installed[$module]) && file_exists($installed[$module] . '/tests/generator/lib.php');
        if (!$hasgenerator) {
            cli_writeln("La imagen no tiene mod_$module (o no trae generador): sin actividad.");
            continue;
        }
        $moduleid = $DB->get_field('modules', 'id', ['name' => $module]);
        if ($DB->record_exists('course_modules', ['course' => $course->id, 'module' => $moduleid])) {
            continue;
        }
        try {
            $instance = $generator->create_module($module, ['course' => $course->id, 'section' => 1, 'name' => $name,
                'intro' => "Una actividad de mod_$module, generada por seed-plugins.php."] + ($extra[$module] ?? []));
            cli_writeln("actividad=mod_$module cmid={$instance->cmid}");
        } catch (Throwable $e) {
            $failed[] = "mod_$module: " . $e->getMessage();
            rollback_failed_transaction();
        }
    }

    $context = context_course::instance($course->id);
    $installedblocks = core_component::get_plugin_list('block');
    foreach ($blocks as $block) {
        if (!isset($installedblocks[$block])) {
            cli_writeln("La imagen no tiene block_$block: sin bloque.");
            continue;
        }
        if ($DB->record_exists('block_instances', ['blockname' => $block, 'parentcontextid' => $context->id])) {
            continue;
        }
        try {
            $generator->create_block($block, ['parentcontextid' => $context->id, 'pagetypepattern' => 'course-view-*',
                'defaultregion' => 'side-pre']);
            cli_writeln("bloque=block_$block");
        } catch (Throwable $e) {
            $failed[] = "block_$block: " . $e->getMessage();
            rollback_failed_transaction();
        }
    }
    return $failed;
}

/** Matricula en el curso al profesor y a los alumnos sembrados que existan y no lo estén ya: los de
 * `activity` pueden llegar después que el curso. */
function enrol_seeded_users(testing_data_generator $generator, stdClass $course): void {
    global $DB;
    $context = context_course::instance($course->id);
    $users = ['editingteacher' => [env('MOODLE_TEACHER_USERNAME', 'profesor')],
        'student' => [env('MOODLE_STUDENT_USERNAME', 'alumno'), 'lucia.martin', 'marcos.lopez', 'sara.gil']];
    foreach ($users as $role => $usernames) {
        foreach ($DB->get_records_list('user', 'username', $usernames) as $user) {
            if (!is_enrolled($context, $user)) {
                $generator->enrol_user($user->id, $course->id, $role);
            }
        }
    }
}

/** Una creación que falla deja abierta su transacción, y todo lo que viniera después iría dentro de
 * ella y se desharía al terminar el script: se deshace ya. */
function rollback_failed_transaction(): void {
    global $DB;
    if ($DB->is_transaction_started()) {
        $DB->force_transaction_rollback();
    }
}

require_once($CFG->dirroot . '/course/lib.php');
seed_questions($generator, $course);
seed_formats($generator);
$failed = seed_activities($generator);
if ($failed) {
    cli_error("No se pudo sembrar:\n- " . implode("\n- ", $failed));
}
cli_writeln('OK');
