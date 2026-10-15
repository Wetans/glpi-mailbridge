<?php

namespace GlpiPlugin\Mailtech;

/**
 * Monta a lista final de quem recebe um aviso. Classe pura: recebe os
 * candidatos já levantados (técnicos, grupos, solicitantes...) e aplica as
 * regras: sem repetidos, sem quem fez a ação, e-mails fixos, listas de
 * bloqueio e de permissão.
 */
final class Recipients
{
    /**
     * @param array<int,array{email:string,name?:string,users_id?:int,language?:string}> $candidates
     * @param array{exclude_users_id?:int,extra?:string[],block?:string[],allow?:string[]} $options
     * @return array{0: array<string,array{name:string,users_id:int,language:string}>, 1: array<int,array{email:string,reason:string}>}
     *         [destinatários por e-mail em minúsculas, descartados com o motivo]
     */
    public static function build(array $candidates, array $options = []): array
    {
        $out     = [];
        $skipped = [];
        $exclude = (int) ($options['exclude_users_id'] ?? 0);

        foreach ($candidates as $candidate) {
            $email = trim((string) ($candidate['email'] ?? ''));
            $key   = strtolower($email);
            if (!Validation::isEmail($email)) {
                $skipped[] = ['email' => $email, 'reason' => 'invalid'];
                continue;
            }
            $users_id = (int) ($candidate['users_id'] ?? 0);
            if ($exclude > 0 && $users_id === $exclude) {
                $skipped[] = ['email' => $key, 'reason' => 'author'];
                continue;
            }
            if (isset($out[$key])) {
                continue;
            }
            $out[$key] = [
                'name'     => Text::oneLine((string) ($candidate['name'] ?? '')) ?: $email,
                'users_id' => $users_id,
                'language' => (string) ($candidate['language'] ?? ''),
            ];
        }

        // E-mails fixos da regra: sempre recebem.
        foreach ((array) ($options['extra'] ?? []) as $email) {
            $email = trim((string) $email);
            $key   = strtolower($email);
            if (Validation::isEmail($email) && !isset($out[$key])) {
                $out[$key] = ['name' => $email, 'users_id' => 0, 'language' => ''];
            }
        }

        $block = (array) ($options['block'] ?? []);
        $allow = (array) ($options['allow'] ?? []);
        foreach (array_keys($out) as $key) {
            if ($block && self::matches((string) $key, $block)) {
                $skipped[] = ['email' => (string) $key, 'reason' => 'blocked'];
                unset($out[$key]);
            } elseif ($allow && !self::matches((string) $key, $allow)) {
                $skipped[] = ['email' => (string) $key, 'reason' => 'not_allowed'];
                unset($out[$key]);
            }
        }

        return [$out, $skipped];
    }

    /**
     * O e-mail está na lista? Entradas: "pessoa@empresa.com" ou "@empresa.com".
     *
     * @param string[] $list
     */
    public static function matches(string $email, array $list): bool
    {
        $email  = strtolower(trim($email));
        $at     = strrpos($email, '@');
        $domain = $at === false ? '' : substr($email, $at);

        foreach ($list as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry === '') {
                continue;
            }
            if ($entry === $email) {
                return true;
            }
            if ($entry[0] === '@' && $domain !== '') {
                // "@empresa.com" vale também para subdomínios ("@ti.empresa.com").
                if ($domain === $entry || substr($domain, -strlen('.' . substr($entry, 1))) === '.' . substr($entry, 1)) {
                    return true;
                }
            }
        }

        return false;
    }
}
