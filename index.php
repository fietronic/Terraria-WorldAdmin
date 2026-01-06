<?php
session_start();

$config = require __DIR__ . '/config.php';

const SESSION_KEY = 'terraria_admin_authenticated_at';
const SESSION_TTL = 28800; // 8 hours

function currentUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($_SERVER['REQUEST_SCHEME'])) {
        $scheme = $_SERVER['REQUEST_SCHEME'];
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';

    return $scheme . '://' . $host . $uri;
}

function saveConfig(array $config): void
{
    $export = var_export($config, true);
    $contents = "<?php\nreturn {$export};\n";
    file_put_contents(__DIR__ . '/config.php', $contents);
}

function isAuthenticated(array $config): bool
{
    if (empty($config['admin_password_hash'])) {
        return false;
    }

    if (!isset($_SESSION[SESSION_KEY])) {
        return false;
    }

    if (time() - $_SESSION[SESSION_KEY] > SESSION_TTL) {
        unset($_SESSION[SESSION_KEY]);
        return false;
    }

    return true;
}

function requireAuth(array $config): void
{
    if (!isAuthenticated($config)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Authentication required.']);
        exit;
    }

    $_SESSION[SESSION_KEY] = time();
}

function loadServers(array $config): array
{
    if (!file_exists($config['data_file'])) {
        file_put_contents($config['data_file'], json_encode([], JSON_PRETTY_PRINT));
    }

    $json = file_get_contents($config['data_file']);
    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

function saveServers(array $config, array $servers): void
{
    file_put_contents($config['data_file'], json_encode($servers, JSON_PRETTY_PRINT));
}

function serverSessionName(array $server, array $config): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_]+/', '_', $server['world_name']);
    return rtrim($config['tmux_prefix'], '_') . '_' . strtolower($safe);
}

function tmuxHasSession(string $session, array $config): bool
{
    $command = 'tmux has-session -t ' . escapeshellarg($session) . ' 2>/dev/null';
    exec($command, $output, $code);
    return $code === 0;
}

function runCommand(string $command): array
{
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);

    return [
        'command' => $command,
        'output' => $output,
        'code' => $code,
    ];
}

function tmuxSend(string $session, string $command, array $config): array
{
    $prefix = 'tmux send-keys -t ' . escapeshellarg($session) . ' ';
    $enter = runCommand($prefix . 'Enter');
    $send = runCommand($prefix . escapeshellarg($command) . ' Enter');

    return [
        'output' => $send['output'],
        'code' => $send['code'],
        'command' => $send['command'],
        'debug' => [$enter, $send],
    ];
}

function appendDebug(array &$response, array $entries): void
{
    if (empty($entries)) {
        return;
    }

    if (!isset($response['debug'])) {
        $response['debug'] = [];
    }

    $response['debug'] = array_merge($response['debug'], $entries);
}

function buildStartCommand(array $server, array $config): string
{
    $binary = rtrim($config['terraria_dir'], '/') . '/' . $config['server_binary'];
    $args = [
        '-port ' . (int) $server['port'],
        '-maxplayers ' . (int) ($server['max_players'] ?? $config['default_max_players']),
    ];

    $worldPath = $server['world_path'];
    if (file_exists($worldPath)) {
        $args[] = '-world ' . escapeshellarg($worldPath);
    } else {
        $args[] = '-autocreate ' . (int) $server['autocreate_size'];
        $args[] = '-worldname ' . escapeshellarg($server['world_name']);
        $args[] = '-worldpath ' . escapeshellarg(dirname($worldPath));
        if (!empty($server['seed'])) {
            $args[] = '-seed ' . escapeshellarg($server['seed']);
        }
    }

    if (!empty($server['password'])) {
        $args[] = '-pass ' . escapeshellarg($server['password']);
    }

    if (!empty($server['motd'])) {
        $args[] = '-motd ' . escapeshellarg($server['motd']);
    }

    return escapeshellarg($binary) . ' ' . implode(' ', $args);
}

