<?php

namespace GlpiPlugin\Mailtech;

use CommonITILActor;
use Glpi\RichText\RichText;
use Group;
use Html;
use ITILCategory;
use Ticket;
use User;

/**
 * Monta as variáveis (##ticket.title## etc.) e renderiza assunto/corpo.
 *
 * Cada variável tem duas versões: 'text' (assunto e corpo em texto simples)
 * e 'html' (corpo HTML, já escapado).
 *
 * render(), unknownVariables(), compose() e sample() não consultam nada:
 * dá para testá-las sem o GLPI.
 */
final class Template
{
    /**
     * Variáveis documentadas na tela do modelo: nome => descrição.
     * É também a lista de variáveis PERMITIDAS: qualquer outra fica como texto.
     */
    public static function placeholders(): array
    {
        return [
            'ticket.id'            => __('Número do chamado', 'mailtech'),
            'ticket.title'         => __('Título do chamado', 'mailtech'),
            'ticket.content'       => __('Descrição do chamado', 'mailtech'),
            'ticket.url'           => __('Link para abrir o chamado', 'mailtech'),
            'ticket.status'        => __('Status atual', 'mailtech'),
            'ticket.old_status'    => __('Status anterior (evento de mudança de status)', 'mailtech'),
            'ticket.priority'      => __('Prioridade', 'mailtech'),
            'ticket.urgency'       => __('Urgência', 'mailtech'),
            'ticket.type'          => __('Tipo (incidente ou requisição)', 'mailtech'),
            'ticket.category'      => __('Categoria', 'mailtech'),
            'ticket.entity'        => __('Entidade', 'mailtech'),
            'ticket.opening_date'  => __('Data de abertura', 'mailtech'),
            'ticket.requesters'    => __('Solicitantes', 'mailtech'),
            'ticket.technicians'   => __('Técnicos atribuídos', 'mailtech'),
            'ticket.groups'        => __('Grupos técnicos atribuídos', 'mailtech'),
            'recipient.name'       => __('Nome de quem recebe o e-mail', 'mailtech'),
            'event.label'          => __('Frase que descreve o evento', 'mailtech'),
            'event.author'         => __('Quem executou a ação', 'mailtech'),
            'event.content'        => __('Texto do acompanhamento, tarefa ou solução', 'mailtech'),
        ];
    }

    /**
     * Substitui ##variavel## no modelo.
     *
     * @param string $template modelo (como guardado na regra)
     * @param array  $vars     resultado de variables() ou sample()
     * @param bool   $html     true = corpo HTML; false = texto simples
     */
    public static function render(string $template, array $vars, bool $html): string
    {
        $template = Text::decode($template);

        // Modelo em texto simples vira HTML preservando as quebras de linha.
        if ($html && $template === strip_tags($template)) {
            $template = nl2br(Text::esc($template));
        }

        $rendered = preg_replace_callback(
            '/##([a-z_]+\.[a-z_]+)##/',
            static function (array $m) use ($vars, $html): string {
                if (!isset($vars[$m[1]])) {
                    return $m[0];
                }

                return $html ? $vars[$m[1]]['html'] : $vars[$m[1]]['text'];
            },
            $template
        );

        return (string) $rendered;
    }

    /**
     * Variáveis usadas nos textos que não existem na lista permitida.
     *
     * @param string[] $known nomes permitidos (sem os ##)
     * @return string[] nomes desconhecidos, sem repetição
     */
    public static function unknownVariables(array $known, string ...$texts): array
    {
        $unknown = [];
        foreach ($texts as $text) {
            if (preg_match_all('/##([^#\s]{1,60})##/', $text, $matches)) {
                foreach ($matches[1] as $name) {
                    if (!in_array($name, $known, true)) {
                        $unknown[$name] = $name;
                    }
                }
            }
        }

        return array_values($unknown);
    }

    /**
     * Monta o e-mail final a partir de um modelo e das variáveis.
     *
     * @param array{subject:string,body:string,body_text?:string} $template
     * @return array{subject:string,html:string,text:string}
     */
    public static function compose(array $template, array $vars): array
    {
        $subject = Text::oneLine(self::render((string) $template['subject'], $vars, false));
        $html    = self::render((string) $template['body'], $vars, true);

        $custom = trim(Text::decode((string) ($template['body_text'] ?? '')));
        $text   = $custom !== '' ? self::render($custom, $vars, false) : Text::toPlain($html);

        return ['subject' => $subject, 'html' => $html, 'text' => $text];
    }

