<?php
// Copy this file to config.php (gitignored) and fill in real values.
// Gmail: use an App Password, not your normal password.
// https://myaccount.google.com/apppasswords (requires 2-Step Verification on).

return [
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_user' => 'your-email@gmail.com',
    'smtp_pass' => 'your-app-password-here',
    'from_email' => 'your-email@gmail.com',
    'from_name'  => 'EduTrack',

    'db_host' => 'localhost',
    'db_name' => 'edutrack',
    'db_user' => 'root',
    'db_pass' => '',

    'guard_passphrase' => 'change-me',
];
