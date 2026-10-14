<?php

namespace GlpiPlugin\Mailbridge;

use Html;

/**
 * Quem alterou o quê: lê o histórico que o próprio GLPI já grava
 * (glpi_logs) para a configuração de e-mail, os ajustes do MailBridge e as
 * regras. Não cria outro registro. Senhas aparecem como "********" (é o
 * próprio GLPI que as mascara ao gravar).
 */
final class Audit
{
    /**
     * Últimas alterações, da mais recente para a mais antiga.
     *
     * @return array<int,array{date:string,user:string,what:string,old:string,new:string}>
     */
    public static function recent(int $limit = 15): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rule_type = class_exists('\\Glpi\\Toolbox\\Sanitizer') ? Db::escape(Rule::class) : Rule::class;

        $rows = $DB->request([
            'SELECT' => ['itemtype', 'items_id', 'linked_action', 'user_name', 'date_mod', 'id_search_option', 'old_value', 'new_value'],
            'FROM'   => 'glpi_logs',
            'WHERE'  => [
                'OR' => [
                    ['itemtype' => 'NotificationMailingSetting'],
                    ['itemtype' => $rule_type],
                    [
                        'itemtype' => 'Config',
                        'OR'       => [
                            ['old_value' => ['LIKE', '%(plugin:mailbridge)%']],
                            ['old_value' => ['LIKE', '%(' . LegacyImport::CONTEXT . ')%']],
                            ['old_value' => ['LIKE', 'notifications\\_mailing %']],
                            ['old_value' => ['LIKE', 'use\\_notifications %']],
                        ],
                    ],
                ],
            ],
            'ORDER'  => ['id DESC'],
            'LIMIT'  => max(1, $limit),
        ]);

        // Rótulos dos campos das regras, pelo número da opção de busca.
        $rule_fields = [];
        foreach ((new Rule())->rawSearchOptions() as $option) {
            if (isset($option['field'], $option['id']) && is_numeric($option['id'])) {
                $rule_fields[(int) $option['id']] = (string) $option['name'];
            }
        }

        $entries  = [];
        $rule_ids = [];
        foreach ($rows as $row) {
            $entry = [
                'date' => (string) Html::convDateTime($row['date_mod']),
                'user' => trim(Text::decode((string) $row['user_name'])) !== ''
                    ? Text::decode((string) $row['user_name'])
                    : __('Sistema', 'mailbridge'),
                'what' => '',
                'old'  => Text::decode((string) $row['old_value']),
                'new'  => Text::decode((string) $row['new_value']),
                'rule' => 0,
            ];

            if ((string) $row['itemtype'] === Rule::class) {
                $entry['rule'] = (int) $row['items_id'];
                $rule_ids[]    = (int) $row['items_id'];
                $option        = (int) $row['id_search_option'];
                $entry['what'] = $option > 0
                    ? ($rule_fields[$option] ?? __('campo', 'mailbridge'))
                    : __('regra criada', 'mailbridge');
                if ($option === 0) {
                    $entry['old'] = '';
                    $entry['new'] = '';
                }
            } else {
                // O GLPI grava "chave valor antigo" (e "(contexto)" para plugins).
                $old = $entry['old'];
                if (preg_match('/^(\S+)(?: \(plugin:(?:mailbridge|' . LegacyImport::KEY . ')\))? ?(.*)$/s', $old, $m)) {
                    $entry['what'] = self::configLabel($m[1]);
                    $entry['old']  = $m[2];
                    if ($m[1] === 'legacy_import' || $m[1] === 'last_test_config') {
                        // Valores técnicos (relatório da importação, marca da configuração testada): não dizem nada a quem lê.
                        $entry['old'] = '';
                        $entry['new'] = '';
                    }
                }
            }

            $entries[] = $entry;
        }

