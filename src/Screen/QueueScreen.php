<?php

namespace GlpiPlugin\Mailtech\Screen;

use GlpiPlugin\Mailtech\ErrorExplainer;
use GlpiPlugin\Mailtech\Facts;
use GlpiPlugin\Mailtech\FailureLog;
use GlpiPlugin\Mailtech\Health;
use GlpiPlugin\Mailtech\Page;
use GlpiPlugin\Mailtech\Queue;
use GlpiPlugin\Mailtech\QueueState;
use GlpiPlugin\Mailtech\Text;
use GlpiPlugin\Mailtech\Validation;
use Html;

/**
 * Fila e histórico: o que está esperando, o que saiu, o que falhou e por quê.
 */
final class QueueScreen
{
    /**
     * @return array{0:string,1:array}
     */
    public static function build(array $get): array
    {
        if (isset($get['id'])) {
            $detail = self::detail((int) $get['id']);
            if ($detail !== null) {
                return $detail;
            }
        }

        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $filter = (string) ($get['filter'] ?? 'all');
        if (!isset(QueueState::FILTERS[$filter])) {
            $filter = 'all';
        }
        $text  = Text::raw($get['q'] ?? '');
        $only  = !empty($get['only']);
        $start = max(0, (int) ($get['start'] ?? 0));

        $found = Queue::search($filter, $text, $only, $start);
        if ($start > 0 && !$found['rows']) {
            $start = 0;
            $found = Queue::search($filter, $text, $only, 0);
        }

        // Motivo das falhas da página inteira, numa consulta só.
        $failing = [];
        foreach ($found['rows'] as $row) {
            if (in_array($row['state'], [QueueState::RETRYING, QueueState::FAILED], true)) {
                $failing[] = $row['id'];
            }
        }
        $failures = FailureLog::forQueueIds($failing);
        $policy   = Queue::policy();

        $rows = [];
        foreach ($found['rows'] as $row) {
            $row['reason'] = '';
            if (in_array($row['state'], [QueueState::RETRYING, QueueState::FAILED], true)) {
                $last          = $failures[$row['id']][0]['error'] ?? '';
                $row['reason'] = $last !== ''
                    ? ErrorExplainer::explain($last)['title']
                    : __('O GLPI não registrou o motivo desta falha.', 'mailtech');
            }
            $row['when']       = self::when($row, $policy['max_retries']);
            $row['detail_url'] = Page::url('queue', ['id' => $row['id']]);
            $rows[]            = $row;
        }

        $params = array_filter(
            ['filter' => $filter !== 'all' ? $filter : '', 'q' => $text, 'only' => $only ? 1 : ''],
            static function ($value): bool {
                return $value !== '';
            }
        );

        $counts  = Queue::counts();
        $labels  = [
            'all'       => __('Todos', 'mailtech'),
            'queued'    => __('Na fila', 'mailtech'),
            'failed'    => __('Com falha', 'mailtech'),
            'sent'      => __('Enviados', 'mailtech'),
            'cancelled' => __('Cancelados', 'mailtech'),
        ];
        $filters = [];
        foreach ($labels as $code => $label) {
            $filters[] = [
                'code'    => $code,
                'label'   => $label,
                'count'   => $counts[$code],
                'url'     => Page::url('queue', array_filter(['filter' => $code !== 'all' ? $code : '', 'q' => $text, 'only' => $only ? 1 : ''], 'strlen')),
                'current' => $code === $filter,
            ];
        }

        $empty = [
            'all'       => __('Nenhum e-mail no histórico ainda.', 'mailtech'),
            'queued'    => __('Nenhum e-mail na fila: tudo em dia.', 'mailtech'),
            'failed'    => __('Nenhuma falha de envio. Tudo certo por aqui.', 'mailtech'),
            'sent'      => __('Nenhum e-mail enviado no período guardado.', 'mailtech'),
            'cancelled' => __('Nenhum e-mail cancelado.', 'mailtech'),
        ];

        // Situação da rotina automática (a mesma verificação da Visão geral).
        $routine_check = null;
        foreach (Facts::health()['checks'] as $check) {
            if ($check['id'] === 'cron') {
                $routine_check = $check;
            }
        }
        $routine = Queue::routine();
        $flash   = Page::pullFlash('policy');

        $result = $_SESSION['plugin_mailtech_result'] ?? null;
        unset($_SESSION['plugin_mailtech_result']);

        return ['queue.html.twig', [
            'rows'          => $rows,
            'total'         => $found['total'],
            'filter'        => $filter,
            'filters'       => $filters,
            'q'             => $text,
            'only'          => $only,
            'start'         => $start,
            'empty'         => ($text !== '' || $only) ? __('Nenhum e-mail encontrado com essa busca.', 'mailtech') : $empty[$filter],
            'pager'         => EventsScreen::pager(Page::url('queue', $params), 'start', $start, $found['total'], Queue::PER_PAGE),
            'search_url'    => Page::url(''),
            'clear_url'     => Page::url('queue', $filter !== 'all' ? ['filter' => $filter] : []),
            'pending'       => $counts['queued'],
            'routine'       => $routine,
            'routine_check' => $routine_check,
            'routine_off'   => $routine['state'] === 0,
            'routine_url'   => $routine['id'] > 0 ? $CFG_GLPI['root_doc'] . '/front/crontask.form.php?id=' . $routine['id'] : '',
            'policy'        => $flash['old'] + $policy,
            'errors'        => $flash['errors'],
            'hints'         => Validation::hints(),
            'focus'         => (string) ($get['focus'] ?? ''),
            'technical'     => is_array($result) ? (string) ($result['technical'] ?? '') : '',
        ]];
    }

