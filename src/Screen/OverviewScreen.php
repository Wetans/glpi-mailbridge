<?php

namespace GlpiPlugin\Mailbridge\Screen;

use GlpiPlugin\Mailbridge\Facts;
use GlpiPlugin\Mailbridge\Health;
use GlpiPlugin\Mailbridge\Page;

/**
 * Visão geral: está funcionando? o que falta? há falhas?
 */
final class OverviewScreen
{
    /**
     * @return array{0:string,1:array}
     */
    public static function build(array $get): array
    {
        $facts  = Facts::collect();
        $health = Facts::health();
        $now    = time();

        // Só o que pede ação aparece aqui; a lista completa fica no Diagnóstico.
        $issues = [];
        foreach ($health['checks'] as $check) {
            if (in_array($check['level'], [Health::OFF, Health::WARN, Health::ERROR], true)) {
                $check['url'] = $check['section'] !== '' ? Page::url($check['section'], $check['params']) : '';
                $issues[]     = $check;
            }
        }

        $checklist = [];
        foreach ($health['checklist'] as $step) {
            $step['url'] = Page::url($step['section'], $step['params']);
            $checklist[] = $step;
        }

        return ['overview.html.twig', [
            'issues'    => $issues,
            'checklist' => $checklist,
            'progress'  => $health['progress'],
            'counters'  => [
                'pending' => (int) ($facts['pending'] ?? 0),
                'failing' => (int) ($facts['failing_7d'] ?? 0),
            ],
            'last_sent' => !empty($facts['last_sent']) ? Health::ago($now - (int) $facts['last_sent']) : '',
            'urls'      => [
                'test'     => Page::url('sending', ['focus' => 'test']),
                'sending'  => Page::url('sending'),
                'events'   => Page::url('events'),
                'queue'    => Page::url('queue', ['filter' => 'queued']),
                'failures' => Page::url('queue', ['filter' => 'failed']),
                'diag'     => Page::url('diagnostics'),
            ],
        ]];
    }
}
