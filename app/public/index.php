<?php
declare(strict_types=1);

// Configura una cookie de sesión que JavaScript no puede leer.
session_set_cookie_params(['secure' => !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https', 'httponly' => true, 'samesite' => 'Lax']);
// Recupera la sesión y los permisos del usuario actual.
session_start();
// Abre la base local y activa excepciones para errores de SQLite.
$db = new PDO('sqlite:' . (getenv('CHAT_DB') ?: '/srv/ia/app/chat.sqlite'), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// Espera hasta tres segundos si otra solicitud está escribiendo.
$db->exec('PRAGMA busy_timeout = 3000');
// Ejecuta consultas con parámetros para tratar la entrada como datos.
function query(PDO $db, string $sql, array $values = []): PDOStatement {
    // Prepara el SQL y luego le asigna los valores.
    $statement = $db->prepare($sql);
    $statement->execute($values);
    return $statement;
}
// Escapa el contenido antes de imprimirlo en HTML.
function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
// Limpia la sesión si pasó media hora sin actividad.
if (isset($_SESSION['time']) && time() - $_SESSION['time'] > 1800) {
    $_SESSION = [];
    session_regenerate_id(true);
}
// Genera el token que comprueba que un formulario salió de esta sesión.
$_SESSION['token'] ??= bin2hex(random_bytes(32));
// Vuelve a consultar permisos y bloqueo en cada solicitud.
$user = isset($_SESSION['id']) ? query($db, 'SELECT * FROM users WHERE id = ? AND blocked = 0', [$_SESSION['id']])->fetch() : false;
if ($user) {
    $_SESSION['time'] = time();
}
// Separa el mensaje de error de las acciones del formulario.
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rechaza formularios con token inválido.
    if (!is_string($_POST['token'] ?? null) || !hash_equals($_SESSION['token'], $_POST['token'])) {
        http_response_code(403);
        exit('Formulario inválido.');
    }
    // Lee la acción enviada por el formulario.
    $action = $_POST['action'] ?? '';
    if ($action === 'login' && !$user) {
        // Limita el tamaño de los datos antes de consultar la cuenta.
        $name = is_string($_POST['name'] ?? null) ? substr(trim($_POST['name']), 0, 64) : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $account = query($db, 'SELECT * FROM users WHERE name = ?', [$name])->fetch();
        // Solo permite entrar si la cuenta está habilitada y la contraseña coincide.
        if ($account && !$account['blocked'] && $account['locked_until'] <= time() && password_verify($password, $account['hash'])) {
            query($db, 'UPDATE users SET failures = 0, locked_until = 0 WHERE id = ?', [$account['id']]);
            session_regenerate_id(true);
            $_SESSION = ['id' => $account['id'], 'time' => time()];
            header('Location: /');
            exit;
        }
        // Cuenta fallos y bloquea la cuenta temporalmente al quinto intento.
        if ($account && !$account['blocked'] && $account['locked_until'] <= time()) {
            query($db, 'UPDATE users SET locked_until = CASE WHEN failures >= 4 THEN ? ELSE 0 END, failures = (failures + 1) % 5 WHERE id = ? AND locked_until <= ?', [time() + 300, $account['id'], time()]);
        }
        // Registra el evento sin escribir la contraseña.
        error_log('ISW612 login_failed');
        $error = 'No se pudo iniciar sesión.';
    } elseif ($action === 'logout') {
        // Elimina el usuario de la sesión y vuelve al inicio.
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: /');
        exit;
    } elseif ($action === 'block' && ($user['role'] ?? '') === 'admin') {
        // Solo un administrador puede bloquear o desbloquear cuentas normales.
        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        query($db, "UPDATE users SET blocked = 1 - blocked WHERE id = ? AND role = 'user'", [$id]);
        header('Location: /?admin=1');
        exit;
    } elseif ($action === 'chat' && $user) {
        // Verifica el límite del mensaje antes de llamar a Ollama.
        $question = is_string($_POST['question'] ?? null) ? trim($_POST['question']) : '';
        if ($question === '' || strlen($question) > 2000) {
            $error = 'Escribí una pregunta de hasta 2000 bytes.';
        } else {
            // Envía únicamente el mensaje a la API local.
            $request = curl_init('http://127.0.0.1:11434/api/generate');
            curl_setopt_array($request, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['model' => getenv('CHAT_MODEL') ?: 'qwen2.5:1.5b', 'prompt' => $question, 'stream' => false, 'options' => ['num_predict' => 160]], JSON_INVALID_UTF8_SUBSTITUTE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 90,
            ]);
            // Lee la respuesta y guarda el turno bajo el usuario de la sesión.
            $body = curl_exec($request);
            $result = is_string($body) ? json_decode($body, true) : null;
            if (curl_getinfo($request, CURLINFO_RESPONSE_CODE) === 200 && is_string($result['response'] ?? null)) {
                query($db, 'INSERT INTO messages (user_id, question, answer) VALUES (?, ?, ?)', [$user['id'], $question, $result['response']]);
                header('Location: /');
                exit;
            }
            $error = 'Ollama no devolvió una respuesta.';
        }
    } else {
        // Rechaza operaciones que no corresponden al rol o a la sesión.
        http_response_code(403);
        exit('Acceso denegado.');
    }
}
// Verifica el acceso directo al panel antes de mostrar información.
$admin = isset($_GET['admin']);
if ($admin && ($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Acceso denegado.');
}
// Carga como máximo los últimos 30 turnos de la cuenta actual.
$history = $user ? query($db, 'SELECT question, answer FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$user['id']])->fetchAll() : [];
?>
<!doctype html>
<html lang="es">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Chat local ISW-612</title>
<style>
/* Colores y tipografía del ingreso, el chat y el panel. */
:root{color-scheme:dark;--bg:#10141a;--surface:#181e27;--raised:#202834;--ink:#f1f5f6;--muted:#a4afbe;--line:#323d4d;--accent:#a8edc0;--error:#ffb4ac;font:16px/1.55 system-ui,sans-serif}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;color:var(--ink);background:radial-gradient(ellipse at 12% 0%,#1b342f 0%,transparent 42%),var(--bg)}
a{color:var(--accent);text-underline-offset:.2em}
main{width:min(100% - 2rem,58rem);margin:clamp(2rem,8vw,6rem) auto;padding:clamp(1.35rem,5vw,3.5rem);border:1px solid var(--line);border-radius:1.5rem;background:var(--surface);box-shadow:0 24px 80px #0005}
main.login{max-width:31rem}
header{margin-bottom:2rem}
h1{margin:0;font-size:clamp(2rem,6vw,3.25rem);line-height:1.1;letter-spacing:-.055em}
header p,.identity{margin:.65rem 0 0;color:var(--muted)}
form{margin:0}
.stack{display:grid;gap:1rem}
label{display:grid;gap:.4rem;font-weight:650}
input,textarea,button{font:inherit}
input,textarea{width:100%;padding:.8rem 1rem;color:var(--ink);background:var(--bg);border:1px solid var(--line);border-radius:.75rem}
textarea{min-height:6rem;resize:vertical}
button{justify-self:start;border:1px solid transparent;border-radius:.75rem;padding:.65rem 1.15rem;background:var(--accent);color:#112219;font-weight:750;cursor:pointer}
button:hover{filter:brightness(1.1)}
button.secondary{border-color:var(--line);background:var(--raised);color:var(--ink)}
:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
.toolbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;border-bottom:1px solid var(--line);margin-bottom:1.5rem;padding-bottom:1rem}
.toolbar .identity{margin:0}
[role=alert]{padding:.75rem 1rem;border:1px solid #74453f;border-radius:.75rem;color:var(--error);background:#342322}
.history{margin-top:2rem}
.history>h2{font-size:1.15rem;letter-spacing:-.02em}
article{margin-bottom:1rem;padding:1rem 1.25rem;border:1px solid var(--line);border-radius:1rem;background:var(--raised);overflow-wrap:anywhere}
article h3{color:var(--accent);font-size:1rem;margin:0}
article p{margin:.5rem 0 0}
.accounts{padding:0;list-style:none}
.accounts li{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;padding:.8rem 0;border-bottom:1px solid var(--line)}
@media(max-width:32rem){main{margin:1rem auto;border-radius:1rem}.stack>button{justify-self:stretch}}
</style>
<main class="<?= $user ? 'workspace' : 'login' ?>">
<header>
<h1><?= $admin ? 'Administración' : 'Chat local ISW-612' ?></h1>
<?php if (!$user): ?><p>Ingresá para conversar con el asistente de la VM.</p><?php endif; ?>
</header>
<!-- Muestra errores y usa tokens de sesión en los formularios. -->
<?php if ($error !== ''): ?><p role="alert"><?= h($error) ?></p><?php endif; ?>
<?php if (!$user): ?>
<form class="stack" method="post">
<input type="hidden" name="action" value="login">
<input type="hidden" name="token" value="<?= h($_SESSION['token']) ?>">
<label>Usuario <input name="name" required maxlength="64"></label>
<label>Contraseña <input name="password" type="password" required></label>
<button>Entrar</button>
</form>
<?php else: ?>
<div class="toolbar"><p class="identity">Sesión de <?= h($user['name']) ?></p>
<form method="post">
<input type="hidden" name="action" value="logout">
<input type="hidden" name="token" value="<?= h($_SESSION['token']) ?>">
<button class="secondary">Salir</button>
</form></div>
<?php if ($admin): ?>
<!-- El panel cuenta turnos sin mostrar las conversaciones de otras cuentas. -->
<p><a href="/">Volver al chat</a></p>
<p>Turnos registrados: <?= (int) $db->query('SELECT COUNT(*) FROM messages')->fetchColumn() ?></p>
<ul class="accounts">
<?php foreach ($db->query('SELECT id, name, role, blocked FROM users ORDER BY name') as $account): ?>
<li><?= h($account['name']) ?> (<?= h($account['role']) ?>)
<?php if ($account['role'] === 'user'): ?>
<form method="post">
<input type="hidden" name="action" value="block">
<input type="hidden" name="token" value="<?= h($_SESSION['token']) ?>">
<input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
<button><?= $account['blocked'] ? 'Desbloquear' : 'Bloquear' ?></button>
</form>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
<?php else: ?>
<?php if ($user['role'] === 'admin'): ?><p><a href="/?admin=1">Administración</a></p><?php endif; ?>
<form class="stack" method="post">
<input type="hidden" name="action" value="chat">
<input type="hidden" name="token" value="<?= h($_SESSION['token']) ?>">
<label>Pregunta <textarea name="question" maxlength="2000" required></textarea></label>
<button>Enviar pregunta</button>
</form>
<!-- El historial ya viene filtrado por el usuario autenticado. -->
<?php if ($history): ?><section class="history" aria-label="Historial"><h2>Conversaciones recientes</h2>
<?php foreach ($history as $turn): ?>
<article><h3><?= h($turn['question']) ?></h3><p><?= nl2br(h($turn['answer'])) ?></p></article>
<?php endforeach; ?></section><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</main>
</html>
