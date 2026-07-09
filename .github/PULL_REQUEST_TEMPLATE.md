## Summary

<!-- What does this change do, and why? -->

## Type of change

- [ ] Bug fix
- [ ] New feature
- [ ] Documentation
- [ ] Refactor / cleanup
- [ ] CI / build

## Checklist

- [ ] Followed [Moodle coding style](https://moodledev.io/general/development/policies/codingstyle); `moodle-plugin-ci phpcs` passes locally
- [ ] No hardcoded user-facing strings — added/updated entries in `lang/en/tiny_authory_tech.php` via `get_string()`
- [ ] If `amd/src/*.js` changed: rebuilt `amd/build/` via Grunt and committed both (see `CLAUDE.md` for the Docker build steps) — never hand-edited `amd/build/`
- [ ] If the DB schema changed: bumped `version.php` and added an upgrade step with a savepoint in `db/upgrade.php`
- [ ] If a new web service function was added: declared it in both `db/services.php` and `externallib.php`
- [ ] If a new capability/event/hook was added: declared it in `db/access.php` / `db/events.php` / `db/hooks.php`
- [ ] Tested against the Docker dev environment (`docker compose up -d`) where applicable

## Testing

<!-- How did you verify this? Manual steps, screenshots, or which automated checks cover it. -->
