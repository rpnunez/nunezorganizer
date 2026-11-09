-- Separate database for the automated test suite (phpunit forces DB_DATABASE=nunezorganizer_test).
CREATE DATABASE IF NOT EXISTS `nunezorganizer_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `nunezorganizer_test`.* TO 'nunezorganizer'@'%';
FLUSH PRIVILEGES;
