<?php
/**
 * Internet Clipboard
 * Stores texts and files online so they can be reached from different devices.
 * Requires PHP 7.4+ with mysqli (mysqlnd).
 */

require __DIR__ . '/clip_db.php';       // provides the mysqli handle $clip_db
$clip_db->set_charset('utf8mb4');

const COOKIE_NAME  = 'clip_token';
# const STORAGE_DIR  = __DIR__ . '/storage/';
const STORAGE_DIR  = '../storage/';

const MAX_NAME_LEN = 200;

const MIME_TYPES = [
    'txt' => 'text/plain',       'csv' => 'text/csv',          'md'   => 'text/markdown',
    'html' => 'text/html',       'htm' => 'text/html',         'css'  => 'text/css',
    'js' => 'text/javascript',   'json' => 'application/json', 'xml'  => 'application/xml',
    'pdf' => 'application/pdf',  'zip' => 'application/zip',   'gz'   => 'application/gzip',
    'png' => 'image/png',        'jpg' => 'image/jpeg',        'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',        'webp' => 'image/webp',       'avif' => 'image/avif',
    'bmp' => 'image/bmp',        'svg' => 'image/svg+xml',     'ico'  => 'image/x-icon',
    'mp3' => 'audio/mpeg',       'wav' => 'audio/wav',         'ogg'  => 'audio/ogg',
    'mp4' => 'video/mp4',        'webm' => 'video/webm',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
];

/* ---------- Helpers ---------- */

/** Escapes a string for safe output inside HTML. */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Determines the mime type from the file extension. */
function mimeFor(string $fileName): string
{
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    return MIME_TYPES[$ext] ?? 'application/octet-stream';
}

