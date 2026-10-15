<?php

namespace GlpiPlugin\Mailtech;

use CommonDBTM;
use Dropdown;
use Session;
use Ticket;

/**
 * Regra de notificação: "quando acontecer EVENTO num chamado que atenda aos
 * FILTROS, enviar ASSUNTO/CORPO para estes DESTINATÁRIOS".
 *
 * As telas ficam em MailTech > Eventos e destinatários (regra) e
 * MailTech > Modelos (mensagem); esta classe guarda só o modelo de dados.
 */
class Rule extends CommonDBTM
{
    public static $rightname = Rights::NAME;

    /** Guarda no histórico do GLPI quem alterou o quê. */
    public $dohistory = true;

    public const EVENT_NEW_TICKET = 'new_ticket';
    public const EVENT_ASSIGNED   = 'assigned';
    public const EVENT_FOLLOWUP   = 'new_followup';
    public const EVENT_TASK       = 'new_task';
    public const EVENT_SOLUTION   = 'new_solution';
    public const EVENT_STATUS     = 'status_changed';

    /** Campos liga/desliga da regra. */
    public const FLAGS = [
        'is_active', 'notify_assigned_users', 'notify_assigned_groups',
        'notify_requesters', 'notify_observers', 'exclude_author',
    ];

    public static function getTypeName($nb = 0)
    {
        return _n('Regra de notificação para técnicos', 'Regras de notificação para técnicos', $nb, 'mailtech');
    }

    public static function getIcon()
    {
        return 'ti ti-mail-forward';
    }

    // Quem tem o direito de configuração do GLPI continua podendo tudo (como no Techmail 1.0.0).
    public static function canView()
    {
        return Rights::can(Rights::VIEW);
    }

    public static function canUpdate()
    {
        return Rights::can(Rights::MANAGE);
    }

    public static function canCreate()
    {
        return Rights::can(Rights::MANAGE);
    }

    public static function canDelete()
    {
        return Rights::can(Rights::MANAGE);
    }

    public static function canPurge()
    {
        return Rights::can(Rights::MANAGE);
    }

    public static function getSearchURL($full = true)
    {
        return Page::url('events');
    }

    /**
     * Eventos disponíveis: código => rótulo.
     */
    public static function getEvents(): array
    {
        return [
            self::EVENT_NEW_TICKET => __('Novo chamado aberto', 'mailtech'),
            self::EVENT_ASSIGNED   => __('Chamado atribuído (técnico ou grupo)', 'mailtech'),
            self::EVENT_FOLLOWUP   => __('Novo acompanhamento', 'mailtech'),
            self::EVENT_TASK       => __('Nova tarefa', 'mailtech'),
            self::EVENT_SOLUTION   => __('Solução registrada', 'mailtech'),
            self::EVENT_STATUS     => __('Mudança de status', 'mailtech'),
        ];
    }

    /**
     * Assunto e corpo padrão para um evento.
     *
     * @return array{0: string, 1: string}
     */
    public static function defaultTemplate(string $event): array
    {
        $subjects = [
            self::EVENT_NEW_TICKET => '[GLPI] Novo chamado ##ticket.id##: ##ticket.title##',
            self::EVENT_ASSIGNED   => '[GLPI] Chamado ##ticket.id## atribuído a você: ##ticket.title##',
            self::EVENT_FOLLOWUP   => '[GLPI] Novo acompanhamento no chamado ##ticket.id##: ##ticket.title##',
            self::EVENT_TASK       => '[GLPI] Nova tarefa no chamado ##ticket.id##: ##ticket.title##',
            self::EVENT_SOLUTION   => '[GLPI] Solução registrada no chamado ##ticket.id##: ##ticket.title##',
            self::EVENT_STATUS     => '[GLPI] Chamado ##ticket.id## mudou de status: ##ticket.title##',
        ];

        $event_block = '';
        if (in_array($event, [self::EVENT_FOLLOWUP, self::EVENT_TASK, self::EVENT_SOLUTION], true)) {
            $event_block = "<hr>\n<p><b>##event.author##</b> escreveu:</p>\n<div>##event.content##</div>\n";
        }

        $body = "<p>Olá, <b>##recipient.name##</b>!</p>\n"
            . "<p>##event.label##</p>\n"
            . "<p><b>Chamado ##ticket.id##</b> - ##ticket.title##<br>\n"
            . "<b>Entidade:</b> ##ticket.entity##<br>\n"
            . "<b>Categoria:</b> ##ticket.category##<br>\n"
            . "<b>Prioridade:</b> ##ticket.priority##<br>\n"
            . "<b>Status:</b> ##ticket.status##<br>\n"
            . "<b>Solicitante:</b> ##ticket.requesters##<br>\n"
            . "<b>Técnicos:</b> ##ticket.technicians##<br>\n"
            . "<b>Grupos:</b> ##ticket.groups##</p>\n"
            . "<div>##ticket.content##</div>\n"
            . $event_block
            . "<p><a href=\"##ticket.url##\">Abrir o chamado no GLPI</a></p>\n";

        return [$subjects[$event] ?? $subjects[self::EVENT_ASSIGNED], $body];
    }

