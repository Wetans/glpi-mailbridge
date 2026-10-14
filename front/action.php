<?php

use GlpiPlugin\Mailbridge\Actions;
use GlpiPlugin\Mailbridge\Page;
use GlpiPlugin\Mailbridge\Text;

// Recebe os formulários do MailBridge (sempre POST, com o token CSRF conferido pelo GLPI),
// executa a ação e volta para a tela com o resultado.
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

// Com o MailBridge desativado as classes dele não carregam: mostra "não encontrado" do GLPI em vez de um erro.
if (!Plugin::isPluginActive('mailbridge')) {
    Html::displayNotFoundError('MailBridge is not active');
}

Session::checkLoginUser();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Html::redirect(Page::url());
}

$result = Actions::run((string) ($_POST['action'] ?? ''), $_POST);

if ($result['errors'] && $result['form'] !== '') {
    Page::flash($result['form'], $result['errors'], $result['old']);
}
if ($result['data']) {
    // Resultado de testes: a tela mostra o passo a passo na próxima exibição.
    $_SESSION['plugin_mailbridge_result'] = $result['data'] + ['message' => $result['message']];
}
if ($result['message'] !== '') {
    Session::addMessageAfterRedirect(Text::esc($result['message']), false, $result['ok'] ? INFO : ERROR);
}
if ($result['warning'] !== '') {
    Session::addMessageAfterRedirect(Text::esc($result['warning']), false, WARNING);
}

Html::redirect($result['redirect']);