/** Removes any path components and control characters from an uploaded file name. */
function cleanFileName(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    return (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
}

/** Location of a stored file: storage/<insert_id>_<original name> */
function storagePath(int $id, string $fileName): string
{
    return STORAGE_DIR . $id . '_' . $fileName;
}

function jsonReply(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/* ---------- Authentication ---------- */

function cookiePath(): string
{
    return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
}

/** Sets (or refreshes) the login cookie. Browsers cap cookie lifetimes, so it is renewed on every visit. */
function setLoginCookie(string $token): void
{
    setcookie(COOKIE_NAME, $token, [
        'expires'  => time() + 10 * 365 * 24 * 3600,
        'path'     => cookiePath(),
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Returns the name of the logged-in user, or null. */
function currentUser(mysqli $db): ?string
{
    $token = $_COOKIE[COOKIE_NAME] ?? '';
    if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
        return null;
    }
    $stmt = $db->prepare('SELECT name FROM users WHERE token = ?');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->bind_result($name);
    $found = $stmt->fetch();
    $stmt->close();
    return $found ? $name : null;
}

/** Checks the credentials and sets the cookie. Returns an error message, or '' on success. */
function tryLogin(mysqli $db, string $user, string $pass): string
{
    $error = 'Wrong user name or password.';

    $stmt = $db->prepare('SELECT pass, token FROM users WHERE name = ?');
    $stmt->bind_param('s', $user);
    $stmt->execute();
    $stmt->bind_result($stored, $token);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found || $pass === '') {
        return $error;
    }

    $newHash = null;
    if ($stored === null || $stored === '') {
        // First login: the chosen password is stored.
        $newHash = password_hash($pass, PASSWORD_DEFAULT);
    } else {
        $isHash = password_get_info($stored)['algo'] !== null && password_get_info($stored)['algo'] !== 0;
        // Passwords that were stored in plain text are accepted once and upgraded to a hash.
        $ok = $isHash ? password_verify($pass, $stored) : hash_equals($stored, $pass);
        if (!$ok) {
            return $error;
        }
        if (!$isHash || password_needs_rehash($stored, PASSWORD_DEFAULT)) {
            $newHash = password_hash($pass, PASSWORD_DEFAULT);
        }
    }
    if ($newHash !== null) {
        $stmt = $db->prepare('UPDATE users SET pass = ? WHERE name = ?');
        $stmt->bind_param('ss', $newHash, $user);
        $stmt->execute();
        $stmt->close();
    }

    // One random token per user, shared by all of their devices.
    if ($token === null || $token === '') {
        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare('UPDATE users SET token = ? WHERE name = ?');
        $stmt->bind_param('ss', $token, $user);
        $stmt->execute();
        $stmt->close();
    }
    setLoginCookie($token);
    return '';
}

/* ---------- Content actions ---------- */

function insertClip(mysqli $db, string $user, ?string $text, ?string $file, int $public): int
{
    $stmt = $db->prepare('INSERT INTO content (name, clip_text, file_name, share_public) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('sssi', $user, $text, $file, $public);
    $stmt->execute();
    $id = $db->insert_id;
    $stmt->close();
    return $id;
}

function deleteRow(mysqli $db, int $id): void
{
    $stmt = $db->prepare('DELETE FROM content WHERE clip_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

/** Creates one database entry for the text (if any) and one for each uploaded file. */
function saveClips(mysqli $db, string $user): void
{
    $public = ($_POST['public'] ?? '0') === '1' ? 1 : 0;
    $text   = (string)($_POST['text'] ?? '');

    // Collect and validate all uploads before anything is written.
    $uploads = [];
    foreach ($_FILES['files']['name'] ?? [] as $i => $originalName) {
        $name = cleanFileName($originalName);
        if ($name === '') {
            jsonReply(['ok' => false, 'error' => 'A file has no usable name.'], 400);
        }
        if (mb_strlen($name, 'UTF-8') > MAX_NAME_LEN) {
            jsonReply(['ok' => false, 'error' => 'File names may have at most ' . MAX_NAME_LEN . ' characters.'], 400);
        }
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) {
            jsonReply(['ok' => false, 'error' => 'Upload of "' . $name . '" failed (is it larger than the server allows?).'], 400);
        }
        $uploads[] = ['name' => $name, 'tmp' => $_FILES['files']['tmp_name'][$i]];
    }
    if (trim($text) === '' && !$uploads) {
        jsonReply(['ok' => false, 'error' => 'Nothing to save.'], 400);
    }

    if (trim($text) !== '') {
        insertClip($db, $user, $text, null, $public);
    }
    foreach ($uploads as $upload) {
        $id = insertClip($db, $user, null, $upload['name'], $public);
        if (!move_uploaded_file($upload['tmp'], storagePath($id, $upload['name']))) {
            deleteRow($db, $id);
            jsonReply(['ok' => false, 'error' => 'Could not store "' . $upload['name'] . '".'], 500);
        }
    }
    jsonReply(['ok' => true]);
}

/** Deletes an entry (and its file) - only the owner may do this. */
function deleteClip(mysqli $db, string $user, int $id): void
{
    $stmt = $db->prepare('SELECT file_name FROM content WHERE clip_id = ? AND name = ?');
    $stmt->bind_param('is', $id, $user);
    $stmt->execute();
    $stmt->bind_result($fileName);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        jsonReply(['ok' => false, 'error' => 'Entry not found.'], 404);
    }
    if ($fileName !== null && is_file(storagePath($id, $fileName))) {
        unlink(storagePath($id, $fileName));
    }
    deleteRow($db, $id);
    jsonReply(['ok' => true]);
}

/** Sends a stored file: as a download, or inline (used by the copy button). */
function sendFile(mysqli $db, string $user, int $id, bool $inline): void
{
    $stmt = $db->prepare('SELECT name, file_name, share_public FROM content WHERE clip_id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->bind_result($owner, $fileName, $public);
    $found = $stmt->fetch();
    $stmt->close();

    $path = $found && $fileName !== null ? storagePath($id, $fileName) : '';
    if (!$found || ($owner !== $user && !$public) || !is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }

    header('Content-Type: ' . mimeFor($fileName));
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    if ($inline) {
        header("Content-Security-Policy: default-src 'none'; sandbox");   // never run uploaded HTML/SVG
    } else {
        $fallback = preg_replace('/[^\x20-\x7E]|["\\\\%]/', '_', $fileName);
        header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
    }
    readfile($path);
    exit;
}

/* ---------- Pages ---------- */

function pageStart(): void
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">',
         '<meta name="viewport" content="width=device-width, initial-scale=1">',
         '<title>Internet Clipboard</title><link rel="stylesheet" href="clip.css"></head><body>';
}

function showLogin(string $error): void
{
    pageStart();
    ?>
    <main class="narrow">
        <h1>Welcome to Internet Clipboard</h1>
        <p class="large">Please enter your username and password</p>
        <p>If you have never entered your password, please choose one now and make sure to remember it!</p>
        <p>If you have not been provided with a username, please contact mail@bhartwig.net</p>
        <?php if ($error !== ''): ?><p class="message" role="alert"><?= h($error) ?></p><?php endif; ?>
        <form action="./clip.php" method="post" class="login">
            <label for="user">User name:</label>
            <input type="text" id="user" name="user" autocomplete="username" autocapitalize="none" required>
            <label for="pass">Password:</label>
            <input type="password" id="pass" name="pass" autocomplete="current-password" required>
            <input type="submit" value="Submit" class="primary">
        </form>
    </main>
    </body></html>
    <?php
    exit;
}

function showClipboard(mysqli $db, string $user): void
{
    $stmt = $db->prepare('SELECT clip_id, name, clip_text, file_name, share_public
                          FROM content WHERE name = ? OR share_public = 1 ORDER BY clip_id DESC');
    $stmt->bind_param('s', $user);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    pageStart();
    ?>
    <div class="input-area">
    <header class="top">
        <h1>Internet Clipboard</h1>
        <a class="logout" href="clip.php?logout=1">Logout</a>
    </header>
    <div class="wrap">
        <section class="composer">
            <textarea id="clip-text" rows="8" autocomplete="off" placeholder="Type or paste text, or drop files here"></textarea>
            <ul id="file-list" class="files"></ul>
            <p id="message" class="message" role="alert" hidden></p>
            <div class="actions">
                <button type="button" id="share-private">Share only with me</button>
                <button type="button" id="share-public" class="primary">Share publicly</button>
            </div>
        </section>
    </div>
    </div>

    <main class="lower">
        <section id="clip-list">
            <?php foreach ($items as $item): $id = (int)$item['clip_id']; $mine = $item['name'] === $user; ?>
                <article class="item" data-id="<?= $id ?>">
                    <?php if ($item['share_public']): ?>
                        <p class="shared">Shared by <?= h($item['name']) ?></p>
                    <?php endif; ?>

                    <?php if ($item['file_name'] === null): ?>
                        <textarea rows="8"><?= "\n" . h($item['clip_text']) ?></textarea>
                        <div class="actions">
                            <button type="button" data-action="copy-text">Copy</button>
                            <button type="button" data-action="download-text">Download as .txt</button>
                    <?php else: ?>
                        <p class="file-name"><?= h($item['file_name']) ?></p>
                        <div class="actions">
                            <a class="button" href="clip.php?download=<?= $id ?>">Download</a>
                            <button type="button" data-action="copy-file" hidden
                                    data-mime="<?= h(mimeFor($item['file_name'])) ?>"
                                    data-url="clip.php?view=<?= $id ?>">Copy</button>
                    <?php endif; ?>
                            <?php if ($mine): ?><button type="button" data-action="delete" class="danger">Delete</button><?php endif; ?>
                        </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$items): ?><p class="empty">Nothing here yet. Add text or files above.</p><?php endif; ?>
        </section>
    </main>
    <script src="clip.js"></script>
    </body></html>
    <?php
}

/* ---------- Request handling ---------- */

if (isset($_GET['logout'])) {
    setcookie(COOKIE_NAME, '', ['expires' => time() - 3600, 'path' => cookiePath()]);
    header('Location: clip.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user'], $_POST['pass'])) {
    $error = tryLogin($clip_db, trim($_POST['user']), $_POST['pass']);
    if ($error === '') {
        header('Location: clip.php');
        exit;
    }
    showLogin($error);
}

$user = currentUser($clip_db);
if ($user === null) {
    showLogin('');
}
setLoginCookie($_COOKIE[COOKIE_NAME]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        jsonReply(['ok' => false, 'error' => 'The upload is larger than the server allows.'], 413);
    }
    switch ($_POST['action'] ?? '') {
        case 'save':
            saveClips($clip_db, $user);
            break;
        case 'delete':
            deleteClip($clip_db, $user, (int)($_POST['id'] ?? 0));
            break;
        default:
            jsonReply(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
}

if (isset($_GET['download']) || isset($_GET['view'])) {
    $inline = isset($_GET['view']);
    sendFile($clip_db, $user, (int)($inline ? $_GET['view'] : $_GET['download']), $inline);
}

showClipboard($clip_db, $user);
