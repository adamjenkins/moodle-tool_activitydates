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

namespace tool_activitydates\local;

/**
 * The button row shared by the Activity dates and Grade locks forms.
 *
 * For use in a moodleform subclass only: it reads the form's $_form.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait action_buttons {
    /**
     * Add "Save and return to course", "Save and display" and "Cancel"
     * buttons, mirroring the standard activity module forms.
     *
     * @param bool $cancel whether to show a cancel button.
     * @param string|null $submitlabel label for the save-and-display button.
     * @param string|null $submit2label label for the save-and-return button.
     */
    public function add_action_buttons($cancel = true, $submitlabel = null, $submit2label = null) {
        if (is_null($submitlabel)) {
            $submitlabel = get_string('savechangesanddisplay');
        }
        if (is_null($submit2label)) {
            $submit2label = get_string('savechangesandreturntocourse');
        }
        $mform = $this->_form;

        $buttonarray = [];
        $buttonarray[] = $mform->createElement('submit', 'submitbutton2', $submit2label);
        if ($submitlabel !== false) {
            $buttonarray[] = $mform->createElement('submit', 'submitbutton', $submitlabel);
        }
        if ($cancel) {
            $buttonarray[] = $mform->createElement('cancel');
        }
        $mform->addGroup($buttonarray, 'buttonar', '', [' '], false);
        $mform->setType('buttonar', PARAM_RAW);
        // Outside the last section, which may be collapsed.
        $mform->closeHeaderBefore('buttonar');
    }
}
