<?php
/**
 * Seeds content that uses the image's plugins (docker/plugins.txt), to test programs against them
 * as on a real campus:
 *
 * - in the test course, a quiz with one question of each installed question type (CodeRunner,
 *   Formulas, Gapfill, Drag-and-drop matching): each plugin's sample ones (its test helpers);
 * - a course per installed course format (Grid, Collapsed Topics, Onetopic, Tiles), with named
 *   sections and activities, and the teacher and the seeded students enrolled.
 *
 *   docker compose exec webserver php seed-plugins.php
 *
 * Needs the test course (seed-course.php). Reads the teacher's and the student's usernames from
 * MOODLE_TEACHER_USERNAME and MOODLE_STUDENT_USERNAME. What already exists is left alone, and a
 * plugin the image doesn't have is skipped, so it can run as many times as needed.
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/testing/generator/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
// create_question() needs PHPUnit's classes (see seed-course.php).
require_once(__DIR__ . '/vendor/autoload.php');

$CFG->noemailever = true;
// As the admin: some modules create calendar events, and without a user there's no permission.
\core\session\manager::set_user(get_admin());

function env(string $name, string $default): string {
    $value = getenv($name);
    return $value !== false && $value !== '' ? $value : $default;
}

$course = $DB->get_record('course', ['shortname' => 'sandbox-course']);
if (!$course) {
    cli_error('The course "sandbox-course" does not exist: seed it first (seed-course.php).');
}
$generator = new testing_data_generator();

/** The quiz with one question of each of the plugins' question types. */
function seed_questions(testing_data_generator $generator, stdClass $course): void {
    global $DB;
    $quizname = 'Cuestionario de plugins';
    // Question type => the sample question of its test helper.
    $questions = [
        'coderunner' => 'sqr',
        'formulas' => 'testsinglenum',
        'gapfill' => 'catmat',
        'ddmatch' => 'foursubq',
    ];
    if ($DB->record_exists('quiz', ['course' => $course->id, 'name' => $quizname])) {
        cli_writeln("The quiz \"$quizname\" already exists.");
        return;
    }
    $installed = array_filter(array_keys($questions), fn($qtype) => question_bank::is_qtype_installed($qtype));
    if (!$installed) {
        cli_writeln('The image has none of the question types: no plugins quiz.');
        return;
    }

    $transaction = $DB->start_delegated_transaction();
    $quiz = $generator->create_module('quiz', [
        'course' => $course->id,
        'name' => $quizname,
        'intro' => 'Una pregunta de cada tipo de pregunta que no viene con Moodle, generado por seed-plugins.php.',
        // As in a programming exam: each question is checked right away (CodeRunner grades it on
        // "Check").
        'preferredbehaviour' => 'adaptive',
    ]);
    // As in seed-course.php, a category of the quiz itself.
    $questiongenerator = $generator->get_plugin_generator('core_question');
    $category = $questiongenerator->create_question_category(['contextid' => context_module::instance($quiz->cmid)->id]);
    foreach ($installed as $qtype) {
        $question = $questiongenerator->create_question($qtype, $questions[$qtype], ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);
        cli_writeln("pregunta=$qtype:{$questions[$qtype]} id={$question->id}");
    }
    // Without this, sumgrades stays at 0 and Moodle doesn't let any attempt start (see seed-course.php).
    \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
    $transaction->allow_commit();
    cli_writeln("quiz_cmid={$quiz->cmid}");
}

/** A course per course format of the plugins, with the same content in all of them. */
function seed_formats(testing_data_generator $generator): void {
    global $DB;
    // Format => its name, for the course.
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
            cli_writeln("The image doesn't have the $format format: no course.");
            continue;
        }
        if ($existing = $DB->get_record('course', ['shortname' => $shortname])) {
            cli_writeln("The course \"$shortname\" already exists.");
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
        // Named sections, each with notes and an assignment, and a forum in the general section.
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
 * A course with one activity of each of the plugins' modules (those with a data generator) and
 * their blocks on the course page. Each activity and each block is created if missing; returns
 * the ones that fail.
 */
function seed_activities(testing_data_generator $generator): array {
    global $DB;
    // Module => the activity's name. Without a generator (and no seeded activity): evoting, hotpot,
    // hvp (needs an H5P package) and offlinequiz in 4.5.
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
    // What a module's generator doesn't set on its own.
    $extra = [
        // A new text document (from its template), not an uploaded one.
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
            cli_writeln("The image doesn't have mod_$module (or it has no generator): no activity.");
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
            cli_writeln("The image doesn't have block_$block: no block.");
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

/** Enrols in the course the teacher and the seeded students that exist and aren't enrolled yet:
 * those of `activity` may arrive after the course. */
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

/** A creation that fails leaves its transaction open, and everything after it would go inside it
 * and be rolled back when the script ends: roll it back now. */
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
    cli_error("Could not seed:\n- " . implode("\n- ", $failed));
}
cli_writeln('OK');
