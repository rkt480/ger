<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/forms.php';

crm_require_admin();

$error = '';
$saved = ($_GET['saved'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    crm_require_valid_csrf();
    $config = json_decode((string) ($_POST['config_json'] ?? '{}'), true);
    if (!is_array($config)) {
        $error = 'Não foi possível ler as perguntas do formulário.';
    } else {
        $publish = true;
        $id = crm_save_form(
            trim((string) ($_POST['id'] ?? '')),
            (string) ($_POST['name'] ?? ''),
            (string) ($_POST['slug'] ?? ''),
            $config,
            $publish
        );
        header('Location: forms.php?id=' . rawurlencode($id) . '&saved=1&published=1');
        exit;
    }
}

$forms = crm_read_forms();
$requestedId = trim((string) ($_GET['id'] ?? ''));
$currentForm = $requestedId === 'new' ? null : crm_find_form($requestedId !== '' ? $requestedId : (string) ($forms[0]['id'] ?? ''));
$currentConfig = $currentForm['draft'] ?? crm_default_form_config();
$isNew = $currentForm === null;
?>
<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?= htmlspecialchars(crm_csrf_token()) ?>" />
    <title>Formulários | CRM</title>
    <script src="./assets/theme.js?v=20261030-clean-layout-v1"></script>
    <link rel="stylesheet" href="./assets/crm.css?v=20261122-sidebar-compact-v2" />
  </head>
  <body>
    <div class="app-shell">
      <?php require __DIR__ . '/partials/sidebar.php'; ?>

      <div class="workspace">

        <header class="app-header">
          <div><p class="eyebrow">Captação</p><h1>Formulários e Lead Score</h1></div>
        </header>

        <main class="dashboard form-builder-layout">
          <?php if ($saved): ?><div class="alert success">Formulário salvo e publicado na landing page.</div><?php endif; ?>
          <?php if ($error !== ''): ?><div class="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>

          <section class="form-builder-card">
            <form method="post" id="formBuilder">
              <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars(crm_csrf_token()) ?>" />
              <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($currentForm['id'] ?? '')) ?>" />
              <input type="hidden" name="config_json" id="configJson" />

              <header class="builder-header">
                <div>
                  <p class="eyebrow"><?= $isNew ? 'Novo formulário' : 'Editando formulário' ?></p>
                  <h2><?= htmlspecialchars((string) ($currentForm['name'] ?? 'Novo formulário')) ?></h2>
                </div>
                <?php if (!$isNew): ?><span class="integration-status <?= ($currentForm['status'] ?? '') === 'published' ? 'is-active' : '' ?>"><?= ($currentForm['status'] ?? '') === 'published' ? 'Publicado' : 'Rascunho' ?></span><?php endif; ?>
              </header>

              <details class="general-settings"<?= $isNew ? ' open' : '' ?>>
                <summary>
                  <div><strong>Configurações gerais</strong><span>Nome, título, descrição e mensagem de sucesso</span></div>
                  <em>Editar</em>
                </summary>
                <div class="builder-grid">
                  <label>Nome interno<input type="text" name="name" id="formName" value="<?= htmlspecialchars((string) ($currentForm['name'] ?? 'Novo formulário')) ?>" required /></label>
                  <label>Identificador da landing<input type="text" name="slug" id="formSlug" value="<?= htmlspecialchars((string) ($currentForm['slug'] ?? 'novo-formulario')) ?>" pattern="[a-z0-9-]+" required /></label>
                  <label class="field-wide">Título no formulário<input type="text" data-config-field="title" value="<?= htmlspecialchars((string) ($currentConfig['title'] ?? '')) ?>" /></label>
                  <label class="field-wide">Descrição<textarea rows="2" data-config-field="description"><?= htmlspecialchars((string) ($currentConfig['description'] ?? '')) ?></textarea></label>
                  <label>Texto do botão<input type="text" data-config-field="submit_label" value="<?= htmlspecialchars((string) ($currentConfig['submit_label'] ?? 'Enviar')) ?>" /></label>
                  <label>Mensagem de sucesso<input type="text" data-config-field="success_message" value="<?= htmlspecialchars((string) ($currentConfig['success_message'] ?? '')) ?>" /></label>
                </div>
              </details>

              <section class="questions-section">
                <header><div><p class="eyebrow">Perguntas</p><h3>Campos exibidos na landing page</h3></div><button class="secondary-action" type="button" id="addQuestion">Adicionar pergunta</button></header>
                <div id="questionsBuilder" class="questions-builder"></div>
              </section>

              <div class="builder-actions">
                <button type="submit" name="action" value="publish">Publicar na landing page</button>
              </div>
            </form>
          </section>
        </main>
      </div>
    </div>

    <script id="initialFormConfig" type="application/json"><?= json_encode($currentConfig, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script src="./assets/forms.js?v=20260711-simple-score"></script>
    <script src="./assets/crm-navigation.js?v=20261122-sidebar-unified-v1"></script>
  </body>
</html>
