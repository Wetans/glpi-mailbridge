# MailBridge — Integração e diagnóstico de e-mail para o GLPI

*Email Integration & Diagnostics for GLPI. English version: [README.md](README.md).*

Plugin que reúne numa tela só, em linguagem simples, tudo o que o GLPI faz com e-mail de saída: o servidor de envio, os testes, as regras de quem recebe o quê, os modelos das mensagens, a fila e o diagnóstico.

O MailBridge **não tem servidor nem fila próprios**: os e-mails entram na fila de notificações nativa do GLPI e saem pelo servidor configurado no GLPI.

O MailBridge 1.0 sucede o **Techmail** (versões 1.0.0 e 1.1.0). Se você o tem instalado, veja "Atualizar a partir do Techmail".

## Versões

- Testado com **GLPI 10.0.18** e **PHP 8.3**.
- O `setup.php` aceita GLPI de 10.0.0 até antes da 12.0.0, mas o **GLPI 11 não foi testado**.
- PHP mínimo declarado: 7.4. Só foi executado em PHP 8.3.

## Instalação

1. Copie a pasta `mailbridge` para `plugins/` do GLPI. O nome da pasta precisa ser exatamente `mailbridge`.
2. Em *Configurar > Plugins*: **Instalar** e depois **Ativar**.
3. Saia e entre de novo no GLPI para o menu mostrar *Configurar > MailBridge*.

Uma instalação nova cria três regras de exemplo (uma ligada). Instalar de novo não muda nada.

A rotina automática do GLPI que envia a fila (*queuednotification*) precisa estar rodando; a Visão geral avisa se ela parar. Para envio contínuo, agende o cron do GLPI (exemplo: `* * * * * php /caminho/glpi/front/cron.php`).

## Atualizar a partir do Techmail

O GLPI identifica um plugin pelo nome da pasta. Por isso, para o GLPI, o MailBridge (`mailbridge`) é outro plugin, e a instalação **copia** os dados do Techmail: nada do Techmail é movido nem apagado.

1. Faça cópia do banco de dados do GLPI e da pasta `plugins/`.
2. Copie a pasta `mailbridge` para `plugins/`. **Deixe a pasta `techmail` onde está.**
3. Em *Configurar > Plugins*, clique em **Instalar** no MailBridge. A instalação:
   - copia regras, traduções dos modelos, motivos de falha, ajustes, permissões por perfil e o histórico das regras;
   - **desativa** o Techmail (não o desinstala), para nenhum aviso sair duas vezes.
4. Clique em **Ativar** no MailBridge, saia e entre de novo.
5. Abra *Configurar > MailBridge > Diagnóstico*: o último quadro diz o que foi trazido. Confira regras e modelos e envie um e-mail de teste.
6. Quando estiver tudo certo, desinstale o Techmail em *Configurar > Plugins* e apague a pasta `techmail`.

Vale saber:

- Funciona a partir do Techmail 1.0.0 e do 1.1.0.
- A cópia acontece uma vez. O que você mudar depois no MailBridge não volta para o Techmail.
- Um ajuste não é copiado de propósito: "apagar os dados ao desinstalar". No MailBridge ele sempre começa desligado.
- O servidor de envio e a fila são do GLPI e não são tocados.
- Os endereços `/plugins/techmail/...` deixam de existir quando a pasta antiga for apagada; o novo é `/plugins/mailbridge/front/index.php`.
- Arquivos de configuração exportados pelo Techmail continuam podendo ser importados.
- Se o Techmail for ativado de novo com o MailBridge ativo, o MailBridge para os próprios avisos e a Visão geral avisa.

**Para voltar atrás:** desative o MailBridge e ative o Techmail. Os dados do Techmail não foram alterados.

## Guia rápido

Tudo fica em **Configurar > MailBridge**.

1. **Visão geral**: mostra se está *Ativo*, *Inativo* ou *Precisa de atenção*, o que falta e se há falhas.
2. **Envio**: informe o servidor, a porta, a segurança, usuário e senha, e o e-mail do remetente. Clique em **Salvar**. Os testes usam sempre o que está salvo.
3. Ainda em **Envio**, clique em **Testar conexão** e em **Enviar e-mail de teste**. O resultado aparece passo a passo; se falhar, a tela diz o que tentar.
4. **Eventos e destinatários**: ligue ou desligue cada regra. O resumo de cada linha diz quando ela dispara e quem recebe.
5. **Modelos**: edite o assunto e a mensagem de cada regra, por idioma. Clique numa variável para inseri-la, veja a prévia, use "Restaurar padrão" e "Enviar teste deste modelo".
6. **Fila e histórico**: o que está esperando, o que saiu e o que falhou, com o motivo. "Reenviar" tenta de novo.
7. **Diagnóstico**: verificações com a ação sugerida, quem alterou o quê, exportar e importar a configuração.

Importante: **quem não tem e-mail no cadastro não recebe avisos**.

## Formas de envio

| Forma | Onde se configura | Situação |
|---|---|---|
| SMTP sem segurança | MailBridge > Envio | Testado (caixa de testes local) |
| SMTP + STARTTLS, com ou sem usuário e senha | MailBridge > Envio | Testado (veja abaixo) |
| SMTP + SSL/TLS | MailBridge > Envio | Existe; um envio com sucesso não foi testado |
| Função de e-mail do servidor (PHP) | MailBridge > Envio | Existe; não testado |
| Login pelo provedor (OAuth: Google, Microsoft) | Tela de e-mail do próprio GLPI; o MailBridge mostra a situação e o atalho | Não testado |

