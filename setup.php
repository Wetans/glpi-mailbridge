<?php

/**
 * MailBridge - Email Integration & Diagnostics for GLPI.
 * (Integração e diagnóstico de e-mail para o GLPI.)
 *
 * Gerencia, numa tela só, o modo de notificação por e-mail do GLPI (servidor,
 * remetentes, fila, rotina automática), testa o envio passo a passo e avisa
 * técnicos por e-mail sobre eventos de chamados, com regras e mensagens
 * configuráveis. Os e-mails passam pela fila de notificações nativa do GLPI e
 * saem pelo servidor configurado no GLPI: o plugin não tem transporte próprio.
 *
 * O MailBridge 1.0 sucede o plugin "Techmail" (versões 1.0.0 e 1.1.0, pasta
 * `techmail`). A instalação do MailBridge traz os dados de uma instalação
 * antiga sem apagar nada (veja GlpiPlugin\Mailbridge\LegacyImport e o README).
 *
 * Copyright (C) 2026 Mateus Oliveira <https://github.com/Wetans>
 *
 * Este programa é software livre: você pode redistribuí-lo e/ou modificá-lo
 * sob os termos da GNU General Public License, versão 2 ou (a seu critério)
 * qualquer versão posterior. Veja o arquivo LICENSE.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Mailbridge\FailureLog;
use GlpiPlugin\Mailbridge\LegacyImport;
use GlpiPlugin\Mailbridge\Menu;
use GlpiPlugin\Mailbridge\Notifier;
use GlpiPlugin\Mailbridge\Profile;

define('PLUGIN_MAILBRIDGE_VERSION', '1.0');

// Versão mínima do GLPI (inclusive) e máxima (exclusiva).
define('PLUGIN_MAILBRIDGE_MIN_GLPI', '10.0.0');
define('PLUGIN_MAILBRIDGE_MAX_GLPI', '12.0.0');

/**
 * Informações do plugin.
 */
function plugin_version_mailbridge(): array
{
    return [
        'name'         => 'MailBridge',
        'version'      => PLUGIN_MAILBRIDGE_VERSION,
        'author'       => 'Mateus Oliveira',
        'license'      => 'GPLv2+',
        'homepage'     => 'https://github.com/Wetans/mailbridge',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MAILBRIDGE_MIN_GLPI,
                'max' => PLUGIN_MAILBRIDGE_MAX_GLPI,
            ],
            'php'  => [
                'min' => '7.4',
            ],
        ],
    ];
}

/**
 * Inicialização: registra menu, página de configuração e hooks.
 */
function plugin_init_mailbridge(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['mailbridge'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('mailbridge')) {
        return;
    }

    // Entrada única do plugin: Configurar > MailBridge (e o atalho em Configurar > Plugins).
    $PLUGIN_HOOKS['config_page']['mailbridge'] = 'front/index.php';
    $PLUGIN_HOOKS['menu_toadd']['mailbridge']  = ['config' => Menu::class];

    // Aba "MailBridge" no perfil (permissões finas).
    Plugin::registerClass(Profile::class, ['addtabon' => ['Profile']]);

    // Guarda o motivo quando o GLPI não consegue entregar um e-mail da fila.
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['mailbridge'] = [
        'QueuedNotification' => [FailureLog::class, 'onQueueItemUpdated'],
    ];

    // Com o plugin antigo (Techmail) ainda ativo, os dois mandariam o mesmo aviso:
    // o MailBridge espera, e a Visão geral mostra o que fazer.
    if (LegacyImport::legacyActive()) {
        return;
    }

    // Eventos que disparam notificações.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['mailbridge'] = [
        'Ticket'       => [Notifier::class, 'onTicketAdded'],
        'Ticket_User'  => [Notifier::class, 'onTicketUserAdded'],
        'Group_Ticket' => [Notifier::class, 'onGroupTicketAdded'],
        'ITILFollowup' => [Notifier::class, 'onFollowupAdded'],
        'TicketTask'   => [Notifier::class, 'onTaskAdded'],
        'ITILSolution' => [Notifier::class, 'onSolutionAdded'],
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['mailbridge']['Ticket'] = [Notifier::class, 'onTicketUpdated'];
}

function plugin_mailbridge_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        echo 'O plugin MailBridge exige PHP 7.4 ou superior.';
        return false;
    }
    return true;
}

function plugin_mailbridge_check_config($verbose = false): bool
{
    return true;
}
