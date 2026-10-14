<?php

namespace GlpiPlugin\Mailbridge;

/**
 * Situação de cada e-mail da fila do GLPI e o que pode ser feito com ele.
 * Classe pura: trabalha só com a linha da tabela glpi_queuednotifications.
 *
 * Como o GLPI marca as coisas:
 * - enviado: preenche "sent_time" e move para a lixeira (is_deleted = 1);
 * - falhou: soma 1 em "sent_try" e reagenda; quando acabam as tentativas,
 *   move para a lixeira sem "sent_time";
 * - cancelado por alguém: vai para a lixeira sem nunca ter tentado.
 */
final class QueueState
{
    public const PENDING   = 'pending';
    public const RETRYING  = 'retrying';
    public const SENT      = 'sent';
    public const FAILED    = 'failed';
    public const CANCELLED = 'cancelled';

    /** Filtros da tela: código => situações incluídas. */
    public const FILTERS = [
        'all'       => [],
        'queued'    => [self::PENDING, self::RETRYING],
        'failed'    => [self::RETRYING, self::FAILED],
        'sent'      => [self::SENT],
        'cancelled' => [self::CANCELLED],
    ];

    public static function of(array $row): string
    {
        if (!empty($row['sent_time'])) {
            return self::SENT;
        }
        $tries   = (int) ($row['sent_try'] ?? 0);
        $deleted = (int) ($row['is_deleted'] ?? 0) === 1;

        if ($deleted) {
            return $tries > 0 ? self::FAILED : self::CANCELLED;
        }

        return $tries > 0 ? self::RETRYING : self::PENDING;
    }

    public static function label(string $state): string
    {
        switch ($state) {
            case self::PENDING:
                return __('Na fila', 'mailbridge');
            case self::RETRYING:
                return __('Falhou, vai tentar de novo', 'mailbridge');
            case self::SENT:
                return __('Enviado', 'mailbridge');
            case self::FAILED:
                return __('Não foi entregue', 'mailbridge');
            case self::CANCELLED:
                return __('Cancelado', 'mailbridge');
        }

        return $state;
    }

    /** Cor do selo (classes de cor do Tabler/Bootstrap). */
    public static function tone(string $state): string
    {
        switch ($state) {
            case self::SENT:
                return 'success';
            case self::RETRYING:
                return 'warning';
            case self::FAILED:
                return 'danger';
            case self::CANCELLED:
                return 'secondary';
        }

        return 'info';
    }

    /** Qualquer e-mail pode ser enviado de novo (um já enviado gera uma cópia). */
    public static function canResend(string $state): bool
    {
        return in_array($state, [self::PENDING, self::RETRYING, self::SENT, self::FAILED, self::CANCELLED], true);
    }

    /** Só dá para cancelar o que ainda está esperando. */
    public static function canCancel(string $state): bool
    {
        return in_array($state, [self::PENDING, self::RETRYING], true);
    }

    /** Só dá para apagar de vez o que já saiu da fila. */
    public static function canDelete(string $state): bool
    {
        return in_array($state, [self::SENT, self::FAILED, self::CANCELLED], true);
    }

    /**
     * Condição (no formato de critérios do GLPI) de cada situação.
     */
    public static function criteria(string $state): array
    {
        switch ($state) {
            case self::SENT:
                return ['NOT' => ['sent_time' => null]];
            case self::PENDING:
                return ['sent_time' => null, 'is_deleted' => 0, 'sent_try' => 0];
            case self::RETRYING:
                return ['sent_time' => null, 'is_deleted' => 0, 'sent_try' => ['>', 0]];
            case self::FAILED:
                return ['sent_time' => null, 'is_deleted' => 1, 'sent_try' => ['>', 0]];
            case self::CANCELLED:
                return ['sent_time' => null, 'is_deleted' => 1, 'sent_try' => 0];
        }

        return [];
    }

    /**
     * Condição de um filtro da tela (várias situações unidas por OU).
     */
    public static function filterCriteria(string $filter): array
    {
        $states = self::FILTERS[$filter] ?? [];
        if (!$states) {
            return [];
        }
        $or = [];
        foreach ($states as $state) {
            $or[] = self::criteria($state);
        }

        return count($or) === 1 ? $or[0] : ['OR' => $or];
    }

    /**
     * Como a linha fica depois de uma tentativa de envio (espelha o que o
     * GLPI faz em NotificationEventMailing). Serve de documentação e de teste.
     */
    public static function afterAttempt(array $row, bool $delivered, int $max_retries, string $now): array
    {
        if ($delivered) {
            $row['sent_time']  = $now;
            $row['is_deleted'] = 1;

            return $row;
        }

        $tries = (int) ($row['sent_try'] ?? 0);
        if ($max_retries - $tries <= 0) {
            $row['is_deleted'] = 1; // desistiu
        }
        $row['sent_try'] = $tries + 1;

        return $row;
    }

    /**
     * Como a linha fica depois de ser cancelada por alguém.
     */
    public static function afterCancel(array $row): array
    {
        $row['is_deleted'] = 1;

        return $row;
    }
}
