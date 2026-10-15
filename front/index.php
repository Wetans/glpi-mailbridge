<?php

use GlpiPlugin\Mailtech\Page;
use GlpiPlugin\Mailtech\Rights;

// No GLPI 10 os arquivos de front precisam carregar o núcleo; no GLPI 11 ele já está carregado.
if (!defined('GLPI_ROOT')) {
    include('../../../inc/includes.php');
}

// Com o MailTech desativado as classes dele não carregam: mostra "não encontrado" do GLPI em vez de um erro.
if (!Plugin::isPluginActive('mailtech')) {
    Html::displayNotFoundError('MailTech is not active');
}

// Tela única do MailTech. A seção vem em ?section= (visão geral, envio, eventos...).
Rights::check(Rights::VIEW);

Page::display($_GET);
