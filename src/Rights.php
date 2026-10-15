<?php

namespace GlpiPlugin\Mailtech;

use Html;
use Session;

/**
 * Permissões do plugin.
 *
 * Quem tem o direito de configuração do GLPI ("config") continua com acesso
 * total, como no Techmail 1.0.0. A permissão própria ("plugin_mailtech") serve
 * para liberar partes do plugin a outros perfis, sem dar a configuração geral.
 */
final class Rights
{
    public const NAME = 'plugin_mailtech';

    /**
     * Cria a permissão (sem ninguém) nos perfis que ainda não a têm. Pode rodar
     * várias vezes: o ProfileRight::addProfileRights() do GLPI insere sem
     * conferir e dá erro de duplicidade na segunda vez.
     */
    public static function register(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $have = [];
        foreach ($DB->request(['SELECT' => ['profiles_id'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => self::NAME]]) as $row) {
            $have[(int) $row['profiles_id']] = true;
        }
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']) as $profile) {
            if (!isset($have[(int) $profile['id']])) {
                $DB->insert('glpi_profilerights', ['profiles_id' => (int) $profile['id'], 'name' => self::NAME, 'rights' => 0]);
            }
        }
    }

    /** Ver as telas. */
    public const VIEW   = 1;    // = READ
    /** Alterar envio, regras e modelos. */
    public const MANAGE = 2;    // = UPDATE
    /** Testar a conexão e enviar e-mails de teste. */
    public const TEST   = 1024;
    /** Reenviar, cancelar, apagar e processar a fila. */
    public const QUEUE  = 2048;

    public static function can(int $right): bool
    {
        if (Session::haveRight('config', UPDATE)) {
            return true;
        }
        if ($right === self::VIEW && Session::haveRight('config', READ)) {
            return true;
        }

        return (bool) Session::haveRight(self::NAME, $right);
    }

    /**
     * Interrompe a página com a mensagem padrão do GLPI se faltar a permissão.
     */
    public static function check(int $right): void
    {
        Session::checkLoginUser();
        if (!self::can($right)) {
            Html::displayRightError();
        }
    }

    /**
     * Permissões mostradas na aba do perfil: valor => rótulo.
     */
    public static function labels(): array
    {
        return [
            self::VIEW   => __('Ver', 'mailtech'),
            self::MANAGE => __('Gerenciar', 'mailtech'),
            self::TEST   => __('Testar envio', 'mailtech'),
            self::QUEUE  => __('Operar a fila', 'mailtech'),
        ];
    }
}
