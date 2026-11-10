# /etc/sudoers.d/{{APP_SLUG}} (0440, validated with visudo -c) — issue #21.
# Lets the app user run exactly one privileged command after a release swap, so
# PHP drops cached paths of the old release (WEB_RELOAD_PRIVILEGED_CMD in deploy.env).
# Nothing else. Delete this file and set WEB_RELOAD_CMD="" if you reload by hand.
{{APP_USER}} ALL=(root) NOPASSWD: {{WEB_RELOAD_PRIVILEGED_CMD}}
