<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This page is the entry page into the quiz UI. Displays information about the
 * quiz to students and teachers, and lets students see their previous attempts.
 *
 * @package    mod
 * @subpackage quiz
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/mod/quiz/locallib.php');
require_once($CFG->libdir . '/completionlib.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID, or
$q = optional_param('q',  0, PARAM_INT);  // quiz ID

if ($id) {
    if (!$cm = get_coursemodule_from_id('quiz', $id)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
        print_error('coursemisconf');
    }
    if (!$quiz = $DB->get_record('quiz', array('id' => $cm->instance))) {
        print_error('invalidcoursemodule');
    }
} else {
    if (!$quiz = $DB->get_record('quiz', array('id' => $q))) {
        print_error('invalidquizid', 'quiz');
    }
    if (!$course = $DB->get_record('course', array('id' => $quiz->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("quiz", $quiz->id, $course->id)) {
        print_error('invalidcoursemodule');
    }
}

// Check login and get context.
require_login($course->id, false, $cm);
$context = get_context_instance(CONTEXT_MODULE, $cm->id);
require_capability('mod/quiz:view', $context);

// Cache some other capabilities we use several times.
$canattempt = has_capability('mod/quiz:attempt', $context);
$canreviewmine = has_capability('mod/quiz:reviewmyattempts', $context);
$canpreview = has_capability('mod/quiz:preview', $context);

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$accessmanager = new quiz_access_manager(quiz::create($quiz->id, $USER->id), $timenow,
        has_capability('mod/quiz:ignoretimelimits', $context, null, false));

// Log this request.
add_to_log($course->id, 'quiz', 'view', 'view.php?id=' . $cm->id, $quiz->id, $cm->id);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Initialize $PAGE, compute blocks
$PAGE->set_url('/mod/quiz/view.php', array('id' => $cm->id));

$edit = optional_param('edit', -1, PARAM_BOOL);
if ($edit != -1 && $PAGE->user_allowed_editing()) {
    $USER->editing = $edit;
}

// Update the quiz with overrides for the current user
$quiz = quiz_update_effective_access($quiz, $USER->id);

// Get this user's attempts.
$attempts = quiz_get_user_attempts($quiz->id, $USER->id, 'finished', true);
$lastfinishedattempt = end($attempts);
$unfinished = false;
if ($unfinishedattempt = quiz_get_user_attempt_unfinished($quiz->id, $USER->id)) {
    $attempts[] = $unfinishedattempt;
    $unfinished = true;
}
$numattempts = count($attempts);

// Work out the final grade, checking whether it was overridden in the gradebook.
$mygrade = quiz_get_best_grade($quiz, $USER->id);
$mygradeoverridden = false;
$gradebookfeedback = '';

$grading_info = grade_get_grades($course->id, 'mod', 'quiz', $quiz->id, $USER->id);
if (!empty($grading_info->items)) {
    $item = $grading_info->items[0];
    if (isset($item->grades[$USER->id])) {
        $grade = $item->grades[$USER->id];

        if ($grade->overridden) {
            $mygrade = $grade->grade + 0; // Convert to number.
            $mygradeoverridden = true;
        }
        if (!empty($grade->str_feedback)) {
            $gradebookfeedback = $grade->str_feedback;
        }
    }
}

$title = $course->shortname . ': ' . format_string($quiz->name);
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$output = $PAGE->get_renderer('mod_quiz');

/*
 * Create view object for use within renderers file
 */
$viewobj = new mod_quiz_view_object();
$viewobj->attempts = $attempts;
$viewobj->accessmanager = $accessmanager;
$viewobj->canattempt = $canattempt;
$viewobj->canpreview = $canpreview;
$viewobj->canreviewmine = $canreviewmine;

// Print table with existing attempts
if ($attempts) {
    // Work out which columns we need, taking account what data is available in each attempt.
    list($someoptions, $alloptions) = quiz_get_combined_reviewoptions($quiz, $attempts, $context);

    $viewobj->attemptcolumn = $quiz->attempts != 1;

    $viewobj->gradecolumn = $someoptions->marks >= question_display_options::MARK_AND_MAX &&
            quiz_has_grades($quiz);
    $viewobj->markcolumn = $viewobj->gradecolumn && ($quiz->grade != $quiz->sumgrades);
    $viewobj->overallstats = $alloptions->marks >= question_display_options::MARK_AND_MAX;

    $viewobj->feedbackcolumn = quiz_has_feedback($quiz) && $alloptions->overallfeedback;
} else {
    $viewobj->attemptcolumn = 1;
}

$moreattempts = $unfinished || !$accessmanager->is_finished($numattempts, $lastfinishedattempt);

$viewobj->timenow = $timenow;
$viewobj->numattempts = $numattempts;
$viewobj->mygrade = $mygrade;
$viewobj->moreattempts = $moreattempts;
$viewobj->mygradeoverridden = $mygradeoverridden;
$viewobj->gradebookfeedback = $gradebookfeedback;
$viewobj->unfinished = $unfinished;
$viewobj->lastfinishedattempt = $lastfinishedattempt;

// Display information about this quiz.
$infomessages = $viewobj->accessmanager->describe_rules();
if ($quiz->attempts != 1) {
    $infomessages[] = get_string('gradingmethod', 'quiz',
            quiz_get_grading_option_name($quiz->grademethod));
}

// This will be set something if as start/continue attempt button should appear.
$buttontext = '';
$preventmessages = array();
if (!quiz_clean_layout($quiz->questions, true)) {
    $buttontext = '';

} else {
    if ($viewobj->unfinished) {
        if ($viewobj->canattempt) {
            $buttontext = get_string('continueattemptquiz', 'quiz');
        } else if ($viewobj->canpreview) {
            $buttontext = get_string('continuepreview', 'quiz');
        }

    } else {
        if ($viewobj->canattempt) {
            $preventmessages = $viewobj->accessmanager->prevent_new_attempt($viewobj->numattempts,
                    $viewobj->lastfinishedattempt);
            if ($preventmessages) {
                $buttontext = '';
            } else if ($viewobj->numattempts == 0) {
                $buttontext = get_string('attemptquiznow', 'quiz');
            } else {
                $buttontext = get_string('reattemptquiz', 'quiz');
            }

        } else if ($viewobj->canpreview) {
            $buttontext = get_string('previewquiznow', 'quiz');
        }
    }

    // If, so far, we think a button should be printed, so check if they will be
    // allowed to access it.
    if ($buttontext) {
        if (!$viewobj->moreattempts) {
            $buttontext = '';
        } else if ($viewobj->canattempt
                && $preventmessages = $viewobj->accessmanager->prevent_access()) {
            $buttontext = '';
        }
    }
}

echo $OUTPUT->header();

// Guests can't do a quiz, so offer them a choice of logging in or going back.
if (isguestuser()) {
    echo $output->view_page_guest($course, $quiz, $cm, $context, $infomessages, $viewobj);
} else if (!isguestuser() && !($viewobj->canattempt || $viewobj->canpreview
          || $viewobj->canreviewmine)) {
    // If they are not enrolled in this course in a good enough role, tell them to enrol.
    echo $output->view_page_notenrolled($course, $quiz, $cm, $context, $infomessages, $viewobj);
} else {
    echo $output->view_page($course, $quiz, $cm, $context, $infomessages, $viewobj,
            $buttontext, $preventmessages);
}

echo $OUTPUT->footer();
