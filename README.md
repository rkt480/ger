# Gerente

Base do CRM operacional para leitura de grupos do WhatsApp, análise por IA e
alertas para gestores.

## Configuração local

1. Configure o MySQL local com o banco `publi_ai_crm`.
2. Preencha as variáveis `GERENTE_*` no ambiente ou ajuste o arquivo local
   `config.php` (que não deve ser versionado).
3. Conecte o número na Pilot Status.
4. Cadastre o webhook apontando para:

   `/gerente/api/pilot-status-webhook.php`

5. No CRM, em Configurações > Pilot Status, informe a API key e marque
   “Aceitar webhook sem senha da Pilot Status” quando o painel da Pilot Status
   não oferecer segredo. Se o provedor passar a oferecer um token, desmarque a
   opção e configure o segredo no CRM.

As chaves de API ficam somente no backend. O frontend recebe apenas dados
necessários para a tela e nunca recebe tokens, segredos ou credenciais.

## Telas

- `/gerente/index.php`: entrada do gestor, com cards por cliente e grupos monitorados;
- `/gerente/manager-group.php?id=ID`: conversa normalizada e alertas de um grupo;
- `/gerente/whatsapp.php`: conversas individuais e grupos do Pilot Status. Os
  grupos aparecem com nome, prévia e histórico em modo somente leitura;
- `/gerente/index.php?view=kanban`: Kanban comercial original, mantido separado do monitoramento.

## Fluxo das mensagens

O webhook valida o evento, identifica mensagens de grupo, salva grupo,
participante e mensagem em tabelas próprias e evita duplicação pelo ID da
mensagem. A análise de IA deve ser executada depois, em uma fila ou ação
autorizada, para que a resposta do webhook permaneça rápida e confiável.

## Estrutura operacional

- `manager_clients`: clientes cadastrados;
- `manager_groups`: grupos vinculados aos clientes;
- `manager_group_participants`: participantes observados;
- `manager_group_messages`: histórico normalizado;
- `manager_ai_analyses`: análises versionadas;
- `manager_alerts`: alertas revisáveis pelo gestor;
- `manager_report_requirements`: regras para relatórios esperados.

As mensagens de grupo ficam separadas dos leads comerciais. Assim, o
remetente de uma mensagem de grupo não é criado como um contato individual no
CRM; contatos individuais continuam seguindo o fluxo comercial original.
