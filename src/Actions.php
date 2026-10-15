<?php

namespace GlpiPlugin\Mailtech;

use QueuedNotification;
use Session;
use UserEmail;

/**
 * Tudo que as telas do MailTech podem MUDAR passa por aqui: cada ação confere
 * a permissão, valida os dados e devolve um resultado único, usado tanto pelo
 * envio normal de formulário (front/action.php) quanto pelas chamadas sem
 * recarregar a página (ajax/action.php). O token CSRF é conferido pelo GLPI
 * antes de chegar aqui.
 */
final class Actions
{
    /** Ação => permissão exigida. */
    private const RIGHTS = [
        'save_sending'         => Rights::MANAGE,
        'test_connection'      => Rights::TEST,
        'send_test'            => Rights::TEST,
        'toggle_mailtech'      => Rights::MANAGE,
        'toggle_rule'          => Rights::MANAGE,
        'save_rule'            => Rights::MANAGE,
        'delete_rule'          => Rights::MANAGE,
        'save_entities'        => Rights::MANAGE,
        'toggle_native'        => Rights::MANAGE,
        'save_template'        => Rights::MANAGE,
        'delete_translation'   => Rights::MANAGE,
        'preview_template'     => Rights::VIEW,
        'test_template'        => Rights::TEST,
        'queue_apply'          => Rights::QUEUE,
        'queue_cancel_all'     => Rights::QUEUE,
        'queue_run'            => Rights::QUEUE,
        'queue_enable_routine' => Rights::QUEUE,
        'save_policy'          => Rights::MANAGE,
        'save_options'         => Rights::MANAGE,
        'import'               => Rights::MANAGE,
        'restore_defaults'     => Rights::MANAGE,
    ];

    private const TEST_STAMPS = 'plugin_mailtech_tests';

    /**
     * @param array $in $_POST como o GLPI entrega
     * @return array{ok:bool,message:string,warning:string,errors:array<string,string>,form:string,old:array,redirect:string,data:array,status:int}
     */
    public static function run(string $action, array $in): array
    {
        $result = [
            'ok'       => false,
            'message'  => '',
            'warning'  => '',
            'errors'   => [],
            'form'     => '',
            'old'      => [],
            'redirect' => Page::url(),
            'data'     => [],
            'status'   => 200,
        ];

        if (!isset(self::RIGHTS[$action])) {
            return ['message' => __('Não entendi o que foi pedido. Recarregue a página e tente de novo.', 'mailtech'), 'status' => 400] + $result;
        }
        if (!Rights::can(self::RIGHTS[$action])) {
            return ['message' => __('Seu perfil não tem permissão para fazer isso no MailTech.', 'mailtech'), 'status' => 403] + $result;
        }

        $method = 'do' . str_replace('_', '', ucwords($action, '_'));
        try {
            return self::$method($in) + $result;
        } catch (\Throwable $e) {
            Settings::log('ERRO na ação ' . $action . ': ' . get_class($e) . ': ' . $e->getMessage(), true);

            return [
                'message' => __('Algo deu errado ao executar a ação. Nada foi alterado; tente de novo.', 'mailtech'),
                'status'  => 500,
            ] + $result;
        }
    }

    // ------------------------------------------------------------------
    // Envio
    // ------------------------------------------------------------------

