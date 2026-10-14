<?php

namespace GlpiPlugin\Mailbridge;

use Config as CoreConfig;

/**
 * Lê e grava a configuração de envio de e-mails DO GLPI (servidor, segurança,
 * remetentes, assinatura). O MailBridge não tem configuração de servidor
 * própria: esta classe só dá uma tela mais simples para a que já existe em
 * Configurar > Notificações.
 *
 * A senha nunca sai daqui: a tela só fica sabendo se existe uma guardada.
 */
final class Sending
{
    private const SECURITY = [1 => 'none', 2 => 'ssl', 3 => 'tls'];

    /**
     * Configuração atual, pronta para a tela (sem segredos).
     */
    public static function current(): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $mode = (int) ($CFG_GLPI['smtp_mode'] ?? 0);
        if ($mode === 0) {
            $transport = 'mail';
        } elseif ($mode === 4) {
            $transport = 'oauth';
        } else {
            $transport = 'smtp';
        }

        $out = [
            'mailing_on'             => !empty($CFG_GLPI['use_notifications']) && !empty($CFG_GLPI['notifications_mailing']),
            'transport'              => $transport,
            'security'               => $mode === 4 ? 'tls' : (self::SECURITY[$mode] ?? 'none'),
            'smtp_host'              => Text::decode((string) ($CFG_GLPI['smtp_host'] ?? '')),
            'smtp_port'              => (int) ($CFG_GLPI['smtp_port'] ?? 0),
            'smtp_username'          => Text::decode((string) ($CFG_GLPI['smtp_username'] ?? '')),
            'has_password'           => (string) ($CFG_GLPI['smtp_passwd'] ?? '') !== '',
            'smtp_check_certificate' => !empty($CFG_GLPI['smtp_check_certificate']),
            'smtp_sender'            => Text::decode((string) ($CFG_GLPI['smtp_sender'] ?? '')),
            'mailing_signature'      => Text::decode((string) ($CFG_GLPI['mailing_signature'] ?? '')),
            'attach_ticket_documents_to_mail' => !empty($CFG_GLPI['attach_ticket_documents_to_mail']),
            'oauth_ready'            => (string) ($CFG_GLPI['smtp_oauth_refresh_token'] ?? '') !== '',
            'mail_function'          => function_exists('mail'),
        ];
        foreach (['from_email', 'replyto_email', 'noreply_email', 'admin_email'] as $key) {
            $out[$key]           = Text::decode((string) ($CFG_GLPI[$key] ?? ''));
            $out[$key . '_name'] = Text::decode((string) ($CFG_GLPI[$key . '_name'] ?? ''));
        }

        return $out;
    }

    /**
     * Endereço de onde os e-mails saem de fato (o mesmo critério do GLPI:
     * remetente e, na falta dele, o e-mail do administrador).
     */
    public static function senderEmail(): string
    {
        $current = self::current();

        return $current['from_email'] !== '' ? $current['from_email'] : $current['admin_email'];
    }

    /**
     * Resumo de tudo o que decide se um envio funciona (forma, servidor, conta,
     * senha guardada e remetente). Muda quando qualquer um deles muda, inclusive
     * pela tela de e-mail do próprio GLPI, e assim um teste antigo deixa de
     * valer. A senha só entra já criptografada e o resultado é um hash.
     */
    public static function fingerprint(): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $parts = [];
        foreach (['smtp_mode', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_passwd', 'smtp_oauth_provider', 'smtp_oauth_client_id'] as $key) {
            $parts[$key] = (string) ($CFG_GLPI[$key] ?? '');
        }
        $parts['sender'] = self::senderEmail();

        return hash('sha256', (string) json_encode($parts));
    }

    /**
     * Grava valores JÁ VALIDADOS por Validation::sending().
     */
    public static function save(array $clean): void
    {
        $values = [];
        foreach ($clean as $key => $value) {
            if ($key === 'smtp_passwd') {
                // Vai como digitada: o GLPI criptografa ao guardar (GLPIKey).
                $values[$key] = (string) $value;
                continue;
            }
            $values[$key] = is_string($value) ? Text::sanitize($value) : $value;
        }
        if (!$values) {
            return;
        }

        // O próprio GLPI atualiza a configuração em memória ($CFG_GLPI) ao gravar cada chave.
        CoreConfig::setConfigurationValues('core', $values);
    }

    /**
     * Liga ou desliga só a chave do modo e-mail.
     */
    public static function setMode(bool $on): void
    {
        self::save($on
            ? ['use_notifications' => 1, 'notifications_mailing' => 1]
            : ['notifications_mailing' => 0]);
    }
}
