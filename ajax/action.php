<?php

use GlpiPlugin\Mailtech\Actions;

// Mesmas ações de front/action.php, sem recarregar a página (resposta em JSON).
// O GLPI confere o token CSRF enviado no cabeçalho antes de chegar aqui.
$AJAX_INCLUDE = 1;
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

header('Content-Type: application/json; charset=UTF-8');

// Com o MailTech desativado as classes dele não carregam: responde "não encontrado" em vez de um erro.
if (!Plugin::isPluginActive('mailtech')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => __('O MailTech está desativado. Ative-o em Configurar > Plugins.', 'mailtech')]);
    return;
}
Html::header_nocache();

Session::checkLoginUser();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST']);
    return;
}

$result = Actions::run((string) ($_POST['action'] ?? ''), $_POST);

if ($result['status'] !== 200) {
    http_response_code($result['status']);
}

echo json_encode([
    'ok'      => $result['ok'],
    'message' => $result['message'],
    'warning' => $result['warning'],
    'errors'  => (object) $result['errors'],
    'data'    => (object) $result['data'],
]);
