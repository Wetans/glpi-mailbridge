<?php

use GlpiPlugin\Mailtech\Page;
use GlpiPlugin\Mailtech\Rights;

// Endereço antigo (Techmail 1.0.0): a lista de regras agora fica em MailTech > Eventos e destinatários.
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

// Com o MailTech desativado as classes dele não carregam: mostra "não encontrado" do GLPI em vez de um erro.
if (!Plugin::isPluginActive('mailtech')) {
    Html::displayNotFoundError('MailTech is not active');
}

Rights::check(Rights::VIEW);

Html::redirect(Page::url('events'));
