<?php

namespace GlpiPlugin\Mailbridge;

use CommonITILActor;
use Group_Ticket;
use Group_User;
use ITILFollowup;
use ITILSolution;
use Notification_NotificationTemplate;
use QueuedNotification;
use Session;
use Ticket;
use Ticket_User;
use TicketTask;

/**
 * Recebe os hooks do GLPI, aplica as regras e coloca os e-mails na fila de
 * notificações do GLPI (tabela glpi_queuednotifications).
 *
 * Nenhuma exceção sai daqui: uma falha do plugin nunca pode impedir que um
 * chamado seja criado ou atualizado.
 */
final class Notifier
{
    /** @var array<string,bool> evita e-mails duplicados dentro da mesma requisição */
    private static $sent = [];

    // ------------------------------------------------------------------
    // Hooks
    // ------------------------------------------------------------------

    public static function onTicketAdded(Ticket $ticket): void
    {
        self::guard(static function () use ($ticket): void {
            self::dispatch(Rule::EVENT_NEW_TICKET, $ticket, [
                'source'    => 'Ticket#' . $ticket->getID(),
                'author_id' => self::currentUserId(),
            ]);
        });
    }

    public static function onTicketUserAdded(Ticket_User $link): void
    {
        self::guard(static function () use ($link): void {
            if ((int) ($link->fields['type'] ?? 0) !== CommonITILActor::ASSIGN) {
                return;
            }
            $ticket = self::loadTicket((int) $link->fields['tickets_id']);
            if ($ticket === null) {
                return;
            }
            self::dispatch(Rule::EVENT_ASSIGNED, $ticket, [
                'source'           => 'Ticket_User#' . $link->getID(),
                'author_id'        => self::currentUserId(),
                'assigned_user_id' => (int) $link->fields['users_id'],
                'use_notification' => (int) ($link->fields['use_notification'] ?? 1),
            ]);
        });
    }

    public static function onGroupTicketAdded(Group_Ticket $link): void
    {
        self::guard(static function () use ($link): void {
            if ((int) ($link->fields['type'] ?? 0) !== CommonITILActor::ASSIGN) {
                return;
            }
            $ticket = self::loadTicket((int) $link->fields['tickets_id']);
            if ($ticket === null) {
                return;
            }
            self::dispatch(Rule::EVENT_ASSIGNED, $ticket, [
                'source'            => 'Group_Ticket#' . $link->getID(),
                'author_id'         => self::currentUserId(),
                'assigned_group_id' => (int) $link->fields['groups_id'],
            ]);
        });
    }

    public static function onFollowupAdded(ITILFollowup $followup): void
    {
        self::guard(static function () use ($followup): void {
            if (($followup->fields['itemtype'] ?? '') !== 'Ticket') {
                return;
            }
            $ticket = self::loadTicket((int) $followup->fields['items_id']);
            if ($ticket === null) {
                return;
            }
            self::dispatch(Rule::EVENT_FOLLOWUP, $ticket, [
                'source'    => 'ITILFollowup#' . $followup->getID(),
                'author_id' => (int) ($followup->fields['users_id'] ?? 0) ?: self::currentUserId(),
                'content'   => (string) ($followup->fields['content'] ?? ''),
            ]);
        });
    }

    public static function onTaskAdded(TicketTask $task): void
    {
        self::guard(static function () use ($task): void {
            $ticket = self::loadTicket((int) ($task->fields['tickets_id'] ?? 0));
            if ($ticket === null) {
                return;
            }
            self::dispatch(Rule::EVENT_TASK, $ticket, [
                'source'    => 'TicketTask#' . $task->getID(),
                'author_id' => (int) ($task->fields['users_id'] ?? 0) ?: self::currentUserId(),
                'content'   => (string) ($task->fields['content'] ?? ''),
            ]);
        });
    }

    public static function onSolutionAdded(ITILSolution $solution): void
    {
        self::guard(static function () use ($solution): void {
            if (($solution->fields['itemtype'] ?? '') !== 'Ticket') {
                return;
            }
            $ticket = self::loadTicket((int) $solution->fields['items_id']);
            if ($ticket === null) {
                return;
            }
            self::dispatch(Rule::EVENT_SOLUTION, $ticket, [
                'source'    => 'ITILSolution#' . $solution->getID(),
                'author_id' => (int) ($solution->fields['users_id'] ?? 0) ?: self::currentUserId(),
                'content'   => (string) ($solution->fields['content'] ?? ''),
            ]);
        });
    }