    /**
     * Limpa o HTML de um modelo: tira scripts, estilos embutidos, molduras e
     * atributos perigosos, usando o filtro de HTML do próprio GLPI.
     * As variáveis (##...##) passam intactas.
     */
    public static function clean(string $html): string
    {
        if ($html === '' || $html === strip_tags($html)) {
            return $html; // texto simples: nada a limpar
        }

        $html = (string) preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1\s*>#is', '', $html);
        $html = (string) preg_replace('#<(script|style|iframe|object|embed|form|link|meta|base)\b[^>]*/?>#i', '', $html);

        if (class_exists(RichText::class)) {
            $html = (string) RichText::getSafeHtml($html);
        }

        return trim($html);
    }

    /**
     * Variáveis do chamado e do evento (sem o destinatário: veja withRecipient()).
     * Faz consultas; chame uma vez por evento, não por destinatário.
     *
     * @param Ticket $ticket
     * @param string $event  código do evento (Rule::EVENT_*)
     * @param array  $ctx    contexto do evento (autor, conteúdo, status antigo...)
     */
    public static function variables(Ticket $ticket, string $event, array $ctx): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $f  = $ticket->fields;
        $id = (int) $ticket->getID();

        $url = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/index.php?redirect=' . rawurlencode('Ticket_' . $id);

        $category = '';
        if ((int) ($f['itilcategories_id'] ?? 0) > 0) {
            $cat = new ITILCategory();
            if ($cat->getFromDB((int) $f['itilcategories_id'])) {
                $category = Text::decode((string) $cat->fields['completename']);
            }
        }

        $entity = '';
        $ent    = new \Entity();
        if ($ent->getFromDB((int) $f['entities_id'])) {
            $entity = Text::decode((string) ($ent->fields['completename'] ?? $ent->fields['name']));
        }

        $status     = Ticket::getStatus((int) $f['status']);
        $old_status = isset($ctx['old_status']) ? Ticket::getStatus((int) $ctx['old_status']) : '';