    /**
     * Rótulos dos campos (usados na busca do GLPI e no histórico de alterações).
     *
     * @return array<string,string> campo => rótulo
     */
    public static function fieldLabels(): array
    {
        return [
            'name'                     => __('Nome', 'mailtech'),
            'event'                    => __('Evento', 'mailtech'),
            'is_active'                => __('Ativa', 'mailtech'),
            'subject'                  => __('Assunto', 'mailtech'),
            'body'                     => __('Mensagem', 'mailtech'),
            'body_text'                => __('Versão em texto simples', 'mailtech'),
            'extra_emails'             => __('E-mails fixos', 'mailtech'),
            'notify_assigned_users'    => __('Técnicos atribuídos ao chamado', 'mailtech'),
            'notify_assigned_groups'   => __('Membros dos grupos técnicos atribuídos', 'mailtech'),
            'notify_requesters'        => __('Solicitantes do chamado', 'mailtech'),
            'notify_observers'         => __('Observadores do chamado', 'mailtech'),
            'exclude_author'           => __('Não avisar quem executou a ação', 'mailtech'),
            'filter_entities_id'       => __('Entidade (inclui subentidades)', 'mailtech'),
            'filter_itilcategories_id' => __('Categoria (inclui subcategorias)', 'mailtech'),
            'filter_type'              => __('Tipo do chamado', 'mailtech'),
            'filter_min_priority'      => __('Prioridade mínima', 'mailtech'),
        ];
    }

