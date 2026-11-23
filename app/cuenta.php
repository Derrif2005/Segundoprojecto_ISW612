<?php
// Este archivo se ejecuta desde la terminal, fuera de la carpeta pública.
declare(strict_types=1);
// Valida nombre y rol antes de pedir la contraseña por entrada estándar.
$name = $argv[1] ?? '';
$role = $argv[2] ?? '';
if (!preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $name) || !in_array($role, ['admin', 'user'], true)) {
    exit("Uso: php cuenta.php NOMBRE admin|user\n");
}
// Lee una contraseña suministrada por el script sin mostrarla.
$password = rtrim((string) fgets(STDIN), "\r\n");
if (strlen($password) < 12 || strlen($password) > 72) {
    exit("La contraseña debe tener entre 12 y 72 bytes.\n");
}
// Crea las tablas si todavía no existen.
$db = new PDO('sqlite:' . (getenv('CHAT_DB') ?: '/srv/ia/app/chat.sqlite'));
$db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE, hash TEXT NOT NULL, role TEXT NOT NULL, blocked INTEGER NOT NULL DEFAULT 0, failures INTEGER NOT NULL DEFAULT 0, locked_until INTEGER NOT NULL DEFAULT 0)');
$db->exec('CREATE TABLE IF NOT EXISTS messages (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id), question TEXT NOT NULL, answer TEXT NOT NULL)');
// Guarda el hash bcrypt y omite contraseñas en texto claro.
$statement = $db->prepare('INSERT INTO users (name, hash, role) VALUES (?, ?, ?)');
$statement->execute([$name, password_hash($password, PASSWORD_BCRYPT), $role]);
echo "Cuenta creada.\n";
