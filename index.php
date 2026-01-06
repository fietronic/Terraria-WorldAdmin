<?php
session_start();

$config = include __DIR__ . '/config.php';
$dataFile = $config['data_file'];

function loadData(string $file): array {
    if (!file_exists($file)) {
        return ['active_world' => null, 'servers' => []];
    }
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['active_world' => null, 'servers' => []];
    }
    $data['active_world'] = $data['active_world'] ?? null;
    $data['servers'] = $data['servers'] ?? [];
    return $data;
}

function saveData(string $file, array $data): void {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function saveConfig(array $config): void {
    $export = var_export($config, true);
    $content = "<?php\nreturn {$export};\n";
    file_put_contents(__DIR__ . '/config.php', $content);
}

function sanitizeSessionName(string $prefix, string $world): string {
    $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $world);
    return $prefix . '_' . $safe;
}

function tmuxSessionExists(string $session): bool {
    exec('tmux has-session -t ' . escapeshellarg($session) . ' 2>/dev/null', $out, $status);
    return $status === 0;
}

function sendTmuxCommand(string $session, string $command): void {
    $escaped = escapeshellarg($command);
    $cmd = sprintf("tmux send-keys -t %s Enter %s Enter", escapeshellarg($session), $escaped);
    exec($cmd);
}

function stopServer(string $session): bool {
    if (!tmuxSessionExists($session)) {
        return true;
    }
    sendTmuxCommand($session, 'exit');
    $waits = 0;
    while (tmuxSessionExists($session) && $waits < 30) {
        usleep(500000);
        $waits++;
    }
    return !tmuxSessionExists($session);
}

function startServer(array $config, array $server): bool {
    $session = sanitizeSessionName($config['tmux_prefix'], $server['world']);
    if (tmuxSessionExists($session)) {
        return true;
    }
    $binary = escapeshellcmd('./' . $config['server_binary']);
    $worldPath = escapeshellarg($server['path']);
    $port = (int) $server['port'];
    $maxPlayers = (int) $server['max_players'];
    $motd = escapeshellarg($server['motd']);
    $cmdParts = [$binary, '-world ' . $worldPath, '-port ' . $port, '-maxplayers ' . $maxPlayers, '-motd ' . $motd];
    if (!empty($server['password'])) {
        $cmdParts[] = '-password ' . escapeshellarg($server['password']);
    }
    $fullCommand = implode(' ', $cmdParts);
    $tmuxCmd = sprintf(
        'tmux new-session -d -s %s "cd %s && exec %s"',
        escapeshellarg($session),
        escapeshellarg($config['terraria_dir']),
        $fullCommand
    );
    exec($tmuxCmd, $output, $status);
    return $status === 0;
}

function syncWorlds(array $config, array &$data): void {
    $worldFiles = glob(rtrim($config['worlds_dir'], '/\\') . '/*.wld');
    $existing = [];
    foreach ($worldFiles as $file) {
        $name = basename($file, '.wld');
        $existing[$name] = $file;
        if (!isset($data['servers'][$name])) {
            $data['servers'][$name] = [
                'world' => $name,
                'path' => $file,
                'motd' => 'Welcome to ' . $name,
                'port' => $config['default_port'],
                'max_players' => $config['default_max_players'],
                'password' => $config['default_password'],
            ];
        } else {
            $data['servers'][$name]['path'] = $file;
        }
    }

    // Remove entries that no longer exist
    foreach (array_keys($data['servers']) as $name) {
        if (!isset($existing[$name])) {
            unset($data['servers'][$name]);
            if ($data['active_world'] === $name) {
                $data['active_world'] = null;
            }
        }
    }
}

function ensureActiveSessionMatches(array &$data, array $config): void {
    if ($data['active_world']) {
        $session = sanitizeSessionName($config['tmux_prefix'], $data['active_world']);
        if (!tmuxSessionExists($session)) {
            $data['active_world'] = null;
        }
    }
}

$messages = [];
$data = loadData($dataFile);
syncWorlds($config, $data);
ensureActiveSessionMatches($data, $config);
saveData($dataFile, $data);

