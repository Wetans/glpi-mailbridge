<?php

namespace GlpiPlugin\Mailbridge;

/**
 * Situação do envio de e-mails: Ativo, Inativo ou Precisa de atenção, com o
 * motivo e o que fazer. Classe pura: recebe os fatos já coletados (veja
 * Facts::collect()) e não consulta nada.
 */
final class Health
{
    public const ACTIVE    = 'active';
    public const INACTIVE  = 'inactive';
    public const ATTENTION = 'attention';

    public const OK    = 'ok';
    public const INFO  = 'info';
    public const WARN  = 'warn';
    public const ERROR = 'error';
    /** Desligado de propósito (não é defeito). */
    public const OFF   = 'off';

    /** Fila parada há mais que isso (segundos) vira alerta. */
    public const BACKLOG_SECONDS = 900;

    public static function defaults(): array
    {
        return [
            'notifications_on'    => false,
            'mailing_on'          => false,
            'mailbridge_on'         => false,
            'transport'           => 'smtp',   // smtp | mail | oauth
            'smtp_host'           => '',
            'smtp_port'           => 0,
            'security'            => 'none',   // none | ssl | tls
            'mail_function'       => true,
            'oauth_ready'         => false,
            'legacy_active'       => false,    // o plugin com o nome antigo (Techmail) ainda está ativo
            'login_without_password' => false, // há usuário de login, mas nenhuma senha guardada
            'provider'            => '',       // Gmail, Microsoft... (servidor que sempre exige login), veja Provider
            'has_login'           => true,     // há usuário de login informado
            'sender_email'        => '',
            'last_test_ok'        => 0,
            'test_current'        => true,     // o último teste com sucesso foi feito com a configuração atual
            'send_immediately'    => false,
            'cron_state'          => 1,        // 0 = desativada
            'cron_mode'           => 2,        // 1 = só com o GLPI em uso, 2 = cron do sistema
            'cron_frequency'      => 60,
            'cron_lastrun'        => 0,
            'pending'             => 0,
            'oldest_pending'      => 0,
            'failing_7d'          => 0,
            'last_sent'           => 0,
            'last_failure'        => 0,
            'last_failure_error'  => '',
            'active_rules'        => 0,
            'total_rules'         => 0,
            'users_without_email' => null,
            'log_in_files'        => true,
            'url_base'            => '',
        ];
    }