function startServer(array $server, array $config): array
{
    $session = serverSessionName($server, $config);
    if (tmuxHasSession($session, $config)) {
        return ['message' => 'Server already running.', 'session' => $session];
    }

    $command = buildStartCommand($server, $config);
    $wrapper = 'tmux new-session -d -s ' . escapeshellarg($session) . ' "bash -lc ' . escapeshellarg($command) . '"';
    $result = runCommand($wrapper);

    return [
        'message' => $result['code'] === 0 ? 'Server starting via tmux session ' . $session : 'Failed to start server',
        'output' => $result['output'],
        'session' => $session,
        'debug' => [$result],
    ];
}

function stopServer(array $server): array
{
    $config = $GLOBALS['config'];
    $session = serverSessionName($server, $config);
    if (!tmuxHasSession($session, $config)) {
        return ['message' => 'Server already stopped.'];
    }

    $exitResponse = tmuxSend($session, 'exit', $config);
    $kill = runCommand('tmux kill-session -t ' . escapeshellarg($session));

    return [
        'message' => 'Server stopped.',
        'session' => $session,
        'debug' => array_merge($exitResponse['debug'] ?? [], [$kill]),
    ];
}

function validatePort(int $port): bool
{
    $blocked = [22, 80, 443, 3306, 5432, 6379];
    return $port > 1024 && !in_array($port, $blocked, true);
}

function ensureServerData(array $config): array
{
    $servers = loadServers($config);
    foreach ($servers as &$server) {
        $server['world_path'] = $server['world_path'] ?? rtrim($config['worlds_dir'], '/') . '/' . $server['world_name'] . '.wld';
        $server['max_players'] = $server['max_players'] ?? $config['default_max_players'];
    }

    saveServers($config, $servers);

    return $servers;
}

