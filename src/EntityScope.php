<?php

namespace GlpiPlugin\Mailtech;

/**
 * Liga/desliga dos avisos por entidade, com herança: uma entidade sem escolha
 * própria segue a entidade "mãe" mais próxima que tenha uma escolha; se
 * nenhuma tiver, vale "ligado". Classe pura.
 */
final class EntityScope
{
    /**
     * @param array<int,int> $modes entidade => 1 (ligado) ou 0 (desligado)
     * @param int[]          $chain a entidade e suas "mães", da mais próxima para a raiz
     * @return array{enabled:bool,source:int|null} source = entidade de onde veio a escolha (null = padrão)
     */
    public static function resolve(array $modes, array $chain): array
    {
        foreach ($chain as $entities_id) {
            $entities_id = (int) $entities_id;
            if (array_key_exists($entities_id, $modes)) {
                return ['enabled' => (int) $modes[$entities_id] === 1, 'source' => $entities_id];
            }
        }

        return ['enabled' => true, 'source' => null];
    }

    /**
     * Aplica uma escolha ("inherit", "on" ou "off") ao mapa e devolve o novo mapa.
     *
     * @param array<int,int> $modes
     * @return array<int,int>
     */
    public static function apply(array $modes, int $entities_id, string $choice): array
    {
        if ($choice === 'on') {
            $modes[$entities_id] = 1;
        } elseif ($choice === 'off') {
            $modes[$entities_id] = 0;
        } else {
            unset($modes[$entities_id]);
        }
        ksort($modes);

        return $modes;
    }
}
