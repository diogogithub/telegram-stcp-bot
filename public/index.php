<?php

declare(strict_types=1);

use Diogo\StcpChatbot\App;
use Diogo\StcpChatbot\Dashboard\Auth;

$config = require dirname(__DIR__) . '/bootstrap.php';
$app = new App($config);
$auth = new Auth($config);
$auth->startSession();

header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$page = trim((string) ($_GET['page'] ?? 'summary'));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** @param array<string,scalar> $parameters */
function adminUrl(string $page = 'summary', array $parameters = []): string
{
    if ($page !== 'summary') {
        $parameters = ['page' => $page] + $parameters;
    }
    $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    return 'index.php' . ($query === '' ? '' : '?' . $query);
}

/** @param array<string,scalar> $parameters */
function redirectTo(string $page = 'summary', array $parameters = []): never
{
    header('Location: ' . adminUrl($page, $parameters), true, 303);
    exit;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function render(string $title, string $content, Auth $auth): never
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    $nav = '';
    if ($auth->loggedIn()) {
        $nav = '<nav>'
            . '<a href="' . e(adminUrl()) . '">Resumo</a>'
            . '<a href="' . e(adminUrl('users')) . '">Utilizadores</a>'
            . '<a href="' . e(adminUrl('announcements')) . '">Anúncios</a>'
            . '<a href="' . e(adminUrl('audit')) . '">Auditoria</a>'
            . '<form method="post" action="' . e(adminUrl('logout')) . '">'
            . '<input type="hidden" name="csrf" value="' . e($auth->csrf()) . '">'
            . '<button class="link" type="submit">Sair</button></form></nav>';
    }

    $flashHtml = is_array($flash)
        ? '<div class="flash ' . e($flash['type'] ?? '') . '">' . e($flash['message'] ?? '') . '</div>'
        : '';

    echo '<!doctype html><html lang="pt"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($title) . ' · STCP Chatbot</title>'
        . '<link rel="stylesheet" href="assets/admin.css"></head><body>'
        . '<header><div><strong>STCP Chatbot</strong><span>administração</span></div>' . $nav . '</header>'
        . '<main><h1>' . e($title) . '</h1>' . $flashHtml . $content . '</main></body></html>';
    exit;
}

if ($page === 'login') {
    if ($auth->loggedIn()) {
        redirectTo();
    }

    $error = '';
    if ($method === 'POST') {
        if (!$auth->validCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            http_response_code(400);
            $error = '<div class="flash error">Pedido inválido.</div>';
        } elseif ($auth->login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            $app->store->audit($auth->actor(), 'admin.login');
            redirectTo();
        } else {
            http_response_code(401);
            $error = '<div class="flash error">Credenciais inválidas.</div>';
        }
    }

    render(
        'Entrar',
        $error . '<section class="panel narrow"><form method="post" action="' . e(adminUrl('login')) . '">'
        . '<input type="hidden" name="csrf" value="' . e($auth->csrf()) . '">'
        . '<label>Utilizador<input name="username" autocomplete="username" required autofocus></label>'
        . '<label>Palavra-passe<input name="password" type="password" autocomplete="current-password" required></label>'
        . '<button type="submit">Entrar</button></form></section>',
        $auth
    );
}

if (!$auth->loggedIn()) {
    redirectTo('login');
}

if ($method === 'POST' && !$auth->validCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
    http_response_code(400);
    render('Pedido inválido', '<p>O token de segurança expirou. Recarrega a página e tenta novamente.</p>', $auth);
}

if ($page === 'logout' && $method === 'POST') {
    $app->store->audit($auth->actor(), 'admin.logout');
    $auth->logout();
    redirectTo('login');
}

if ($page === 'summary') {
    $stats = $app->store->dashboardStats();
    $cards = [
        'Utilizadores conhecidos' => $stats['known_users'],
        'Ativos hoje' => $stats['active_today'],
        'Ativos em 7 dias' => $stats['active_7_days'],
        'Ativos em 30 dias' => $stats['active_30_days'],
        'Contactáveis' => $stats['reachable_users'],
        'Com anúncios ativos' => $stats['announcement_users'],
    ];

    $html = '<section class="cards">';
    foreach ($cards as $label => $value) {
        $html .= '<article><strong>' . e($value) . '</strong><span>' . e($label) . '</span></article>';
    }

    $html .= '</section><section class="grid"><div class="panel"><h2>Plataformas</h2>'
        . '<table><thead><tr><th>Plataforma</th><th>Utilizadores</th><th>Contactáveis</th><th>Ativos 30d</th></tr></thead><tbody>';
    foreach (($stats['platforms'] ?? []) as $row) {
        $html .= '<tr><td><span class="platform ' . e($row['platform']) . '">' . e($row['platform']) . '</span></td>'
            . '<td>' . e($row['users']) . '</td><td>' . e($row['reachable']) . '</td><td>' . e($row['active_30']) . '</td></tr>';
    }

    $html .= '</tbody></table></div><div class="panel"><h2>Comandos, últimos 30 dias</h2>'
        . '<table><thead><tr><th>Ação</th><th>Usos</th><th>Utilizadores</th></tr></thead><tbody>';
    foreach (($stats['top_actions'] ?? []) as $row) {
        $html .= '<tr><td>' . e($row['action']) . '</td><td>' . e($row['uses']) . '</td><td>' . e($row['users']) . '</td></tr>';
    }

    $html .= '</tbody></table></div></section><section class="panel"><h2>Atividade diária</h2>'
        . '<table><thead><tr><th>Dia</th><th>Plataforma</th><th>Interações</th></tr></thead><tbody>';
    foreach (array_reverse($app->store->dailyActivity(30)) as $row) {
        $html .= '<tr><td>' . e($row['day']) . '</td><td>' . e($row['platform']) . '</td><td>' . e($row['interactions']) . '</td></tr>';
    }
    $html .= '</tbody></table></section>';
    render('Resumo', $html, $auth);
}

