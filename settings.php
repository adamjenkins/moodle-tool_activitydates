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
 * @copyright  2022 Marcus Green
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('tool_activitydates_settings', new lang_string('pluginname', 'tool_activitydates'));

    // Headings keep the dates defaults and the same-named grade-lock defaults apart.
    $settings->add(new admin_setting_heading(
        'tool_activitydates/datesettings',
        get_string('tabdates', 'tool_activitydates'),
        ''
    ));

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

    $modes = [
        'days' => get_string('mode_days', 'tool_activitydates'),
        'session' => get_string('mode_session', 'tool_activitydates'),
        'date' => get_string('mode_date', 'tool_activitydates'),
        'none' => get_string('mode_none', 'tool_activitydates'),
    ];

    $settings->add(new admin_setting_configcheckbox(
        'tool_activitydates/finishenabled',
        get_string('finishenabled', 'tool_activitydates'),
        get_string('configfinishenabled', 'tool_activitydates'),
        0
    ));

    $settings->add(new admin_setting_configselect(
        'tool_activitydates/duemode',
        get_string('duemode', 'tool_activitydates'),
        get_string('configduemode', 'tool_activitydates'),
        'none',
        $modes
    ));

    $settings->add(new admin_setting_configtext(
        'tool_activitydates/duedays',
        get_string('duedays', 'tool_activitydates'),
        '',
        '7',
        PARAM_INT,
        3
    ));

    $settings->add(new admin_setting_configselect(
        'tool_activitydates/closemode',
        get_string('closemode', 'tool_activitydates'),
        get_string('configclosemode', 'tool_activitydates'),
        'session',
        $modes
    ));

    $settings->add(new admin_setting_configtext(
        'tool_activitydates/closedays',
        get_string('closedays', 'tool_activitydates'),
        '',
        '7',
        PARAM_INT,
        3
    ));

    $settings->add(new admin_setting_configcheckbox(
        'tool_activitydates/hideunselected',
        get_string('hideunselected', 'tool_activitydates'),
        get_string('hideunselected_help', 'tool_activitydates'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'tool_activitydates/locksettings',
        get_string('locksettings', 'tool_activitydates'),
        get_string('locksettings_desc', 'tool_activitydates')
    ));

    $lockmodes = [
        'days' => get_string('mode_days', 'tool_activitydates'),
        'session' => get_string('mode_session', 'tool_activitydates'),
        'date' => get_string('mode_date', 'tool_activitydates'),
        'none' => get_string('lockmode_none', 'tool_activitydates'),
    ];

    $settings->add(new admin_setting_configselect(
        'tool_activitydates/lockmode',
        get_string('lockmode', 'tool_activitydates'),
        get_string('configlockmode', 'tool_activitydates'),
        'none',
        $lockmodes
    ));

    $settings->add(new admin_setting_configtext(
        'tool_activitydates/lockdays',
        get_string('lockdays', 'tool_activitydates'),
        '',
        '7',
        PARAM_INT,
        3
    ));

    $settings->add(new admin_setting_configcheckbox(
        'tool_activitydates/lockshownote',
        get_string('shownote', 'tool_activitydates'),
        get_string('shownote_desc', 'tool_activitydates'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'tool_activitydates/lockshownotecoursepage',
        get_string('shownotecoursepage', 'tool_activitydates'),
        get_string('shownotecoursepage_desc', 'tool_activitydates'),
        0
    ));

    $ADMIN->add('tools', $settings);
}
