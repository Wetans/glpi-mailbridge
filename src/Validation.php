<?php

namespace GlpiPlugin\Mailbridge;

/**
 * Validação de tudo que chega das telas. Classe pura: não lê nem grava nada,
 * só recebe os valores como digitados e devolve [valores limpos, erros].
 * Cada erro diz o que fazer para corrigir.
 */
final class Validation
{
    /**
     * Endereço de e-mail válido. Aceita também endereços de rede interna sem
     * ponto no domínio ("glpi@servidor"), como o próprio GLPI aceita.
     */
    public static function isEmail(string $value): bool
    {
        if ($value === '' || strlen($value) > 254 || preg_match('/[\x00-\x1F\x7F\s]/', $value)) {
            return false;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }
        $at = strrpos($value, '@');
        if ($at === false || $at === 0) {
            return false;
        }

        return filter_var(substr($value, 0, $at) . '@example.com', FILTER_VALIDATE_EMAIL) !== false
            && self::isHost(substr($value, $at + 1));
    }

    /**
     * Nome de servidor ou endereço IP, sem "http://", porta ou espaços.
     */
    public static function isHost(string $value): bool
    {
        if ($value === '' || strlen($value) > 253) {
            return false;
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return (bool) preg_match(
            '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i',
            $value
        );
    }

    /**
     * Número inteiro dentro de uma faixa; null se não for.
     */
    public static function intBetween($value, int $min, int $max): ?int
    {
        $value = trim((string) $value);
        if (!preg_match('/^\d{1,9}$/', $value)) {
            return null;
        }
        $number = (int) $value;

        return ($number >= $min && $number <= $max) ? $number : null;
    }

    /**
     * Separa e valida uma lista de e-mails (por linha, vírgula ou ponto e vírgula).
     *
     * @return array{0: string[], 1: string[]} [válidos, inválidos]
     */
    public static function emailList(string $raw): array
    {
        $valid   = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            if (self::isEmail($candidate)) {
                $valid[strtolower($candidate)] = $candidate;
            } else {
                $invalid[] = $candidate;
            }
        }

        return [array_values($valid), $invalid];
    }

    /**
     * Lista de endereços ou domínios ("pessoa@empresa.com" ou "@empresa.com").
     *
     * @return array{0: string[], 1: string[]} [válidos normalizados, inválidos]
     */
    public static function addressList(string $raw): array
    {
        $valid   = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $lower = strtolower($entry);
            if (self::isEmail($lower)) {
                $valid[$lower] = $lower;
                continue;
            }
            $domain = ltrim($lower, '@');
            if (strpos($domain, '@') === false && strpos($domain, '.') !== false && self::isHost($domain)) {
                $valid['@' . $domain] = '@' . $domain;
                continue;
            }
            $invalid[] = $entry;
        }