    private static function doSaveSending(array $in): array
    {
        $fields = [
            'mailing_on', 'transport', 'security', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_passwd',
            'clear_passwd', 'smtp_check_certificate', 'smtp_sender', 'from_email', 'from_email_name',
            'replyto_email', 'replyto_email_name', 'noreply_email', 'noreply_email_name', 'admin_email',
            'admin_email_name', 'mailing_signature', 'attach_ticket_documents_to_mail',
        ];
        $raw = self::rawFields($in, $fields);

        $before = Sending::current();
        if ($before['transport'] === 'oauth' && !in_array($raw['transport'], ['smtp', 'mail'], true)) {
            $raw['transport'] = 'oauth'; // quem usa OAuth configura o servidor na tela nativa
        }

        $own_fields = ['sender_email', 'sender_name', 'send_immediately', 'debug_log', 'block_list', 'allow_list'];
        $own_raw    = self::rawFields($in, $own_fields);

        [$clean, $errors]   = Validation::sending($raw);
        [$own, $own_errors] = Validation::mailtech($own_raw);
        $errors += $own_errors;

        if ($errors) {
            return [
                'errors'   => $errors,
                'form'     => 'sending',
                'old'      => $raw + $own_raw,
                'redirect' => Page::url('sending'),
            ];
        }

        Sending::save($clean);
        Settings::set($own);
        Facts::reset();

        $message = __('Configuração de envio salva.', 'mailtech');
        if ($before['mailing_on'] && !$clean['notifications_mailing']) {
            $message = __('Configuração salva. O envio de e-mails do GLPI foi DESLIGADO.', 'mailtech');
        } elseif (!$before['mailing_on'] && $clean['notifications_mailing']) {
            $message = __('Configuração salva. O envio de e-mails do GLPI foi LIGADO.', 'mailtech');
        }

        return ['ok' => true, 'message' => $message, 'redirect' => Page::url('sending')];
    }

    private static function doTestConnection(array $in): array
    {
        $limit = self::rateLimit();
        if ($limit !== null) {
            return $limit + ['redirect' => Page::url('sending', ['focus' => 'test'])];
        }

        $probe = SmtpProbe::run();

        return self::testResult($probe, $probe['ok'] ? __('Conexão com o servidor de envio funcionando.', 'mailtech') : '')
            + ['redirect' => Page::url('sending', ['focus' => 'test'])];
    }

    private static function doSendTest(array $in): array
    {
        $redirect = ['redirect' => Page::url('sending', ['focus' => 'test'])];

        $to = trim(Text::raw($in['test_email'] ?? ''));
        if (!Validation::isEmail($to)) {
            return [
                'message' => __('Informe um e-mail válido para receber o teste. Exemplo: voce@suaempresa.com.br', 'mailtech'),
                'errors'  => ['test_email' => __('Informe um e-mail válido para receber o teste. Exemplo: voce@suaempresa.com.br', 'mailtech')],
            ] + $redirect;
        }

        return self::sendTestMail($to, Notifier::testMail()) + $redirect;
    }

    /**
     * Teste completo: confere a conexão passo a passo e envia o e-mail.
     *
     * @param array{subject:string,html:string,text:string} $mail
     */
    private static function sendTestMail(string $to, array $mail): array
    {
        $limit = self::rateLimit();
        if ($limit !== null) {
            return $limit;
        }

        $probe = SmtpProbe::run();
        if (!$probe['ok']) {
            return self::testResult($probe, '');
        }

        $sent_label = sprintf(__('Enviar o e-mail para %s', 'mailtech'), $to);

        // Gmail e Microsoft recusam um remetente que não existe ("admsys@localhost"): nem envia.
        $conf = Sending::current();
        if ($conf['transport'] !== 'mail' && Provider::name($conf['smtp_host']) !== '' && !Provider::isPublicAddress(Sending::senderEmail())) {
            $probe['ok']      = false;
            $probe['error']   = 'sender-not-real';
            $probe['steps'][] = ['id' => 'sent', 'label' => $sent_label, 'status' => SmtpProbe::FAIL, 'detail' => ''];

            return self::testResult($probe, '');
        }

        $id = Notifier::queueTest($to, $mail);
        if ($id <= 0) {
            $probe['ok']      = false;
            $probe['error']   = 'missing-sender';
            $probe['steps'][] = ['id' => 'sent', 'label' => $sent_label, 'status' => SmtpProbe::FAIL, 'detail' => ''];

            return self::testResult($probe, '');
        }

        $send = Queue::resend($id, true);
        if (!$send['ok']) {
            // Um teste que falhou não deve ficar tentando sozinho depois.
            (new QueuedNotification())->delete(['id' => $id]);
            $probe['ok']    = false;
            $probe['error'] = $send['error'];
        } else {
            Settings::set(['last_test_ok' => date('Y-m-d H:i:s'), 'last_test_config' => Sending::fingerprint()]);
            Facts::reset();
        }
        $probe['steps'][] = [
            'id'     => 'sent',
            'label'  => $sent_label,
            'status' => $send['ok'] ? SmtpProbe::OK : SmtpProbe::FAIL,
            'detail' => '',
        ];

        $result = self::testResult(
            $probe,
            sprintf(__('E-mail de teste enviado para %s. Confira a caixa de entrada (e a pasta de spam).', 'mailtech'), $to)
        );
        $result['data']['queue_url'] = Page::url('queue', ['id' => $id]);

        return $result;
    }

