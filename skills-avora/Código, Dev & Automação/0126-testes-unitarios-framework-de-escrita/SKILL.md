# Testes Unitários (Framework de Escrita)

- **ID:** #126
- **Categoria:** Código, Dev & Automação
- **Nível:** Intermediário
- **Tipo:** Template

## Objetivo

Aplicar um framework estruturado para escrever testes unitários eficazes — cobrindo cenários reais, edge cases e comportamentos esperados — para garantir que o código funciona, evitar regressões e permitir refatoração com confiança.

## Quando usar

- Ao implementar testes pela primeira vez no projeto
- Para padronizar como a equipe escreve testes
- Quando a cobertura de testes está baixa ou os testes são frágeis
- Antes de refatorar código crítico

## Como usar

- Copie o prompt abaixo no Claude ou ChatGPT
- Cole a função ou módulo que quer testar
- Receba os testes completos com explicações
- Implemente e rode no seu test runner

## Prompt

```text
Você é um engenheiro de software que escreve testes unitários exemplares. Seus princípios: (1) testes documentam o comportamento — o nome do teste deve dizer o que o código faz, (2) Arrange-Act-Assert — estrutura clara em todo teste, (3) cada teste testa UMA coisa — se falhar, você sabe exatamente o que quebrou, (4) testes devem ser rápidos e independentes — sem dependência de ordem ou estado compartilhado, (5) teste comportamento, não implementação — testes não devem quebrar quando refatora.

Crie os testes unitários para:

**Linguagem:** [ex: TypeScript, Python, Go, Java]
**Framework de teste:** [ex: Jest, Vitest, pytest, Go testing, JUnit]
**Código para testar:** [cole a função/classe/módulo]
**Contexto:** [o que esse código faz no sistema]
**Dependências externas:** [banco de dados, APIs, filesystem — o que precisa mockar]
**Casos especiais conhecidos:** [edge cases que você sabe que existem]

Entregue:

**1. Análise do código** - O que faz, inputs, outputs, efeitos colaterais
**2. Matriz de cenários** - Tabela com cenário, input, output, tipo
**3. Testes completos** - Código organizado por describe/context
**4. Testes de erro** - Input nulo, tipos errados, dependência falhando
**5. Cobertura alcançada** - Linhas e branches cobertos
**6. Dicas de manutenção** - Como manter os testes ao evoluir o código
```

## Exemplo de entrada

Linguagem: TypeScript
Framework: Jest
Código: Função calculateDiscount(price, couponCode, userTier)
- Aplica desconto baseado em cupom + tier do usuário
- Premium = 10% extra, VIP = 20% extra
- Cupom pode ser percentual ou valor fixo
- Preço mínimo final é R$1.00

## Exemplo de saída

describe('calculateDiscount', () => {
  describe('sem cupom', () => {
    it('retorna preço original para usuário regular sem cupom', () => {
      const result = calculateDiscount(100, null, 'regular');
      expect(result).toBe(100);
    });

    it('aplica 10% de desconto para usuário premium', () => {
      const result = calculateDiscount(100, null, 'premium');
      expect(result).toBe(90);
    });

    it('aplica 20% de desconto para usuário VIP', () => {
      const result = calculateDiscount(100, null, 'vip');
      expect(result).toBe(80);
    });
  });

  describe('edge cases', () => {
    it('nunca retorna valor menor que R$1.00', () => {
      const result = calculateDiscount(5, 'SAVE99', 'vip');
      expect(result).toBe(1);
    });

    it('lança erro para preço negativo', () => {
      expect(() => calculateDiscount(-10, null, 'regular'))
        .toThrow('Preço deve ser positivo');
    });
  });
});

