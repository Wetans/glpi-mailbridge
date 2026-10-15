<?php

namespace GlpiPlugin\Mailtech;

/**
 * Exporta e importa a configuração (ajustes, regras, modelos e, se pedido, o
 * servidor de envio). Senhas e chaves NUNCA entram no arquivo.
 */
final class Porter
{
    public const FORMAT = 1;

    /** Chaves do envio do GLPI que podem viajar no arquivo (sem segredos). */
    private const SENDING_KEYS = [
        'mailing_on', 'transport', 'security', 'smtp_host', 'smtp_port', 'smtp_username',
        'smtp_check_certificate', 'smtp_sender', 'from_email', 'from_email_name',
        'replyto_email', 'replyto_email_name', 'noreply_email', 'noreply_email_name',
        'admin_email', 'admin_email_name', 'mailing_signature', 'attach_ticket_documents_to_mail',
    ];

    /** Ajustes do plugin que viajam no arquivo. */
    private const SETTINGS_KEYS = [
        'enabled', 'send_immediately', 'sender_email', 'sender_name', 'debug_log', 'block_list', 'allow_list',
    ];

    private const RULE_KEYS = [
        'name', 'event', 'is_active', 'notify_assigned_users', 'notify_assigned_groups',
        'notify_requesters', 'notify_observers', 'exclude_author', 'extra_emails',
        'filter_type', 'filter_min_priority', 'subject', 'body', 'body_text',
    ];

    /**
     * Monta o conteúdo do arquivo de exportação.
     */
    public static function export(): array
    {
        $sending = array_intersect_key(Sending::current(), array_flip(self::SENDING_KEYS));

        $settings = [];
        foreach (self::SETTINGS_KEYS as $key) {
            $settings[$key] = Settings::text($key);
        }

        $rules = [];
        foreach ((new Rule())->find([], ['id']) as $row) {
            $rule = [];
            foreach (self::RULE_KEYS as $key) {
                $value      = $row[$key] ?? '';
                $rule[$key] = in_array($key, Rule::FLAGS, true) || strpos($key, 'filter_') === 0
                    ? (int) $value
                    : Text::decode((string) $value);
            }
            $rule['translations'] = (object) Translation::forRule((int) $row['id']);
            $rules[]              = $rule;
        }

        return [
            'plugin'      => 'mailtech',
            'format'      => self::FORMAT,
            'version'     => PLUGIN_MAILTECH_VERSION,
            'exported_at' => date('c'),
            'note'        => 'Sem senhas: a senha do servidor de envio precisa ser digitada de novo depois de importar.',
            'settings'    => $settings,
            'policy'      => Queue::policy(),
            'sending'     => $sending,
            'rules'       => $rules,
        ];
    }

