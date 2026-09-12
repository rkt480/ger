(() => {
  const clock = document.querySelector('[data-manager-clock]');
  const date = document.querySelector('[data-manager-date]');
  const greeting = document.querySelector('[data-manager-greeting-label]');

  if (!clock || !date) {
    return;
  }

  const render = () => {
    const now = new Date();
    if (greeting) {
      const hour = now.getHours();
      greeting.textContent = hour < 12 ? 'Bom dia' : (hour < 18 ? 'Boa tarde' : 'Boa noite');
    }
    clock.textContent = new Intl.DateTimeFormat('pt-BR', {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).format(now);
    date.textContent = new Intl.DateTimeFormat('pt-BR', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    }).format(now);
  };

  render();
  window.setInterval(render, 30000);
})();

(() => {
  const buttons = document.querySelectorAll('[data-manager-resolve-alert]');
  const csrfToken = document.querySelector("meta[name='csrf-token']")?.content || '';

  if (buttons.length === 0 || !csrfToken) {
    return;
  }

  buttons.forEach((button) => {
    button.addEventListener('click', async (event) => {
      event.preventDefault();
      event.stopPropagation();

      if (button.disabled) {
        return;
      }

      const note = window.prompt(
        'Como o problema foi resolvido? Essa observação será considerada pela próxima análise da IA. (Opcional)',
        'Resolvido pelo gestor.'
      );

      if (note === null) {
        return;
      }

      button.disabled = true;
      const originalLabel = button.textContent;
      button.textContent = 'Salvando…';

      try {
        const formData = new FormData();
        formData.set('alert_id', button.dataset.alertId || '');
        formData.set('resolution_note', note);

        const response = await fetch('./api/manager-resolve-alert.php', {
          method: 'POST',
          headers: { 'X-CSRF-Token': csrfToken, Accept: 'application/json' },
          body: formData,
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok || data.ok !== true) {
          throw new Error(data.error || 'Não foi possível resolver o alerta.');
        }

        window.location.reload();
      } catch (error) {
        button.disabled = false;
        button.textContent = originalLabel;
        window.alert(error.message || 'Não foi possível resolver o alerta.');
      }
    });
  });
})();

(() => {
  const dialog = document.querySelector('[data-dialog="manager-client"]');
  const groupIdField = dialog?.querySelector('[data-manager-group-id-field]');
  const groupNameLabel = dialog?.querySelector('[data-manager-group-name-label]');
  const context = dialog?.querySelector('[data-manager-client-context]');
  const submit = dialog?.querySelector('[data-manager-client-submit]');

  if (!dialog || !groupIdField || !groupNameLabel || !context || !submit) {
    return;
  }

  const resetClientDialog = () => {
    groupIdField.value = '';
    groupNameLabel.textContent = '';
    context.hidden = true;
    submit.textContent = 'Salvar cliente';
  };

  document.querySelectorAll('[data-open-dialog="manager-client"]').forEach((button) => {
    button.addEventListener('click', () => {
      const groupId = button.dataset.managerGroupId || '';
      const groupName = button.dataset.managerGroupName || '';

      if (!groupId) {
        resetClientDialog();
        return;
      }

      groupIdField.value = groupId;
      groupNameLabel.textContent = groupName;
      context.hidden = false;
      submit.textContent = 'Cadastrar e vincular';
    });
  });
})();

(() => {
  const buttons = document.querySelectorAll('[data-manager-analyze-pending], [data-manager-analyze-group]');
  const csrfToken = document.querySelector("meta[name='csrf-token']")?.content || '';

  if (buttons.length === 0 || !csrfToken) {
    return;
  }

  const processAnalysis = async (button, silent = false) => {
    if (button.disabled) {
      return;
    }

    button.disabled = true;
    const originalLabel = button.textContent;

    if (!silent) {
      button.textContent = 'Lendo mensagens…';
    }

    try {
      const formData = new FormData();
      const groupId = button.dataset.groupId || '';

      if (groupId) {
        formData.set('group_id', groupId);
      } else {
        formData.set('limit', '2');
      }

      const response = await fetch('./api/manager-analyze.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrfToken, Accept: 'application/json' },
        body: formData,
      });
      const data = await response.json().catch(() => ({}));

      if (!response.ok || data.ok !== true) {
        throw new Error(data.error || 'Não foi possível concluir a leitura da IA.');
      }

      const processed = Number(data.processed || 0);
      const batchProcessed = Array.isArray(data.results)
        ? data.results.reduce((total, item) => total + Number(item?.processed || 0), 0)
        : processed;

      if (batchProcessed > 0) {
        window.location.reload();
        return;
      }

      if (!silent) {
        button.textContent = data.reason || 'Nenhuma mensagem pendente';
      }
    } catch (error) {
      if (!silent) {
        button.textContent = error.message || 'Falha na leitura da IA';
      }
    } finally {
      if (document.body.contains(button) && !button.dataset.reloadPending) {
        button.disabled = false;
        if (button.textContent === 'Lendo mensagens…') {
          button.textContent = originalLabel;
        }
      }
    }
  };

  buttons.forEach((button) => {
    button.addEventListener('click', () => processAnalysis(button));
  });

  const pendingButton = document.querySelector('[data-manager-analyze-pending]');

  if (pendingButton && document.visibilityState === 'visible') {
    window.setTimeout(() => processAnalysis(pendingButton, true), 900);
  }
})();
