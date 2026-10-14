<?php

namespace GlpiPlugin\Mailbridge;

use CronTask;
use QueuedNotification;

/**
 * A fila de e-mails é a do próprio GLPI (glpi_queuednotifications) e quem a
 * despacha é a ação automática "queuednotification" do GLPI. Esta classe só
 * consulta e opera o que já existe; não cria outra fila.
 */
final class Queue
{
    public const PER_PAGE   = 20;
    public const TASK_SEND  = 'queuednotification';
    public const TASK_CLEAN = 'queuednotificationclean';

    /** @var array<string,array>|null */
    private static $tasks = null;

    // ------------------------------------------------------------------
    // Rotina automática e regras da fila
    // ------------------------------------------------------------------

    /**
     * @return array<string,array> nome => linha de glpi_crontasks
     */
    private static function tasks(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::$tasks === null) {
            self::$tasks = [];
            $rows = $DB->request([
                'FROM'  => CronTask::getTable(),
                'WHERE' => ['itemtype' => 'QueuedNotification', 'name' => [self::TASK_SEND, self::TASK_CLEAN]],
            ]);
            foreach ($rows as $row) {
                self::$tasks[(string) $row['name']] = $row;
            }
        }

        return self::$tasks;
    }

    /**
     * Situação da rotina que despacha a fila.
     *
     * @return array{id:int,state:int,mode:int,frequency:int,lastrun:int,batch:int}
     */
    public static function routine(): array
    {
        $task = self::tasks()[self::TASK_SEND] ?? [];

        return [
            'id'        => (int) ($task['id'] ?? 0),
            'state'     => (int) ($task['state'] ?? 0),
            'mode'      => (int) ($task['mode'] ?? 1),
            'frequency' => (int) ($task['frequency'] ?? 60),
            'lastrun'   => Db::time($task['lastrun'] ?? null),
            'batch'     => (int) ($task['param'] ?? 50),
        ];
    }

    /**
     * Regras atuais de novas tentativas e limpeza.
     *
     * @return array{max_retries:int,retry_minutes:int,keep_days:int,batch:int}
     */
    public static function policy(): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $tasks = self::tasks();

        return [
            'max_retries'   => (int) ($CFG_GLPI['smtp_max_retries'] ?? 5),
            'retry_minutes' => (int) ($CFG_GLPI['smtp_retry_time'] ?? 5),
            'keep_days'     => (int) ($tasks[self::TASK_CLEAN]['param'] ?? 30),
            'batch'         => (int) ($tasks[self::TASK_SEND]['param'] ?? 50),
        ];
    }

    /**
     * Grava valores JÁ VALIDADOS por Validation::queuePolicy().
     */
    public static function savePolicy(array $clean): void
    {
        $core = [];
        if (isset($clean['max_retries'])) {
            $core['smtp_max_retries'] = (int) $clean['max_retries'];
        }
        if (isset($clean['retry_minutes'])) {
            $core['smtp_retry_time'] = (int) $clean['retry_minutes'];
        }
        Sending::save($core);

        $tasks = self::tasks();
        $map   = ['batch' => self::TASK_SEND, 'keep_days' => self::TASK_CLEAN];
        foreach ($map as $key => $name) {
            if (isset($clean[$key], $tasks[$name]) && (int) $tasks[$name]['param'] !== (int) $clean[$key]) {
                (new CronTask())->update(['id' => (int) $tasks[$name]['id'], 'param' => (int) $clean[$key]]);
            }
        }
        self::$tasks = null;
    }

    /**
     * Reativa a rotina que despacha a fila (e a de limpeza).
     */
    public static function enableRoutine(): void
    {
        foreach (self::tasks() as $task) {
            if ((int) $task['state'] === CronTask::STATE_DISABLE) {
                (new CronTask())->update(['id' => (int) $task['id'], 'state' => CronTask::STATE_WAITING]);
            }
        }
        self::$tasks = null;
    }

    /**
     * Manda o GLPI processar a fila agora (a mesma rotina automática, forçada).
     *
     * @return array{ok:bool,sent:int,left:int,error:string}
     */
    public static function runNow(): array
    {
        $probe = self::connectionProblem();
        if ($probe !== '') {
            return ['ok' => false, 'sent' => 0, 'left' => self::countPending(), 'error' => $probe];
        }

        $before   = self::countPending();
        $messages = self::snapshotMessages();
        $error    = '';
        try {
            CronTask::launch(-CronTask::MODE_INTERNAL, 1, self::TASK_SEND);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $captured = self::takeNewErrors($messages);
        $left     = self::countPending();
        self::$tasks = null;
        Facts::reset();

        return [
            'ok'    => $error === '' && $captured === '',
            'sent'  => max(0, $before - $left),
            'left'  => $left,
            'error' => $error !== '' ? $error : $captured,
        ];
    }

    // ------------------------------------------------------------------
    // Consulta
    // ------------------------------------------------------------------

    public static function countPending(): int
    {
        return (int) countElementsInTable(
            QueuedNotification::getTable(),
            ['mode' => 'mailing', 'is_deleted' => 0, 'sent_time' => null]
        );
    }

    /**
     * Quantos e-mails há em cada filtro da tela (uma consulta só).
     *
     * @return array<string,int>
     */
    public static function counts(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = $DB->request([
            'SELECT' => [
                Db::expr('COUNT(*) AS `all`'),
                Db::expr('SUM(CASE WHEN `sent_time` IS NULL AND `is_deleted` = 0 THEN 1 ELSE 0 END) AS `queued`'),
                Db::expr('SUM(CASE WHEN `sent_time` IS NULL AND `sent_try` > 0 THEN 1 ELSE 0 END) AS `failed`'),
                Db::expr('SUM(CASE WHEN `sent_time` IS NOT NULL THEN 1 ELSE 0 END) AS `sent`'),
                Db::expr('SUM(CASE WHEN `sent_time` IS NULL AND `is_deleted` = 1 AND `sent_try` = 0 THEN 1 ELSE 0 END) AS `cancelled`'),
            ],
            'FROM'   => QueuedNotification::getTable(),
            'WHERE'  => ['mode' => 'mailing'],
        ]);
        $out = ['all' => 0, 'queued' => 0, 'failed' => 0, 'sent' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            foreach ($out as $key => $zero) {
                $out[$key] = (int) $row[$key];
            }
        }

        return $out;
    }

    /**
     * Página da lista (sem o corpo dos e-mails, que é pesado).
     *
     * @return array{rows:array<int,array>,total:int}
     */
    public static function search(string $filter, string $text, bool $only_mailbridge, int $start, int $limit = self::PER_PAGE): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = ['mode' => 'mailing'];
        $state = QueueState::filterCriteria($filter);
        if ($state) {
            $where[] = $state;
        }
        $text = trim($text);
        if ($text !== '') {
            // Os textos ficam guardados "sanitizados"; a busca precisa do mesmo formato.
            $like    = '%' . addcslashes((string) Text::sanitize($text), '%_') . '%';
            $where[] = [
                'OR' => [
                    'recipient'     => ['LIKE', $like],
                    'recipientname' => ['LIKE', $like],
                    'name'          => ['LIKE', $like],
                ],
            ];
        }
        if ($only_mailbridge) {
            // Inclui o que foi enviado quando o plugin ainda tinha o nome antigo.
            $where[] = ['OR' => [
                ['event' => ['LIKE', 'plugin\\_mailbridge\\_%']],
                ['event' => ['LIKE', 'plugin\\_' . LegacyImport::KEY . '\\_%']],
            ]];
        }

        $total = (int) countElementsInTable(QueuedNotification::getTable(), $where);
        $rows  = [];
        if ($total > 0) {
            $iterator = $DB->request([
                'SELECT' => [
                    'id', 'itemtype', 'items_id', 'is_deleted', 'sent_try', 'create_time',
                    'send_time', 'sent_time', 'name', 'recipient', 'recipientname', 'event',
                ],
                'FROM'   => QueuedNotification::getTable(),
                'WHERE'  => $where,
                'ORDER'  => ['id DESC'],
                'START'  => max(0, $start),
                'LIMIT'  => max(1, $limit),
            ]);
            foreach ($iterator as $row) {
                $rows[] = self::decorate($row);
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Um e-mail da fila com todos os campos (para a tela de detalhe).
     */
    public static function find(int $id): ?array
    {
        $queued = new QueuedNotification();
        if ($id <= 0 || !$queued->getFromDB($id) || ($queued->fields['mode'] ?? '') !== 'mailing') {
            return null;
        }
        $row = self::decorate($queued->fields);
        $row['sender']     = Text::decode((string) ($queued->fields['sender'] ?? ''));
        $row['sendername'] = Text::decode((string) ($queued->fields['sendername'] ?? ''));
        $row['replyto']    = Text::decode((string) ($queued->fields['replyto'] ?? ''));
        $row['body_html']  = Text::decode((string) ($queued->fields['body_html'] ?? ''));
        $row['body_text']  = Text::decode((string) ($queued->fields['body_text'] ?? ''));

        return $row;
    }

    private static function decorate(array $row): array
    {
        $state = QueueState::of($row);

        return [
            'id'            => (int) $row['id'],
            'state'         => $state,
            'state_label'   => QueueState::label($state),
            'tone'          => QueueState::tone($state),
            'can_resend'    => QueueState::canResend($state),
            'can_cancel'    => QueueState::canCancel($state),
            'can_delete'    => QueueState::canDelete($state),
            'recipient'     => Text::decode((string) ($row['recipient'] ?? '')),
            'recipientname' => Text::decode((string) ($row['recipientname'] ?? '')),
            'subject'       => Text::decode((string) ($row['name'] ?? '')),
            'event'         => (string) ($row['event'] ?? ''),
            'event_label'   => self::eventLabel((string) ($row['event'] ?? '')),
            'is_mailbridge'   => self::ownEventCode((string) ($row['event'] ?? '')) !== null,
            'itemtype'      => (string) ($row['itemtype'] ?? ''),
            'items_id'      => (int) ($row['items_id'] ?? 0),
            'tries'         => (int) ($row['sent_try'] ?? 0),
            'create_time'   => (string) ($row['create_time'] ?? ''),
            'send_time'     => (string) ($row['send_time'] ?? ''),
            'sent_time'     => (string) ($row['sent_time'] ?? ''),
        ];
    }

    /**
     * Código do acontecimento de um e-mail gerado por este plugin (hoje ou
     * quando ele ainda tinha o nome antigo); null se o e-mail é de outra origem.
     */
    private static function ownEventCode(string $event): ?string
    {
        foreach (['plugin_mailbridge_', 'plugin_' . LegacyImport::KEY . '_'] as $prefix) {
            if (strpos($event, $prefix) === 0) {
                return substr($event, strlen($prefix));
            }
        }

        return null;
    }

    /**
     * Nome amigável da origem do e-mail.
     */
    public static function eventLabel(string $event): string
    {
        $code = self::ownEventCode($event);
        if ($code !== null) {
            if ($code === 'test') {
                return __('MailBridge: e-mail de teste', 'mailbridge');
            }
            $events = Rule::getEvents();

            return sprintf(__('MailBridge: %s', 'mailbridge'), $events[$code] ?? $code);
        }
        if ($event === '') {
            return __('Aviso do GLPI', 'mailbridge');
        }

        return sprintf(__('Aviso do GLPI (%s)', 'mailbridge'), $event);
    }

    // ------------------------------------------------------------------
    // Operações
    // ------------------------------------------------------------------

    /**
     * Envia agora um e-mail da fila (reenvio).
     *
     * @return array{ok:bool,error:string}
     */
    public static function resend(int $id, bool $skip_probe = false): array
    {
        $queued = new QueuedNotification();
        if ($id <= 0 || !$queued->getFromDB($id) || ($queued->fields['mode'] ?? '') !== 'mailing') {
            return ['ok' => false, 'error' => 'not-found'];
        }
        if (!$skip_probe) {
            $probe = self::connectionProblem();
            if ($probe !== '') {
                return ['ok' => false, 'error' => $probe];
            }
        }

        $messages = self::snapshotMessages();
        $error    = '';
        $sent     = 0;
        try {
            $sent = (int) $queued->sendById($id);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $captured = self::takeNewErrors($messages);
        Facts::reset();

        if ($sent > 0) {
            return ['ok' => true, 'error' => ''];
        }

        return ['ok' => false, 'error' => $error !== '' ? $error : $captured];
    }

    /**
     * Tira da fila um e-mail que ainda não saiu (vai para "Cancelados").
     */
    public static function cancel(int $id): bool
    {
        $row = self::find($id);
        if ($row === null || !$row['can_cancel']) {
            return false;
        }

        return (bool) (new QueuedNotification())->delete(['id' => $id]);
    }

    /**
     * Apaga de vez um e-mail que já saiu da fila (enviado, cancelado ou não entregue).
     */
    public static function purge(int $id): bool
    {
        $row = self::find($id);
        if ($row === null || !$row['can_delete']) {
            return false;
        }

        $done = (bool) (new QueuedNotification())->delete(['id' => $id], 1);
        if ($done) {
            FailureLog::forget($id);
        }

        return $done;
    }

    /**
     * Cancela tudo que está esperando na fila.
     *
     * @return int quantos foram cancelados
     */
    public static function cancelAllPending(): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(
            QueuedNotification::getTable(),
            ['is_deleted' => 1],
            ['mode' => 'mailing', 'is_deleted' => 0, 'sent_time' => null]
        );
        Facts::reset();

        return (int) $DB->affectedRows();
    }

    /**
     * Antes de mandar o GLPI enviar algo pela tela, confere rapidamente se o
     * servidor atende: o envio do GLPI pode esperar minutos por um servidor
     * que não responde. Devolve '' se está tudo bem, ou o erro técnico.
     */
    public static function connectionProblem(): string
    {
        $probe = SmtpProbe::run();

        return $probe['ok'] ? '' : ($probe['error'] !== '' ? $probe['error'] : 'Failed to connect to server');
    }

    // ------------------------------------------------------------------
    // Mensagens que o GLPI empilha na sessão quando um envio falha
    // ------------------------------------------------------------------

    /**
     * @return int quantas mensagens de erro já existem
     */
    private static function snapshotMessages(): int
    {
        return count((array) ($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? []));
    }

    /**
     * Retira da sessão as mensagens de erro que o GLPI acabou de empilhar (a
     * tela do MailBridge mostra uma explicação melhor) e devolve o erro técnico.
     */
    private static function takeNewErrors(int $before): string
    {
        $all = (array) ($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? []);
        if (count($all) <= $before) {
            return '';
        }
        $new = array_slice($all, $before);
        $_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] = array_slice($all, 0, $before);
        if (!$_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR]) {
            unset($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR]);
        }

        $last  = (string) end($new);
        $parts = preg_split('#<br\s*/?>#i', $last, 2) ?: [];

        return Text::oneLine(html_entity_decode(strip_tags((string) end($parts)), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 600);
    }
}
