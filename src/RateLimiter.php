<?php

namespace GlpiPlugin\Mailtech;

/**
 * Limite simples de repetições numa janela de tempo (usado nos testes de
 * conexão e de envio). Classe pura: quem chama guarda a lista de horários.
 */
final class RateLimiter
{
    /**
     * @param int[] $stamps horários (timestamp) das tentativas anteriores
     * @return array{allowed:bool,stamps:int[],retry_after:int} retry_after = segundos até liberar
     */
    public static function hit(array $stamps, int $now, int $max = 6, int $window = 60): array
    {
        $recent = [];
        foreach ($stamps as $stamp) {
            $stamp = (int) $stamp;
            if ($stamp > $now - $window && $stamp <= $now) {
                $recent[] = $stamp;
            }
        }
        sort($recent);

        if (count($recent) >= $max) {
            return [
                'allowed'     => false,
                'stamps'      => $recent,
                'retry_after' => max(1, $recent[0] + $window - $now),
            ];
        }

        $recent[] = $now;

        return ['allowed' => true, 'stamps' => $recent, 'retry_after' => 0];
    }
}