        return [
            'ticket.id'           => self::plain((string) $id),
            'ticket.title'        => self::plain(Text::decode((string) $f['name'])),
            'ticket.content'      => self::rich((string) ($f['content'] ?? '')),
            'ticket.url'          => self::plain($url),
            'ticket.status'       => self::plain($status),
            'ticket.old_status'   => self::plain($old_status),
            'ticket.priority'     => self::plain(Ticket::getPriorityName((int) $f['priority'])),
            'ticket.urgency'      => self::plain(Ticket::getUrgencyName((int) $f['urgency'])),
            'ticket.type'         => self::plain(Ticket::getTicketTypeName((int) $f['type'])),
            'ticket.category'     => self::plain($category),
            'ticket.entity'       => self::plain($entity),
            'ticket.opening_date' => self::plain((string) Html::convDateTime($f['date'] ?? null)),
            'ticket.requesters'   => self::plain(self::userNames($id, CommonITILActor::REQUESTER)),
            'ticket.technicians'  => self::plain(self::userNames($id, CommonITILActor::ASSIGN)),
            'ticket.groups'       => self::plain(self::groupNames($id, CommonITILActor::ASSIGN)),
            'recipient.name'      => self::plain(''),
            'event.label'         => self::plain(self::eventLabel($event, $status, $old_status)),
            'event.author'        => self::plain(self::authorName($ctx)),
            'event.content'       => self::rich((string) ($ctx['content'] ?? '')),
        ];
    }

    /**
     * Completa as variáveis com o nome de quem recebe.
     */
    public static function withRecipient(array $vars, string $name): array
    {
        $vars['recipient.name'] = self::plain($name);

        return $vars;
    }

    /**
     * Dados de exemplo (inventados) para a prévia e o teste de um modelo.
     */
    public static function sample(string $event, string $recipient_name = '', string $url_base = ''): array
    {
        $status     = __('Em atendimento (atribuído)', 'mailtech');
        $old_status = __('Novo', 'mailtech');
        if ($event === Rule::EVENT_SOLUTION) {
            $old_status = $status;
            $status     = __('Solucionado', 'mailtech');
        }
        if ($recipient_name === '') {
            $recipient_name = __('João Lima', 'mailtech');
        }
        $url = rtrim($url_base !== '' ? $url_base : 'https://glpi.exemplo.com.br', '/') . '/index.php?redirect=Ticket_1234';

        $content = '<p>' . Text::esc(__('Desde hoje cedo a impressora do financeiro não imprime. Já tentei desligar e ligar.', 'mailtech')) . '</p>';
        $note    = '<p>' . Text::esc(__('Troquei o cabo de rede e a impressora voltou a funcionar.', 'mailtech')) . '</p>';

        return [
            'ticket.id'           => self::plain('1234'),
            'ticket.title'        => self::plain(__('Impressora do 2º andar não imprime', 'mailtech')),
            'ticket.content'      => ['text' => Text::toPlain($content), 'html' => $content],
            'ticket.url'          => self::plain($url),
            'ticket.status'       => self::plain($status),
            'ticket.old_status'   => self::plain($event === Rule::EVENT_STATUS || $event === Rule::EVENT_SOLUTION ? $old_status : ''),
            'ticket.priority'     => self::plain(__('Alta', 'mailtech')),
            'ticket.urgency'      => self::plain(__('Alta', 'mailtech')),
            'ticket.type'         => self::plain(__('Incidente', 'mailtech')),
            'ticket.category'     => self::plain(__('Equipamentos > Impressoras', 'mailtech')),
            'ticket.entity'       => self::plain(__('Matriz', 'mailtech')),
            'ticket.opening_date' => self::plain(date('d/m/Y') . ' 08:15'),
            'ticket.requesters'   => self::plain(__('Maria Souza', 'mailtech')),
            'ticket.technicians'  => self::plain(__('João Lima', 'mailtech')),
            'ticket.groups'       => self::plain(__('Suporte N1', 'mailtech')),
            'recipient.name'      => self::plain($recipient_name),
            'event.label'         => self::plain(self::eventLabel($event, $status, $old_status)),
            'event.author'        => self::plain(__('Maria Souza', 'mailtech')),
            'event.content'       => ['text' => Text::toPlain($note), 'html' => $note],
        ];
    }

    public static function eventLabel(string $event, string $status, string $old_status = ''): string
    {
        switch ($event) {
            case Rule::EVENT_NEW_TICKET:
                return __('Um novo chamado foi aberto.', 'mailtech');
            case Rule::EVENT_ASSIGNED:
                return __('Um chamado foi atribuído a você ou ao seu grupo de atendimento.', 'mailtech');
            case Rule::EVENT_FOLLOWUP:
                return __('Um novo acompanhamento foi adicionado ao chamado.', 'mailtech');
            case Rule::EVENT_TASK:
                return __('Uma nova tarefa foi adicionada ao chamado.', 'mailtech');
            case Rule::EVENT_SOLUTION:
                return __('Uma solução foi registrada para o chamado.', 'mailtech');
            case Rule::EVENT_STATUS:
                return sprintf(
                    __('O status do chamado mudou de "%1$s" para "%2$s".', 'mailtech'),
                    $old_status !== '' ? $old_status : '?',
                    $status
                );
        }

        return '';
    }

    private static function authorName(array $ctx): string
    {
        $users_id = (int) ($ctx['author_id'] ?? 0);
        if ($users_id <= 0) {
            return __('Sistema', 'mailtech');
        }
        $user = new User();
        if (!$user->getFromDB($users_id)) {
            return '';
        }

        return Text::decode(strip_tags($user->getFriendlyName()));
    }

    /**
     * Nomes dos usuários vinculados ao chamado com o papel informado.
     */
    private static function userNames(int $tickets_id, int $type): string
    {
        /** @var \DBmysql $DB */
        global $DB;

        $names = [];
        $rows  = $DB->request([
            'FROM'  => \Ticket_User::getTable(),
            'WHERE' => ['tickets_id' => $tickets_id, 'type' => $type],
        ]);
        foreach ($rows as $row) {
            if ((int) $row['users_id'] > 0) {
                $user = new User();
                if ($user->getFromDB((int) $row['users_id'])) {
                    $names[] = Text::decode(strip_tags($user->getFriendlyName()));
                }
            } elseif (!empty($row['alternative_email'])) {
                $names[] = Text::decode((string) $row['alternative_email']);
            }
        }

        return implode(', ', $names);
    }

    /**
     * Nomes dos grupos vinculados ao chamado com o papel informado.
     */
    private static function groupNames(int $tickets_id, int $type): string
    {
        /** @var \DBmysql $DB */
        global $DB;

        $names = [];
        $rows  = $DB->request([
            'FROM'  => \Group_Ticket::getTable(),
            'WHERE' => ['tickets_id' => $tickets_id, 'type' => $type],
        ]);
        foreach ($rows as $row) {
            $group = new Group();
            if ($group->getFromDB((int) $row['groups_id'])) {
                $names[] = Text::decode((string) ($group->fields['completename'] ?? $group->fields['name']));
            }
        }

        return implode(', ', $names);
    }

    /**
     * Variável de texto simples (escapada automaticamente no HTML).
     */
    private static function plain(string $value): array
    {
        return ['text' => $value, 'html' => Text::esc($value)];
    }

    /**
     * Variável de texto formatado (HTML do GLPI, já filtrado).
     */
    private static function rich(string $stored): array
    {
        $html = RichText::getSafeHtml($stored);

        return ['text' => Text::toPlain($html), 'html' => $html];
    }
}