    public function rawSearchOptions()
    {
        $table  = $this->getTable();
        $labels = self::fieldLabels();

        $options = [
            [
                'id'   => 'common',
                'name' => __('Características', 'mailtech'),
            ],
            [
                'id'            => '1',
                'table'         => $table,
                'field'         => 'name',
                'name'          => $labels['name'],
                'datatype'      => 'itemlink',
                'massiveaction' => false,
            ],
            [
                'id'            => '2',
                'table'         => $table,
                'field'         => 'id',
                'name'          => __('ID', 'mailtech'),
                'datatype'      => 'number',
                'massiveaction' => false,
            ],
            [
                'id'         => '3',
                'table'      => $table,
                'field'      => 'event',
                'name'       => $labels['event'],
                'datatype'   => 'specific',
                'searchtype' => ['equals', 'notequals'],
            ],
            [
                'id'       => '4',
                'table'    => $table,
                'field'    => 'is_active',
                'name'     => $labels['is_active'],
                'datatype' => 'bool',
            ],
            [
                'id'            => '19',
                'table'         => $table,
                'field'         => 'date_mod',
                'name'          => __('Última atualização', 'mailtech'),
                'datatype'      => 'datetime',
                'massiveaction' => false,
            ],
            [
                'id'            => '121',
                'table'         => $table,
                'field'         => 'date_creation',
                'name'          => __('Data de criação', 'mailtech'),
                'datatype'      => 'datetime',
                'massiveaction' => false,
            ],
        ];

        // Techmail 1.1.0: os demais campos entram para o histórico registrar suas alterações.
        $extra = [
            5  => ['subject', 'string'],
            6  => ['body', 'text'],
            7  => ['body_text', 'text'],
            8  => ['extra_emails', 'text'],
            9  => ['notify_assigned_users', 'bool'],
            10 => ['notify_assigned_groups', 'bool'],
            11 => ['notify_requesters', 'bool'],
            12 => ['notify_observers', 'bool'],
            13 => ['exclude_author', 'bool'],
            14 => ['filter_entities_id', 'number'],
            15 => ['filter_itilcategories_id', 'number'],
            16 => ['filter_type', 'number'],
            17 => ['filter_min_priority', 'number'],
        ];
        foreach ($extra as $id => [$field, $datatype]) {
            $options[] = [
                'id'            => (string) $id,
                'table'         => $table,
                'field'         => $field,
                'name'          => $labels[$field],
                'datatype'      => $datatype,
                'massiveaction' => false,
                'nosearch'      => true,
            ];
        }

        return $options;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'event') {
            return self::getEvents()[$values[$field]] ?? (string) $values[$field];
        }

        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        if ($field === 'event') {
            $options['value'] = $values[$field];

            return Dropdown::showFromArray($name, self::getEvents(), $options);
        }

        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /**
     * @return array<int,string> prioridades 1..6
     */
    public static function priorities(): array
    {
        $list = [];
        for ($i = 1; $i <= 6; $i++) {
            $list[$i] = Ticket::getPriorityName($i);
        }

        return $list;
    }

    /**
     * Resumo legível de "quem recebe o quê" de uma regra.
     *
     * @param array               $rule       linha da tabela (já decodificada ou não)
     * @param array<int,string>   $entities   nomes das entidades usadas em filtros
     * @param array<int,string>   $categories nomes das categorias usadas em filtros
     * @param array<int,string>   $priorities nomes das prioridades
     * @param array<int,string>   $types      nomes dos tipos de chamado
     * @return array{when:string,who:string[],filters:string[],notes:string[]}
     */
    public static function summary(
        array $rule,
        array $entities = [],
        array $categories = [],
        array $priorities = [],
        array $types = []
    ): array {
        $events = self::getEvents();
        $who    = [];
        if (!empty($rule['notify_assigned_users'])) {
            $who[] = ((string) $rule['event'] === self::EVENT_ASSIGNED)
                ? __('o técnico que acabou de ser atribuído', 'mailtech')
                : __('os técnicos atribuídos ao chamado', 'mailtech');
        }
        if (!empty($rule['notify_assigned_groups'])) {
            $who[] = ((string) $rule['event'] === self::EVENT_ASSIGNED)
                ? __('os membros do grupo que acabou de ser atribuído', 'mailtech')
                : __('os membros dos grupos técnicos atribuídos', 'mailtech');
        }
        if (!empty($rule['notify_requesters'])) {
            $who[] = __('os solicitantes do chamado', 'mailtech');
        }
        if (!empty($rule['notify_observers'])) {
            $who[] = __('os observadores do chamado', 'mailtech');
        }
        [$extra] = Validation::emailList(Text::decode((string) ($rule['extra_emails'] ?? '')));
        if ($extra) {
            $who[] = count($extra) <= 2
                ? implode(', ', $extra)
                : sprintf(__('%d e-mails fixos', 'mailtech'), count($extra));
        }

        $filters = [];
        $entity  = (int) ($rule['filter_entities_id'] ?? 0);
        if ($entity > 0) {
            $filters[] = sprintf(__('entidade %s e as que estão dentro dela', 'mailtech'), $entities[$entity] ?? ('#' . $entity));
        }
        $category = (int) ($rule['filter_itilcategories_id'] ?? 0);
        if ($category > 0) {
            $filters[] = sprintf(__('categoria %s e as que estão dentro dela', 'mailtech'), $categories[$category] ?? ('#' . $category));
        }
        $type = (int) ($rule['filter_type'] ?? 0);
        if ($type > 0) {
            $filters[] = sprintf(__('tipo %s', 'mailtech'), $types[$type] ?? ('#' . $type));
        }
        $priority = (int) ($rule['filter_min_priority'] ?? 0);
        if ($priority > 0) {
            $filters[] = sprintf(__('prioridade a partir de %s', 'mailtech'), $priorities[$priority] ?? ('#' . $priority));
        }

        $notes = [];
        if (!empty($rule['exclude_author'])) {
            $notes[] = __('Quem fez a ação não recebe.', 'mailtech');
        }

        return [
            'when'    => $events[(string) ($rule['event'] ?? '')] ?? (string) ($rule['event'] ?? ''),
            'who'     => $who,
            'filters' => $filters,
            'notes'   => $notes,
        ];
    }