// Authentication
$hasPassword = !empty($config['admin_password_hash']);
if (!$hasPassword) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_password'])) {
        $pwd = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($pwd !== $confirm) {
            $messages[] = ['type' => 'error', 'text' => 'Passwords do not match.'];
        } elseif (strlen($pwd) < 6) {
            $messages[] = ['type' => 'error', 'text' => 'Password should be at least 6 characters.'];
        } else {
            $config['admin_password_hash'] = password_hash($pwd, PASSWORD_DEFAULT);
            saveConfig($config);
            $_SESSION['authenticated'] = true;
            header('Location: index.php');
            exit;
        }
    }
    renderLogin($messages, $hasPassword);
    exit;
} else {
    if (isset($_POST['logout'])) {
        session_destroy();
        header('Location: index.php');
        exit;
    }

    if (!($_SESSION['authenticated'] ?? false)) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
            $pwd = $_POST['password'] ?? '';
            if (password_verify($pwd, $config['admin_password_hash'])) {
                $_SESSION['authenticated'] = true;
                header('Location: index.php');
                exit;
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Invalid password.'];
            }
        }
        renderLogin($messages, $hasPassword);
        exit;
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SESSION['authenticated'] ?? false)) {
    $action = $_POST['action'] ?? '';
    $world = $_POST['world'] ?? null;
    if ($world && !isset($data['servers'][$world])) {
        $messages[] = ['type' => 'error', 'text' => 'Unknown world selected.'];
    } else {
        switch ($action) {
            case 'start':
                if ($data['active_world'] && $data['active_world'] !== $world) {
                    $currentSession = sanitizeSessionName($config['tmux_prefix'], $data['active_world']);
                    if (!stopServer($currentSession)) {
                        $messages[] = ['type' => 'error', 'text' => 'Unable to stop currently running server.'];
                        break;
                    }
                    $data['active_world'] = null;
                }
                $server = $data['servers'][$world] ?? null;
                if ($server && startServer($config, $server)) {
                    $data['active_world'] = $world;
                    $messages[] = ['type' => 'success', 'text' => "Started {$world}."];
                } else {
                    $messages[] = ['type' => 'error', 'text' => 'Failed to start server.'];
                }
                break;
            case 'stop':
                if ($data['active_world'] === $world) {
                    $session = sanitizeSessionName($config['tmux_prefix'], $world);
                    if (stopServer($session)) {
                        $data['active_world'] = null;
                        $messages[] = ['type' => 'success', 'text' => "Stopped {$world}."];
                    } else {
                        $messages[] = ['type' => 'error', 'text' => 'Failed to stop server.'];
                    }
                }
                break;
            case 'save':
                if ($data['active_world'] === $world) {
                    $session = sanitizeSessionName($config['tmux_prefix'], $world);
                    sendTmuxCommand($session, 'save');
                    $messages[] = ['type' => 'success', 'text' => "Save command sent to {$world}."];
                }
                break;
            case 'update':
                $motd = trim($_POST['motd'] ?? '');
                $port = (int) ($_POST['port'] ?? $config['default_port']);
                $maxPlayers = (int) ($_POST['max_players'] ?? $config['default_max_players']);
                $password = $_POST['password'] ?? '';
                $data['servers'][$world]['motd'] = $motd;
                $data['servers'][$world]['port'] = $port;
                $data['servers'][$world]['max_players'] = $maxPlayers;
                $data['servers'][$world]['password'] = $password;
                $messages[] = ['type' => 'success', 'text' => "Updated settings for {$world}."];

                if ($data['active_world'] === $world) {
                    $session = sanitizeSessionName($config['tmux_prefix'], $world);
                    stopServer($session);
                    $data['active_world'] = null;
                    if (startServer($config, $data['servers'][$world])) {
                        $data['active_world'] = $world;
                        $messages[] = ['type' => 'success', 'text' => 'Server restarted with new settings.'];
                    } else {
                        $messages[] = ['type' => 'error', 'text' => 'Failed to restart server with new settings.'];
                    }
                }
                break;
        }
    }
    saveData($dataFile, $data);
}

// render UI
renderPage($data, $messages, $config);

function renderLogin(array $messages, bool $hasPassword): void {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Terraria Admin - Login</title>
        <link rel="stylesheet" href="styles.css">
    </head>
    <body>
    <div class="form-panel">
        <h2><?php echo $hasPassword ? 'Admin Login' : 'Create Admin Password'; ?></h2>
        <?php foreach ($messages as $msg): ?>
            <div class="message <?php echo htmlspecialchars($msg['type']); ?>"><?php echo htmlspecialchars($msg['text']); ?></div>
        <?php endforeach; ?>
        <form method="POST">
            <?php if ($hasPassword): ?>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" name="login" class="btn-primary">Login</button>
            <?php else: ?>
                <div class="notice">Set an admin password to continue.</div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <button type="submit" name="set_password" class="btn-primary">Save Password</button>
            <?php endif; ?>
        </form>
    </div>
    </body>
    </html>
    <?php
}