    /**
     * Transforma o resultado de um teste em mensagem + explicação.
     */
    private static function testResult(array $probe, string $success): array
    {
        $data = ['ok' => (bool) $probe['ok'], 'steps' => $probe['steps']];
        if ($probe['ok']) {
            return ['ok' => true, 'message' => $success, 'data' => $data];
        }

        if ($probe['error'] === 'missing-server') {
            $data['title']  = __('Falta informar o servidor de envio e a porta.', 'mailtech');
            $data['advice'] = [__('Preencha o servidor e a porta, clique em Salvar e teste de novo.', 'mailtech')];
        } elseif ($probe['error'] === 'missing-sender') {
            $data['title']  = __('Falta informar o e-mail do remetente.', 'mailtech');
            $data['advice'] = [__('Preencha o e-mail do remetente, clique em Salvar e teste de novo.', 'mailtech')];
        } elseif ($probe['error'] === 'sender-not-real') {
            $data['title']  = sprintf(__('O remetente "%s" não é um endereço de e-mail de verdade.', 'mailtech'), Sending::senderEmail());
            $data['advice'] = [
                sprintf(__('O %s recusa e-mails com esse remetente. Em "E-mail do remetente", informe o endereço da conta que faz o login, clique em Salvar e teste de novo.', 'mailtech'), Provider::name(Sending::current()['smtp_host'])),
            ];
        } elseif ($probe['error'] === SmtpProbe::NO_PASSWORD || $probe['error'] === SmtpProbe::NO_LOGIN) {
            $explained      = ErrorExplainer::explain($probe['error']);
            $data['title']  = $explained['title'];
            $data['advice'] = $explained['advice'];
        } else {
            $explained         = ErrorExplainer::explain((string) $probe['error']);
            $data['title']     = $explained['title'];
            $data['advice']    = $explained['advice'];
            $data['technical'] = (string) $probe['error'];
        }

        return ['ok' => false, 'message' => $data['title'], 'data' => $data];
    }

    /**
     * No máximo 6 testes por minuto por pessoa: cada teste abre uma conexão
     * com o servidor de e-mail.
     */
    private static function rateLimit(): ?array
    {
        $check = RateLimiter::hit((array) ($_SESSION[self::TEST_STAMPS] ?? []), time());
        $_SESSION[self::TEST_STAMPS] = $check['stamps'];
        if ($check['allowed']) {
            return null;
        }

        return [
            'message' => sprintf(
                _n(
                    'Muitos testes em pouco tempo. Espere %d segundo e tente de novo.',
                    'Muitos testes em pouco tempo. Espere %d segundos e tente de novo.',
                    $check['retry_after'],
                    'mailtech'
                ),
                $check['retry_after']
            ),
            'status'  => 429,
        ];
    }

    // ------------------------------------------------------------------
    // Eventos e destinatários
    // ------------------------------------------------------------------

    private static function doToggleMailTech(array $in): array
    {
        $on = !empty($in['on']);
        Settings::set(['enabled' => $on ? 1 : 0]);
        Facts::reset();

        return [
            'ok'       => true,
            'message'  => $on
                ? __('Avisos do MailTech ligados.', 'mailtech')
                : __('Avisos do MailTech desligados: as regras não geram e-mails até você ligar de novo.', 'mailtech'),
            'redirect' => Page::url('events'),
        ];
    }

