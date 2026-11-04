# Formulário de Pesquisa NPS/CSAT

- **ID:** #113
- **Categoria:** Produto, E-commerce & SaaS
- **Nível:** Iniciante
- **Tipo:** Template

## Objetivo

Criar pesquisas de satisfação do cliente (NPS, CSAT e CES) com perguntas estratégicas que geram dados acionáveis — não apenas um número, mas insights sobre o que melhorar, o que manter e o que está gerando promotores ou detratores.

## Quando usar

- Para medir satisfação de clientes periodicamente
- Após interações específicas (compra, suporte, onboarding)
- Para identificar riscos de churn antes que aconteça
- Na criação de programas de voz do cliente (VoC)

## Como usar

- Copie o prompt abaixo no Claude ou ChatGPT
- Descreva seu produto e os momentos que quer medir
- Receba os formulários completos com perguntas e automações
- Implemente na ferramenta de pesquisa e analise os resultados

## Prompt

```text
Você é um especialista em pesquisa de satisfação que sabe que: (1) a pergunta de NPS sozinha não diz quase nada — o valor está nas perguntas de follow-up, (2) pesquisas longas demais ninguém responde (máx. 3-5 min), (3) o timing do envio afeta mais a taxa de resposta que o texto.

Crie as pesquisas de satisfação para:

**Produto/Serviço:** [descreva]
**Tipo de cliente:** [B2B, B2C, misto]
**Momentos para medir:** [pós-compra, pós-suporte, trimestral, onboarding]
**Ferramenta de pesquisa:** [Typeform, Google Forms, Hotjar, in-app]

Entregue: Pesquisa NPS (com follow-ups para promotores, neutros e detratores), Pesquisa CSAT, Pesquisa CES, Automações baseadas nas respostas, Template de análise, Dicas de taxa de resposta.
```

## Exemplo de entrada

Produto: Plataforma SaaS de e-commerce
Clientes: B2B (lojistas)
Momentos: Pós-onboarding (30 dias), trimestral, pós-suporte
Ferramenta: Typeform

## Exemplo de saída

NPS Relacional:
Pergunta 1: "De 0 a 10, qual a probabilidade de você recomendar a [plataforma] para outro lojista?"

Follow-up promotor (9-10): "Que bom! O que você mais gosta na [plataforma]?"
Follow-up detrator (0-6): "Lamentamos. O que poderíamos fazer diferente?"

Automação:
- Score 9-10: Email 24h depois pedindo review no Google + desconto de indicação
- Score 0-6: Alerta imediato no Slack para Head de CS + ligação em 24h

