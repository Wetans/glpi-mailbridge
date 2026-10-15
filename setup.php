<?php

/**
 * MailTech - Email Integration & Diagnostics for GLPI.
 * (Integração e diagnóstico de e-mail para o GLPI.)
 *
 * Gerencia, numa tela só, o modo de notificação por e-mail do GLPI (servidor,
 * remetentes, fila, rotina automática), testa o envio passo a passo e avisa
 * técnicos por e-mail sobre eventos de chamados, com regras e mensagens
 * configuráveis. Os e-mails passam pela fila de notificações nativa do GLPI e
 * saem pelo servidor configurado no GLPI: o plugin não tem transporte próprio.
 *
 * O MailTech 1.0 sucede o plugin "Techmail" (versões 1.0.0 e 1.1.0, pasta
 * `techmail`), que por um breve período se chamou "MailBridge" (pasta
 * `mailbridge`). A instalação do MailTech traz os dados de uma instalação
 * anterior sem apagar nada (veja GlpiPlugin\Mailtech\LegacyImport e o README).
 *
 * Copyright (C) 2026 Mateus Oliveira <https://github.com/mateusoliveiraw>
 *
 * Este programa é software livre: você pode redistribuí-lo e/ou modificá-lo
 * sob os termos da GNU General Public License, versão 2 ou (a seu critério)
 * qualquer versão posterior. Veja o arquivo LICENSE.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Mailtech\FailureLog;
use GlpiPlugin\Mailtech\LegacyImport;
use GlpiPlugin\Mailtech\Menu;
use GlpiPlugin\Mailtech\Notifier;
use GlpiPlugin\Mailtech\Profile;

define('PLUGIN_MAILTECH_VERSION', '1.0');

// Versão mínima do GLPI (inclusive) e máxima (exclusiva).
define('PLUGIN_MAILTECH_MIN_GLPI', '10.0.0');
define('PLUGIN_MAILTECH_MAX_GLPI', '12.0.0');

/**
 * Informações do plugin.
 */
function plugin_version_mailtech(): array
{
    return [
        'name'         => 'MailTech',
        'version'      => PLUGIN_MAILTECH_VERSION,
        'author'       => 'Mateus Oliveira',
        'license'      => 'GPLv2+',
        'homepage'     => 'https://github.com/mateusoliveiraw/MailTech',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MAILTECH_MIN_GLPI,
                'max' => PLUGIN_MAILTECH_MAX_GLPI,
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
function plugin_init_mailtech(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['mailtech'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('mailtech')) {
        return;
    }

    // Entrada única do plugin: Configurar > MailTech (e o atalho em Configurar > Plugins).
    $PLUGIN_HOOKS['config_page']['mailtech'] = 'front/index.php';
    $PLUGIN_HOOKS['menu_toadd']['mailtech']  = ['config' => Menu::class];

    // Aba "MailTech" no perfil (permissões finas).
    Plugin::registerClass(Profile::class, ['addtabon' => ['Profile']]);

    // Guarda o motivo quando o GLPI não consegue entregar um e-mail da fila.
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['mailtech'] = [
        'QueuedNotification' => [FailureLog::class, 'onQueueItemUpdated'],
    ];

    // Com um plugin anterior (MailBridge, Techmail) ainda ativo, os dois mandariam o mesmo aviso:
    // o MailTech espera, e a Visão geral mostra o que fazer.
    if (LegacyImport::legacyActive()) {
        return;
    }

    // Eventos que disparam notificações.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['mailtech'] = [
        'Ticket'       => [Notifier::class, 'onTicketAdded'],
        'Ticket_User'  => [Notifier::class, 'onTicketUserAdded'],
        'Group_Ticket' => [Notifier::class, 'onGroupTicketAdded'],
        'ITILFollowup' => [Notifier::class, 'onFollowupAdded'],
        'TicketTask'   => [Notifier::class, 'onTaskAdded'],
        'ITILSolution' => [Notifier::class, 'onSolutionAdded'],
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['mailtech']['Ticket'] = [Notifier::class, 'onTicketUpdated'];
}

function plugin_mailtech_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        echo 'O plugin MailTech exige PHP 7.4 ou superior.';
        return false;
    }
    return true;
}

function plugin_mailtech_check_config($verbose = false): bool
{
    return true;
}