        // Nomes das regras citadas, de uma vez.
        $names = [];
        if ($rule_ids) {
            foreach ((new Rule())->find(['id' => array_values(array_unique($rule_ids))]) as $rule) {
                $names[(int) $rule['id']] = Text::decode((string) $rule['name']);
            }
        }
        foreach ($entries as $i => $entry) {
            if ($entry['rule'] > 0) {
                $entries[$i]['what'] = sprintf(
                    __('Regra "%1$s": %2$s', 'mailbridge'),
                    $names[$entry['rule']] ?? ('#' . $entry['rule']),
                    $entry['what']
                );
            }
            $entries[$i]['old'] = self::short($entries[$i]['old']);
            $entries[$i]['new'] = self::short($entries[$i]['new']);
            unset($entries[$i]['rule']);
        }

        return $entries;
    }

    private static function short(string $value): string
    {
        $value = Text::oneLine(strip_tags($value), 500);

        return strlen($value) > 80 ? substr($value, 0, 77) . '...' : $value;
    }

    /**
     * Nome amigável de uma chave de configuração.
     */
    private static function configLabel(string $key): string
    {
        $labels = [
            'notifications_mailing'  => __('Envio de e-mails (liga/desliga)', 'mailbridge'),
            'use_notifications'      => __('Notificações do GLPI (liga/desliga)', 'mailbridge'),
            'smtp_mode'              => __('Forma de envio e segurança', 'mailbridge'),
            'smtp_host'              => __('Servidor', 'mailbridge'),
            'smtp_port'              => __('Porta', 'mailbridge'),
            'smtp_username'          => __('Usuário', 'mailbridge'),
            'smtp_passwd'            => __('Senha', 'mailbridge'),
            'smtp_check_certificate' => __('Conferir o certificado', 'mailbridge'),
            'smtp_sender'            => __('Endereço de retorno', 'mailbridge'),
            'smtp_max_retries'       => __('Novas tentativas', 'mailbridge'),
            'smtp_retry_time'        => __('Minutos entre as tentativas', 'mailbridge'),
            'from_email'             => __('E-mail do remetente', 'mailbridge'),
            'from_email_name'        => __('Nome do remetente', 'mailbridge'),
            'replyto_email'          => __('E-mail para respostas', 'mailbridge'),
            'replyto_email_name'     => __('Nome para respostas', 'mailbridge'),
            'noreply_email'          => __('E-mail "não responda"', 'mailbridge'),
            'noreply_email_name'     => __('Nome do "não responda"', 'mailbridge'),
            'admin_email'            => __('E-mail do administrador', 'mailbridge'),
            'admin_email_name'       => __('Nome do administrador', 'mailbridge'),
            'mailing_signature'      => __('Assinatura', 'mailbridge'),
            'attach_ticket_documents_to_mail' => __('Anexar documentos do chamado', 'mailbridge'),
            'enabled'                => __('Avisos do MailBridge (liga/desliga)', 'mailbridge'),
            'send_immediately'       => __('MailBridge: enviar na hora', 'mailbridge'),
            'sender_email'           => __('MailBridge: remetente próprio', 'mailbridge'),
            'sender_name'            => __('MailBridge: nome do remetente próprio', 'mailbridge'),
            'debug_log'              => __('MailBridge: registro detalhado', 'mailbridge'),
            'block_list'             => __('MailBridge: nunca enviar para', 'mailbridge'),
            'allow_list'             => __('MailBridge: só enviar para', 'mailbridge'),
            'entity_modes'           => __('MailBridge: escolhas por entidade', 'mailbridge'),
            'last_test_ok'           => __('MailBridge: último teste com sucesso', 'mailbridge'),
            'purge_on_uninstall'     => __('MailBridge: apagar dados ao desinstalar', 'mailbridge'),
            'legacy_import'          => __('MailBridge: dados trazidos do plugin antigo', 'mailbridge'),
            'last_test_config'       => __('MailBridge: configuração usada no último teste', 'mailbridge'),
        ];

        return $labels[$key] ?? $key;
    }
}
