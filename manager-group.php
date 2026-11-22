<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/manager-response-monitor.php';

crm_require_sales_manager();

$groupId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if (!is_int($groupId)) {
    http_response_code(404);
    exit('Grupo não encontrado.');
}

$pdo = crm_db();
$group = crm_manager_monitor_read_group($pdo, $groupId);

if ($group === null) {
    http_response_code(404);
    exit('Grupo não encontrado.');
}

crm_manager_response_schema($pdo);
$dailyQuery = $pdo->prepare('SELECT summary, summary_date, pending_count FROM manager_group_daily_summaries WHERE group_id = :group_id ORDER BY summary_date DESC LIMIT 1');
$dailyQuery->execute(['group_id' => $groupId]);
$dailySummary = $dailyQuery->fetch(PDO::FETCH_ASSOC);
$messages = crm_manager_monitor_read_group_messages($pdo, $groupId);
$alerts = crm_manager_monitor_read_group_alerts($pdo, $groupId);
$latestAnalysis = crm_manager_monitor_read_latest_group_analysis($pdo, $groupId);
$openAlerts = array_values(array_filter(
    $alerts,
    static fn(array $alert): bool => in_array((string) ($alert['status'] ?? ''), ['open', 'acknowledged', 'in_progress'], true)
));
$criticalAlerts = array_values(array_filter(
    $openAlerts,
    static fn(array $alert): bool => (string) ($alert['severity'] ?? '') === 'critical'
));
$priorityAlerts = array_values(array_filter(
    $openAlerts,
    static fn(array $alert): bool => in_array((string) ($alert['severity'] ?? ''), ['high', 'critical'], true)
));
$hasOpenAlerts = $openAlerts !== [];

$formatDate = static function (mixed $value, bool $withYear = false): string {
    $value = trim((string) $value);

    if ($value === '') {
        return 'Sem data';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return 'Sem data';
    }

    return date($withYear ? 'd/m/Y · H:i' : 'd/m · H:i', $timestamp);
};
$messageLabel = static function (array $message): string {
    $body = trim((string) ($message['body'] ?? ''));

    if ($body !== '') {
        return $body;
    }

    $type = trim((string) ($message['message_type'] ?? ''));

    return $type !== '' ? 'Mensagem ' . $type : 'Mensagem sem texto';
};
$severityLabel = static function (string $severity): string {
    return [
        'critical' => 'Crítico',
        'high' => 'Alto',
        'medium' => 'Médio',
        'low' => 'Baixo',
    ][$severity] ?? ucfirst($severity !== '' ? $severity : 'Médio');
};
$statusLabel = static function (string $status): string {
    return [
        'open' => 'Aberto',
        'acknowledged' => 'Reconhecido',
        'in_progress' => 'Em andamento',
        'resolved' => 'Resolvido',
    ][$status] ?? ucfirst($status !== '' ? $status : 'Aberto');
};
$clientName = trim((string) ($group['client_name'] ?? ''));
$managerName = trim((string) ($group['manager_name'] ?? ''));
$groupName = trim((string) ($group['name'] ?? 'Grupo sem nome'));
$groupName = $groupName !== '' ? $groupName : 'Grupo sem nome';
$latestAnalysisStatus = (string) ($latestAnalysis['status'] ?? '');
$latestAnalysisSeverity = (string) ($latestAnalysis['severity'] ?? '');
$pendingAnalysisCount = count(array_filter(
    $messages,
    static fn(array $message): bool => in_array((string) ($message['analysis_status'] ?? ''), ['pending', 'processing'], true)
));
$groupState = ($latestAnalysisStatus === 'failed' || $pendingAnalysisCount > 0)
    ? 'attention'
    : ($hasOpenAlerts ? (count($priorityAlerts) > 0 ? 'critical' : 'attention') : 'normal');
