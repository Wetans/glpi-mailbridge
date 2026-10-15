<?php

namespace GlpiPlugin\Mailtech;

use Html;

/**
 * Quem alterou o quê: lê o histórico que o próprio GLPI já grava
 * (glpi_logs) para a configuração de e-mail, os ajustes do MailTech e as
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
                        'OR'       => array_merge(
                            [
                                ['old_value' => ['LIKE', '%(plugin:mailtech)%']],
                                ['old_value' => ['LIKE', 'notifications\\_mailing %']],
                                ['old_value' => ['LIKE', 'use\\_notifications %']],
                            ],
                            // Inclui o que foi alterado quando o plugin tinha um nome anterior.
                            array_map(static function (string $key): array {
                                return ['old_value' => ['LIKE', '%(plugin:' . $key . ')%']];
                            }, LegacyImport::keys())
                        ),
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
                    : __('Sistema', 'mailtech'),
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
                    ? ($rule_fields[$option] ?? __('campo', 'mailtech'))
                    : __('regra criada', 'mailtech');
                if ($option === 0) {
                    $entry['old'] = '';
                    $entry['new'] = '';
                }
            } else {
                // O GLPI grava "chave valor antigo" (e "(contexto)" para plugins).
                $old = $entry['old'];
                if (preg_match('/^(\S+)(?: \(plugin:(?:mailtech|' . implode('|', LegacyImport::keys()) . ')\))? ?(.*)$/s', $old, $m)) {
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
                    __('Regra "%1$s": %2$s', 'mailtech'),
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
            'notifications_mailing'  => __('Envio de e-mails (liga/desliga)', 'mailtech'),
            'use_notifications'      => __('Notificações do GLPI (liga/desliga)', 'mailtech'),
            'smtp_mode'              => __('Forma de envio e segurança', 'mailtech'),
            'smtp_host'              => __('Servidor', 'mailtech'),
            'smtp_port'              => __('Porta', 'mailtech'),
            'smtp_username'          => __('Usuário', 'mailtech'),
            'smtp_passwd'            => __('Senha', 'mailtech'),
            'smtp_check_certificate' => __('Conferir o certificado', 'mailtech'),
            'smtp_sender'            => __('Endereço de retorno', 'mailtech'),
            'smtp_max_retries'       => __('Novas tentativas', 'mailtech'),
            'smtp_retry_time'        => __('Minutos entre as tentativas', 'mailtech'),
            'from_email'             => __('E-mail do remetente', 'mailtech'),
            'from_email_name'        => __('Nome do remetente', 'mailtech'),
            'replyto_email'          => __('E-mail para respostas', 'mailtech'),
            'replyto_email_name'     => __('Nome para respostas', 'mailtech'),
            'noreply_email'          => __('E-mail "não responda"', 'mailtech'),
            'noreply_email_name'     => __('Nome do "não responda"', 'mailtech'),
            'admin_email'            => __('E-mail do administrador', 'mailtech'),
            'admin_email_name'       => __('Nome do administrador', 'mailtech'),
            'mailing_signature'      => __('Assinatura', 'mailtech'),
            'attach_ticket_documents_to_mail' => __('Anexar documentos do chamado', 'mailtech'),
            'enabled'                => __('Avisos do MailTech (liga/desliga)', 'mailtech'),
            'send_immediately'       => __('MailTech: enviar na hora', 'mailtech'),
            'sender_email'           => __('MailTech: remetente próprio', 'mailtech'),
            'sender_name'            => __('MailTech: nome do remetente próprio', 'mailtech'),
            'debug_log'              => __('MailTech: registro detalhado', 'mailtech'),
            'block_list'             => __('MailTech: nunca enviar para', 'mailtech'),
            'allow_list'             => __('MailTech: só enviar para', 'mailtech'),
            'entity_modes'           => __('MailTech: escolhas por entidade', 'mailtech'),
            'last_test_ok'           => __('MailTech: último teste com sucesso', 'mailtech'),
            'purge_on_uninstall'     => __('MailTech: apagar dados ao desinstalar', 'mailtech'),
            'legacy_import'          => __('MailTech: dados trazidos do plugin antigo', 'mailtech'),
            'last_test_config'       => __('MailTech: configuração usada no último teste', 'mailtech'),
        ];

        return $labels[$key] ?? $key;
    }
}
