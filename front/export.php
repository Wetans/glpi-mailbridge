<?php

use GlpiPlugin\Mailtech\Porter;
use GlpiPlugin\Mailtech\Rights;

// Baixa a configuração do MailTech num arquivo .json (sem senhas).
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

// Com o MailTech desativado as classes dele não carregam: mostra "não encontrado" do GLPI em vez de um erro.
if (!Plugin::isPluginActive('mailtech')) {
    Html::displayNotFoundError('MailTech is not active');
}

Rights::check(Rights::MANAGE);

$json = json_encode(Porter::export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="mailtech-configuracao-' . date('Y-m-d') . '.json"');
header('Cache-Control: no-store');
echo $json;
