<?php

return [
    'name' => '2026_06_21_195650_create_report_uploaders',
    'up' => <<<'SQL'
CREATE TABLE IF NOT EXISTS report_uploaders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    secret_key VARCHAR(255) NOT NULL UNIQUE,
    uploader VARCHAR(255) NOT NULL
);

INSERT IGNORE INTO report_uploaders (secret_key, uploader)
VALUES ('temp', 'Default uploader');
SQL,
];
