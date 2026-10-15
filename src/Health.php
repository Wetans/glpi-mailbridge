<?php

namespace GlpiPlugin\Mailtech;

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
            'mailtech_on'         => false,
            'transport'           => 'smtp',   // smtp | mail | oauth
            'smtp_host'           => '',
            'smtp_port'           => 0,
            'security'            => 'none',   // none | ssl | tls
            'mail_function'       => true,
            'oauth_ready'         => false,
            'legacy_active'       => '',       // nome do plugin anterior (MailBridge, Techmail) ainda ativo; '' = nenhum
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
                ? __('O envio de e-mails do GLPI está ligado.', 'mailtech')
                : __('O envio de e-mails do GLPI está desligado.', 'mailtech'),
            $mode_on ? '' : __('Nenhum e-mail de notificação sai enquanto estiver desligado.', 'mailtech'),
            'sending',
            $mode_on ? '' : __('Ligar o envio', 'mailtech')
        );

        // 2. Avisos do próprio MailTech.
        $checks[] = self::check(
            'mailtech',
            $f['mailtech_on'] ? self::OK : self::OFF,
            $f['mailtech_on']
                ? __('Os avisos do MailTech estão ligados.', 'mailtech')
                : __('Os avisos do MailTech estão desligados.', 'mailtech'),
            $f['mailtech_on'] ? '' : __('As regras do MailTech não geram e-mails enquanto estiver desligado.', 'mailtech'),
            'events',
            $f['mailtech_on'] ? '' : __('Ligar os avisos', 'mailtech')
        );

        // 2b. Um plugin com nome anterior ativo ao mesmo tempo mandaria cada aviso duas vezes.
        if ($f['legacy_active'] !== '') {
            $checks[] = self::check(
                'legacy',
                self::ERROR,
                sprintf(__('O plugin antigo (%s) está ativo junto com o MailTech.', 'mailtech'), $f['legacy_active']),
                sprintf(__('Para não mandar o mesmo aviso duas vezes, os avisos do MailTech ficam parados. Desative o %s em Configurar > Plugins.', 'mailtech'), $f['legacy_active'])
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
                    ? __('Envio pela função de e-mail do próprio servidor do GLPI.', 'mailtech')
                    : __('A função de e-mail do servidor do GLPI não está disponível.', 'mailtech'),
                $transport_ok ? '' : __('Escolha "Servidor de e-mail (SMTP)" e informe os dados do seu provedor.', 'mailtech'),
                'sending',
                $transport_ok ? '' : __('Configurar o envio', 'mailtech')
            );
        } elseif ($f['transport'] === 'oauth') {
            $transport_ok = (bool) $f['oauth_ready'] && $f['smtp_host'] !== '';
            $checks[] = self::check(
                'transport',
                $transport_ok ? self::OK : self::ERROR,
                $transport_ok
                    ? sprintf(__('Servidor de envio: %s (login pelo provedor, OAuth).', 'mailtech'), $f['smtp_host'])
                    : __('A autorização no provedor de e-mail (OAuth) não foi concluída.', 'mailtech'),
                $transport_ok ? '' : __('Conclua a autorização na tela de e-mail do GLPI.', 'mailtech'),
                'sending',
                $transport_ok ? '' : __('Ver o envio', 'mailtech')
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
                    sprintf(__('O %s exige login: falta informar o usuário e a senha.', 'mailtech'), $f['provider']),
                    sprintf(__('Sem login o %s recusa todos os e-mails. Informe como usuário o endereço completo da conta e a senha dela.', 'mailtech'), $f['provider']),
                    'sending',
                    __('Informar o usuário', 'mailtech')
                );
            } elseif ($no_password) {
                $checks[] = self::check(
                    'transport',
                    self::ERROR,
                    __('Falta guardar a senha do servidor de envio.', 'mailtech'),
                    __('Há um usuário de login, mas nenhuma senha guardada: o servidor vai recusar todos os e-mails.', 'mailtech'),
                    'sending',
                    __('Informar a senha', 'mailtech')
                );
            } else {
                $checks[] = self::check(
                    'transport',
                    $transport_ok ? self::OK : self::ERROR,
                    $transport_ok
                        ? sprintf(__('Servidor de envio: %1$s, porta %2$d.', 'mailtech'), $f['smtp_host'], (int) $f['smtp_port'])
                        : __('Falta informar o servidor de envio.', 'mailtech'),
                    $transport_ok ? '' : __('Sem o servidor e a porta, o GLPI não tem por onde mandar os e-mails.', 'mailtech'),
                    'sending',
                    $transport_ok ? '' : __('Configurar o envio', 'mailtech')
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
                sprintf(__('O remetente "%s" não é um endereço de e-mail de verdade.', 'mailtech'), $f['sender_email']),
                sprintf(__('O %s recusa e-mails com esse remetente. Informe o endereço da conta que faz o login.', 'mailtech'), $f['provider']),
                'sending',
                __('Informar o remetente', 'mailtech')
            );
        } else {
            $checks[] = self::check(
                'sender',
                $sender_ok ? self::OK : self::ERROR,
                $sender_ok
                    ? sprintf(__('Remetente: %s.', 'mailtech'), $f['sender_email'])
                    : __('Falta informar o e-mail do remetente.', 'mailtech'),
                $sender_ok ? '' : __('Os e-mails precisam de um endereço de origem válido.', 'mailtech'),
                'sending',
                $sender_ok ? '' : __('Informar o remetente', 'mailtech')
            );
        }

        // 5. Teste. Um sucesso com outro servidor, outra conta ou outro remetente não vale mais.
        $tested_before = (int) $f['last_test_ok'] > 0;
        $tested        = $tested_before && $f['test_current'];
        $checks[] = self::check(
            'test',
            $tested ? self::OK : self::WARN,
            $tested
                ? sprintf(__('Último e-mail de teste com sucesso: %s.', 'mailtech'), self::ago($now - (int) $f['last_test_ok']))
                : ($tested_before
                    ? __('A configuração de envio mudou depois do último teste com sucesso.', 'mailtech')
                    : __('Nenhum e-mail de teste foi enviado com sucesso ainda.', 'mailtech')),
            $tested ? '' : __('Envie um teste para confirmar que o servidor aceita os e-mails.', 'mailtech'),
            'sending',
            $tested ? '' : __('Enviar e-mail de teste', 'mailtech'),
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
                        'mailtech'
                    ),
                    (int) $f['pending'],
                    self::ago($waiting)
                )
                : ((int) $f['pending'] > 0
                    ? sprintf(_n('%d e-mail na fila, dentro do prazo.', '%d e-mails na fila, dentro do prazo.', (int) $f['pending'], 'mailtech'), (int) $f['pending'])
                    : __('Nenhum e-mail esperando na fila.', 'mailtech')),
            $backlog ? __('A fila deveria esvaziar em poucos minutos. Veja se a rotina automática está rodando.', 'mailtech') : '',
            'queue',
            $backlog ? __('Ver a fila', 'mailtech') : '',
            ['filter' => 'queued']
        );

        // 8. Falhas recentes.
        $failing = (int) $f['failing_7d'] > 0;
        $checks[] = self::check(
            'failures',
            $failing ? self::WARN : self::OK,
            $failing
                ? sprintf(
                    _n('%d e-mail com falha nos últimos 7 dias.', '%d e-mails com falha nos últimos 7 dias.', (int) $f['failing_7d'], 'mailtech'),
                    (int) $f['failing_7d']
                )
                : __('Nenhuma falha de envio nos últimos 7 dias.', 'mailtech'),
            $failing ? __('Abra a lista para ver o motivo de cada um e reenviar.', 'mailtech') : '',
            'queue',
            $failing ? __('Ver falhas', 'mailtech') : '',
            ['filter' => 'failed']
        );

        // 9. Regras.
        $has_rules = (int) $f['active_rules'] > 0;
        $checks[] = self::check(
            'rules',
            $has_rules ? self::OK : self::WARN,
            $has_rules
                ? sprintf(__('%1$d de %2$d regras de aviso ligadas.', 'mailtech'), (int) $f['active_rules'], (int) $f['total_rules'])
                : __('Nenhuma regra de aviso está ligada.', 'mailtech'),
            $has_rules ? '' : __('Sem regras ligadas, o MailTech não avisa ninguém.', 'mailtech'),
            'events',
            $has_rules ? '' : __('Ver as regras', 'mailtech')
        );

        // Verificações extras (só no Diagnóstico).
        if ($f['users_without_email'] !== null) {
            $n = (int) $f['users_without_email'];
            $checks[] = self::check(
                'users',
                $n > 0 ? self::INFO : self::OK,
                $n > 0
                    ? sprintf(_n('%d usuário ativo sem e-mail cadastrado.', '%d usuários ativos sem e-mail cadastrado.', $n, 'mailtech'), $n)
                    : __('Todos os usuários ativos têm e-mail cadastrado.', 'mailtech'),
                $n > 0 ? __('Quem não tem e-mail no cadastro não recebe avisos. Confira os técnicos em Administração > Usuários.', 'mailtech') : '',
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
                __('O registro em arquivos do GLPI está desligado.', 'mailtech'),
                __('Sem ele o MailTech não consegue mostrar o motivo das falhas de envio. Ligue em Configurar > Geral > Sistema.', 'mailtech'),
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
                sprintf(__('Os links dos e-mails apontam para "%s".', 'mailtech'), (string) $f['url_base']),
                __('Esse endereço só abre neste computador. Em produção, ajuste o endereço do GLPI em Configurar > Geral.', 'mailtech'),
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
                ? sprintf(__('Último e-mail enviado: %s.', 'mailtech'), self::ago($now - (int) $f['last_sent']))
                : __('Nenhum e-mail enviado ainda (dentro do período guardado).', 'mailtech'),
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
                'label'   => __('Configurar o servidor de envio e o remetente', 'mailtech'),
                'done'    => $transport_ok && $sender_ok,
                'section' => 'sending',
                'params'  => [],
            ],
            [
                'id'      => 'test',
                'label'   => __('Enviar um e-mail de teste com sucesso', 'mailtech'),
                'done'    => $tested,
                'section' => 'sending',
                'params'  => ['focus' => 'test'],
            ],
            [
                'id'      => 'mode',
                'label'   => __('Ligar o envio de e-mails e os avisos do MailTech', 'mailtech'),
                'done'    => $mode_on && $f['mailtech_on'],
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
            ? ' ' . __('Ela só roda enquanto alguém usa o GLPI; para envio contínuo, peça para agendar o "cron" do GLPI no servidor.', 'mailtech')
            : '';

        if ((int) $f['cron_state'] === 0) {
            return self::check(
                'cron',
                self::ERROR,
                __('A rotina automática que envia a fila está desativada.', 'mailtech'),
                __('Os e-mails ficam parados na fila até ela ser reativada.', 'mailtech'),
                'queue',
                __('Reativar a rotina', 'mailtech'),
                ['focus' => 'routine']
            );
        }
        if ((int) $f['cron_lastrun'] <= 0) {
            return self::check(
                'cron',
                self::WARN,
                __('A rotina automática que envia a fila ainda não rodou nenhuma vez.', 'mailtech'),
                __('Use "Processar a fila agora" para enviar o que estiver esperando.', 'mailtech') . $only_in_use,
                'queue',
                __('Ver a rotina', 'mailtech'),
                ['focus' => 'routine']
            );
        }

        $age       = $now - (int) $f['cron_lastrun'];
        $threshold = max(3 * (int) $f['cron_frequency'], self::BACKLOG_SECONDS);
        if ($age > $threshold) {
            return self::check(
                'cron',
                self::WARN,
                sprintf(__('A rotina automática que envia a fila parou: rodou pela última vez %s.', 'mailtech'), self::ago($age)),
                __('Use "Processar a fila agora" e avise quem cuida do servidor.', 'mailtech') . $only_in_use,
                'queue',
                __('Ver a rotina', 'mailtech'),
                ['focus' => 'routine']
            );
        }

        return self::check(
            'cron',
            self::OK,
            sprintf(__('A rotina automática que envia a fila está rodando (última vez %s).', 'mailtech'), self::ago($age)),
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
            return __('há menos de 1 minuto', 'mailtech');
        }
        if ($seconds < 3600) {
            $n = (int) floor($seconds / 60);

            return sprintf(_n('há %d minuto', 'há %d minutos', $n, 'mailtech'), $n);
        }
        if ($seconds < 86400) {
            $n = (int) floor($seconds / 3600);

            return sprintf(_n('há %d hora', 'há %d horas', $n, 'mailtech'), $n);
        }
        $n = (int) floor($seconds / 86400);

        return sprintf(_n('há %d dia', 'há %d dias', $n, 'mailtech'), $n);
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