## Gmail e Microsoft 365

Nada aqui é garantia para a sua conta: provedores e organizações mudam as regras.

**Gmail**: servidor `smtp.gmail.com`, porta 587, STARTTLS, usuário = e-mail completo, remetente igual à conta. Exige uma **senha de app** do Google (com a verificação em duas etapas ligada); a senha normal é recusada. Verificado em 13/10/2026 com o mesmo código ainda com o nome antigo: dois e-mails de teste aceitos pelo servidor do Gmail. Não foi repetido depois da troca de nome.

**Microsoft 365 (conta de organização)**: servidor `smtp.office365.com`, porta 587, STARTTLS (a porta 465 não é oferecida). Verificado a partir do servidor de testes: nome, conexão, segurança e certificado. **Login e entrega não foram testados.** Login com usuário e senha só funciona se a organização liberar o "SMTP autenticado" para a caixa e não exigir os padrões de segurança; caso contrário, use o login pelo provedor na tela de e-mail do GLPI. Enviar com um endereço diferente da conta exige a permissão "Enviar como".

**Outlook.com / Hotmail (conta pessoal)**: não testado; há relatos de que o login com usuário e senha não é mais aceito.

## Solução de problemas

| O que aparece | O que quer dizer | O que fazer |
|---|---|---|
| "O Gmail exige login: falta informar o usuário e a senha." (ou Microsoft 365 / Outlook.com) | Esse provedor sempre pede uma conta e nenhuma está salva | Informe o endereço completo da conta como usuário, a senha e o mesmo endereço como remetente; **Salvar**; teste |
| "O remetente … não é um endereço de e-mail de verdade." | Com Gmail ou Microsoft, o remetente é um endereço provisório como `admsys@localhost` | Informe o endereço da conta em "E-mail do remetente" |
| "A configuração de envio mudou depois do último teste com sucesso." | Servidor, conta, senha ou remetente mudaram depois do último teste bom | Envie um teste novo |
| "Falta guardar a senha do servidor de envio." | Há usuário salvo, mas nenhuma senha | Digite a senha, clique em **Salvar** e teste |
| "Você alterou a configuração e ainda não salvou." | O teste usa só o que está salvo | Salve e teste de novo |
| "O servidor de envio não aceitou o usuário e a senha." | O provedor recusou o login | Abra "Detalhe técnico": ali está o motivo dado pelo provedor |
| O teste diz enviado e nada chega | O servidor aceitou a mensagem | Veja o spam; confira se o servidor não é de testes |
| "O plugin antigo (Techmail) está ativo junto com o MailBridge." | Os dois plugins ativos | Desative o Techmail |

## Registros

- *Fila e histórico* mostra o motivo de cada falha.
- `files/_log/mailbridge.log`: por que cada aviso foi gerado ou ignorado (ligue "Registrar o motivo de cada aviso" em Envio).
- Do GLPI: `files/_log/mail-error.log`, `mail.log` e `php-errors.log`.

Nenhum registro, exportação, tela ou mensagem de erro contém senhas.

## Segurança

- A senha do servidor é guardada pelo GLPI, criptografada. Nunca volta para a tela, não vai para a exportação e não aparece em logs nem no histórico. Para manter a senha atual, deixe o campo em branco.
- Os testes só usam o servidor salvo, pedem permissão própria e têm limite de 6 por minuto por pessoa.
- Os modelos são limpos ao salvar e ao enviar; só as variáveis da lista são trocadas.
- Permissões: quem pode alterar a configuração geral do GLPI tem acesso total. Para os demais perfis, use a aba **MailBridge** do cadastro do perfil (*Ver*, *Gerenciar*, *Testar envio*, *Operar a fila*).

## Cópia e volta

- Antes de instalar ou atualizar: copie o banco e a pasta `plugins/`.
- Cópia dos ajustes: *Diagnóstico > Exportar* (arquivo sem senhas).
- Desinstalar **não apaga os dados**. Para apagar tudo, ligue antes "Apagar os dados do MailBridge ao desinstalar o plugin" em *Diagnóstico*. Os e-mails que já estão na fila do GLPI nunca são tocados.

## Problemas conhecidos

- GLPI 11 não testado.
- O login pelo provedor (OAuth) é configurado na tela de e-mail do GLPI.
- Se você trocar o servidor e deixar a senha em branco, a senha antiga continua guardada e seria oferecida ao servidor novo. Digite a senha nova ou marque "Apagar a senha guardada".
- A Visão geral avalia a configuração; ela não sabe se o provedor vai aceitar o login. Use os botões de teste. Ela reconhece Gmail e Microsoft só pelo nome do servidor (`smtp.gmail.com`, `smtp.googlemail.com`, `smtp.office365.com`, `smtp-mail.outlook.com`); para outros servidores login e remetente público não são exigidos, então caixas de teste locais continuam funcionando.
- Depois de instalar, é preciso sair e entrar de novo para ver o menu e as permissões novas.
- Não incluído: limite de envios por período, resumo agrupado, predefinições de provedores, tratamento de e-mails devolvidos.

## Histórico de versões

Veja [CHANGELOG.md](CHANGELOG.md). Em resumo: o MailBridge 1.0 é a primeira versão com o nome novo e deixa a Visão geral e os testes mais rigorosos com login, remetente e testes antigos. Antes disso o plugin se chamava Techmail: o Techmail 1.1.0 trouxe a tela única e o Techmail 1.0.0 foi a primeira versão.
