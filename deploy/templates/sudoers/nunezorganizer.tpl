# /etc/sudoers.d/{{APP_SLUG}} (0440, validated with visudo -c) — issue #21.
# Lets the app user restart OpenLiteSpeed after a release swap so LSPHP drops
# cached paths of the old release. Nothing else. Delete this file and set
# WEB_RELOAD_CMD="" in deploy.env if you prefer restarting by hand.
{{APP_USER}} ALL=(root) NOPASSWD: {{OLS_ROOT}}/bin/lswsctrl restart