function handleRequest(array $config): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['action'])) {
        return;
    }

    $action = $_POST['action'];
    $response = ['success' => false];

    if ($action === 'set_password') {
        if (!empty($config['admin_password_hash'])) {
            $response['message'] = 'Password already configured.';
        } else {
            $password = $_POST['password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            if ($password === '' || $confirm === '') {
                $response['message'] = 'Password and confirmation are required.';
            } elseif ($password !== $confirm) {
                $response['message'] = 'Passwords do not match.';
            } else {
                $config['admin_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                saveConfig($config);
                $_SESSION[SESSION_KEY] = time();
                $response['success'] = true;
                $response['message'] = 'Password set. Session created.';
                $response['redirect'] = currentUrl();
            }
        }

        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    if (empty($config['admin_password_hash'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Admin password must be set first.']);
        exit;
    }

    if ($action === 'logout') {
        session_unset();
        session_destroy();
        session_start();
        $response['success'] = true;
        $response['message'] = 'Logged out.';
        $response['redirect'] = currentUrl();

        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    if ($action === 'login') {
        $password = $_POST['password'] ?? '';
        if (password_verify($password, $config['admin_password_hash'])) {
            $_SESSION[SESSION_KEY] = time();
            $response['success'] = true;
            $response['message'] = 'Login successful.';
            $response['redirect'] = currentUrl();
        } else {
            $response['message'] = 'Invalid password.';
        }

        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    requireAuth($config);

    $servers = ensureServerData($config);
    $id = $_POST['id'] ?? null;

    if ($id !== null && isset($servers[$id])) {
        $server = &$servers[$id];
    }

    switch ($action) {
        case 'toggle':
            if (!isset($server)) {
                break;
            }
            if (tmuxHasSession(serverSessionName($server, $config), $config)) {
                $response = stopServer($server);
            } else {
                $response = startServer($server, $config);
            }
            $response['running'] = tmuxHasSession(serverSessionName($server, $config), $config);
            $response['success'] = true;
            break;
        case 'save':
            if (!isset($server)) {
                break;
            }
            $response = tmuxSend(serverSessionName($server, $config), 'save', $config);
            $response['message'] = 'Save command sent to server.';
            $response['success'] = true;
            break;
        case 'time':
            if (!isset($server) || empty($_POST['time'])) {
                break;
            }
            $response = tmuxSend(serverSessionName($server, $config), $_POST['time'], $config);
            $response['message'] = ucfirst($_POST['time']) . ' command sent to server.';
            $response['success'] = true;
            break;
        case 'edit':
            if (!isset($server)) {
                break;
            }
            $port = (int) ($_POST['port'] ?? $server['port']);
            if (!validatePort($port)) {
                $response['message'] = 'Please choose a non-standard port above 1024.';
                break;
            }

            $server['motd'] = trim($_POST['motd'] ?? '') ?: '';
            $server['password'] = trim($_POST['password'] ?? '');
            $server['port'] = $port;

            saveServers($config, $servers);

            $wasRunning = tmuxHasSession(serverSessionName($server, $config), $config);
            if ($wasRunning) {
                $stopResponse = stopServer($server);
                appendDebug($response, $stopResponse['debug'] ?? []);
                $startResponse = startServer($server, $config);
                appendDebug($response, $startResponse['debug'] ?? []);
            }

            $response['success'] = true;
            $response['message'] = 'Server settings saved' . ($wasRunning ? ' and server restarted.' : '.');
            $response['running'] = tmuxHasSession(serverSessionName($server, $config), $config);
            break;
        case 'create':
            $worldName = trim($_POST['world_name'] ?? '');
            $size = $_POST['world_size'] ?? 'small';
            $password = trim($_POST['password'] ?? '');
            $port = (int) ($_POST['port'] ?? $config['default_port']);
            $motd = trim($_POST['motd'] ?? '');
            $seed = trim($_POST['seed'] ?? '');

            if ($worldName === '') {
                $response['message'] = 'World name is required.';
                break;
            }
            if ($config['require_password'] && $password === '') {
                $response['message'] = 'Password is required by configuration.';
                break;
            }
            if (!validatePort($port)) {
                $response['message'] = 'Please choose a non-standard port above 1024.';
                break;
            }

            $sizeMap = ['small' => 1, 'medium' => 2, 'large' => 3];
            $autocreate = $sizeMap[$size] ?? 1;
            $worldPath = rtrim($config['worlds_dir'], '/') . '/' . $worldName . '.wld';

            $servers[] = [
                'world_name' => $worldName,
                'world_size' => $size,
                'autocreate_size' => $autocreate,
                'world_path' => $worldPath,
                'password' => $password,
                'port' => $port,
                'motd' => $motd,
                'seed' => $seed,
                'max_players' => $config['default_max_players'],
            ];

            saveServers($config, $servers);
            $response['success'] = true;
            $response['message'] = 'Server added. Use start to create or load the world.';
            break;
        default:
            $response['message'] = 'Unknown action.';
    }

    if (!isset($response['success'])) {
        $response['success'] = false;
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

$needsPassword = empty($config['admin_password_hash']);
handleRequest($config);
$authenticated = isAuthenticated($config);
$servers = $authenticated ? ensureServerData($config) : [];

function isRunning(array $server): bool
{
    $config = $GLOBALS['config'];
    return tmuxHasSession(serverSessionName($server, $config), $config);
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terraria Server Admin</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
<header>
    <h1>Terraria World Admin</h1>
    <?php if ($authenticated): ?>
        <a href="#" id="logout-link" class="logout-link">Logout</a>
    <?php endif; ?>
</header>
<?php if ($needsPassword): ?>
<div class="container">
    <div class="card full-width">
        <h2>Set Admin Password</h2>
        <p class="meta">A password is required before managing servers. Store the hash securely in config.php.</p>
        <form id="set-password-form">
            <label for="new-password">New Password</label>
            <input type="password" id="new-password" name="password" required>
            <label for="confirm-password">Confirm Password</label>
            <input type="password" id="confirm-password" name="confirm_password" required>
            <button type="submit" class="btn-green full-width">Save Password</button>
        </form>
        <div id="auth-status" class="meta"></div>
    </div>
</div>
<?php elseif (!$authenticated): ?>
<div class="container">
    <div class="card full-width">
        <h2>Login</h2>
        <p class="meta">Session lasts 8 hours.</p>
        <form id="login-form">
            <label for="login-password">Password</label>
            <input type="password" id="login-password" name="password" required>
            <button type="submit" class="btn-blue full-width">Login</button>
        </form>
        <div id="auth-status" class="meta"></div>
    </div>
</div>
<?php else: ?>
<div class="container">
    <?php foreach ($servers as $id => $server): $running = isRunning($server); ?>
        <div
            class="card"
            data-id="<?= $id ?>"
            data-motd="<?= htmlspecialchars($server['motd'] ?? '', ENT_QUOTES) ?>"
            data-password="<?= htmlspecialchars($server['password'] ?? '', ENT_QUOTES) ?>"
            data-port="<?= (int)$server['port'] ?>"
            data-maxplayers="<?= (int)$server['max_players'] ?>"
        >
            <div>
                <h2><?= htmlspecialchars($server['world_name']) ?></h2>
                <div class="meta">Port <?= (int)$server['port'] ?> · Max <?= (int)$server['max_players'] ?></div>
                <div class="status <?= $running ? 'active' : 'inactive' ?>">
                    <?= $running ? 'Active' : 'Inactive' ?>
                </div>
            </div>
            <div class="actions">
                <button class="toggle btn-<?= $running ? 'green' : 'gray' ?>"><?= $running ? 'Stop' : 'Start' ?></button>
                <button class="save btn-blue">Save</button>
                <button class="time btn-orange">Time</button>
                <button class="edit btn-red">Edit</button>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="card add-card" id="add-card">+</div>
</div>

<div class="overlay" id="time-overlay">
    <div class="modal">
        <div class="close" data-close>✖</div>
        <h3>Select Time</h3>
        <div class="actions time-actions">
            <button data-time="dawn">Dawn</button>
            <button data-time="noon">Noon</button>
            <button data-time="dusk">Dusk</button>
            <button data-time="midnight">Midnight</button>
        </div>
    </div>
</div>

<div class="overlay" id="edit-overlay">
    <div class="modal">
        <div class="close" data-close>✖</div>
        <h3>Edit Server</h3>
        <form id="edit-form">
            <input type="hidden" name="id" id="edit-id">
            <label for="edit-motd">MOTD</label>
            <input type="text" name="motd" id="edit-motd">
            <label for="edit-port">Port</label>
            <input type="number" name="port" id="edit-port" min="1025">
            <label for="edit-password">Password</label>
            <input type="text" name="password" id="edit-password">
            <p class="warning-text">Changing the port will restart the server.</p>
            <button type="submit" class="btn-blue full-width">Save Changes</button>
        </form>
    </div>
</div>

<div class="overlay" id="create-overlay">
    <div class="modal">
        <div class="close" data-close>✖</div>
        <h3>Create Server</h3>
        <form id="create-form">
            <label for="world-size">World Size*</label>
            <select name="world_size" id="world-size" required>
                <option value="small">Small</option>
                <option value="medium">Medium</option>
                <option value="large">Large</option>
            </select>
            <label for="world-name">World Name*</label>
            <input type="text" name="world_name" id="world-name" required>
            <label for="create-password">Password<?= $config['require_password'] ? '*' : '' ?></label>
            <input type="text" name="password" id="create-password" <?= $config['require_password'] ? 'required' : '' ?> >
            <label for="create-port">Port</label>
            <input type="number" name="port" id="create-port" value="<?= (int)$config['default_port'] ?>" min="1025">
            <label for="create-motd">MOTD</label>
            <input type="text" name="motd" id="create-motd">
            <label for="create-seed">Seed</label>
            <input type="text" name="seed" id="create-seed">
            <button type="submit" class="btn-green full-width">Add Server</button>
        </form>
    </div>
</div>

<script>
    const serverCards = document.querySelectorAll('.card[data-id]');
    let activeServerId = null;

    function logDebug(resp) {
        if (resp && resp.debug) {
            console.log('Debug output:', resp.debug);
        }
    }

    function post(action, data = {}) {
        const formData = new FormData();
        formData.append('action', action);
        Object.entries(data).forEach(([key, value]) => formData.append(key, value));

        return fetch('', { method: 'POST', body: formData })
            .then(res => res.json());
    }

    function refreshCard(card, running) {
        const status = card.querySelector('.status');
        status.textContent = running ? 'Active' : 'Inactive';
        status.classList.toggle('active', running);
        status.classList.toggle('inactive', !running);
        const toggle = card.querySelector('.toggle');
        toggle.textContent = running ? 'Stop' : 'Start';
        toggle.classList.toggle('btn-green', running);
        toggle.classList.toggle('btn-gray', !running);
    }

    serverCards.forEach(card => {
        const id = card.getAttribute('data-id');

        card.querySelector('.toggle').addEventListener('click', () => {
            post('toggle', { id }).then(resp => {
                if (resp.success) {
                    refreshCard(card, !!resp.running);
                }
                logDebug(resp);
            });
        });

        card.querySelector('.save').addEventListener('click', () => {
            post('save', { id }).then(logDebug);
        });

        card.querySelector('.time').addEventListener('click', () => {
            activeServerId = id;
            openOverlay('time-overlay');
        });

        card.querySelector('.edit').addEventListener('click', () => {
            activeServerId = id;
            document.getElementById('edit-id').value = id;
            document.getElementById('edit-motd').value = card.dataset.motd || '';
            document.getElementById('edit-port').value = card.dataset.port || '';
            document.getElementById('edit-password').value = card.dataset.password || '';
            openOverlay('edit-overlay');
        });
    });

    function openOverlay(id) {
        document.getElementById(id).style.display = 'flex';
    }

    function closeOverlay(evt) {
        if (evt.target.classList.contains('overlay') || evt.target.dataset.close !== undefined) {
            evt.target.closest('.overlay').style.display = 'none';
        }
    }

    document.querySelectorAll('.overlay').forEach(overlay => overlay.addEventListener('click', closeOverlay));

    document.querySelectorAll('#time-overlay [data-time]').forEach(btn => {
        btn.addEventListener('click', () => {
            post('time', { id: activeServerId, time: btn.dataset.time }).then(logDebug);
            document.getElementById('time-overlay').style.display = 'none';
        });
    });

    document.getElementById('edit-form').addEventListener('submit', evt => {
        evt.preventDefault();
        const fd = new FormData(evt.target);
        fd.append('action', 'edit');
        const data = Object.fromEntries(fd.entries());
        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(resp => {
                const card = document.querySelector(`.card[data-id="${data.id}"]`);
                if (card) {
                    card.dataset.motd = data.motd || '';
                    card.dataset.port = data.port || '';
                    card.dataset.password = data.password || '';
                    const max = card.dataset.maxplayers || '';
                    card.querySelector('.meta').textContent = `Port ${data.port} · Max ${max}`;
                }
                document.getElementById('edit-overlay').style.display = 'none';
                logDebug(resp);
            });
    });

    document.getElementById('add-card').addEventListener('click', () => openOverlay('create-overlay'));

    document.getElementById('create-form').addEventListener('submit', evt => {
        evt.preventDefault();
        const fd = new FormData(evt.target);
        fd.append('action', 'create');
        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(resp => {
                if (resp.success) {
                    window.location.reload();
                }
        });
    });

    const logoutLink = document.getElementById('logout-link');
    if (logoutLink) {
        logoutLink.addEventListener('click', evt => {
            evt.preventDefault();
            post('logout').then(resp => {
                if (resp.success && resp.redirect) {
                    window.location.href = resp.redirect;
                }
            });
        });
    }
</script>
<?php endif; ?>

<script>
    const passwordForm = document.getElementById('set-password-form');
    const loginForm = document.getElementById('login-form');
    const authStatus = document.getElementById('auth-status');

    function authPost(action, form) {
        const fd = new FormData(form);
        fd.append('action', action);
        fetch('', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(resp => {
                if (authStatus) {
                    authStatus.textContent = resp.message || 'Request complete';
                }
                if (resp.success) {
                    const target = resp.redirect || window.location.href;
                    window.location.replace(target);
                }
            })
            .catch(() => window.location.reload());
    }

    if (passwordForm) {
        passwordForm.addEventListener('submit', evt => {
            evt.preventDefault();
            authPost('set_password', passwordForm);
        });
    }

    if (loginForm) {
        loginForm.addEventListener('submit', evt => {
            evt.preventDefault();
            authPost('login', loginForm);
        });
    }
</script>
</body>
</html>
