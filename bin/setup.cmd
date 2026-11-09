@echo off
rem Wrapper for setup.php on Windows. Uses %PHP% if set, e.g.: set PHP=C:\php84\php.exe
rem Docker: docker compose exec app php setup.php
if "%PHP%"=="" set PHP=php
"%PHP%" "%~dp0..\setup.php" %*
