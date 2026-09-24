<?php
// Copy this file OUTSIDE the web folder and fill in real values: under XAMPP
// to C:\xampp\edutrack-private\config.php, where Apache cannot serve it
// (api/db.php, config_path()). api/config.php (gitignored) also works.
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
];
