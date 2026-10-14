<?php

use GlpiPlugin\Mailbridge\Page;
use GlpiPlugin\Mailbridge\Rights;

// No GLPI 10 os arquivos de front precisam carregar o núcleo; no GLPI 11 ele já está carregado.
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

// Com o MailBridge desativado as classes dele não carregam: mostra "não encontrado" do GLPI em vez de um erro.
if (!Plugin::isPluginActive('mailbridge')) {
    Html::displayNotFoundError('MailBridge is not active');
}

// Tela única do MailBridge. A seção vem em ?section= (visão geral, envio, eventos...).
Rights::check(Rights::VIEW);

Page::display($_GET);