        return [array_values($valid), $invalid];
    }

    /**
     * Mensagens de correção mostradas ao sair do campo (as mesmas do servidor,
     * obtidas dos próprios validadores para não haver duas versões do texto).
     *
     * @return array<string,string>
     */
    public static function hints(): array
    {
        [, $invalid] = self::sending([
            'transport'     => 'smtp',
            'security'      => 'none',
            'smtp_host'     => 'http://x',
            'smtp_port'     => '0',
            'smtp_sender'   => 'x',
            'from_email'    => 'x',
            'replyto_email' => 'x',
            'noreply_email' => 'x',
            'admin_email'   => 'x',
        ]);
        [, $empty]  = self::sending(['transport' => 'smtp', 'security' => 'none', 'mailing_on' => 1]);
        [, $policy] = self::queuePolicy([]);
        [, $own]    = self::mailbridge(['sender_email' => 'x']);

        return $invalid + $policy + $own + [
            'smtp_host_required'  => $empty['smtp_host'] ?? '',
            'from_email_required' => $empty['from_email'] ?? '',
        ];
    }

    /**
     * Configuração de envio do GLPI (tela "Envio").
     *
     * @param array $in valores como digitados
     * @return array{0: array, 1: array<string,string>} [chaves da configuração do GLPI, erros por campo]
     */
    public static function sending(array $in): array
    {
        $clean  = [];
        $errors = [];

        $mailing_on = !empty($in['mailing_on']);
        $clean['notifications_mailing'] = $mailing_on ? 1 : 0;
        if ($mailing_on) {
            // O modo e-mail depende da chave geral de notificações do GLPI.
            $clean['use_notifications'] = 1;
        }

        // Transporte. "oauth" é configurado na tela nativa do GLPI: aqui não se mexe nele.
        $transport = (string) ($in['transport'] ?? 'smtp');
        if ($transport === 'mail') {
            $clean['smtp_mode'] = 0;
        } elseif ($transport === 'smtp') {
            $modes    = ['none' => 1, 'ssl' => 2, 'tls' => 3];
            $security = (string) ($in['security'] ?? 'none');
            if (!isset($modes[$security])) {
                $errors['security'] = __('Escolha o tipo de segurança da conexão.', 'mailbridge');
            } else {
                $clean['smtp_mode'] = $modes[$security];
            }

            $host = trim((string) ($in['smtp_host'] ?? ''));
            if ($host === '') {
                $errors['smtp_host'] = __('Informe o servidor de envio. Exemplo: smtp.suaempresa.com.br', 'mailbridge');
            } elseif (!self::isHost($host)) {
                $errors['smtp_host'] = __(
                    'Servidor inválido: digite só o nome ou o IP, sem "http://", sem espaços e sem a porta. Exemplo: smtp.suaempresa.com.br',
                    'mailbridge'
                );
            } else {
                $clean['smtp_host'] = strtolower($host);
            }

            $port = self::intBetween($in['smtp_port'] ?? '', 1, 65535);
            if ($port === null) {
                $errors['smtp_port'] = __('Porta inválida: use um número de 1 a 65535. As mais comuns são 587, 465 e 25.', 'mailbridge');
            } else {
                $clean['smtp_port'] = $port;
            }

            $username = (string) ($in['smtp_username'] ?? '');
            if (preg_match('/[\x00-\x1F\x7F]/', $username) || strlen($username) > 255) {
                $errors['smtp_username'] = __('Usuário inválido: use até 255 caracteres, numa linha só.', 'mailbridge');
            } else {
                $clean['smtp_username'] = trim($username);
            }

            $password = (string) ($in['smtp_passwd'] ?? '');
            if (!empty($in['clear_passwd'])) {
                $clean['smtp_passwd'] = '';
            } elseif ($password !== '') {
                if (preg_match('/[\x00-\x1F\x7F]/', $password) || strlen($password) > 255) {
                    $errors['smtp_passwd'] = __('Senha inválida: use até 255 caracteres, numa linha só.', 'mailbridge');
                } else {
                    $clean['smtp_passwd'] = $password;
                }
            }
            // Senha em branco = manter a que já está guardada (a chave nem é enviada).

            $clean['smtp_check_certificate'] = empty($in['smtp_check_certificate']) ? 0 : 1;

            $return_path = trim((string) ($in['smtp_sender'] ?? ''));
            if ($return_path !== '' && !self::isEmail($return_path)) {
                $errors['smtp_sender'] = __('Endereço de retorno inválido. Exemplo: devolvidos@suaempresa.com.br', 'mailbridge');
            } else {
                $clean['smtp_sender'] = $return_path;
            }
        } elseif ($transport !== 'oauth') {
            $errors['transport'] = __('Escolha como os e-mails serão enviados.', 'mailbridge');
        }

        // Endereços e nomes.
        $addresses = [
            'from_email'    => __('E-mail do remetente inválido. Exemplo: suporte@suaempresa.com.br', 'mailbridge'),
            'replyto_email' => __('E-mail de resposta inválido. Exemplo: suporte@suaempresa.com.br', 'mailbridge'),
            'noreply_email' => __('E-mail "não responda" inválido. Exemplo: naoresponda@suaempresa.com.br', 'mailbridge'),
            'admin_email'   => __('E-mail do administrador inválido. Exemplo: ti@suaempresa.com.br', 'mailbridge'),
        ];
        foreach ($addresses as $key => $message) {
            $value = trim((string) ($in[$key] ?? ''));
            if ($value !== '' && !self::isEmail($value)) {
                $errors[$key] = $message;
            } else {
                $clean[$key] = $value;
            }
            $clean[$key . '_name'] = Text::oneLine((string) ($in[$key . '_name'] ?? ''));
        }

        if ($mailing_on && !isset($errors['from_email']) && !isset($errors['admin_email'])
            && ($clean['from_email'] ?? '') === '' && ($clean['admin_email'] ?? '') === ''
        ) {
            $errors['from_email'] = __(
                'Informe o e-mail do remetente: sem ele nenhum e-mail pode sair. Exemplo: suporte@suaempresa.com.br',
                'mailbridge'
            );
        }

        $signature = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) ($in['mailing_signature'] ?? ''));
        $signature = str_replace(["\r\n", "\r"], "\n", $signature);
        if (strlen($signature) > 2000) {
            $errors['mailing_signature'] = __('Assinatura longa demais: use até 2000 caracteres.', 'mailbridge');
        } else {
            $clean['mailing_signature'] = trim($signature);
        }

        $clean['attach_ticket_documents_to_mail'] = empty($in['attach_ticket_documents_to_mail']) ? 0 : 1;

        return [$clean, $errors];
    }

    /**
     * Ajustes próprios do MailBridge.
     *
     * @param array $in valores como digitados (só as chaves presentes são tratadas)
     * @return array{0: array, 1: array<string,string>}
     */
    public static function mailbridge(array $in): array
    {
        $clean  = [];
        $errors = [];

        foreach (['enabled', 'send_immediately', 'debug_log', 'purge_on_uninstall'] as $flag) {
            if (array_key_exists($flag, $in)) {
                $clean[$flag] = empty($in[$flag]) ? 0 : 1;
            }
        }

        if (array_key_exists('sender_email', $in)) {
            $sender = trim((string) $in['sender_email']);
            if ($sender !== '' && !self::isEmail($sender)) {
                $errors['sender_email'] = __('E-mail do remetente do MailBridge inválido. Exemplo: suporte@suaempresa.com.br', 'mailbridge');
            } else {
                $clean['sender_email'] = $sender;
            }
        }
        if (array_key_exists('sender_name', $in)) {
            $clean['sender_name'] = Text::oneLine((string) $in['sender_name']);
        }

        foreach (['block_list', 'allow_list'] as $list) {
            if (!array_key_exists($list, $in)) {
                continue;
            }
            [$valid, $invalid] = self::addressList((string) $in[$list]);
            if ($invalid) {
                $errors[$list] = sprintf(
                    __('Não entendi: %s. Use um endereço (pessoa@empresa.com) ou um domínio (@empresa.com) por linha.', 'mailbridge'),
                    implode(', ', array_slice($invalid, 0, 5))
                );
            } else {
                $clean[$list] = implode("\n", $valid);
            }
        }

        return [$clean, $errors];
    }

    /**
     * Regras da fila (novas tentativas e limpeza).
     *
     * @return array{0: array{max_retries?:int,retry_minutes?:int,keep_days?:int,batch?:int}, 1: array<string,string>}
     */
    public static function queuePolicy(array $in): array
    {
        $clean  = [];
        $errors = [];
        $fields = [
            'max_retries'   => [0, 20, __('Número de novas tentativas inválido: use de 0 a 20.', 'mailbridge')],
            'retry_minutes' => [0, 1440, __('Intervalo inválido: use de 0 a 1440 minutos (1 dia).', 'mailbridge')],
            'keep_days'     => [0, 3650, __('Prazo inválido: use de 0 a 3650 dias (0 = nunca apagar).', 'mailbridge')],
            'batch'         => [1, 500, __('Quantidade inválida: use de 1 a 500 e-mails por rodada.', 'mailbridge')],
        ];
        foreach ($fields as $key => [$min, $max, $message]) {
            $value = self::intBetween($in[$key] ?? '', $min, $max);
            if ($value === null) {
                $errors[$key] = $message;
            } else {
                $clean[$key] = $value;
            }
        }

        return [$clean, $errors];
    }

    /**
     * Regra (evento, destinatários e filtros).
     *
     * @param array $in     valores como digitados
     * @param array $events eventos válidos: código => rótulo
     * @return array{0: array, 1: array<string,string>}
     */
    public static function rule(array $in, array $events): array
    {
        $clean  = [];
        $errors = [];

        $event = (string) ($in['event'] ?? '');
        if (!isset($events[$event])) {
            $errors['event'] = __('Escolha o acontecimento que dispara o aviso.', 'mailbridge');
        } else {
            $clean['event'] = $event;
        }

        $name = Text::oneLine((string) ($in['name'] ?? ''));
        if ($name === '' && isset($events[$event])) {
            $name = (string) $events[$event];
        }
        if ($name === '') {
            $errors['name'] = __('Dê um nome para a regra. Exemplo: Avisar o técnico quando receber um chamado', 'mailbridge');
        } else {
            $clean['name'] = $name;
        }

        foreach (['is_active', 'notify_assigned_users', 'notify_assigned_groups', 'notify_requesters', 'notify_observers', 'exclude_author'] as $flag) {
            $clean[$flag] = empty($in[$flag]) ? 0 : 1;
        }

        [$valid, $invalid] = self::emailList((string) ($in['extra_emails'] ?? ''));
        if ($invalid) {
            $errors['extra_emails'] = sprintf(
                __('E-mails inválidos: %s. Corrija ou apague esses endereços (um por linha).', 'mailbridge'),
                implode(', ', array_slice($invalid, 0, 5))
            );
        } else {
            $clean['extra_emails'] = implode("\n", $valid);
        }

        if (!$invalid && !$valid
            && !$clean['notify_assigned_users'] && !$clean['notify_assigned_groups']
            && !$clean['notify_requesters'] && !$clean['notify_observers']
        ) {
            $errors['recipients'] = __('Escolha pelo menos um destinatário, ou informe um e-mail fixo.', 'mailbridge');
        }

        $clean['filter_entities_id']       = max(0, (int) ($in['filter_entities_id'] ?? 0));
        $clean['filter_itilcategories_id'] = max(0, (int) ($in['filter_itilcategories_id'] ?? 0));

        $type = (int) ($in['filter_type'] ?? 0);
        $clean['filter_type'] = in_array($type, [0, 1, 2], true) ? $type : 0;

        $priority = (int) ($in['filter_min_priority'] ?? 0);
        $clean['filter_min_priority'] = ($priority >= 0 && $priority <= 6) ? $priority : 0;

        return [$clean, $errors];
    }

    /**
     * Modelo de e-mail (assunto, corpo e versão em texto).
     *
     * @param array    $in    valores como digitados
     * @param string[] $known variáveis permitidas (sem os ##)
     * @return array{0: array, 1: array<string,string>, 2: string[]} [limpos, erros, variáveis desconhecidas]
     */
    public static function template(array $in, array $known): array
    {
        $clean  = [];
        $errors = [];

        $subject = Text::oneLine((string) ($in['subject'] ?? ''));
        if ($subject === '') {
            $errors['subject'] = __('Escreva o assunto do e-mail. Exemplo: Chamado ##ticket.id## atribuído a você', 'mailbridge');
        } else {
            $clean['subject'] = $subject;
        }

        $body = trim((string) ($in['body'] ?? ''));
        if (trim(strip_tags(str_replace('&nbsp;', ' ', $body))) === '') {
            $errors['body'] = __('Escreva a mensagem do e-mail. Use "Restaurar padrão" se quiser começar pelo texto sugerido.', 'mailbridge');
        } elseif (strlen($body) > 200000) {
            $errors['body'] = __('Mensagem longa demais: reduza o texto.', 'mailbridge');
        } else {
            $clean['body'] = $body;
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string) ($in['body_text'] ?? ''));
        $clean['body_text'] = trim($text);

        $unknown = Template::unknownVariables($known, $subject, $body, $text);

        return [$clean, $errors, $unknown];
    }
}
