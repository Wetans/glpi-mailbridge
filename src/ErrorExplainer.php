<?php

namespace GlpiPlugin\Mailbridge;

/**
 * Traduz o erro técnico de envio (em inglês, cheio de códigos) para uma frase
 * simples e uma lista do que tentar. Classe pura.
 */
final class ErrorExplainer
{
    /**
     * Ordem importa: o primeiro grupo que casar vence.
     *
     * @var array<string,string[]>
     */
    private const PATTERNS = [
        'no_password' => ['missing-password'],
        // Antes de 'auth' e 'need_tls': "Client not authenticated" e "530 5.7.0 Authentication Required" querem dizer que faltou o login, não que a senha está errada.
        'no_login'  => ['missing-login', 'client not authenticated', 'authentication required', '5.7.57'],
        'mailfn'    => ['could not instantiate mail function'],
        'dns'       => ['getaddrinfo', 'name or service not known', 'nodename nor servname', 'php_network_getaddresses', 'could not resolve', 'no such host', 'name resolution'],
        'refused'   => ['connection refused', 'smtp code: 111', '(111)', 'actively refused'],
        'timeout'   => ['timed out', 'timeout', 'smtp code: 110', '(110)'],
        'cert'      => ['certificate', 'peer verification', 'verify failed', 'self signed', 'self-signed', 'cn=`', 'did not match expected'],
        'need_tls'  => ['must issue a starttls', '530 5.7.0', 'encryption required', 'tls required'],
        'tls'       => ['starttls', 'ssl operation failed', 'ssl3_get_record', 'wrong version number', 'crypto', 'ssl routines', 'unable to connect to ssl', 'unable to connect to tls'],
        'relay'     => ['relay access denied', 'relaying denied', 'relay not permitted', '554 5.7.1', 'not permitted to relay'],
        'auth'      => ['could not authenticate', 'authentication failed', 'authentication unsuccessful', 'username and password not accepted', 'invalid login', 'incorrect authentication', 'bad credentials', ' 535', ' 534', '535 ', '534 ', '5.7.8', '5.7.9', 'auth'],
        'sender'    => ['sender address rejected', 'sender rejected', 'not allowed to send as', 'sendasdenied', 'mail from command failed', 'from address', ' 553', '553 ', 'sender verify failed', 'invalid address: (from)', 'not owned by user'],
        'recipient' => ['recipient address rejected', 'user unknown', 'no such user', 'mailbox unavailable', 'mailbox not found', '5.1.1', 'rcpt to command failed', 'recipients failed', 'invalid address: (to)', 'does not exist'],
        'size'      => ['message size exceeds', 'message too large', ' 552', '552 '],
        'quota'     => ['quota', 'too many', 'rate limit', 'daily user sending limit', ' 421', '421 ', ' 450', ' 451', ' 452'],
        'connect'   => ['could not connect to smtp host', 'failed to connect to server', 'smtp connect() failed', 'connection failed', 'unable to connect'],
    ];

    /**
     * @return array{kind:string,title:string,advice:string[]}
     */
    public static function explain(string $raw): array
    {
        $kind = self::kind($raw);

        return ['kind' => $kind] + self::texts($kind);
    }

    public static function kind(string $raw): string
    {
        $haystack = ' ' . strtolower($raw) . ' ';
        if (trim($haystack) === '') {
            return 'unknown';
        }
        foreach (self::PATTERNS as $kind => $needles) {
            foreach ($needles as $needle) {
                if (strpos($haystack, $needle) !== false) {
                    return $kind;
                }
            }
        }

        return 'unknown';
    }

