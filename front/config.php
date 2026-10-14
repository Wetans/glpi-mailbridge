<?php

use GlpiPlugin\Mailbridge\Page;
use GlpiPlugin\Mailbridge\Rights;

// Endereço antigo (Techmail 1.0.0): a configuração agora fica em MailBridge > Envio.
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

// Com o MailBridge desativado as classes dele não carregam: mostra "não encontrado" do GLPI em vez de um erro.
if (!Plugin::isPluginActive('mailbridge')) {
    Html::displayNotFoundError('MailBridge is not active');
}

Rights::check(Rights::VIEW);

Html::redirect(Page::url('sending'));
