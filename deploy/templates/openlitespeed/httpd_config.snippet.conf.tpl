# Snippets to merge into {{OLS_ROOT}}/conf/httpd_config.conf (issue #21).
# Webuzo owns this file: prefer creating the domain in Webuzo, then compare
# its generated config with these blocks. Back the file up before editing and
# restart with `{{OLS_ROOT}}/bin/lswsctrl restart`.

# 1) Register the virtual host.
virtualhost {{OLS_VHOST_NAME}} {
  vhRoot                  {{APP_ROOT}}/
  configFile              {{OLS_ROOT}}/conf/vhosts/{{OLS_VHOST_NAME}}/vhconf.conf
  allowSymbolLink         1      # `current` is a symlink to releases/<id>
  enableScript            1
  restrained              1      # scripts can't reach outside vhRoot
  setUIDMode              2      # run PHP as the docroot owner ({{APP_USER}})
}

# 2) Map the domain on the existing listeners (add one line inside each block,
#    keep the WordPress maps that are already there).
#
# listener HTTP {         # *:80
#   ...
#   map                   {{OLS_VHOST_NAME}} {{APP_DOMAIN}}
# }
# listener HTTPS {        # *:443
#   ...
#   map                   {{OLS_VHOST_NAME}} {{APP_DOMAIN}}
# }
