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
 * Block for displayed logged in user's course completion status
 *
 * @package   moodlecore
 * @copyright 2009 Catalyst IT Ltd
 * @author    Aaron Barnes <aaronb@catalyst.net.nz>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->libdir.'/completionlib.php');

/**
 * Course completion status
 * Displays overall, and individual criteria status for logged in user
 */
class block_completionstatus extends block_base {

    public function init() {
        $this->title   = get_string('completionstatus', 'block_completionstatus');
        $this->version = 2009072800;
    }

    public function get_content() {
        global $USER, $CFG, $COURSE;

        // If content is cached
        if ($this->content !== NULL) {
            return $this->content;
        }

        // Create empty content
        $this->content = new stdClass;
        $this->content->footer = '';

        // Don't display if completion isn't enabled!
        if (!$COURSE->enablecompletion) {
            return $this->content;
        }

        // Load criteria to display
        $info = new completion_info($COURSE);
        $completions = $info->get_completions($USER->id);

        // Check if this course has any criteria
        if (empty($completions)) {
            return $this->content;
        }

        // Check this user is enroled
        $users = $info->internal_get_tracked_users(true);
        if (!in_array($USER->id, array_keys($users))) {
            $this->content->text = get_string('notenroled', 'completion');
            return $this->content;
        }

        // Generate markup for criteria statuses
        $shtml = '<tr><td><b>'.get_string('requiredcriteria', 'completion').'</b></td><td style="text-align: right"><b>'.get_string('status').'</b></td></tr>';

        // For aggregating activity completion
        $activities = array();
        $activities_complete = 0;

        // Flag to set if current completion data is inconsistent with
        // what is stored in the database
        $pending_update = false;

        // Loop through course criteria
        foreach ($completions as $completion) {

            $criteria = $completion->get_criteria();
            $complete = $completion->is_complete();

            if (!$pending_update && $criteria->is_pending($completion)) {
                $pending_update = true;
            }

            // Activities are a special case, so cache them and leave them till last
            if ($criteria->criteriatype == COMPLETION_CRITERIA_TYPE_ACTIVITY) {
                $activities[$criteria->moduleinstance] = $complete;

                if ($complete) {
                    $activities_complete++;
                }

                continue;
            }

            $shtml .= '<tr><td>';
            $shtml .= $criteria->get_title();
            $shtml .= '</td><td style="text-align: right">';
            $shtml .= $completion->get_status();
            $shtml .= '</td></tr>';
        }

        // Aggregate activities
        if (!empty($activities)) {

            $shtml .= '<tr><td>';
            $shtml .= 'Activities complete';
            $shtml .= '</td><td style="text-align: right">';
            $shtml .= $activities_complete.' of '.count($activities);
            $shtml .= '</td></tr>';
        }

        // Display completion status
        $this->content->text  = '<table width="100%" style="font-size: 90%;"><tbody>';
        $this->content->text .= '<tr><td colspan="2"><b>'.get_string('status').':</b> ';

        // Is course complete?
        $coursecomplete = $info->is_course_complete($USER->id);

        // Has this user completed any criteria?
        $criteriacomplete = $info->count_course_user_data($USER->id);

        if ($pending_update) {
            $this->content->text .= '<i>'.get_string('pending', 'completion').'</i>';
        } else if ($coursecomplete) {
            $this->content->text .= get_string('complete');
        } else if (!$criteriacomplete) {
            $this->content->text .= '<i>'.get_string('notyetstarted', 'completion').'</i>';
        } else {
            $this->content->text .= '<i>'.get_string('inprogress','completion').'</i>';
        }

        $this->content->text .= '</td></tr>';
        $this->content->text .= '<tr><td colspan="2">';

        // Get overall aggregation method
        $overall = $info->get_aggregation_method();

        if ($overall == COMPLETION_AGGREGATION_ALL) {
            $this->content->text .= get_string('criteriarequiredall', 'completion');
        } else {
            $this->content->text .= get_string('criteriarequiredany', 'completion');
        }

        $this->content->text .= ':</td></tr>'.$shtml.'</tbody></table>';

        // Display link to detailed view
        $this->content->footer = '<br><a href="'.$CFG->wwwroot.'/blocks/completionstatus/details.php?course='.$COURSE->id.'">More details</a>';

        return $this->content;
    }
}