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
 * Site-level default settings for tool_activitydates.
 *
 * @package    tool_activitydates
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('tool_activitydates_settings', new lang_string('pluginname', 'tool_activitydates'));

    $settings->add(new admin_setting_configtext(
        'tool_activitydates/sessionlength',
        get_string('sessionlength', 'tool_activitydates'),
        get_string('sessionlength_help', 'tool_activitydates'),
        '7',
        PARAM_INT,
        3
    ));

    $settings->add(new admin_setting_configtext(
        'tool_activitydates/activitiespersession',
        get_string('activitiespersession', 'tool_activitydates'),
        get_string('activitiespersession_help', 'tool_activitydates'),
        '2',
        PARAM_INT,
        3
    ));

    $settings->add(new admin_setting_configcheckbox(
        'tool_activitydates/stayavailable',
        get_string('stayavailable', 'tool_activitydates'),
        get_string('stayavailable_help', 'tool_activitydates'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'tool_activitydates/hideunselected',
        get_string('hideunselected', 'tool_activitydates'),
        get_string('hideunselected_help', 'tool_activitydates'),
        0
    ));

    $ADMIN->add('tools', $settings);
}
