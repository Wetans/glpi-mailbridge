<?php

namespace GlpiPlugin\Mailtech\Screen;

use Entity;
use GlpiPlugin\Mailtech\Entities;
use GlpiPlugin\Mailtech\NativeNotifications;
use GlpiPlugin\Mailtech\Page;
use GlpiPlugin\Mailtech\Rule;
use GlpiPlugin\Mailtech\Settings;
use GlpiPlugin\Mailtech\Text;
use GlpiPlugin\Mailtech\Translation;
use ITILCategory;
use Ticket;

/**
 * Eventos e destinatários: regras do MailTech (quem recebe o quê), liga/desliga
 * por entidade e os avisos nativos do GLPI.
 */
final class EventsScreen
{
    private const PER_PAGE = 20;

    /**
     * @return array{0:string,1:array}
     */
    public static function build(array $get): array
    {
        if (isset($get['rule'])) {
            return self::form($get);
        }

        $tab = (string) ($get['tab'] ?? 'rules');
        if (!in_array($tab, ['rules', 'entities', 'native'], true)) {
            $tab = 'rules';
        }

        $native = NativeNotifications::counts();
        $data   = [
            'tab'          => $tab,
            'tabs'         => [
                'rules'    => ['label' => __('Avisos do MailTech', 'mailtech'), 'url' => Page::url('events')],
                'entities' => ['label' => __('Por entidade', 'mailtech'), 'url' => Page::url('events', ['tab' => 'entities'])],
                'native'   => [
                    'label' => sprintf(__('Avisos nativos do GLPI (%d)', 'mailtech'), $native['total']),
                    'url'   => Page::url('events', ['tab' => 'native']),
                ],
            ],
            'mailtech_on'  => (int) Settings::get('enabled') === 1,
            'native_count' => $native,
        ];

        if ($tab === 'entities') {
            $data += self::entities($get);
        } elseif ($tab === 'native') {
            $data += self::native($get);
        } else {
            $data += self::rules();
        }

        return ['events.html.twig', $data];
    }

    /**
     * Lista de regras com o resumo "quem recebe o quê".
     */
    private static function rules(): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = (new Rule())->find([], ['id']);

        // Nomes usados nos filtros, buscados de uma vez.
        $entity_ids   = [];
        $category_ids = [];
        foreach ($rows as $row) {
            if ((int) $row['filter_entities_id'] > 0) {
                $entity_ids[] = (int) $row['filter_entities_id'];
            }
            if ((int) $row['filter_itilcategories_id'] > 0) {
                $category_ids[] = (int) $row['filter_itilcategories_id'];
            }
        }
        $entities   = Entities::names($entity_ids);
        $categories = [];
        if ($category_ids) {
            $iterator = $DB->request([
                'SELECT' => ['id', 'completename'],
                'FROM'   => 'glpi_itilcategories',
                'WHERE'  => ['id' => array_values(array_unique($category_ids))],
            ]);
            foreach ($iterator as $category) {
                $categories[(int) $category['id']] = Text::decode((string) $category['completename']);
            }
        }
        $priorities = Rule::priorities();
        $types      = [
            Ticket::INCIDENT_TYPE => Ticket::getTicketTypeName(Ticket::INCIDENT_TYPE),
            Ticket::DEMAND_TYPE   => Ticket::getTicketTypeName(Ticket::DEMAND_TYPE),
        ];
        $languages = Translation::languagesByRule(array_map('intval', array_keys($rows)));