    /**
     * Função pura: confere o arquivo e devolve só o que é esperado.
     *
     * @return array{0: array|null, 1: string} [dados, mensagem de erro]
     */
    public static function parse(string $json): array
    {
        if (strlen($json) > 2 * 1024 * 1024) {
            return [null, __('Arquivo grande demais: o limite é 2 MB.', 'mailtech')];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [null, __('Não consegui ler o arquivo: ele precisa ser o arquivo .json gerado pelo botão "Exportar".', 'mailtech')];
        }
        // Identificação gravada no arquivo; exportações feitas com um nome anterior do plugin continuam valendo.
        if (!in_array($data['plugin'] ?? '', array_merge(['mailtech'], LegacyImport::keys()), true)) {
            return [null, __('Este arquivo não é uma exportação do MailTech.', 'mailtech')];
        }
        if ((int) ($data['format'] ?? 0) < 1 || (int) $data['format'] > self::FORMAT) {
            return [null, __('Este arquivo foi gerado por uma versão mais nova do MailTech. Atualize o plugin antes de importar.', 'mailtech')];
        }

        $out = ['settings' => [], 'policy' => [], 'sending' => [], 'rules' => []];

        foreach (self::SETTINGS_KEYS as $key) {
            if (isset($data['settings'][$key]) && is_scalar($data['settings'][$key])) {
                $out['settings'][$key] = (string) $data['settings'][$key];
            }
        }
        foreach (['max_retries', 'retry_minutes', 'keep_days', 'batch'] as $key) {
            if (isset($data['policy'][$key]) && is_scalar($data['policy'][$key])) {
                $out['policy'][$key] = (string) $data['policy'][$key];
            }
        }
        foreach (self::SENDING_KEYS as $key) {
            if (isset($data['sending'][$key]) && is_scalar($data['sending'][$key])) {
                $out['sending'][$key] = is_bool($data['sending'][$key])
                    ? ($data['sending'][$key] ? '1' : '0')
                    : (string) $data['sending'][$key];
            }
        }

        foreach ((array) ($data['rules'] ?? []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $clean = [];
            foreach (self::RULE_KEYS as $key) {
                $clean[$key] = isset($rule[$key]) && is_scalar($rule[$key]) ? (string) $rule[$key] : '';
            }
            $clean['translations'] = [];
            foreach ((array) ($rule['translations'] ?? []) as $language => $translation) {
                if (is_array($translation) && preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', (string) $language)) {
                    $clean['translations'][(string) $language] = [
                        'subject'   => (string) ($translation['subject'] ?? ''),
                        'body'      => (string) ($translation['body'] ?? ''),
                        'body_text' => (string) ($translation['body_text'] ?? ''),
                    ];
                }
            }
            $out['rules'][] = $clean;
            if (count($out['rules']) >= 200) {
                break;
            }
        }

        return [$out, ''];
    }

    /**
     * Aplica um arquivo já conferido por parse(). Cada parte passa pelos
     * mesmos validadores das telas; o que for inválido é ignorado e relatado.
     *
     * @return array{created:int,updated:int,skipped:string[],sending:bool}
     */
    public static function import(array $data, bool $with_sending): array
    {
        $report = ['created' => 0, 'updated' => 0, 'skipped' => [], 'sending' => false];

        // Ajustes do plugin.
        [$settings, $errors] = Validation::mailtech($data['settings']);
        foreach ($errors as $message) {
            $report['skipped'][] = $message;
        }
        Settings::set($settings);

        // Regras da fila.
        if ($data['policy']) {
            [$policy, $errors] = Validation::queuePolicy($data['policy'] + Queue::policy());
            if (!$errors) {
                Queue::savePolicy($policy);
            }
        }

        // Servidor de envio (só se pedido; a senha guardada é mantida).
        if ($with_sending && $data['sending']) {
            [$sending, $errors] = Validation::sending($data['sending']);
            if ($errors) {
                $report['skipped'][] = __('A configuração do servidor de envio do arquivo tem erros e não foi importada.', 'mailtech');
            } else {
                unset($sending['smtp_passwd']);
                Sending::save($sending);
                $report['sending'] = true;
            }
        }

        // Regras e modelos: atualiza a de mesmo nome e evento, senão cria.
        $events   = Rule::getEvents();
        $known    = array_keys(Template::placeholders());
        $existing = [];
        foreach ((new Rule())->find() as $row) {
            $existing[Text::decode((string) $row['name']) . '|' . $row['event']] = (int) $row['id'];
        }

        foreach ($data['rules'] as $rule) {
            [$clean, $errors] = Validation::rule($rule, $events);
            [$template, $template_errors] = Validation::template($rule, $known);
            if ($errors || $template_errors) {
                $report['skipped'][] = sprintf(
                    __('Regra "%s" ignorada: %s', 'mailtech'),
                    $rule['name'],
                    (string) reset($errors) ?: (string) reset($template_errors)
                );
                continue;
            }
            $template['body'] = Template::clean($template['body']);
            $input            = Text::sanitize($clean + $template);

            $item = new Rule();
            $key  = $clean['name'] . '|' . $clean['event'];
            if (isset($existing[$key])) {
                $id = $existing[$key];
                // Os filtros por entidade e categoria não viajam no arquivo: mantém os da regra.
                unset($input['filter_entities_id'], $input['filter_itilcategories_id']);
                $item->update(['id' => $id] + $input);
                $report['updated']++;
            } else {
                $id = (int) $item->add($input);
                if ($id <= 0) {
                    continue;
                }
                $existing[$key] = $id;
                $report['created']++;
            }

            foreach ($rule['translations'] as $language => $translation) {
                [$translated, $translation_errors] = Validation::template($translation, $known);
                if (!$translation_errors) {
                    $translated['body'] = Template::clean($translated['body']);
                    Translation::save($id, (string) $language, $translated);
                }
            }
        }

        return $report;
    }
}
