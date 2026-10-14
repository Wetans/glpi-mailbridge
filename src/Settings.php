<?php

namespace GlpiPlugin\Mailbridge;

use Config as CoreConfig;
use Toolbox;

/**
 * Configurações próprias do plugin (guardadas na tabela glpi_configs,
 * contexto "plugin:mailbridge"). A configuração de envio do GLPI (servidor,
 * remetentes...) fica na classe Sending.
 */
class Settings
{
    public const CONTEXT = 'plugin:mailbridge';

    /** @var array|null cache da requisição */
    private static $cache = null;

    public static function defaults(): array
    {
        return [
            'enabled'            => 1,
            'send_immediately'   => 0,
            'sender_email'       => '',
            'sender_name'        => '',
            'debug_log'          => 0,
            // Techmail 1.1.0
            'block_list'         => '',
            'allow_list'         => '',
            'entity_modes'       => '{}',
            'last_test_ok'       => '',
            'purge_on_uninstall' => 0,
            // MailBridge 1.0: relatório da importação dos dados do plugin antigo (Techmail); vazio = não houve
            'legacy_import'      => '',
            // MailBridge 1.0: marca da configuração de envio usada no último teste com sucesso (Sending::fingerprint())
            'last_test_config'   => '',
        ];
    }

    /**
     * Todas as configurações, já com valores padrão aplicados.
     */
    public static function all(): array
    {
        if (self::$cache === null) {
            $stored      = CoreConfig::getConfigurationValues(self::CONTEXT);
            self::$cache = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        }

        return self::$cache;
    }

    /**
     * @return mixed
     */
    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }

    /**
     * Texto de configuração já decodificado (como o usuário digitou).
     */
    public static function text(string $key): string
    {
        return Text::decode((string) (self::all()[$key] ?? ''));
    }

    /**
     * Grava valores JÁ VALIDADOS (veja Validation::mailbridge()). Só aceita
     * chaves conhecidas.
     */
    public static function set(array $values): void
    {
        $values = array_intersect_key($values, self::defaults());
        if (!$values) {
            return;
        }
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = Text::sanitize($value);
            }
        }

        CoreConfig::setConfigurationValues(self::CONTEXT, $values);
        self::$cache = null;
    }

    /**
     * Mapa "entidade => ligado/desligado" (só as entidades com escolha própria).
     *
     * @return array<int,int>
     */
    public static function entityModes(): array
    {
        $decoded = json_decode(self::text('entity_modes'), true);
        $modes   = [];
        if (is_array($decoded)) {
            foreach ($decoded as $id => $on) {
                $modes[(int) $id] = (int) (bool) $on;
            }
        }

        return $modes;
    }

    /**
     * Registra uma linha em files/_log/mailbridge.log (se o log estiver ligado).
     */
    public static function log(string $message, bool $force = false): void
    {
        if (!$force && !self::get('debug_log')) {
            return;
        }
        Toolbox::logInFile('mailbridge', $message . "\n");
    }
}
