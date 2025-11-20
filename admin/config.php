<?php
// konfiguracja
return [
    // ustaw swoje hasło admina → wpisz normalne hasło tutaj i uruchom /admin/login.php, ono przekształci na hash
    'admin_password_hash' => password_hash("twoje_haslo_tutaj", PASSWORD_BCRYPT),
    'db_file' => __DIR__ . '/db.sqlite'
];
