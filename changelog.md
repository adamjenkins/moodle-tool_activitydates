# Changelog

All notable changes to `tool_activitydates` are documented in this file.

## [0.1.0] - 2026-07-11

### Added

- Initial scaffold of the plugin: version metadata, `tool/activitydates:manage` capability, site default settings (session length, activities per session, stay available, hide unselected), course-navigation link, GDPR null privacy provider, and language strings.
- The session-scheduling approach is adapted from [Driprelease](https://moodle.org/plugins/tool_driprelease) by Marcus Green: activities of a course are split into fixed-length sessions and each session is given a start/finish window. Unlike Driprelease, which controls access with availability restrictions, Activity dates writes each activity's own open/close date fields directly.
