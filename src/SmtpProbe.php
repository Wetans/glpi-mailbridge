<?php

namespace GlpiPlugin\Mailbridge;

/**
 * Testa a conexão com o servidor de envio JÁ CONFIGURADO no GLPI (nunca um
 * endereço vindo da tela), passo a passo: conectar, segurança, login.
 * Usa a biblioteca de e-mail que já vem com o GLPI 10 (PHPMailer); o tempo
 * de espera é curto para a tela não ficar travada.
 */
final class SmtpProbe
{
    public const TIMEOUT = 8;

    public const OK   = 'ok';
    public const FAIL = 'fail';
    public const SKIP = 'skip';

    /** Há usuário de login, mas nenhuma senha foi guardada (ErrorExplainer conhece este código). */
    public const NO_PASSWORD = 'missing-password';

    /** Gmail ou Microsoft sem usuário de login: o provedor recusaria cada e-mail (ErrorExplainer conhece este código). */
    public const NO_LOGIN = 'missing-login';

    /**
     * @return array{ok:bool,steps:array<int,array{id:string,label:string,status:string,detail:string}>,error:string}
     */
    public static function run(): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $conf  = Sending::current();
        $steps = [];

        if ($conf['transport'] === 'mail') {
            $ok      = (bool) $conf['mail_function'];
            $steps[] = self::step(
                'connect',
                __('Função de e-mail do servidor do GLPI disponível', 'mailbridge'),
                $ok ? self::OK : self::FAIL
            );

            return ['ok' => $ok, 'steps' => $steps, 'error' => $ok ? '' : 'Could not instantiate mail function'];
        }

        $target = sprintf('%s:%d', $conf['smtp_host'], $conf['smtp_port']);
        if ($conf['smtp_host'] === '' || $conf['smtp_port'] <= 0) {
            $steps[] = self::step('connect', __('Conectar ao servidor de envio', 'mailbridge'), self::FAIL);

            return ['ok' => false, 'steps' => $steps, 'error' => 'missing-server'];
        }

        if ($conf['transport'] === 'oauth') {
            return self::runOauth($target);
        }

        $smtp_class = '\\PHPMailer\\PHPMailer\\SMTP';
        if (!class_exists($smtp_class)) {
            // GLPI 11 usa outra biblioteca: só dá para conferir se a porta atende.
            return self::runSocketOnly($conf, $target);
        }

        $connect_label = sprintf(__('Conectar a %s', 'mailbridge'), $target);
        $secure_label  = $conf['security'] === 'ssl'
            ? __('Conexão segura (SSL/TLS)', 'mailbridge')
            : __('Conexão segura (STARTTLS)', 'mailbridge');
        $auth_label    = __('Entrar com usuário e senha', 'mailbridge');

        /** @var \PHPMailer\PHPMailer\SMTP $smtp */
        $smtp            = new $smtp_class();
        $smtp->Timeout   = self::TIMEOUT;
        $smtp->Timelimit = self::TIMEOUT;

        $options = [];
        if (!$conf['smtp_check_certificate']) {
            $options = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        }
        $host = ($conf['security'] === 'ssl' ? 'ssl://' : '') . $conf['smtp_host'];