    /**
     * Frase "quando" de uma linha da lista.
     */
    private static function when(array $row, int $max_retries): string
    {
        switch ($row['state']) {
            case QueueState::SENT:
                return sprintf(__('Enviado em %s', 'mailtech'), Html::convDateTime($row['sent_time']));
            case QueueState::PENDING:
                return sprintf(__('Na fila desde %s', 'mailtech'), Html::convDateTime($row['create_time']));
            case QueueState::RETRYING:
                return sprintf(
                    __('Tentativa %1$d de %2$d falhou. Próxima: %3$s', 'mailtech'),
                    $row['tries'],
                    $max_retries + 1,
                    Html::convDateTime($row['send_time'])
                );
            case QueueState::FAILED:
                return sprintf(
                    _n('Parou após %d tentativa', 'Parou após %d tentativas', $row['tries'], 'mailtech'),
                    $row['tries']
                );
        }

        return sprintf(__('Criado em %s', 'mailtech'), Html::convDateTime($row['create_time']));
    }

    /**
     * Detalhe de um e-mail: destinatário, origem, tentativas, motivo e prévia.
     *
     * @return array{0:string,1:array}|null
     */
    private static function detail(int $id): ?array
    {
        $row = Queue::find($id);
        if ($row === null) {
            return null;
        }

        $policy   = Queue::policy();
        $attempts = [];
        foreach (FailureLog::forQueueIds([$id])[$id] ?? [] as $failure) {
            $explained = $failure['error'] !== ''
                ? ErrorExplainer::explain($failure['error'])
                : ['title' => __('O GLPI não registrou o motivo desta falha.', 'mailtech'), 'advice' => []];
            $attempts[] = [
                'date'      => Html::convDateTime($failure['date']),
                'title'     => $explained['title'],
                'advice'    => $explained['advice'],
                'technical' => $failure['error'],
                'gave_up'   => $failure['gave_up'],
            ];
        }

        $item_url = '';
        if ($row['items_id'] > 0 && $row['itemtype'] !== '' && class_exists($row['itemtype'])
            && is_subclass_of($row['itemtype'], \CommonDBTM::class)
        ) {
            $item_url = $row['itemtype']::getFormURLWithID($row['items_id']);
        }

        $html = $row['body_html'];
        unset($row['body_html']);

        return ['queue_detail.html.twig', [
            'mail'      => $row,
            'when'      => self::when($row, $policy['max_retries']),
            'created'   => Html::convDateTime($row['create_time']),
            'sent'      => $row['sent_time'] !== '' ? Html::convDateTime($row['sent_time']) : '',
            'attempts'  => $attempts,
            'item_url'  => $item_url,
            'item_name' => $item_url !== '' ? $row['itemtype']::getTypeName(1) . ' ' . $row['items_id'] : '',
            'html'      => $html,
            'back_url'  => Page::url('queue', $row['state'] === QueueState::SENT ? [] : ['filter' => 'failed']),
            'list_url'  => Page::url('queue'),
            'ago'       => Health::ago(max(0, time() - (int) strtotime($row['create_time']))),
        ]];
    }
}
