<?php

use GlpiPlugin\Mailbridge\Porter;
use GlpiPlugin\Mailbridge\Rights;

// Baixa a configuração do MailBridge num arquivo .json (sem senhas).
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

// Com o MailBridge desativado as classes dele não carregam: mostra "não encontrado" do GLPI em vez de um erro.
if (!Plugin::isPluginActive('mailbridge')) {
    Html::displayNotFoundError('MailBridge is not active');
}

Rights::check(Rights::MANAGE);

$json = json_encode(Porter::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="mailbridge-configuracao-' . date('Y-m-d') . '.json"');
header('Cache-Control: no-store');
echo $json;