    /**
     * Lê o pedido de liga/desliga: "id" + "on" (chave da tela) ou "row" = "id:1"
     * (botão usado quando o navegador está sem JavaScript).
     *
     * @return array{0:int,1:bool}
     */
    private static function readToggle(array $in): array
    {
        if (isset($in['row']) && preg_match('/^(\d+):([01])$/', (string) $in['row'], $m)) {
            return [(int) $m[1], $m[2] === '1'];
        }

        return [(int) ($in['id'] ?? 0), !empty($in['on'])];
    }

    private static function doToggleRule(array $in): array
    {
        [$id, $on] = self::readToggle($in);
        $rule = new Rule();
        if ($id <= 0 || !$rule->getFromDB($id)) {
            return ['message' => __('Essa regra não existe mais. Recarregue a página.', 'mailtech'), 'status' => 404, 'redirect' => Page::url('events')];
        }
        $rule->update(['id' => $id, 'is_active' => $on ? 1 : 0]);
        Facts::reset();

        $name = Text::decode((string) $rule->fields['name']);

        return [
            'ok'       => true,
            'message'  => $on
                ? sprintf(__('Regra "%s" ligada.', 'mailtech'), $name)
                : sprintf(__('Regra "%s" desligada: ela não gera mais e-mails.', 'mailtech'), $name),
            'redirect' => Page::url('events'),
        ];
    }

    private static function doSaveRule(array $in): array
    {
        $id     = (int) ($in['id'] ?? 0);
        $fields = [
            'name', 'event', 'is_active', 'notify_assigned_users', 'notify_assigned_groups', 'notify_requesters',
            'notify_observers', 'exclude_author', 'extra_emails', 'filter_entities_id', 'filter_itilcategories_id',
            'filter_type', 'filter_min_priority',
        ];
        $raw  = self::rawFields($in, $fields);
        $back = Page::url('events', ['rule' => $id > 0 ? $id : 'new']);

        $rule = new Rule();
        if ($id > 0 && !$rule->getFromDB($id)) {
            return ['message' => __('Essa regra não existe mais. Recarregue a página.', 'mailtech'), 'status' => 404, 'redirect' => Page::url('events')];
        }

        [$clean, $errors] = Validation::rule($raw, Rule::getEvents());
        if ($errors) {
            return ['errors' => $errors, 'form' => 'rule', 'old' => $raw, 'redirect' => $back];
        }

        if ($id > 0) {
            $rule->update(['id' => $id] + Text::sanitize($clean));
            $message = sprintf(__('Regra "%s" salva.', 'mailtech'), $clean['name']);
        } else {
            // A regra nova nasce com o modelo padrão do evento (editável em Modelos).
            $id = (int) $rule->add(Text::sanitize($clean));
            if ($id <= 0) {
                return ['message' => __('Não foi possível criar a regra. Tente de novo.', 'mailtech'), 'redirect' => $back];
            }
            $message = sprintf(__('Regra "%s" criada com a mensagem padrão. Você pode ajustar o texto em Modelos.', 'mailtech'), $clean['name']);
        }
        Facts::reset();

        return ['ok' => true, 'message' => $message, 'redirect' => Page::url('events')];
    }

    private static function doDeleteRule(array $in): array
    {
        $id   = (int) ($in['id'] ?? 0);
        $rule = new Rule();
        if ($id <= 0 || !$rule->getFromDB($id)) {
            return ['message' => __('Essa regra não existe mais. Recarregue a página.', 'mailtech'), 'status' => 404, 'redirect' => Page::url('events')];
        }
        $name = Text::decode((string) $rule->fields['name']);
        $rule->delete(['id' => $id], 1);
        Facts::reset();

        return [
            'ok'       => true,
            'message'  => sprintf(__('Regra "%s" apagada, junto com a mensagem dela.', 'mailtech'), $name),
            'redirect' => Page::url('events'),
        ];
    }

