<?php

namespace GlpiPlugin\Mailtech;

use QueuedNotification;

/**
 * Coleta, em poucas consultas, os fatos que a classe Health avalia.
 * Só é chamada nas telas do plugin.
 */
final class Facts
{
    /** @var array<int,array> cache da requisição */
    private static $cache = [];

    /**
     * @param bool $extra inclui as verificações mais caras (só o Diagnóstico pede)
     */
    public static function collect(bool $extra = false): array
    {
        $slot = $extra ? 1 : 0;
        if (isset(self::$cache[$slot])) {
            return self::$cache[$slot];
        }

        /**
         * @var array $CFG_GLPI
         * @var \DBmysql $DB
         */
        global $CFG_GLPI, $DB;

        $sending  = Sending::current();
        $settings = Settings::all();
        $now      = time();

        $facts = [
            'notifications_on' => !empty($CFG_GLPI['use_notifications']),
            'mailing_on'       => !empty($CFG_GLPI['notifications_mailing']),
            'mailtech_on'      => (int) $settings['enabled'] === 1,
            'transport'        => $sending['transport'],
            'smtp_host'        => $sending['smtp_host'],
            'smtp_port'        => $sending['smtp_port'],
            'security'         => $sending['security'],
            'mail_function'    => $sending['mail_function'],
            'oauth_ready'      => $sending['oauth_ready'],
            'legacy_active'    => LegacyImport::activeName() ?? '',
            'login_without_password' => $sending['transport'] === 'smtp' && $sending['smtp_username'] !== '' && !$sending['has_password'],
            'provider'         => $sending['transport'] === 'mail' ? '' : Provider::name($sending['smtp_host']),
            'has_login'        => $sending['smtp_username'] !== '',
            'sender_email'     => Sending::senderEmail(),
            'last_test_ok'     => Db::time($settings['last_test_ok']),
            'test_current'     => (string) $settings['last_test_config'] !== '' && hash_equals((string) $settings['last_test_config'], Sending::fingerprint()),
            'send_immediately' => (int) $settings['send_immediately'] === 1,
            'log_in_files'     => !empty($CFG_GLPI['use_log_in_files']),
            'url_base'         => (string) ($CFG_GLPI['url_base'] ?? ''),
        ];

        // Fila: uma consulta só.
        $since = date('Y-m-d H:i:s', $now - 7 * 86400);
        $rows  = $DB->request([
            'SELECT' => [
                Db::expr('SUM(CASE WHEN `is_deleted` = 0 AND `sent_time` IS NULL THEN 1 ELSE 0 END) AS `pending`'),
                Db::expr("SUM(CASE WHEN `sent_time` IS NULL AND `sent_try` > 0 AND `send_time` >= '" . Db::escape($since) . "' THEN 1 ELSE 0 END) AS `failing`"),
                Db::expr('MAX(`sent_time`) AS `last_sent`'),
                Db::expr('MIN(CASE WHEN `is_deleted` = 0 AND `sent_time` IS NULL THEN `send_time` END) AS `oldest`'),
            ],
            'FROM'   => QueuedNotification::getTable(),
            'WHERE'  => ['mode' => 'mailing'],
        ]);
        foreach ($rows as $row) {
            $facts['pending']        = (int) $row['pending'];
            $facts['failing_7d']     = (int) $row['failing'];
            $facts['last_sent']      = Db::time($row['last_sent']);
            $facts['oldest_pending'] = Db::time($row['oldest']);
        }

        // Rotina automática do GLPI que despacha a fila.
        $routine = Queue::routine();
        $facts['cron_state']     = $routine['state'];
        $facts['cron_mode']      = $routine['mode'];
        $facts['cron_frequency'] = $routine['frequency'];
        $facts['cron_lastrun']   = $routine['lastrun'];

        // Última falha registrada.
        $failure = FailureLog::latest();
        if ($failure !== null) {
            $facts['last_failure']       = Db::time($failure['date']);
            $facts['last_failure_error'] = $failure['error'];
        }

        // Regras.
        $facts['total_rules']  = (int) countElementsInTable(Rule::getTable());
        $facts['active_rules'] = (int) countElementsInTable(Rule::getTable(), ['is_active' => 1]);

        if ($extra) {
            $rows = $DB->request([
                'SELECT'    => [Db::expr('COUNT(*) AS `total`')],
                'FROM'      => 'glpi_users',
                'LEFT JOIN' => [
                    'glpi_useremails' => [
                        'ON' => ['glpi_useremails' => 'users_id', 'glpi_users' => 'id'],
                    ],
                ],
                'WHERE'     => [
                    'glpi_users.is_active'  => 1,
                    'glpi_users.is_deleted' => 0,
                    'glpi_useremails.id'    => null,
                    'NOT'                   => ['glpi_users.name' => ['glpi-system']],
                ],
            ]);
            foreach ($rows as $row) {
                $facts['users_without_email'] = (int) $row['total'];
            }
        }

        self::$cache[$slot] = $facts;

        return $facts;
    }

    /**
     * Situação avaliada (com cache na requisição).
     */
    public static function health(bool $extra = false): array
    {
        return Health::evaluate(self::collect($extra), time());
    }

    public static function reset(): void
    {
        self::$cache = [];
    }
}