if ($page === 'users') {
    $platform = trim((string) ($_GET['platform'] ?? ''));
    $search = trim((string) ($_GET['q'] ?? ''));
    $users = $app->store->listIdentities($platform !== '' ? $platform : null, $search, 500);

    $html = '<section class="panel"><form class="filters" method="get" action="index.php">'
        . '<input type="hidden" name="page" value="users"><label>Plataforma<select name="platform">'
        . '<option value="">Todas</option>';
    foreach (['telegram', 'discord', 'matrix'] as $item) {
        $html .= '<option value="' . $item . '"' . ($platform === $item ? ' selected' : '') . '>' . e($item) . '</option>';
    }

    $html .= '</select></label><label>Pesquisa<input name="q" value="' . e($search)
        . '" placeholder="nome, username ou ID"></label><button type="submit">Filtrar</button></form>'
        . '<div class="table-wrap"><table><thead><tr><th>Plataforma</th><th>Utilizador</th>'
        . '<th>Primeira utilização</th><th>Última utilização</th><th>Interações</th><th>Casa</th>'
        . '<th>Trabalho</th><th>Anúncios</th></tr></thead><tbody>';

    foreach ($users as $row) {
        $name = trim((string) ($row['display_name'] ?? '')) ?: ('@' . trim((string) ($row['username'] ?? '')));
        if ($name === '@') {
            $name = (string) $row['external_user_id'];
        }
        $html .= '<tr><td><span class="platform ' . e($row['platform']) . '">' . e($row['platform']) . '</span></td>'
            . '<td><strong>' . e($name) . '</strong><small>' . e($row['external_user_id']) . '</small></td>'
            . '<td>' . e($row['first_seen_at']) . '</td><td>' . e($row['last_seen_at']) . '</td>'
            . '<td>' . e($row['interaction_count']) . '</td><td>' . e($row['home_stop'] ?? '—') . '</td>'
            . '<td>' . e($row['work_stop'] ?? '—') . '</td><td>'
            . ((int) $row['announcements_enabled'] === 1 ? 'sim' : 'não') . '</td></tr>';
    }

    $html .= '</tbody></table></div></section>';
    render('Utilizadores', $html, $auth);
}

if ($page === 'announcement_new') {
    if ($method === 'POST') {
        try {
            $platforms = array_values(array_filter(
                (array) ($_POST['platforms'] ?? []),
                static fn (mixed $value): bool => is_string($value)
            ));
            $id = $app->store->createAnnouncement(
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['message'] ?? ''),
                (string) ($_POST['audience'] ?? 'private'),
                $platforms,
                $auth->actor()
            );
            flash('Rascunho criado. Revê-o antes de o colocar na fila.');
            redirectTo('announcement', ['id' => $id]);
        } catch (Throwable $exception) {
            flash($exception->getMessage(), 'error');
        }
    }

    $checks = '';
    foreach (['telegram', 'discord', 'matrix'] as $platformName) {
        $enabled = $config->enabled($platformName);
        $checks .= '<label class="check"><input type="checkbox" name="platforms[]" value="'
            . $platformName . '"' . ($enabled ? ' checked' : ' disabled') . '> '
            . e($platformName) . ($enabled ? '' : ' (desativada)') . '</label>';
    }

    render(
        'Novo anúncio',
        '<section class="panel"><form method="post" action="' . e(adminUrl('announcement_new')) . '">'
        . '<input type="hidden" name="csrf" value="' . e($auth->csrf()) . '">'
        . '<label>Título interno<input name="title" maxlength="160" required></label>'
        . '<label>Mensagem<textarea name="message" rows="9" maxlength="12000" required></textarea></label>'
        . '<fieldset><legend>Plataformas</legend><div class="checks">' . $checks . '</div></fieldset>'
        . '<label>Audiência<select name="audience"><option value="private">Conversas privadas</option>'
        . '<option value="all_chats">Todas as conversas conhecidas</option></select></label>'
        . '<button type="submit">Criar rascunho</button></form></section>',
        $auth
    );
}