$analysisCategories = is_array($latestAnalysis['categories_decoded'] ?? null) ? $latestAnalysis['categories_decoded'] : [];
$analysisEvidence = is_array($latestAnalysis['evidence_decoded'] ?? null) ? $latestAnalysis['evidence_decoded'] : [];
$analysisResult = is_array($latestAnalysis['result_json_decoded'] ?? null) ? $latestAnalysis['result_json_decoded'] : [];
$analysisAction = trim((string) ($analysisResult['recommended_action'] ?? ''));
$analysisStatusLabel = $pendingAnalysisCount > 0
    ? 'Aguardando análise'
    : ($latestAnalysisStatus === 'completed'
        ? ($latestAnalysisSeverity !== '' && $latestAnalysisSeverity !== 'none'
            ? ($hasOpenAlerts ? 'Atenção identificada' : 'Resolvido pelo gestor')
            : 'Tudo bem')
        : ($latestAnalysisStatus === 'failed' ? 'Análise com erro' : 'Aguardando análise'));
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?= htmlspecialchars(crm_csrf_token()) ?>" />
    <meta name="theme-color" content="#070a10" />
    <title><?= htmlspecialchars($groupName) ?> | Monitoramento</title>
    <script src="./assets/theme.js?v=20261030-clean-layout-v1"></script>
    <link rel="stylesheet" href="./assets/crm.css?v=20261122-sidebar-unified-v1" />
  </head>
  <body class="settings-page manager-monitor-page manager-group-page">
    <div class="app-shell">
      <?php require __DIR__ . '/partials/sidebar.php'; ?>

      <div class="workspace">
        <header class="topbar">
          <nav class="topbar-nav" aria-label="Áreas do CRM">
            <a class="active" href="manager-dashboard.php">Monitoramento</a>
            <a href="whatsapp.php">WhatsApp</a>
            <a href="index.php?view=kanban">Kanban comercial</a>
            <a href="dashboard.php">Indicadores</a>
            <?php if (crm_current_user_is_admin()): ?>
              <a href="settings.php">Configurações</a>
            <?php endif; ?>
          </nav>
        </header>

        <header class="app-header manager-page-header">
          <div>
            <a class="manager-back-link" href="manager-dashboard.php">← Voltar para clientes</a>
            <p class="eyebrow">Conversa monitorada</p>
            <h1><?= htmlspecialchars($groupName) ?></h1>
            <p class="page-intro"><?= $clientName !== '' ? 'Cliente: ' . htmlspecialchars($clientName) . ($managerName !== '' ? ' · Gestor: ' . htmlspecialchars($managerName) : '') : 'Este grupo ainda não foi vinculado a um cliente.' ?></p>
          </div>
          <span class="manager-detail-state is-<?= htmlspecialchars($groupState) ?>"><i></i><?= $groupState === 'critical' ? 'Atenção crítica' : ($groupState === 'attention' ? 'Requer atenção' : 'Sem alertas abertos') ?></span>
        </header>

        <main class="dashboard manager-dashboard-layout">
          <section class="manager-detail-summary" aria-label="Resumo do grupo">
            <div><span>Mensagens armazenadas</span><strong><?= count($messages) ?></strong></div>
            <div><span>Alertas abertos</span><strong><?= count($openAlerts) ?></strong></div>
            <div><span>Última atividade</span><strong><?= htmlspecialchars($formatDate($group['last_message_at'] ?? '', true)) ?></strong></div>
            <div><span>Análise</span><strong><?= htmlspecialchars($analysisStatusLabel) ?></strong></div>
          </section>

          <section class="manager-ai-summary-card is-<?= htmlspecialchars($groupState) ?>" aria-labelledby="manager-ai-summary-title">
            <header class="manager-detail-card-header">
              <div><p class="eyebrow">Leitura automática</p><h2 id="manager-ai-summary-title">Resumo da IA</h2></div>
              <div class="manager-ai-summary-actions">
                <span class="manager-ai-state"><i></i><?= htmlspecialchars($analysisStatusLabel) ?></span>
                <?php if ($pendingAnalysisCount > 0 || $latestAnalysisStatus === 'failed' || $latestAnalysis === null): ?>
                  <button type="button" class="manager-ai-trigger" data-manager-analyze-group data-group-id="<?= (int) $groupId ?>">Analisar agora</button>
                <?php endif; ?>
              </div>
            </header>
            <?php if ($latestAnalysis === null): ?>
              <div class="manager-ai-summary-empty"><strong>As mensagens deste grupo ainda aguardam leitura.</strong><span>A análise seguirá o prompt cadastrado em Configurações.</span></div>
            <?php elseif ($latestAnalysisStatus === 'failed'): ?>
              <div class="manager-ai-summary-empty is-error"><strong>Não foi possível concluir a última leitura.</strong><span><?= htmlspecialchars((string) ($latestAnalysis['error_message'] ?? 'Tente novamente quando a integração estiver disponível.')) ?></span></div>
            <?php else: ?>
              <div class="manager-ai-summary-body">
                <p><?= nl2br(htmlspecialchars((string) ($latestAnalysis['summary'] ?? 'Sem resumo disponível.'))) ?></p>
                <?php if ($analysisCategories !== []): ?><div class="manager-ai-tags"><?php foreach ($analysisCategories as $category): ?><span><?= htmlspecialchars((string) $category) ?></span><?php endforeach; ?></div><?php endif; ?>
                <?php if ($analysisAction !== ''): ?><div class="manager-ai-action"><strong>Próximo passo</strong><span><?= nl2br(htmlspecialchars($analysisAction)) ?></span></div><?php endif; ?>
                <?php if ($analysisEvidence !== []): ?><details class="manager-ai-evidence"><summary>Ver evidências consideradas</summary><ul><?php foreach ($analysisEvidence as $evidence): ?><li><?= nl2br(htmlspecialchars((string) $evidence)) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
              </div>
            <?php endif; ?>
          </section>

          <div class="manager-detail-grid">
            <section class="manager-conversation-card" aria-labelledby="conversation-title">
              <?php if (is_array($dailySummary)): ?><p><strong>Resumo do dia <?= htmlspecialchars((string) $dailySummary['summary_date']) ?>:</strong> <?= htmlspecialchars((string) $dailySummary['summary']) ?></p><?php endif; ?>
              <header class="manager-detail-card-header">
                <div><p class="eyebrow">WhatsApp</p><h2 id="conversation-title">Conversas recentes</h2></div>
                <span><?= count($messages) ?> registro<?= count($messages) === 1 ? '' : 's' ?></span>
              </header>
              <?php if ($messages === []): ?>
                <div class="manager-detail-empty"><span aria-hidden="true">⌁</span><h3>A conversa ainda está vazia</h3><p>As mensagens recebidas pelo webhook do Pilot Status aparecerão aqui depois da normalização.</p></div>
              <?php else: ?>
                <div class="manager-conversation-list">
                  <?php foreach ($messages as $message): ?>
                    <?php
                      $isOutgoing = (int) ($message['from_me'] ?? 0) === 1;
                      $sender = trim((string) ($message['sender_name'] ?? ''));
                      $sender = $sender !== '' ? $sender : (trim((string) ($message['sender_phone'] ?? '')) ?: ($isOutgoing ? 'Sua operação' : 'Participante'));
                      $sentAt = $message['sent_at'] ?? $message['received_at'] ?? '';
                    ?>
                    <article class="manager-message is-<?= $isOutgoing ? 'outgoing' : 'incoming' ?>">
                      <header><strong><?= htmlspecialchars($sender) ?></strong><time datetime="<?= htmlspecialchars((string) $sentAt) ?>"><?= htmlspecialchars($formatDate($sentAt, true)) ?></time></header>
                      <p><?= nl2br(htmlspecialchars($messageLabel($message))) ?></p>
                      <?php if (trim((string) ($message['media_metadata'] ?? '')) !== ''): ?>
                        <small class="manager-message-media">Anexo recebido · <?= htmlspecialchars((string) ($message['message_type'] ?? 'mídia')) ?></small>
                      <?php endif; ?>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>

            <aside class="manager-alerts-card" aria-labelledby="alerts-title">
              <header class="manager-detail-card-header">
                <div><p class="eyebrow">Análise operacional</p><h2 id="alerts-title">Alertas do grupo</h2></div>
                <span class="manager-alert-count is-<?= count($priorityAlerts) > 0 ? 'critical' : 'normal' ?>"><?= count($openAlerts) ?></span>
              </header>
              <?php if ($alerts === []): ?>
                <div class="manager-detail-empty manager-alert-empty"><span aria-hidden="true">✦</span><h3>Nenhum alerta gerado</h3><p>Quando a IA identificar reclamação, custo alto ou relatório ausente, o alerta ficará visível neste painel.</p></div>
              <?php else: ?>
                <div class="manager-alert-list">
                  <?php foreach ($alerts as $alert): ?>
                    <article class="manager-alert-item is-<?= htmlspecialchars((string) ($alert['severity'] ?? 'medium')) ?> is-<?= htmlspecialchars((string) ($alert['status'] ?? 'open')) ?>">
                      <header><span><?= htmlspecialchars($severityLabel((string) ($alert['severity'] ?? 'medium'))) ?></span><time><?= htmlspecialchars($formatDate($alert['created_at'] ?? '', true)) ?></time></header>
                      <h3><?= htmlspecialchars((string) ($alert['title'] ?? 'Alerta operacional')) ?></h3>
                      <p><?= nl2br(htmlspecialchars((string) ($alert['description'] ?? ''))) ?></p>
                      <?php if (trim((string) ($alert['evidence'] ?? '')) !== ''): ?><blockquote><?= nl2br(htmlspecialchars((string) $alert['evidence'])) ?></blockquote><?php endif; ?>
                      <div class="manager-alert-item-footer">
                        <small><?= htmlspecialchars($statusLabel((string) ($alert['status'] ?? 'open'))) ?></small>
                        <?php if (in_array((string) ($alert['status'] ?? ''), ['open', 'acknowledged', 'in_progress'], true)): ?>
                          <button type="button" class="manager-alert-resolve-button" data-manager-resolve-alert data-alert-id="<?= (int) ($alert['id'] ?? 0) ?>">Marcar como resolvido</button>
                        <?php elseif ((string) ($alert['resolution_note'] ?? '') !== ''): ?>
                          <span class="manager-alert-resolution-note">✓ <?= htmlspecialchars((string) $alert['resolution_note']) ?></span>
                        <?php endif; ?>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </aside>
          </div>
        </main>
      </div>
    </div>
    <script src="./assets/crm.js?v=20260911-coach-v5"></script>
    <script src="./assets/manager-dashboard.js?v=20260912-theme-v5"></script>
    <script src="./assets/crm-navigation.js?v=20261122-sidebar-unified-v1"></script>
  </body>
</html>
