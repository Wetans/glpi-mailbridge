<?php

namespace GlpiPlugin\Mailbridge\Screen;

use GlpiPlugin\Mailbridge\Actions;
use GlpiPlugin\Mailbridge\Facts;
use GlpiPlugin\Mailbridge\Page;
use GlpiPlugin\Mailbridge\Sending;
use GlpiPlugin\Mailbridge\Settings;
use GlpiPlugin\Mailbridge\Validation;

/**
 * Envio: modo e-mail, servidor, remetentes, assinatura e testes.
 */
final class SendingScreen
{
    /**
     * @return array{0:string,1:array}
     */
    public static function build(array $get): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $flash = Page::pullFlash('sending');
        $conf  = Sending::current();

        $values = $conf;
        foreach (['sender_email', 'sender_name', 'block_list', 'allow_list'] as $key) {
            $values[$key] = Settings::text($key);
        }
        foreach (['send_immediately', 'debug_log'] as $key) {
            $values[$key] = (int) Settings::get($key) === 1;
        }

        // Depois de um erro, mostra de novo o que a pessoa digitou (menos a senha).
        $switches = ['mailing_on', 'smtp_check_certificate', 'attach_ticket_documents_to_mail', 'send_immediately', 'debug_log'];
        foreach ($flash['old'] as $key => $old) {
            if (!array_key_exists($key, $values)) {
                continue;
            }
            $values[$key] = in_array($key, $switches, true) ? !empty($old) : (string) $old;
        }

        // Os mesmos problemas de servidor e remetente que a Visão geral mostra, no topo desta tela.
        $problems = [];
        foreach (Facts::health()['checks'] as $check) {
            if (in_array($check['id'], ['transport', 'sender'], true) && $check['level'] === 'error') {
                $problems[] = $check;
            }
        }

        $result = $_SESSION['plugin_mailbridge_result'] ?? null;
        unset($_SESSION['plugin_mailbridge_result']);

        return ['sending.html.twig', [
            'v'            => $values,
            'errors'       => $flash['errors'],
            'problems'     => $problems,
            'hints'        => Validation::hints(),
            'has_password' => $conf['has_password'],
            'is_oauth'     => $conf['transport'] === 'oauth',
            'oauth_ready'  => $conf['oauth_ready'],
            'test_email'   => Actions::currentUserEmail(),
            'result'       => is_array($result) && isset($result['steps']) ? $result : null,
            'focus'        => (string) ($get['focus'] ?? ''),
            'native_url'   => $CFG_GLPI['root_doc'] . '/front/notificationmailingsetting.form.php',
            'entity_url'   => $CFG_GLPI['root_doc'] . '/front/entity.php',
            'transports'   => [
                'smtp' => __('Servidor de e-mail (SMTP) - recomendado', 'mailbridge'),
                'mail' => __('Função de e-mail do próprio servidor do GLPI', 'mailbridge'),
            ],
            'securities'   => [
                'tls'  => __('STARTTLS (o mais comum, porta 587)', 'mailbridge'),
                'ssl'  => __('SSL/TLS (porta 465)', 'mailbridge'),
                'none' => __('Nenhuma (porta 25, só em rede interna)', 'mailbridge'),
            ],
        ]];
    }
}