if ($page === 'announcements') {
    $html = '<div class="actions"><a class="button" href="' . e(adminUrl('announcement_new')) . '">Novo anúncio</a></div>'
        . '<section class="panel"><table><thead><tr><th>ID</th><th>Título</th><th>Plataformas</th>'
        . '<th>Estado</th><th>Destinatários</th><th>Enviados</th><th>Falhas</th><th>Criado</th></tr></thead><tbody>';

    foreach ($app->store->listAnnouncements() as $row) {
        $html .= '<tr><td>' . e($row['id']) . '</td><td><a href="'
            . e(adminUrl('announcement', ['id' => (int) $row['id']])) . '">' . e($row['title']) . '</a></td>'
            . '<td>' . e($row['platforms']) . '</td><td><span class="status ' . e($row['status']) . '">'
            . e($row['status']) . '</span></td><td>' . e($row['recipient_count']) . '</td>'
            . '<td>' . e($row['delivered_count']) . '</td><td>' . e($row['failed_count']) . '</td>'
            . '<td>' . e($row['created_at']) . '</td></tr>';
    }

    $html .= '</tbody></table></section>';
    render('Anúncios', $html, $auth);
}

if (in_array($page, ['announcement', 'announcement_queue', 'announcement_cancel'], true)) {
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        http_response_code(404);
        render('Anúncio não encontrado', '<p>Não existe.</p>', $auth);
    }
    $id = (int) $id;

    if ($method === 'POST' && $page === 'announcement_queue') {
        try {
            $counts = $app->store->queueAnnouncement($id, $auth->actor());
            flash('Anúncio colocado na fila: ' . $counts['recipients'] . ' destinatários; ' . $counts['skipped'] . ' ignorados.');
        } catch (Throwable $exception) {
            flash($exception->getMessage(), 'error');
        }
        redirectTo('announcement', ['id' => $id]);
    }

    if ($method === 'POST' && $page === 'announcement_cancel') {
        $app->store->cancelAnnouncement($id, $auth->actor());
        flash('Anúncio cancelado.');
        redirectTo('announcement', ['id' => $id]);
    }

    $announcement = $app->store->announcement($id);
    if ($announcement === null) {
        http_response_code(404);
        render('Anúncio não encontrado', '<p>Não existe.</p>', $auth);
    }

    $buttons = '';
    if ($announcement['status'] === 'draft') {
        $buttons .= '<form method="post" action="' . e(adminUrl('announcement_queue', ['id' => $id])) . '">'
            . '<input type="hidden" name="csrf" value="' . e($auth->csrf()) . '">'
            . '<button type="submit">Colocar na fila</button></form>';
    }
    if (in_array($announcement['status'], ['draft', 'queued'], true)) {
        $buttons .= '<form method="post" action="' . e(adminUrl('announcement_cancel', ['id' => $id])) . '">'
            . '<input type="hidden" name="csrf" value="' . e($auth->csrf()) . '">'
            . '<button class="danger" type="submit">Cancelar</button></form>';
    }

    $html = '<section class="panel"><dl><dt>Estado</dt><dd><span class="status ' . e($announcement['status']) . '">'
        . e($announcement['status']) . '</span></dd><dt>Plataformas</dt><dd>'
        . e(implode(', ', $app->store->announcementPlatforms($id))) . '</dd><dt>Audiência</dt><dd>'
        . e($announcement['audience']) . '</dd><dt>Criado por</dt><dd>' . e($announcement['created_by'])
        . ' em ' . e($announcement['created_at']) . '</dd></dl><h2>' . e($announcement['title']) . '</h2>'
        . '<pre class="message-preview">' . e($announcement['message_text']) . '</pre><div class="actions">'
        . $buttons . '</div></section><section class="panel"><h2>Entregas</h2><table><thead><tr>'
        . '<th>Plataforma</th><th>Conversa</th><th>Estado</th><th>Tentativas</th><th>Erro</th>'
        . '</tr></thead><tbody>';

    foreach ($app->store->announcementDeliveries($id) as $row) {
        $html .= '<tr><td>' . e($row['platform']) . '</td><td>' . e($row['external_chat_id']) . '</td>'
            . '<td>' . e($row['status']) . '</td><td>' . e($row['attempts']) . '</td><td>'
            . e($row['last_error']) . '</td></tr>';
    }

    $html .= '</tbody></table></section>';
    render('Anúncio #' . $id, $html, $auth);
}

if ($page === 'audit') {
    $html = '<section class="panel"><table><thead><tr><th>Data</th><th>Administrador</th>'
        . '<th>Ação</th><th>Detalhes</th></tr></thead><tbody>';
    foreach ($app->store->auditLog(250) as $row) {
        $html .= '<tr><td>' . e($row['occurred_at']) . '</td><td>' . e($row['actor']) . '</td>'
            . '<td>' . e($row['action']) . '</td><td>' . e($row['details']) . '</td></tr>';
    }
    $html .= '</tbody></table></section>';
    render('Auditoria', $html, $auth);
}

http_response_code(404);
render('Não encontrado', '<p>A página pedida não existe.</p>', $auth);
