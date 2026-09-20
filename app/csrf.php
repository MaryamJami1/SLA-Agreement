<?php
/**
 * CSRF protection: one token per session, checked centrally by bootstrap.php on every POST.
 * Every form includes csrf_field().
 */
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

/** Reject any POST without the session's token (also catches uploads larger than post_max_size). */
function csrf_check(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // PHP discards an over-sized POST body, including the token: say so plainly.
        http_response_code(413);
        $pageTitle = 'Upload too large';
        $bare = true;
        require APP_ROOT . '/app/views/layout_top.php';
        echo '<div class="auth-card"><h2>That upload was too large</h2>'
            . '<p class="muted">The file exceeds what the server accepts (5 MB per file). Go back and choose a smaller file.</p>'
            . '<p><a class="btn primary" href="' . h(url('index.php')) . '">Go to the registry</a></p></div>';
        require APP_ROOT . '/app/views/layout_bottom.php';
        exit;
    }
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        $pageTitle = 'Form expired';
        $bare = true;
        require APP_ROOT . '/app/views/layout_top.php';
        echo '<div class="auth-card"><h2>This form has expired</h2>'
            . '<p class="muted">For your security, the page must be reloaded before it can be submitted. '
            . 'Go back, reload the page, and submit again.</p>'
            . '<p><a class="btn primary" href="' . h(url('index.php')) . '">Go to the home page</a></p></div>';
        require APP_ROOT . '/app/views/layout_bottom.php';
        exit;
    }
}