        try {
            if (!$smtp->connect($host, $conf['smtp_port'], self::TIMEOUT, $options)) {
                $steps[] = self::step('connect', $connect_label, self::FAIL);

                return self::fail($steps, self::errorOf($smtp));
            }
            $hello = (string) (gethostname() ?: 'localhost');
            if (!$smtp->hello($hello)) {
                $steps[] = self::step('connect', $connect_label, self::FAIL);

                return self::fail($steps, self::errorOf($smtp), $smtp);
            }
            $steps[] = self::step('connect', $connect_label, self::OK);

            if ($conf['security'] === 'ssl') {
                $steps[] = self::step('encryption', $secure_label, self::OK);
            } elseif ($conf['security'] === 'tls') {
                if (!$smtp->startTLS() || !$smtp->hello($hello)) {
                    $steps[] = self::step('encryption', $secure_label, self::FAIL);

                    return self::fail($steps, 'STARTTLS ' . self::errorOf($smtp), $smtp);
                }
                $steps[] = self::step('encryption', $secure_label, self::OK);
            } else {
                $steps[] = self::step(
                    'encryption',
                    __('Conexão segura', 'mailbridge'),
                    self::SKIP,
                    __('Não usada: a segurança está em "Nenhuma".', 'mailbridge')
                );
            }

            if ($conf['smtp_username'] !== '') {
                if (!$conf['has_password']) {
                    // Sem senha guardada o servidor recusaria o login e a mensagem dele ("usuário e
                    // senha não aceitos") esconderia o motivo real: nem tenta.
                    $steps[] = self::step('auth', $auth_label, self::FAIL, __('Nenhuma senha está guardada.', 'mailbridge'));

                    return self::fail($steps, self::NO_PASSWORD, $smtp);
                }
                $password = (string) (new \GLPIKey())->decrypt((string) ($CFG_GLPI['smtp_passwd'] ?? ''));
                if (!$smtp->authenticate($conf['smtp_username'], $password)) {
                    $steps[] = self::step('auth', $auth_label, self::FAIL);

                    return self::fail($steps, 'Could not authenticate. ' . self::errorOf($smtp), $smtp);
                }
                $steps[] = self::step('auth', $auth_label, self::OK);
            } elseif (Provider::name($conf['smtp_host']) !== '') {
                // A conexão funciona sem login, mas o provedor recusa o envio depois ("not authenticated").
                $steps[] = self::step(
                    'auth',
                    $auth_label,
                    self::FAIL,
                    sprintf(__('O %s exige login e nenhum usuário foi informado.', 'mailbridge'), Provider::name($conf['smtp_host']))
                );

                return self::fail($steps, self::NO_LOGIN, $smtp);
            } else {
                $steps[] = self::step(
                    'auth',
                    $auth_label,
                    self::SKIP,
                    __('Não usado: nenhum usuário foi informado.', 'mailbridge')
                );
            }

            $smtp->quit();
            $smtp->close();
        } catch (\Throwable $e) {
            $steps[] = self::step('connect', $connect_label, self::FAIL);

            return self::fail($steps, $e->getMessage(), $smtp);
        }

        return ['ok' => true, 'steps' => $steps, 'error' => ''];
    }

    private static function runOauth(string $target): array
    {
        $label = sprintf(__('Conectar e entrar em %s (login pelo provedor)', 'mailbridge'), $target);
        try {
            $mailer          = new \GLPIMailer();
            $mailer->Timeout = self::TIMEOUT;
            $ok              = (bool) $mailer->smtpConnect();
            $error           = $ok ? '' : (string) $mailer->ErrorInfo;
            $mailer->smtpClose();
        } catch (\Throwable $e) {
            $ok    = false;
            $error = $e->getMessage();
        }

        return [
            'ok'    => $ok,
            'steps' => [self::step('connect', $label, $ok ? self::OK : self::FAIL)],
            'error' => $error,
        ];
    }

    private static function runSocketOnly(array $conf, string $target): array
    {
        $errno  = 0;
        $errstr = '';
        $prefix = $conf['security'] === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client($prefix . $target, $errno, $errstr, self::TIMEOUT);
        $ok     = is_resource($socket);
        if ($ok) {
            fclose($socket);
        }

        return [
            'ok'    => $ok,
            'steps' => [self::step('connect', sprintf(__('Conectar a %s', 'mailbridge'), $target), $ok ? self::OK : self::FAIL)],
            'error' => $ok ? '' : trim('Failed to connect to server ' . $errstr . ' (' . $errno . ')'),
        ];
    }

    /**
     * @param object|null $smtp
     */
    private static function fail(array $steps, string $error, $smtp = null): array
    {
        if ($smtp !== null) {
            try {
                $smtp->close();
            } catch (\Throwable $ignored) {
                // nada a fazer
            }
        }

        return ['ok' => false, 'steps' => $steps, 'error' => Text::oneLine($error, 600)];
    }

    /**
     * @param object $smtp
     */
    private static function errorOf($smtp): string
    {
        $error = (array) $smtp->getError();
        $code  = (string) ($error['smtp_code'] ?? '');

        return trim(
            (string) ($error['error'] ?? '') . ' '
            . (string) ($error['detail'] ?? '') . ' '
            . ($code !== '' ? '(' . $code . ')' : '') . ' '
            . (string) ($error['smtp_code_ex'] ?? '')
        );
    }

    private static function step(string $id, string $label, string $status, string $detail = ''): array
    {
        return ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