    public function prepareInputForAdd($input)
    {
        return $this->normalizeInput($input, true);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->normalizeInput($input, false);
    }

    /**
     * Última barreira (as telas validam antes com Validation::rule()).
     *
     * @param array $input
     * @return array|false
     */
    private function normalizeInput(array $input, bool $creating)
    {
        if (isset($input['event']) && !array_key_exists($input['event'], self::getEvents())) {
            Session::addMessageAfterRedirect(__('Evento inválido.', 'mailtech'), false, ERROR);

            return false;
        }

        if ($creating) {
            $event = $input['event'] ?? self::EVENT_ASSIGNED;
            $input['event'] = $event;

            if (trim((string) ($input['name'] ?? '')) === '') {
                $input['name'] = self::getEvents()[$event];
            }

            [$subject, $body] = self::defaultTemplate($event);
            if (trim((string) ($input['subject'] ?? '')) === '') {
                $input['subject'] = Text::sanitize($subject);
            }
            if (trim((string) ($input['body'] ?? '')) === '') {
                $input['body'] = Text::sanitize($body);
            }
        }

        if (array_key_exists('extra_emails', $input)) {
            [$valid] = Validation::emailList(Text::decode((string) $input['extra_emails']));
            $input['extra_emails'] = Text::sanitize(implode("\n", $valid));
        }

        foreach (self::FLAGS as $flag) {
            if (isset($input[$flag])) {
                $input[$flag] = (int) (bool) $input[$flag];
            }
        }
        foreach (['filter_entities_id', 'filter_itilcategories_id', 'filter_type', 'filter_min_priority'] as $number) {
            if (isset($input[$number])) {
                $input[$number] = max(0, (int) $input[$number]);
            }
        }

        return $input;
    }

    /**
     * Ao apagar uma regra, apaga também as traduções do modelo dela.
     */
    public function cleanDBonPurge()
    {
        Translation::deleteForRule((int) $this->getID());
    }

    /**
     * O chamado atende aos filtros da regra?
     *
     * @param array  $rule   linha da tabela de regras
     * @param Ticket $ticket
     */
    public static function matches(array $rule, Ticket $ticket): bool
    {
        $fields = $ticket->fields;

        $entity = (int) ($rule['filter_entities_id'] ?? 0);
        if ($entity > 0) {
            $allowed = array_map('intval', array_values(getSonsOf('glpi_entities', $entity)));
            if (!in_array((int) ($fields['entities_id'] ?? 0), $allowed, true)) {
                return false;
            }
        }

        $category = (int) ($rule['filter_itilcategories_id'] ?? 0);
        if ($category > 0) {
            $allowed = array_map('intval', array_values(getSonsOf('glpi_itilcategories', $category)));
            if (!in_array((int) ($fields['itilcategories_id'] ?? 0), $allowed, true)) {
                return false;
            }
        }

        $type = (int) ($rule['filter_type'] ?? 0);
        if ($type > 0 && (int) ($fields['type'] ?? 0) !== $type) {
            return false;
        }

        $min_priority = (int) ($rule['filter_min_priority'] ?? 0);
        if ($min_priority > 0 && (int) ($fields['priority'] ?? 0) < $min_priority) {
            return false;
        }

        return true;
    }
}