    private static function doSaveEntities(array $in): array
    {
        $choices = (array) ($in['entity'] ?? []);
        $names   = Entities::names(array_map('intval', array_keys($choices)));
        $modes   = Settings::entityModes();
        foreach ($choices as $entities_id => $choice) {
            if (isset($names[(int) $entities_id])) {
                $modes = EntityScope::apply($modes, (int) $entities_id, (string) $choice);
            }
        }
        Settings::set(['entity_modes' => (string) json_encode((object) $modes)]);

        return [
            'ok'       => true,
            'message'  => __('Escolhas por entidade salvas.', 'mailtech'),
            'redirect' => Page::url('events', ['tab' => 'entities', 'estart' => max(0, (int) ($in['estart'] ?? 0))]),
        ];
    }

    private static function doToggleNative(array $in): array
    {
        [$id, $on] = self::readToggle($in);
        if (!NativeNotifications::toggle($id, $on)) {
            return ['message' => __('Esse aviso do GLPI não existe mais. Recarregue a página.', 'mailtech'), 'status' => 404, 'redirect' => Page::url('events', ['tab' => 'native'])];
        }

        return [
            'ok'       => true,
            'message'  => $on ? __('Aviso do GLPI ligado.', 'mailtech') : __('Aviso do GLPI desligado.', 'mailtech'),
            'redirect' => Page::url('events', ['tab' => 'native', 'nstart' => max(0, (int) ($in['nstart'] ?? 0))]),
        ];
    }

    // ------------------------------------------------------------------
    // Modelos
    // ------------------------------------------------------------------

    /**
     * Lê e valida o modelo vindo do editor.
     *
     * @return array{0: Rule|null, 1: string, 2: array, 3: array, 4: array, 5: string[]} [regra, idioma, cru, limpo, erros, variáveis desconhecidas]
     */
    private static function readTemplate(array $in): array
    {
        $rule = new Rule();
        $id   = (int) ($in['id'] ?? 0);
        if ($id <= 0 || !$rule->getFromDB($id)) {
            return [null, '', [], [], [], []];
        }
        $language = (string) ($in['lang'] ?? '');
        if ($language !== '' && !isset(self::languages()[$language])) {
            $language = '';
        }

        $raw = self::rawFields($in, ['subject', 'body', 'body_text']);
        [$clean, $errors, $unknown] = Validation::template($raw, array_keys(Template::placeholders()));
        if (isset($clean['body'])) {
            $clean['body'] = Template::clean($clean['body']);
        }

        return [$rule, $language, $raw, $clean, $errors, $unknown];
    }

    private static function doSaveTemplate(array $in): array
    {
        [$rule, $language, $raw, $clean, $errors, $unknown] = self::readTemplate($in);
        if ($rule === null) {
            return ['message' => __('Essa regra não existe mais. Recarregue a página.', 'mailtech'), 'status' => 404, 'redirect' => Page::url('templates')];
        }
        $id   = (int) $rule->getID();
        $back = Page::url('templates', ['rule' => $id] + ($language !== '' ? ['lang' => $language] : []));

        if ($errors) {
            return ['errors' => $errors, 'form' => 'template', 'old' => $raw, 'redirect' => $back];
        }

        if ($language === '') {
            $rule->update(['id' => $id] + Text::sanitize($clean));
        } else {
            Translation::save($id, $language, $clean);
        }

        return [
            'ok'       => true,
            'message'  => __('Modelo salvo.', 'mailtech'),
            'warning'  => $unknown
                ? sprintf(
                    __('Estas variáveis não existem e vão aparecer no e-mail como texto: %s', 'mailtech'),
                    '##' . implode('##, ##', $unknown) . '##'
                )
                : '',
            'redirect' => $back,
        ];
    }

    private static function doDeleteTranslation(array $in): array
    {
        $id       = (int) ($in['id'] ?? 0);
        $language = (string) ($in['lang'] ?? '');
        Translation::delete($id, $language);

        return [
            'ok'       => true,
            'message'  => __('Tradução apagada. Quem usa esse idioma volta a receber o modelo padrão.', 'mailtech'),
            'redirect' => Page::url('templates', ['rule' => $id]),
        ];
    }

