<?php

namespace GlpiPlugin\Mailtech;

use QueuedNotification;

/**
 * Motivo das falhas de envio, por e-mail da fila.
 *
 * O GLPI só escreve o erro no arquivo files/_log/mail-error.log, sem ligá-lo
 * ao e-mail. Quando o GLPI registra uma tentativa que falhou (ele soma 1 em
 * "sent_try"), este hook lê o fim desse arquivo, recupera o erro que acabou
 * de ser escrito e o guarda numa tabela própria, para a tela "Fila e
 * histórico" poder explicar por que cada e-mail não saiu.
 */
final class FailureLog
{
    private const TAIL_BYTES = 8192;
    private const MAX_ERROR  = 1000;

    public static function getTable(): string
    {
        return 'glpi_plugin_mailtech_failures';
    }

    /**
     * Hook item_update de QueuedNotification. Nunca deixa uma exceção sair:
     * um problema aqui não pode atrapalhar o processamento da fila.
     */
    public static function onQueueItemUpdated(QueuedNotification $item): void
    {
        try {
            if (!in_array('sent_try', (array) ($item->updates ?? []), true)) {
                return;
            }
            $old = (int) ($item->oldvalues['sent_try'] ?? 0);
            $new = (int) ($item->fields['sent_try'] ?? 0);
            if ($new <= $old || ($item->fields['mode'] ?? '') !== 'mailing') {
                return;
            }

            /**
             * @var array $CFG_GLPI
             * @var \DBmysql $DB
             */
            global $CFG_GLPI, $DB;

            // Mesma conta que o GLPI faz em NotificationEventMailing::handleFailedSend().
            $retries = (int) ($CFG_GLPI['smtp_max_retries'] ?? 0) - $old;
            // O GLPI escreveu o log com os valores decodificados; aqui eles vêm do banco.
            $error   = self::readLastError(
                Text::decode((string) ($item->fields['recipient'] ?? '')),
                Text::decode((string) ($item->fields['name'] ?? '')),
                $retries
            );

            $DB->insert(self::getTable(), Text::sanitize([
                'queuednotifications_id' => (int) $item->getID(),
                'date_creation'          => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                'error'                  => $error,
                'retries_left'           => max(0, $retries),
                'gave_up'                => $retries <= 0 ? 1 : 0,
            ]));

            self::purgeOrphans();
        } catch (\Throwable $e) {
            try {
                Settings::log('ERRO ao registrar falha de envio: ' . $e->getMessage(), true);
            } catch (\Throwable $ignored) {
                // nada a fazer
            }
        }
    }

    /**
     * Lê o erro que o GLPI acabou de escrever em mail-error.log para este e-mail.
     */
    private static function readLastError(string $recipient, string $subject, int $retries): string
    {
        $file = GLPI_LOG_DIR . '/mail-error.log';
        if (!is_readable($file)) {
            return '';
        }

        // Reconstrói a frase exata que o GLPI escreveu (no idioma desta execução),
        // com um marcador no lugar do erro.
        $marker = "\x01MAILTECH\x01";
        $line   = sprintf(
            __('%1$s. Message: %2$s, Error: %3$s'),
            sprintf(
                __('Warning: an email was undeliverable to %s with %d retries remaining'),
                $recipient,
                $retries
            ),
            $subject,
            $marker
        );
        $parts = explode($marker, $line, 2);
        if (count($parts) !== 2) {
            return '';
        }

        $size   = (int) filesize($file);
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return '';
        }
        if ($size > self::TAIL_BYTES) {
            fseek($handle, -self::TAIL_BYTES, SEEK_END);
        }
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        return (string) self::extract($tail, $parts[0], $parts[1]);
    }

    /**
     * Função pura: acha no trecho final do log o texto do erro que fica entre
     * $before e $after (ou até o fim do registro, se $after for vazio).
     *
     * @return string|null null quando o registro não foi encontrado
     */
    public static function extract(string $tail, string $before, string $after = ''): ?string
    {
        if ($before === '') {
            return null;
        }
        $pos = strrpos($tail, $before);
        if ($pos === false) {
            return null;
        }
        $rest = substr($tail, $pos + strlen($before));

        // Um registro termina onde começa o próximo ("AAAA-MM-DD HH:MM:SS [").
        if (preg_match('/\n\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?: \[|\n)/', $rest, $m, PREG_OFFSET_CAPTURE)) {
            $rest = substr($rest, 0, (int) $m[0][1]);
        }
        if ($after !== '') {
            $end = strpos($rest, $after);
            if ($end !== false) {
                $rest = substr($rest, 0, $end);
            }
        }

        $rest = trim((string) preg_replace('/\s+/', ' ', $rest));

        return substr($rest, 0, self::MAX_ERROR);
    }

    /**
     * Remove registros de e-mails que já saíram da fila (limpeza do GLPI).
     */
    public static function purgeOrphans(): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $table = self::getTable();
        $queue = QueuedNotification::getTable();
        $query = "DELETE `f` FROM `$table` AS `f`"
            . " LEFT JOIN `$queue` AS `q` ON `q`.`id` = `f`.`queuednotifications_id`"
            . " WHERE `q`.`id` IS NULL";
        if (method_exists($DB, 'doQuery')) {
            $DB->doQuery($query);
        } else {
            $DB->query($query);
        }
    }

    /**
     * Apaga o que foi registrado sobre um e-mail (usado quando ele é apagado do histórico).
     */
    public static function forget(int $queue_id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($queue_id > 0) {
            $DB->delete(self::getTable(), ['queuednotifications_id' => $queue_id]);
        }
    }

    /**
     * Falhas dos e-mails informados, da mais recente para a mais antiga
     * (uma consulta só para a página inteira).
     *
     * @param int[] $ids
     * @return array<int,array<int,array{date:string,error:string,retries_left:int,gave_up:bool}>>
     */
    public static function forQueueIds(array $ids): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }

        $out  = [];
        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['queuednotifications_id' => $ids],
            'ORDER' => ['id DESC'],
        ]);
        foreach ($rows as $row) {
            $out[(int) $row['queuednotifications_id']][] = self::format($row);
        }

        return $out;
    }

    /**
     * Falha mais recente registrada (para a Visão geral e o Diagnóstico).
     *
     * @return array{date:string,error:string,retries_left:int,gave_up:bool,queue_id:int}|null
     */
    public static function latest(): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $rows = $DB->request([
            'FROM'  => self::getTable(),
            'ORDER' => ['id DESC'],
            'LIMIT' => 1,
        ]);
        foreach ($rows as $row) {
            return self::format($row) + ['queue_id' => (int) $row['queuednotifications_id']];
        }

        return null;
    }

    private static function format(array $row): array
    {
        return [
            'date'         => (string) $row['date_creation'],
            'error'        => Text::decode((string) $row['error']),
            'retries_left' => (int) $row['retries_left'],
            'gave_up'      => (int) $row['gave_up'] === 1,
        ];
    }
}
