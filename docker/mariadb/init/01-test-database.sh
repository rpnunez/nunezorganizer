#!/bin/bash
# Runs once, when the MariaDB volume is first created. Creates the database the test
# suite uses (phpunit.xml forces DB_DATABASE=nunezorganizer_test) and grants it to the
# app user from .env (DB_USERNAME -> MARIADB_USER in docker-compose.yml).
# Not executable on purpose: the entrypoint then sources it, which provides docker_process_sql.

docker_process_sql --database=mysql <<-EOSQL
	CREATE DATABASE IF NOT EXISTS \`nunezorganizer_test\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
	GRANT ALL PRIVILEGES ON \`nunezorganizer_test\`.* TO '${MARIADB_USER}'@'%';
	FLUSH PRIVILEGES;
EOSQL