    public static function onTicketUpdated(Ticket $ticket): void
    {
        self::guard(static function () use ($ticket): void {
            if (!isset($ticket->oldvalues['status'])) {
                return;
            }
            $old = (int) $ticket->oldvalues['status'];
            $new = (int) ($ticket->fields['status'] ?? $old);
            if ($old === $new) {
                return;
            }
            // O GLPI muda sozinho de "Novo" para "Em atendimento (atribuído)" ao atribuir um
            // técnico. Isso já é coberto pelo evento "Chamado atribuído"; avisar de novo aqui
            // geraria um e-mail extra a cada chamado criado com técnico.
            if ($old === Ticket::INCOMING && $new === Ticket::ASSIGNED) {
                return;
            }
            self::dispatch(Rule::EVENT_STATUS, $ticket, [
                'source'     => 'Ticket#' . $ticket->getID() . ':status:' . $old . '>' . $new,
                'author_id'  => self::currentUserId(),
                'old_status' => $old,
            ]);
        });
    }

    // ------------------------------------------------------------------
    // Núcleo
    // ------------------------------------------------------------------

    /**
     * Aplica as regras ativas do evento e enfileira os e-mails.
     */
    private static function dispatch(string $event, Ticket $ticket, array $ctx): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!self::isEnabled()) {
            return;
        }

        $rules = (new Rule())->find(['event' => $event, 'is_active' => 1]);
        if (!$rules) {
            return;
        }

        // Entidade com os avisos desligados (a escolha pode vir de uma entidade "mãe").
        $entities_id = (int) ($ticket->fields['entities_id'] ?? 0);
        $modes       = Settings::entityModes();
        if ($modes) {
            $scope = EntityScope::resolve($modes, Entities::chain($entities_id));
            if (!$scope['enabled']) {
                Settings::log(sprintf(
                    'ignorado: avisos desligados para a entidade #%d (escolha feita na entidade #%d), chamado #%d',
                    $entities_id,
                    (int) $scope['source'],
                    $ticket->getID()
                ));

                return;
            }
        }

        [$block] = Validation::addressList(Settings::text('block_list'));
        [$allow] = Validation::addressList(Settings::text('allow_list'));

        $base_vars = null; // variáveis no idioma atual (montadas só se alguma regra precisar)

        foreach ($rules as $rule) {
            if (!Rule::matches($rule, $ticket)) {
                Settings::log(sprintf(
                    'ignorado: regra #%d (%s) não atende aos filtros do chamado #%d',
                    $rule['id'],
                    $event,
                    $ticket->getID()
                ));
                continue;
            }

            [$extra] = Validation::emailList(Text::decode((string) ($rule['extra_emails'] ?? '')));
            [$recipients, $skipped] = Recipients::build(
                self::candidates($rule, $ticket, $event, $ctx),
                [
                    'exclude_users_id' => (int) $rule['exclude_author'] === 1 ? (int) ($ctx['author_id'] ?? 0) : 0,
                    'extra'            => $extra,
                    'block'            => $block,
                    'allow'            => $allow,
                ]
            );
            foreach ($skipped as $skip) {
                Settings::log(sprintf('ignorado: %s (%s), regra #%d', $skip['email'], $skip['reason'], $rule['id']));
            }
            if (!$recipients) {
                Settings::log(sprintf(
                    'ignorado: regra #%d (%s) sem destinatários para o chamado #%d',
                    $rule['id'],
                    $event,
                    $ticket->getID()
                ));
                continue;
            }

            $sender = self::sender($ticket);
            if ($sender['email'] === '') {
                Settings::log('ignorado: nenhum remetente configurado no GLPI nem no plugin', true);

                return;
            }

            // Modelo padrão da regra e traduções por idioma.
            $default      = [
                'subject'   => (string) $rule['subject'],
                'body'      => (string) $rule['body'],
                'body_text' => (string) ($rule['body_text'] ?? ''),
            ];
            $translations = Translation::forRule((int) $rule['id']);

            // Agrupa por idioma: só troca o idioma do GLPI para quem tem modelo traduzido.
            $groups = [];
            foreach ($recipients as $email => $recipient) {
                $language = isset($translations[$recipient['language']]) ? $recipient['language'] : '';
                $groups[$language][(string) $email] = $recipient;
            }

            foreach ($groups as $language => $members) {
                if ($language === '') {
                    // O modelo padrão é escrito no idioma padrão do GLPI: os dados do
                    // chamado (status, prioridade...) saem nesse idioma, e não no de
                    // quem executou a ação, para o e-mail não misturar idiomas.
                    $base_vars = $base_vars ?? self::inLanguage(
                        (string) ($CFG_GLPI['language'] ?? ''),
                        static function () use ($ticket, $event, $ctx): array {
                            return Template::variables($ticket, $event, $ctx);
                        }
                    );
                    $vars      = $base_vars;
                    $template  = $default;
                } else {
                    $vars = self::inLanguage($language, static function () use ($ticket, $event, $ctx): array {
                        return Template::variables($ticket, $event, $ctx);
                    });
                    $template = $translations[$language];
                }

                foreach ($members as $email => $recipient) {
                    self::enqueue($rule, $ticket, $event, $ctx, (string) $email, $recipient, $template, $vars, $sender);
                }
            }
        }
    }

    /**
     * Levanta todos os possíveis destinatários da regra (a lista final é
     * decidida por Recipients::build()).
     *
     * @return array<int,array{email:string,name:string,users_id:int,language:string}>
     */
    private static function candidates(array $rule, Ticket $ticket, string $event, array $ctx): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $user_ids    = [];
        $external    = [];
        $with_users  = (int) $rule['notify_assigned_users'] === 1;
        $with_groups = (int) $rule['notify_assigned_groups'] === 1;

        // Papéis de usuário a buscar no chamado.
        $roles = [];
        if (!empty($rule['notify_requesters'])) {
            $roles[] = CommonITILActor::REQUESTER;
        }
        if (!empty($rule['notify_observers'])) {
            $roles[] = CommonITILActor::OBSERVER;
        }

        if ($event === Rule::EVENT_ASSIGNED) {
            // Só quem acabou de ser atribuído.
            if ($with_users && !empty($ctx['assigned_user_id']) && (int) ($ctx['use_notification'] ?? 1) === 1) {
                $user_ids[] = (int) $ctx['assigned_user_id'];
            }
            if ($with_groups && !empty($ctx['assigned_group_id'])) {
                $user_ids = array_merge($user_ids, self::groupMembers((int) $ctx['assigned_group_id']));
            }
        } else {
            // Todos os técnicos/grupos atualmente atribuídos.
            if ($with_users) {
                $roles[] = CommonITILActor::ASSIGN;
            }
            if ($with_groups) {
                $rows = $DB->request([
                    'FROM'  => Group_Ticket::getTable(),
                    'WHERE' => ['tickets_id' => $ticket->getID(), 'type' => CommonITILActor::ASSIGN],
                ]);
                foreach ($rows as $row) {
                    $user_ids = array_merge($user_ids, self::groupMembers((int) $row['groups_id']));
                }
            }
        }

        if ($roles) {
            $rows = $DB->request([
                'FROM'  => Ticket_User::getTable(),
                'WHERE' => ['tickets_id' => $ticket->getID(), 'type' => array_values(array_unique($roles))],
            ]);
            foreach ($rows as $row) {
                if ((int) $row['use_notification'] !== 1) {
                    continue;
                }
                if ((int) $row['users_id'] > 0) {
                    $user_ids[] = (int) $row['users_id'];
                } elseif (!empty($row['alternative_email'])) {
                    // Solicitante sem cadastro no GLPI (só o e-mail).
                    $address    = Text::decode((string) $row['alternative_email']);
                    $external[] = ['email' => $address, 'name' => $address, 'users_id' => 0, 'language' => ''];
                }
            }
        }

        return array_merge(self::usersToCandidates($user_ids), $external);
    }

    /**
     * @return int[] ids dos membros do grupo
     */
    private static function groupMembers(int $groups_id): array
    {
        $ids = [];
        foreach (Group_User::getGroupUsers($groups_id) as $member) {
            $ids[] = (int) $member['id'];
        }

        return $ids;
    }

    /**
     * Carrega nome, idioma e e-mail padrão de vários usuários em duas consultas
     * (em vez de duas por usuário). Ignora inativos, apagados e sem e-mail.
     *
     * @param int[] $user_ids
     * @return array<int,array{email:string,name:string,users_id:int,language:string}>
     */
    private static function usersToCandidates(array $user_ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
        if (!$user_ids) {
            return [];
        }

        $emails = [];
        $rows   = $DB->request([
            'SELECT' => ['users_id', 'email'],
            'FROM'   => 'glpi_useremails',
            'WHERE'  => ['users_id' => $user_ids],
            'ORDER'  => ['is_default DESC', 'id ASC'],
        ]);
        foreach ($rows as $row) {
            $emails[(int) $row['users_id']] = $emails[(int) $row['users_id']] ?? (string) $row['email'];
        }

        $users = [];
        $rows  = $DB->request([
            'SELECT' => ['id', 'name', 'realname', 'firstname', 'language', 'is_active', 'is_deleted'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => $user_ids],
        ]);
        foreach ($rows as $row) {
            $users[(int) $row['id']] = $row;
        }

        $out = [];
        foreach ($user_ids as $users_id) { // mantém a ordem em que foram levantados
            $user = $users[$users_id] ?? null;
            if ($user === null || (int) $user['is_active'] !== 1 || (int) $user['is_deleted'] === 1) {
                continue;
            }
            $email = Text::decode((string) ($emails[$users_id] ?? ''));
            if (!Validation::isEmail($email)) {
                Settings::log(sprintf('ignorado: usuário #%d sem e-mail válido', $users_id));
                continue;
            }
            $name = formatUserName($users_id, (string) $user['name'], (string) $user['realname'], (string) $user['firstname']);

            $out[] = [
                'email'    => $email,
                'name'     => Text::decode(strip_tags((string) $name)),
                'users_id' => $users_id,
                'language' => (string) ($user['language'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Executa algo com o GLPI no idioma informado e volta ao idioma anterior.
     *
     * @return mixed
     */
    private static function inLanguage(string $language, callable $callback)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $current = (string) ($_SESSION['glpilanguage'] ?? '');
        if ($language === '' || $language === $current || !isset($CFG_GLPI['languages'][$language])) {
            return $callback();
        }

        Session::loadLanguage($language);
        try {
            return $callback();
        } finally {
            Session::loadLanguage();
        }
    }

    /**
     * Gera o e-mail de um destinatário e o coloca na fila do GLPI.
     */
    private static function enqueue(
        array $rule,
        Ticket $ticket,
        string $event,
        array $ctx,
        string $email,
        array $recipient,
        array $template,
        array $vars,
        array $sender
    ): void {
        $dedupe = $rule['id'] . '|' . ($ctx['source'] ?? '') . '|' . $email;
        if (isset(self::$sent[$dedupe])) {
            return;
        }
        self::$sent[$dedupe] = true;

        $template['body'] = Template::clean(Text::decode((string) $template['body']));
        $mail             = Template::compose($template, Template::withRecipient($vars, (string) $recipient['name']));

        $input = self::envelope($sender, (int) $ticket->fields['entities_id']) + [
            'itemtype'                 => 'Ticket',
            'items_id'                 => (int) $ticket->getID(),
            'notificationtemplates_id' => 0,
            'entities_id'              => (int) $ticket->fields['entities_id'],
            'name'                     => $mail['subject'],
            'recipient'                => $email,
            'recipientname'            => Text::oneLine((string) $recipient['name']),
            'body_html'                => $mail['html'],
            'body_text'                => $mail['text'],
            'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
            'event'                    => 'plugin_mailbridge_' . $event,
        ];

        self::queue($input, sprintf('regra #%d, evento %s, chamado #%d', $rule['id'], $event, $ticket->getID()));
    }

    /**
     * Campos comuns a todo e-mail do MailBridge: remetente, endereço de resposta
     * e os cabeçalhos que marcam a mensagem como automática (evitam respostas
     * automáticas em cadeia, como "estou de férias").
     */
    private static function envelope(array $sender, int $entities_id): array
    {
        $input = [
            'sender'     => $sender['email'],
            'sendername' => Text::oneLine((string) $sender['name']),
            'headers'    => [
                'Auto-Submitted'           => 'auto-generated',
                'X-Auto-Response-Suppress' => 'OOF, DR, NDR, RN, NRN',
            ],
        ];

        $reply = \Config::getReplyToEmailSender($entities_id);
        $to    = trim(Text::decode((string) ($reply['email'] ?? '')));
        if (Validation::isEmail($to)) {
            $input['replyto']     = $to;
            $input['replytoname'] = Text::oneLine(Text::decode((string) ($reply['name'] ?? '')));
        }

        return $input;
    }

    /**
     * Insere na fila e, se configurado, envia na hora.
     *
     * @return int|false id na fila
     */
    private static function queue(array $input, string $context)
    {
        $queued = new QueuedNotification();
        $id     = $queued->add(Text::sanitize($input));
        if (!$id) {
            Settings::log('ERRO ao enfileirar e-mail para ' . $input['recipient'] . ' (' . $context . ')', true);

            return false;
        }

        Settings::log(sprintf('enfileirado #%d para %s (%s)', $id, $input['recipient'], $context));

        if ((int) Settings::get('send_immediately') === 1) {
            try {
                $queued->sendById($id);
            } catch (\Throwable $e) {
                Settings::log('ERRO no envio imediato #' . $id . ': ' . $e->getMessage(), true);
            }
        }

        return $id;
    }

    /**
     * Coloca na fila um e-mail avulso (teste de envio ou teste de modelo) e
     * devolve o id, sem enviar. Quem chama decide o envio (veja Queue::resend()).
     *
     * @param array{subject:string,html:string,text:string} $mail
     * @return int id na fila, ou 0 se não foi possível
     */
    public static function queueTest(string $to, array $mail): int
    {
        $to = trim($to);
        if (!Validation::isEmail($to)) {
            return 0;
        }
        $sender = self::sender(null);
        if ($sender['email'] === '') {
            return 0;
        }

        $input = self::envelope($sender, 0) + [
            'itemtype'                 => '',
            'items_id'                 => 0,
            'notificationtemplates_id' => 0,
            'entities_id'              => 0,
            'name'                     => Text::oneLine((string) $mail['subject']),
            'recipient'                => $to,
            'recipientname'            => $to,
            'body_html'                => (string) $mail['html'],
            'body_text'                => (string) $mail['text'],
            'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
            'event'                    => 'plugin_mailbridge_test',
        ];

        try {
            $queued = new QueuedNotification();

            return (int) $queued->add(Text::sanitize($input));
        } catch (\Throwable $e) {
            Settings::log('ERRO ao enfileirar e-mail de teste: ' . $e->getMessage(), true);

            return 0;
        }
    }

    /**
     * E-mail de teste simples (confirma que o servidor de envio funciona).
     *
     * @return array{subject:string,html:string,text:string}
     */
    public static function testMail(): array
    {
        $html = '<p>' . Text::esc(__('Este é um e-mail de teste do plugin MailBridge. Se você está lendo isto, o envio de e-mails do GLPI está funcionando.', 'mailbridge')) . '</p>';

        return [
            'subject' => __('[GLPI] E-mail de teste do MailBridge', 'mailbridge'),
            'html'    => $html,
            'text'    => Text::toPlain($html),
        ];
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /**
     * Separa e valida uma lista de e-mails (por linha, vírgula ou ponto e vírgula).
     *
     * @return array{0: string[], 1: string[]} [válidos, inválidos]
     */
    public static function parseEmails(string $raw): array
    {
        return Validation::emailList($raw);
    }

    /**
     * Remetente: o do plugin, se definido; senão o do GLPI para a entidade.
     *
     * @return array{email:string,name:string}
     */
    public static function sender(?Ticket $ticket): array
    {
        $email = trim(Settings::text('sender_email'));
        if ($email !== '' && Validation::isEmail($email)) {
            return ['email' => $email, 'name' => Settings::text('sender_name')];
        }

        $entities_id = $ticket !== null ? (int) $ticket->fields['entities_id'] : 0;
        $default     = \Config::getEmailSender($entities_id);

        return [
            'email' => trim(Text::decode((string) ($default['email'] ?? ''))),
            'name'  => Text::decode((string) ($default['name'] ?? '')),
        ];
    }

    private static function isEnabled(): bool
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!(int) Settings::get('enabled')) {
            return false;
        }
        if (empty($CFG_GLPI['use_notifications']) || empty($CFG_GLPI['notifications_mailing'])) {
            Settings::log('ignorado: notificações por e-mail desativadas no GLPI');

            return false;
        }

        return true;
    }

    private static function loadTicket(int $id): ?Ticket
    {
        $ticket = new Ticket();

        return ($id > 0 && $ticket->getFromDB($id)) ? $ticket : null;
    }

    private static function currentUserId(): int
    {
        return (int) Session::getLoginUserID(false);
    }

    /**
     * Executa o hook sem deixar nenhuma falha escapar para o GLPI.
     */
    private static function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            try {
                Settings::log(
                    'ERRO: ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine(),
                    true
                );
            } catch (\Throwable $ignored) {
                // nada a fazer
            }
        }
    }
}