    /**
     * @return array{title:string,advice:string[]}
     */
    private static function texts(string $kind): array
    {
        switch ($kind) {
            case 'no_password':
                return [
                    'title'  => __('Falta guardar a senha do servidor de envio.', 'mailbridge'),
                    'advice' => [
                        __('Digite a senha no campo "Senha" e clique em Salvar. Os testes e os envios só usam o que está salvo.', 'mailbridge'),
                        __('Gmail e Outlook pedem uma "senha de app" ou login pelo provedor (OAuth); a senha normal da conta não funciona.', 'mailbridge'),
                        __('Se o servidor não pede login, apague o usuário e salve.', 'mailbridge'),
                    ],
                ];
            case 'no_login':
                return [
                    'title'  => __('O servidor de envio exige login e nenhum usuário foi informado.', 'mailbridge'),
                    'advice' => [
                        __('Em Envio, informe como usuário o endereço completo da conta e a senha dela, clique em Salvar e teste de novo.', 'mailbridge'),
                        __('No Gmail a senha é uma "senha de app" criada na Conta Google (com a verificação em duas etapas ligada); a senha normal da conta não funciona.', 'mailbridge'),
                        __('Use como remetente o mesmo endereço da conta.', 'mailbridge'),
                    ],
                ];
            case 'dns':
                return [
                    'title'  => __('O servidor de envio não foi encontrado.', 'mailbridge'),
                    'advice' => [
                        __('Confira se o nome do servidor foi digitado certo (exemplo: smtp.suaempresa.com.br).', 'mailbridge'),
                        __('Veja se o servidor do GLPI tem acesso à internet ou à rede onde fica o servidor de e-mail.', 'mailbridge'),
                    ],
                ];
            case 'refused':
                return [
                    'title'  => __('O servidor de envio recusou a conexão nessa porta.', 'mailbridge'),
                    'advice' => [
                        __('Confira a porta: as mais comuns são 587 (STARTTLS), 465 (SSL/TLS) e 25 (sem segurança).', 'mailbridge'),
                        __('Peça a quem cuida da rede para liberar a saída do GLPI até esse servidor e porta.', 'mailbridge'),
                    ],
                ];
            case 'timeout':
                return [
                    'title'  => __('O servidor de envio não respondeu a tempo.', 'mailbridge'),
                    'advice' => [
                        __('Confira o servidor e a porta.', 'mailbridge'),
                        __('Um firewall pode estar bloqueando a saída: peça para liberar a porta usada.', 'mailbridge'),
                        __('Tente de novo em alguns minutos: o servidor de e-mail pode estar fora do ar.', 'mailbridge'),
                    ],
                ];
            case 'cert':
                return [
                    'title'  => __('O certificado de segurança do servidor de envio não foi aceito.', 'mailbridge'),
                    'advice' => [
                        __('Use o mesmo nome de servidor que aparece no certificado (o que o provedor informa).', 'mailbridge'),
                        __('Em servidor interno com certificado próprio, desligue "Conferir o certificado de segurança" nas opções avançadas.', 'mailbridge'),
                    ],
                ];
            case 'need_tls':
                return [
                    'title'  => __('O servidor de envio exige conexão segura.', 'mailbridge'),
                    'advice' => [
                        __('Em "Segurança da conexão", escolha STARTTLS (normalmente na porta 587) ou SSL/TLS (porta 465).', 'mailbridge'),
                    ],
                ];
            case 'tls':
                return [
                    'title'  => __('Não foi possível iniciar a conexão segura com o servidor de envio.', 'mailbridge'),
                    'advice' => [
                        __('A segurança escolhida precisa combinar com a porta: STARTTLS com 587, SSL/TLS com 465, nenhuma com 25.', 'mailbridge'),
                        __('Se o servidor não oferece segurança, escolha "Nenhuma".', 'mailbridge'),
                    ],
                ];
            case 'auth':
                return [
                    'title'  => __('O servidor de envio não aceitou o usuário e a senha.', 'mailbridge'),
                    'advice' => [
                        __('Digite de novo o usuário (normalmente o e-mail completo) e a senha.', 'mailbridge'),
                        __('Gmail e Outlook pedem uma "senha de app" ou login pelo provedor (OAuth); a senha normal da conta não funciona.', 'mailbridge'),
                        __('Se o servidor não pede login, deixe usuário e senha em branco.', 'mailbridge'),
                    ],
                ];
            case 'relay':
                return [
                    'title'  => __('O servidor de envio não permite que o GLPI envie por ele.', 'mailbridge'),
                    'advice' => [
                        __('Informe usuário e senha, se o servidor exigir login.', 'mailbridge'),
                        __('Ou peça a quem cuida do e-mail para autorizar o servidor do GLPI.', 'mailbridge'),
                    ],
                ];
            case 'sender':
                return [
                    'title'  => __('O servidor de envio recusou o endereço do remetente.', 'mailbridge'),
                    'advice' => [
                        __('Use como remetente um endereço da mesma conta ou do mesmo domínio do usuário de login.', 'mailbridge'),
                        __('Confira se o e-mail do remetente foi digitado certo.', 'mailbridge'),
                    ],
                ];
            case 'recipient':
                return [
                    'title'  => __('O servidor de envio recusou o endereço de quem ia receber.', 'mailbridge'),
                    'advice' => [
                        __('Confira o e-mail no cadastro da pessoa (pode ter erro de digitação ou a caixa não existe mais).', 'mailbridge'),
                        __('Depois de corrigir o cadastro, o próximo aviso sai certo; este pode ser cancelado.', 'mailbridge'),
                    ],
                ];
            case 'size':
                return [
                    'title'  => __('O e-mail é grande demais para o servidor de envio.', 'mailbridge'),
                    'advice' => [
                        __('Desligue "Anexar os documentos do chamado" ou reduza o texto do modelo.', 'mailbridge'),
                    ],
                ];
            case 'quota':
                return [
                    'title'  => __('O servidor de envio pediu para esperar (limite de envios ou ocupado).', 'mailbridge'),
                    'advice' => [
                        __('Espere alguns minutos: o GLPI tenta de novo sozinho.', 'mailbridge'),
                        __('Se acontecer sempre, a conta pode ter atingido o limite diário: use um servidor de e-mail da empresa.', 'mailbridge'),
                    ],
                ];
            case 'mailfn':
                return [
                    'title'  => __('A função de e-mail do servidor do GLPI não funcionou.', 'mailbridge'),
                    'advice' => [
                        __('Em "Como enviar", escolha "Servidor de e-mail (SMTP)" e informe os dados do seu provedor.', 'mailbridge'),
                    ],
                ];
            case 'connect':
                return [
                    'title'  => __('Não foi possível conectar ao servidor de envio.', 'mailbridge'),
                    'advice' => [
                        __('Confira o servidor, a porta e a segurança da conexão.', 'mailbridge'),
                        __('Use "Testar conexão" na tela Envio para ver em que passo parou.', 'mailbridge'),
                    ],
                ];
        }

        return [
            'title'  => __('O servidor de envio devolveu um erro.', 'mailbridge'),
            'advice' => [
                __('Use "Testar conexão" na tela Envio para ver em que passo parou.', 'mailbridge'),
                __('Mostre o detalhe técnico abaixo para quem cuida do servidor de e-mail.', 'mailbridge'),
            ],
        ];
    }
}