function renderPage(array $data, array $messages, array $config): void {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Terraria Admin</title>
        <link rel="stylesheet" href="styles.css">
    </head>
    <body>
        <header>
            <h1>Terraria World Admin</h1>
            <form method="POST" style="margin:0;">
                <button type="submit" name="logout" class="btn-secondary">Logout</button>
            </form>
        </header>
        <div class="container">
            <?php foreach ($messages as $msg): ?>
                <div class="message <?php echo htmlspecialchars($msg['type']); ?>"><?php echo htmlspecialchars($msg['text']); ?></div>
            <?php endforeach; ?>
            <div class="grid">
                <?php foreach ($data['servers'] as $server): $active = $data['active_world'] === $server['world']; ?>
                    <div class="card <?php echo $active ? 'active' : ''; ?>">
                        <h2><?php echo htmlspecialchars($server['world']); ?></h2>
                        <div class="meta">Port: <?php echo htmlspecialchars($server['port']); ?> • Max players: <?php echo htmlspecialchars($server['max_players']); ?></div>
                        <div class="meta">MOTD: <?php echo htmlspecialchars($server['motd']); ?></div>
                        <div class="meta">Password: <?php echo $server['password'] !== '' ? htmlspecialchars($server['password']) : 'None'; ?></div>
                        <?php if ($active): ?><span class="badge">Active</span><?php endif; ?>
                        <div class="actions">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="world" value="<?php echo htmlspecialchars($server['world']); ?>">
                                <input type="hidden" name="action" value="<?php echo $active ? 'stop' : 'start'; ?>">
                                <button type="submit" class="<?php echo $active ? 'btn-danger' : 'btn-primary'; ?>"><?php echo $active ? 'Stop' : 'Start'; ?></button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="world" value="<?php echo htmlspecialchars($server['world']); ?>">
                                <input type="hidden" name="action" value="save">
                                <button type="submit" class="btn-secondary" <?php echo $active ? '' : 'disabled'; ?>>Save</button>
                            </form>
                            <button class="btn-secondary" onclick="openModal('<?php echo htmlspecialchars($server['world']); ?>', '<?php echo htmlspecialchars(addslashes($server['motd'])); ?>', '<?php echo htmlspecialchars($server['port']); ?>', '<?php echo htmlspecialchars($server['max_players']); ?>', '<?php echo htmlspecialchars(addslashes($server['password'])); ?>')">Edit</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="modal-backdrop" id="modal">
            <div class="modal">
                <header><h3>Edit Server</h3></header>
                <form method="POST">
                    <input type="hidden" name="world" id="modal-world">
                    <input type="hidden" name="action" value="update">
                    <div class="form-group">
                        <label for="modal-motd">MOTD</label>
                        <textarea id="modal-motd" name="motd" rows="2" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="modal-port">Port</label>
                        <input type="number" id="modal-port" name="port" required>
                        <small class="meta">Changing port requires a restart. The server will restart automatically.</small>
                    </div>
                    <div class="form-group">
                        <label for="modal-max-players">Max Players</label>
                        <input type="number" id="modal-max-players" name="max_players" required>
                    </div>
                    <div class="form-group">
                        <label for="modal-password">Server Password (plain text)</label>
                        <input type="text" id="modal-password" name="password">
                    </div>
                    <footer>
                        <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                        <button type="submit" class="btn-primary">Save Changes</button>
                    </footer>
                </form>
            </div>
        </div>
        <script>
            function openModal(world, motd, port, maxPlayers, password) {
                document.getElementById('modal-world').value = world;
                document.getElementById('modal-motd').value = motd.replace(/\\'/g, "'");
                document.getElementById('modal-port').value = port;
                document.getElementById('modal-max-players').value = maxPlayers;
                document.getElementById('modal-password').value = password.replace(/\\'/g, "'");
                document.getElementById('modal').classList.add('active');
            }
            function closeModal() {
                document.getElementById('modal').classList.remove('active');
            }
            window.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeModal();
            });
        </script>
    </body>
    </html>
    <?php
}