    private static function doPreviewTemplate(array $in): array
    {
        [$rule, , , $clean, $errors, $unknown] = self::readTemplate($in);
        if ($rule === null) {
            return ['message' => __('Essa regra não existe mais. Recarregue a página.', 'mailtech'), 'status' => 404];
        }
        if ($errors) {
            return ['message' => (string) reset($errors), 'errors' => $errors];
        }

        $mail = Template::compose($clean, self::sampleFor($rule));

        return [
            'ok'   => true,
            'data' => [
                'subject' => $mail['subject'],
                'html'    => $mail['html'],
                'text'    => $mail['text'],
                'unknown' => $unknown,
            ],
        ];
    }

    private static function doTestTemplate(array $in): array
    {
        [$rule, $language, , $clean, $errors] = self::readTemplate($in);
        if ($rule === null) {
            return ['message' => __('Essa regra não existe mais. Recarregue a página.', 'mailtech'), 'status' => 404, 'redirect' => Page::url('templates')];
        }
        $back = ['redirect' => Page::url('templates', ['rule' => (int) $rule->getID()] + ($language !== '' ? ['lang' => $language] : []))];
        if ($errors) {
            return ['message' => (string) reset($errors), 'errors' => $errors] + $back;
        }

        $to = trim(Text::raw($in['test_email'] ?? ''));
        if (!Validation::isEmail($to)) {
            $message = __('Informe um e-mail válido para receber o teste. Exemplo: voce@suaempresa.com.br', 'mailtech');

            return ['message' => $message, 'errors' => ['test_email' => $message]] + $back;
        }

        $mail = Template::compose($clean, self::sampleFor($rule));
        $mail['subject'] = sprintf(__('[TESTE] %s', 'mailtech'), $mail['subject']);

        return self::sendTestMail($to, $mail) + $back;
    }