    /**
     * @param array $facts fatos coletados
     * @param int   $now   horário atual (timestamp)
     * @return array{status:string,reasons:string[],checks:array<int,array>,checklist:array<int,array>,progress:int}
     */
    public static function evaluate(array $facts, int $now): array
    {
        $f      = $facts + self::defaults();
        $checks = [];

        // 1. Modo e-mail do GLPI.
        $mode_on = $f['notifications_on'] && $f['mailing_on'];
        $checks[] = self::check(
            'mode',
            $mode_on ? self::OK : self::OFF,
            $mode_on
                ? __('O envio de e-mails do GLPI está ligado.', 'mailbridge')
                : __('O envio de e-mails do GLPI está desligado.', 'mailbridge'),
            $mode_on ? '' : __('Nenhum e-mail de notificação sai enquanto estiver desligado.', 'mailbridge'),
            'sending',
            $mode_on ? '' : __('Ligar o envio', 'mailbridge')
        );

        // 2. Avisos do próprio MailBridge.
        $checks[] = self::check(
            'mailbridge',
            $f['mailbridge_on'] ? self::OK : self::OFF,
            $f['mailbridge_on']
                ? __('Os avisos do MailBridge estão ligados.', 'mailbridge')
                : __('Os avisos do MailBridge estão desligados.', 'mailbridge'),
            $f['mailbridge_on'] ? '' : __('As regras do MailBridge não geram e-mails enquanto estiver desligado.', 'mailbridge'),
            'events',
            $f['mailbridge_on'] ? '' : __('Ligar os avisos', 'mailbridge')
        );

        // 2b. O plugin com o nome antigo ativo ao mesmo tempo mandaria cada aviso duas vezes.
        if ($f['legacy_active']) {
            $checks[] = self::check(
                'legacy',
                self::ERROR,
                __('O plugin antigo (Techmail) está ativo junto com o MailBridge.', 'mailbridge'),
                __('Para não mandar o mesmo aviso duas vezes, os avisos do MailBridge ficam parados. Desative o Techmail em Configurar > Plugins.', 'mailbridge')
            );
        }

        // 3. Servidor de envio.
        $transport_ok = false;
        if ($f['transport'] === 'mail') {
            $transport_ok = (bool) $f['mail_function'];
            $checks[] = self::check(
                'transport',
                $transport_ok ? self::OK : self::ERROR,
                $transport_ok
                    ? __('Envio pela função de e-mail do próprio servidor do GLPI.', 'mailbridge')
                    : __('A função de e-mail do servidor do GLPI não está disponível.', 'mailbridge'),
                $transport_ok ? '' : __('Escolha "Servidor de e-mail (SMTP)" e informe os dados do seu provedor.', 'mailbridge'),
                'sending',
                $transport_ok ? '' : __('Configurar o envio', 'mailbridge')
            );
        } elseif ($f['transport'] === 'oauth') {
            $transport_ok = (bool) $f['oauth_ready'] && $f['smtp_host'] !== '';
            $checks[] = self::check(
                'transport',
                $transport_ok ? self::OK : self::ERROR,
                $transport_ok
                    ? sprintf(__('Servidor de envio: %s (login pelo provedor, OAuth).', 'mailbridge'), $f['smtp_host'])
                    : __('A autorização no provedor de e-mail (OAuth) não foi concluída.', 'mailbridge'),
                $transport_ok ? '' : __('Conclua a autorização na tela de e-mail do GLPI.', 'mailbridge'),
                'sending',
                $transport_ok ? '' : __('Ver o envio', 'mailbridge')
            );
        } else {
            $server_ok    = $f['smtp_host'] !== '' && (int) $f['smtp_port'] > 0;
            $no_password  = $server_ok && !empty($f['login_without_password']);
            $no_login     = $server_ok && $f['provider'] !== '' && !$f['has_login'];
            $transport_ok = $server_ok && !$no_password && !$no_login;
            if ($no_login) {
                $checks[] = self::check(
                    'transport',
                    self::ERROR,
                    sprintf(__('O %s exige login: falta informar o usuário e a senha.', 'mailbridge'), $f['provider']),
                    sprintf(__('Sem login o %s recusa todos os e-mails. Informe como usuário o endereço completo da conta e a senha dela.', 'mailbridge'), $f['provider']),
                    'sending',
                    __('Informar o usuário', 'mailbridge')
                );
            } elseif ($no_password) {
                $checks[] = self::check(
                    'transport',
                    self::ERROR,
                    __('Falta guardar a senha do servidor de envio.', 'mailbridge'),
                    __('Há um usuário de login, mas nenhuma senha guardada: o servidor vai recusar todos os e-mails.', 'mailbridge'),
                    'sending',
                    __('Informar a senha', 'mailbridge')
                );
            } else {
                $checks[] = self::check(
                    'transport',
                    $transport_ok ? self::OK : self::ERROR,
                    $transport_ok
                        ? sprintf(__('Servidor de envio: %1$s, porta %2$d.', 'mailbridge'), $f['smtp_host'], (int) $f['smtp_port'])
                        : __('Falta informar o servidor de envio.', 'mailbridge'),
                    $transport_ok ? '' : __('Sem o servidor e a porta, o GLPI não tem por onde mandar os e-mails.', 'mailbridge'),
                    'sending',
                    $transport_ok ? '' : __('Configurar o envio', 'mailbridge')
                );
            }
        }

        // 4. Remetente. Gmail e Microsoft só aceitam um endereço que exista (não "admsys@localhost").
        $sender_valid = Validation::isEmail((string) $f['sender_email']);
        $sender_real  = $f['transport'] === 'mail' || $f['provider'] === '' || Provider::isPublicAddress((string) $f['sender_email']);
        $sender_ok    = $sender_valid && $sender_real;
        if ($sender_valid && !$sender_real) {
            $checks[] = self::check(
                'sender',
                self::ERROR,
                sprintf(__('O remetente "%s" não é um endereço de e-mail de verdade.', 'mailbridge'), $f['sender_email']),
                sprintf(__('O %s recusa e-mails com esse remetente. Informe o endereço da conta que faz o login.', 'mailbridge'), $f['provider']),
                'sending',
                __('Informar o remetente', 'mailbridge')
            );
        } else {
            $checks[] = self::check(
                'sender',
                $sender_ok ? self::OK : self::ERROR,
                $sender_ok
                    ? sprintf(__('Remetente: %s.', 'mailbridge'), $f['sender_email'])
                    : __('Falta informar o e-mail do remetente.', 'mailbridge'),
                $sender_ok ? '' : __('Os e-mails precisam de um endereço de origem válido.', 'mailbridge'),
                'sending',
                $sender_ok ? '' : __('Informar o remetente', 'mailbridge')
            );
        }

        // 5. Teste. Um sucesso com outro servidor, outra conta ou outro remetente não vale mais.
        $tested_before = (int) $f['last_test_ok'] > 0;
        $tested        = $tested_before && $f['test_current'];
        $checks[] = self::check(
            'test',
            $tested ? self::OK : self::WARN,
            $tested
                ? sprintf(__('Último e-mail de teste com sucesso: %s.', 'mailbridge'), self::ago($now - (int) $f['last_test_ok']))
                : ($tested_before
                    ? __('A configuração de envio mudou depois do último teste com sucesso.', 'mailbridge')
                    : __('Nenhum e-mail de teste foi enviado com sucesso ainda.', 'mailbridge')),
            $tested ? '' : __('Envie um teste para confirmar que o servidor aceita os e-mails.', 'mailbridge'),
            'sending',
            $tested ? '' : __('Enviar e-mail de teste', 'mailbridge'),
            ['focus' => 'test']
        );

        // 6. Rotina automática que despacha a fila.
        $checks[] = self::cronCheck($f, $now);

        // 7. Fila acumulando.
        $waiting = (int) $f['oldest_pending'] > 0 ? $now - (int) $f['oldest_pending'] : 0;
        $backlog = (int) $f['pending'] > 0 && $waiting > self::BACKLOG_SECONDS;
        $checks[] = self::check(
            'backlog',
            $backlog ? self::WARN : self::OK,
            $backlog
                ? sprintf(
                    _n(
                        '%1$d e-mail esperando na fila (o mais antigo %2$s).',
                        '%1$d e-mails esperando na fila (o mais antigo %2$s).',
                        (int) $f['pending'],
                        'mailbridge'
                    ),
                    (int) $f['pending'],
                    self::ago($waiting)
                )
                : ((int) $f['pending'] > 0
                    ? sprintf(_n('%d e-mail na fila, dentro do prazo.', '%d e-mails na fila, dentro do prazo.', (int) $f['pending'], 'mailbridge'), (int) $f['pending'])
                    : __('Nenhum e-mail esperando na fila.', 'mailbridge')),
            $backlog ? __('A fila deveria esvaziar em poucos minutos. Veja se a rotina automática está rodando.', 'mailbridge') : '',
            'queue',
            $backlog ? __('Ver a fila', 'mailbridge') : '',
            ['filter' => 'queued']
        );

        // 8. Falhas recentes.
        $failing = (int) $f['failing_7d'] > 0;
        $checks[] = self::check(
            'failures',
            $failing ? self::WARN : self::OK,
            $failing
                ? sprintf(
                    _n('%d e-mail com falha nos últimos 7 dias.', '%d e-mails com falha nos últimos 7 dias.', (int) $f['failing_7d'], 'mailbridge'),
                    (int) $f['failing_7d']
                )
                : __('Nenhuma falha de envio nos últimos 7 dias.', 'mailbridge'),
            $failing ? __('Abra a lista para ver o motivo de cada um e reenviar.', 'mailbridge') : '',
            'queue',
            $failing ? __('Ver falhas', 'mailbridge') : '',
            ['filter' => 'failed']
        );

        // 9. Regras.
        $has_rules = (int) $f['active_rules'] > 0;
        $checks[] = self::check(
            'rules',
            $has_rules ? self::OK : self::WARN,
            $has_rules
                ? sprintf(__('%1$d de %2$d regras de aviso ligadas.', 'mailbridge'), (int) $f['active_rules'], (int) $f['total_rules'])
                : __('Nenhuma regra de aviso está ligada.', 'mailbridge'),
            $has_rules ? '' : __('Sem regras ligadas, o MailBridge não avisa ninguém.', 'mailbridge'),
            'events',
            $has_rules ? '' : __('Ver as regras', 'mailbridge')
        );

        // Verificações extras (só no Diagnóstico).
        if ($f['users_without_email'] !== null) {
            $n = (int) $f['users_without_email'];
            $checks[] = self::check(
                'users',
                $n > 0 ? self::INFO : self::OK,
                $n > 0
                    ? sprintf(_n('%d usuário ativo sem e-mail cadastrado.', '%d usuários ativos sem e-mail cadastrado.', $n, 'mailbridge'), $n)
                    : __('Todos os usuários ativos têm e-mail cadastrado.', 'mailbridge'),
                $n > 0 ? __('Quem não tem e-mail no cadastro não recebe avisos. Confira os técnicos em Administração > Usuários.', 'mailbridge') : '',
                '',
                '',
                [],
                true
            );
        }
        if (!$f['log_in_files']) {
            $checks[] = self::check(
                'logging',
                self::INFO,
                __('O registro em arquivos do GLPI está desligado.', 'mailbridge'),
                __('Sem ele o MailBridge não consegue mostrar o motivo das falhas de envio. Ligue em Configurar > Geral > Sistema.', 'mailbridge'),
                '',
                '',
                [],
                true
            );
        }
        $host = strtolower((string) parse_url((string) $f['url_base'], PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || $host === '::1' || strpos($host, '127.') === 0) {
            $checks[] = self::check(
                'url',
                self::INFO,
                sprintf(__('Os links dos e-mails apontam para "%s".', 'mailbridge'), (string) $f['url_base']),
                __('Esse endereço só abre neste computador. Em produção, ajuste o endereço do GLPI em Configurar > Geral.', 'mailbridge'),
                '',
                '',
                [],
                true
            );
        }
        $checks[] = self::check(
            'last_sent',
            self::INFO,
            (int) $f['last_sent'] > 0
                ? sprintf(__('Último e-mail enviado: %s.', 'mailbridge'), self::ago($now - (int) $f['last_sent']))
                : __('Nenhum e-mail enviado ainda (dentro do período guardado).', 'mailbridge'),
            '',
            '',
            '',
            [],
            true
        );

        // Situação geral.
        $off      = [];
        $problems = [];
        foreach ($checks as $check) {
            if ($check['level'] === self::OFF) {
                $off[] = $check['title'];
            } elseif ($check['level'] === self::WARN || $check['level'] === self::ERROR) {
                $problems[] = $check['title'];
            }
        }
        if ($off) {
            $status  = self::INACTIVE;
            $reasons = $off;
        } elseif ($problems) {
            $status  = self::ATTENTION;
            $reasons = $problems;
        } else {
            $status  = self::ACTIVE;
            $reasons = [];
        }

        // Primeiros passos.
        $checklist = [
            [
                'id'      => 'server',
                'label'   => __('Configurar o servidor de envio e o remetente', 'mailbridge'),
                'done'    => $transport_ok && $sender_ok,
                'section' => 'sending',
                'params'  => [],
            ],
            [
                'id'      => 'test',
                'label'   => __('Enviar um e-mail de teste com sucesso', 'mailbridge'),
                'done'    => $tested,
                'section' => 'sending',
                'params'  => ['focus' => 'test'],
            ],
            [
                'id'      => 'mode',
                'label'   => __('Ligar o envio de e-mails e os avisos do MailBridge', 'mailbridge'),
                'done'    => $mode_on && $f['mailbridge_on'],
                'section' => $mode_on ? 'events' : 'sending',
                'params'  => [],
            ],
        ];
        $progress = 0;
        foreach ($checklist as $step) {
            $progress += $step['done'] ? 1 : 0;
        }

        return [
            'status'    => $status,
            'reasons'   => $reasons,
            'checks'    => $checks,
            'checklist' => $checklist,
            'progress'  => $progress,
        ];
    }

    private static function cronCheck(array $f, int $now): array
    {
        $only_in_use = (int) $f['cron_mode'] === 1
            ? ' ' . __('Ela só roda enquanto alguém usa o GLPI; para envio contínuo, peça para agendar o "cron" do GLPI no servidor.', 'mailbridge')
            : '';

        if ((int) $f['cron_state'] === 0) {
            return self::check(
                'cron',
                self::ERROR,
                __('A rotina automática que envia a fila está desativada.', 'mailbridge'),
                __('Os e-mails ficam parados na fila até ela ser reativada.', 'mailbridge'),
                'queue',
                __('Reativar a rotina', 'mailbridge'),
                ['focus' => 'routine']
            );
        }
        if ((int) $f['cron_lastrun'] <= 0) {
            return self::check(
                'cron',
                self::WARN,
                __('A rotina automática que envia a fila ainda não rodou nenhuma vez.', 'mailbridge'),
                __('Use "Processar a fila agora" para enviar o que estiver esperando.', 'mailbridge') . $only_in_use,
                'queue',
                __('Ver a rotina', 'mailbridge'),
                ['focus' => 'routine']
            );
        }

        $age       = $now - (int) $f['cron_lastrun'];
        $threshold = max(3 * (int) $f['cron_frequency'], self::BACKLOG_SECONDS);
        if ($age > $threshold) {
            return self::check(
                'cron',
                self::WARN,
                sprintf(__('A rotina automática que envia a fila parou: rodou pela última vez %s.', 'mailbridge'), self::ago($age)),
                __('Use "Processar a fila agora" e avise quem cuida do servidor.', 'mailbridge') . $only_in_use,
                'queue',
                __('Ver a rotina', 'mailbridge'),
                ['focus' => 'routine']
            );
        }

        return self::check(
            'cron',
            self::OK,
            sprintf(__('A rotina automática que envia a fila está rodando (última vez %s).', 'mailbridge'), self::ago($age)),
            trim($only_in_use),
            'queue',
            ''
        );
    }

    /**
     * "há 5 minutos", "há 2 horas"...
     */
    public static function ago(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return __('há menos de 1 minuto', 'mailbridge');
        }
        if ($seconds < 3600) {
            $n = (int) floor($seconds / 60);

            return sprintf(_n('há %d minuto', 'há %d minutos', $n, 'mailbridge'), $n);
        }
        if ($seconds < 86400) {
            $n = (int) floor($seconds / 3600);

            return sprintf(_n('há %d hora', 'há %d horas', $n, 'mailbridge'), $n);
        }
        $n = (int) floor($seconds / 86400);

        return sprintf(_n('há %d dia', 'há %d dias', $n, 'mailbridge'), $n);
    }

    private static function check(
        string $id,
        string $level,
        string $title,
        string $detail = '',
        string $section = '',
        string $action = '',
        array $params = [],
        bool $extra = false
    ): array {
        return [
            'id'      => $id,
            'level'   => $level,
            'title'   => $title,
            'detail'  => $detail,
            'section' => $section,
            'action'  => $action,
            'params'  => $params,
            'extra'   => $extra,
        ];
    }
}
