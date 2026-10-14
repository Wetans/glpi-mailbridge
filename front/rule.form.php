<?php

use GlpiPlugin\Mailbridge\Page;
use GlpiPlugin\Mailbridge\Rights;

// Endereço antigo (Techmail 1.0.0): o formulário da regra agora fica em MailBridge > Eventos e destinatários.
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

// Com o MailBridge desativado as classes dele não carregam: mostra "não encontrado" do GLPI em vez de um erro.
if (!Plugin::isPluginActive('mailbridge')) {
    Html::displayNotFoundError('MailBridge is not active');
}

Rights::check(Rights::VIEW);

$id = (int) ($_GET['id'] ?? 0);

Html::redirect(Page::url('events', $id > 0 ? ['rule' => $id] : ['rule' => 'new']));
