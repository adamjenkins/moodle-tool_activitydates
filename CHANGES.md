# Changes

## v1.0.0

First stable release.

- Security: the form header now shows the course short name through
  `format_string()` instead of raw, so any markup in it is cleaned before
  output.
- Fixed: the activity table, and the sessions dates are assigned from, now
  follow the course page. Activities inside a subsection are listed where the
  subsection sits instead of after every other section.
- Maturity is now stable.