    /**
     * Dados de exemplo para a prévia/teste do modelo de uma regra.
     */
    private static function sampleFor(Rule $rule): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return Template::sample(
            (string) $rule->fields['event'],
            self::currentUserName(),
            (string) ($CFG_GLPI['url_base'] ?? '')
        );
    }

    // ------------------------------------------------------------------
    // Fila e histórico
    // ------------------------------------------------------------------

    /**
     * Ação numa linha (botão da linha) ou em lote (caixas marcadas).
     */
    private static function doQueueApply(array $in): array
    {
        $back = Page::url('queue', array_filter([
            'filter' => (string) ($in['filter'] ?? ''),
            'q'      => Text::raw($in['q'] ?? ''),
            'only'   => !empty($in['only']) ? 1 : null,
            'start'  => max(0, (int) ($in['start'] ?? 0)) ?: null,
        ], static function ($value) {
            return $value !== null && $value !== '';
        }));

        $single = false;
        if (!empty($in['row']) && preg_match('/^(resend|cancel|delete):(\d+)$/', (string) $in['row'], $m)) {
            $do     = $m[1];
            $ids    = [(int) $m[2]];
            $single = true;
            if (!empty($in['from_detail'])) {
                $back = $do === 'delete' ? $back : Page::url('queue', ['id' => (int) $m[2]]);
            }
        } else {
            $do  = (string) ($in['do'] ?? '');
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['ids'] ?? [])))));
        }

        if (!in_array($do, ['resend', 'cancel', 'delete'], true)) {
            return ['message' => __('Escolha o que fazer com os e-mails marcados.', 'mailtech'), 'redirect' => $back];
        }
        if (!$ids) {
            return ['message' => __('Marque pelo menos um e-mail da lista.', 'mailtech'), 'redirect' => $back];
        }
        $ids = array_slice($ids, 0, 100);

        $done    = 0;
        $failed  = 0;
        $error   = '';
        $checked = false;
        foreach ($ids as $id) {
            if ($do === 'resend') {
                if (!$checked) {
                    // Confere a conexão uma vez só antes de reenviar vários.
                    $error   = Queue::connectionProblem();
                    $checked = true;
                    if ($error !== '') {
                        $failed = count($ids);
                        break;
                    }
                }
                $result = Queue::resend($id, true);
                if ($result['ok']) {
                    $done++;
                } else {
                    $failed++;
                    $error = $result['error'] !== '' ? $result['error'] : $error;
                }
            } elseif ($do === 'cancel') {
                Queue::cancel($id) ? $done++ : $failed++;
            } else {
                Queue::purge($id) ? $done++ : $failed++;
            }
        }
        Facts::reset();

        $labels = [
            'resend' => [
                _n('%d e-mail reenviado.', '%d e-mails reenviados.', $done, 'mailtech'),
                _n('%d e-mail não pôde ser reenviado.', '%d e-mails não puderam ser reenviados.', $failed, 'mailtech'),
            ],
            'cancel' => [
                _n('%d e-mail cancelado.', '%d e-mails cancelados.', $done, 'mailtech'),
                _n('%d e-mail não pôde ser cancelado (só dá para cancelar o que ainda está na fila).', '%d e-mails não puderam ser cancelados (só dá para cancelar o que ainda está na fila).', $failed, 'mailtech'),
            ],
            'delete' => [
                _n('%d e-mail apagado.', '%d e-mails apagados.', $done, 'mailtech'),
                _n('%d e-mail não pôde ser apagado (cancele antes o que ainda está na fila).', '%d e-mails não puderam ser apagados (cancele antes o que ainda está na fila).', $failed, 'mailtech'),
            ],
        ];

        $message = $done > 0 ? sprintf($labels[$do][0], $done) : '';
        $warning = '';
        if ($failed > 0) {
            $warning = sprintf($labels[$do][1], $failed);
            if ($do === 'resend' && $error !== '') {
                $explained = ErrorExplainer::explain($error);
                $warning  .= ' ' . $explained['title'] . ' ' . __('O que tentar:', 'mailtech') . ' ' . $explained['advice'][0];
            }
        }

        return [
            'ok'       => $failed === 0,
            'message'  => $done > 0 || $failed === 0 ? $message : $warning,
            'warning'  => $done > 0 ? $warning : '',
            'redirect' => $back,
            'data'     => ['done' => $done, 'failed' => $failed, 'single' => $single],
        ];
    }

    private static function doQueueCancelAll(array $in): array
    {
        $count = Queue::cancelAllPending();

        return [
            'ok'       => true,
            'message'  => $count > 0
                ? sprintf(_n('%d e-mail cancelado: a fila está vazia.', '%d e-mails cancelados: a fila está vazia.', $count, 'mailtech'), $count)
                : __('Não havia e-mails esperando na fila.', 'mailtech'),
            'redirect' => Page::url('queue'),
        ];
    }

    private static function doQueueRun(array $in): array
    {
        $back   = ['redirect' => Page::url('queue', ['filter' => 'queued'])];
        $result = Queue::runNow();

        if ($result['error'] !== '' && $result['sent'] === 0) {
            $explained = ErrorExplainer::explain($result['error']);

            return [
                'message' => $explained['title'] . ' ' . __('O que tentar:', 'mailtech') . ' ' . $explained['advice'][0],
                'data'    => ['technical' => $result['error']],
            ] + $back;
        }

        $message = $result['sent'] > 0
            ? sprintf(_n('Fila processada: %d e-mail enviado.', 'Fila processada: %d e-mails enviados.', $result['sent'], 'mailtech'), $result['sent'])
            : __('Fila processada: não havia e-mails prontos para envio.', 'mailtech');
        $warning = '';
        if ($result['left'] > 0) {
            $warning = sprintf(
                _n(
                    '%d e-mail continua na fila (agendado para depois ou com falha).',
                    '%d e-mails continuam na fila (agendados para depois ou com falha).',
                    $result['left'],
                    'mailtech'
                ),
                $result['left']
            );
        }

        return ['ok' => true, 'message' => $message, 'warning' => $warning] + $back;
    }

    private static function doQueueEnableRoutine(array $in): array
    {
        Queue::enableRoutine();
        Facts::reset();

        return [
            'ok'       => true,
            'message'  => __('Rotina automática reativada: a fila volta a ser enviada sozinha.', 'mailtech'),
            'redirect' => Page::url('queue', ['focus' => 'routine']),
        ];
    }

    private static function doSavePolicy(array $in): array
    {
        $raw  = self::rawFields($in, ['max_retries', 'retry_minutes', 'keep_days', 'batch']);
        $back = Page::url('queue', ['focus' => 'routine']);

        [$clean, $errors] = Validation::queuePolicy($raw);
        if ($errors) {
            return ['errors' => $errors, 'form' => 'policy', 'old' => $raw, 'redirect' => $back];
        }
        Queue::savePolicy($clean);

        return ['ok' => true, 'message' => __('Regras da fila salvas.', 'mailtech'), 'redirect' => $back];
    }

    // ------------------------------------------------------------------
    // Diagnóstico
    // ------------------------------------------------------------------

    private static function doSaveOptions(array $in): array
    {
        [$clean] = Validation::mailtech(['purge_on_uninstall' => Text::raw($in['purge_on_uninstall'] ?? '0')]);
        Settings::set($clean);

        return [
            'ok'       => true,
            'message'  => $clean['purge_on_uninstall']
                ? __('Salvo: ao desinstalar o plugin, as regras, modelos e ajustes do MailTech SERÃO apagados.', 'mailtech')
                : __('Salvo: ao desinstalar o plugin, as regras, modelos e ajustes do MailTech ficam guardados.', 'mailtech'),
            'redirect' => Page::url('diagnostics'),
        ];
    }

    private static function doImport(array $in): array
    {
        $back = ['redirect' => Page::url('diagnostics')];
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return ['message' => __('Escolha o arquivo .json gerado pelo botão "Exportar" antes de importar.', 'mailtech')] + $back;
        }
        if ((int) $file['size'] > 2 * 1024 * 1024) {
            return ['message' => __('Arquivo grande demais: o limite é 2 MB.', 'mailtech')] + $back;
        }

        [$data, $error] = Porter::parse((string) file_get_contents((string) $file['tmp_name']));
        if ($data === null) {
            return ['message' => $error] + $back;
        }

        $report = Porter::import($data, !empty($in['with_sending']));
        Facts::reset();

        $message = sprintf(
            __('Importação concluída: %1$d regra(s) criada(s), %2$d atualizada(s).', 'mailtech'),
            $report['created'],
            $report['updated']
        );
        if ($report['sending']) {
            $message .= ' ' . __('Servidor de envio importado: digite a senha de novo em Envio.', 'mailtech');
        }

        return [
            'ok'      => true,
            'message' => $message,
            'warning' => implode(' ', array_slice($report['skipped'], 0, 5)),
        ] + $back;
    }

    private static function doRestoreDefaults(array $in): array
    {
        $defaults = Settings::defaults();
        unset($defaults['last_test_ok'], $defaults['last_test_config']);
        Settings::set($defaults);
        Facts::reset();

        return [
            'ok'       => true,
            'message'  => __('Ajustes do MailTech restaurados para o padrão. As regras, os modelos e o servidor de envio não foram alterados.', 'mailtech'),
            'redirect' => Page::url('diagnostics'),
        ];
    }

    // ------------------------------------------------------------------
    // Apoio
    // ------------------------------------------------------------------

    /**
     * @param string[] $fields
     * @return array<string,string> valores como digitados
     */
    private static function rawFields(array $in, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $out[$field] = Text::raw($in[$field] ?? '');
        }

        return $out;
    }

    /**
     * Idiomas disponíveis no GLPI: código => nome.
     *
     * @return array<string,string>
     */
    public static function languages(): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $out = [];
        foreach ((array) ($CFG_GLPI['languages'] ?? []) as $code => $info) {
            $out[(string) $code] = (string) ($info[0] ?? $code);
        }
        asort($out);

        return $out;
    }

    public static function currentUserEmail(): string
    {
        $email = UserEmail::getDefaultForUser((int) Session::getLoginUserID());

        return is_string($email) && Validation::isEmail(Text::decode($email)) ? Text::decode($email) : '';
    }

    public static function currentUserName(): string
    {
        $name = (string) ($_SESSION['glpifriendlyname'] ?? ($_SESSION['glpiname'] ?? ''));

        return Text::decode(strip_tags($name));
    }
}