        $rules = [];
        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $rules[] = [
                'id'           => $id,
                'name'         => Text::decode((string) $row['name']),
                'active'       => (int) $row['is_active'] === 1,
                'summary'      => Rule::summary($row, $entities, $categories, $priorities, $types),
                'languages'    => $languages[$id] ?? [],
                'edit_url'     => Page::url('events', ['rule' => $id]),
                'template_url' => Page::url('templates', ['rule' => $id]),
            ];
        }

        return ['rules' => $rules, 'new_url' => Page::url('events', ['rule' => 'new'])];
    }

    /**
     * Liga/desliga por entidade, com a herança visível.
     */
    private static function entities(array $get): array
    {
        $total = Entities::total();
        $start = max(0, (int) ($get['estart'] ?? 0));
        if ($start >= $total) {
            $start = 0;
        }

        return [
            'entity_total' => $total,
            'entities'     => $total > 1 ? Entities::page(Settings::entityModes(), $start, self::PER_PAGE) : [],
            'pager'        => self::pager(Page::url('events', ['tab' => 'entities']), 'estart', $start, $total),
            'estart'       => $start,
        ];
    }

    /**
     * Avisos nativos do GLPI que saem por e-mail.
     */
    private static function native(array $get): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $search = Text::raw($get['nq'] ?? '');
        $total  = NativeNotifications::total($search);
        $start  = max(0, (int) ($get['nstart'] ?? 0));
        if ($start >= $total) {
            $start = 0;
        }

        return [
            'natives'    => NativeNotifications::page($search, $start, self::PER_PAGE),
            'nq'         => $search,
            'nstart'     => $start,
            'pager'      => self::pager(Page::url('events', ['tab' => 'native'] + ($search !== '' ? ['nq' => $search] : [])), 'nstart', $start, $total),
            'native_url' => $CFG_GLPI['root_doc'] . '/front/notification.php',
            'search_url' => Page::url(''),
        ];
    }

    /**
     * Formulário de uma regra (evento, destinatários e filtros).
     */
    private static function form(array $get): array
    {
        $flash  = Page::pullFlash('rule');
        $id     = (int) $get['rule'];
        $rule   = new Rule();
        $is_new = $id <= 0 || !$rule->getFromDB($id);

        if ($is_new) {
            $id     = 0;
            $values = [
                'name'                     => '',
                'event'                    => Rule::EVENT_ASSIGNED,
                'is_active'                => 1,
                'notify_assigned_users'    => 1,
                'notify_assigned_groups'   => 0,
                'notify_requesters'        => 0,
                'notify_observers'         => 0,
                'exclude_author'           => 1,
                'extra_emails'             => '',
                'filter_entities_id'       => 0,
                'filter_itilcategories_id' => 0,
                'filter_type'              => 0,
                'filter_min_priority'      => 0,
            ];
        } else {
            $values = [];
            foreach ($rule->fields as $key => $value) {
                $values[$key] = is_string($value) ? Text::decode($value) : $value;
            }
        }
        foreach ($flash['old'] as $key => $old) {
            $values[$key] = $old;
        }
        foreach (Rule::FLAGS as $flag) {
            $values[$flag] = !empty($values[$flag]);
        }

        $has_filters = (int) $values['filter_entities_id'] > 0 || (int) $values['filter_itilcategories_id'] > 0
            || (int) $values['filter_type'] > 0 || (int) $values['filter_min_priority'] > 0;

        return ['rule_form.html.twig', [
            'id'          => $id,
            'is_new'      => $is_new,
            'v'           => $values,
            'errors'      => $flash['errors'],
            'events'      => Rule::getEvents(),
            'types'       => [
                0                     => __('Qualquer tipo', 'mailtech'),
                Ticket::INCIDENT_TYPE => Ticket::getTicketTypeName(Ticket::INCIDENT_TYPE),
                Ticket::DEMAND_TYPE   => Ticket::getTicketTypeName(Ticket::DEMAND_TYPE),
            ],
            'priorities'  => [0 => __('Qualquer prioridade', 'mailtech')] + Rule::priorities(),
            'has_filters' => $has_filters,
            'entity_dropdown' => Entity::dropdown([
                'name'    => 'filter_entities_id',
                'value'   => (int) $values['filter_entities_id'],
                'rand'     => 'tm',
                'display'  => false,
                'comments' => false,
            ]),
            'category_dropdown' => ITILCategory::dropdown([
                'name'                => 'filter_itilcategories_id',
                'value'               => (int) $values['filter_itilcategories_id'],
                'rand'                => 'tm',
                'display'             => false,
                'comments'            => false,
                'display_emptychoice' => true,
                'emptylabel'          => __('Qualquer categoria', 'mailtech'),
            ]),
            'back_url'     => Page::url('events'),
            'template_url' => $is_new ? '' : Page::url('templates', ['rule' => $id]),
        ]];
    }

    /**
     * Dados da paginação (anterior/próxima) de uma lista.
     *
     * @return array{start:int,total:int,from:int,to:int,prev:string,next:string}
     */
    public static function pager(string $base, string $param, int $start, int $total, int $per_page = self::PER_PAGE): array
    {
        $glue = strpos($base, '?') === false ? '?' : '&';

        return [
            'start' => $start,
            'total' => $total,
            'from'  => $total > 0 ? $start + 1 : 0,
            'to'    => min($total, $start + $per_page),
            'prev'  => $start > 0 ? $base . $glue . $param . '=' . max(0, $start - $per_page) : '',
            'next'  => $start + $per_page < $total ? $base . $glue . $param . '=' . ($start + $per_page) : '',
        ];
    }
}
