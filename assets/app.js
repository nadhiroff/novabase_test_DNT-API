// Banc CRUD NovaBase : thème, fenêtres modales, notifications (aucun alert/confirm natif).
(() => {
  'use strict';

  const root = document.documentElement;
  const store = {
    get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
    set: (k, v) => { try { localStorage.setItem(k, v); } catch { /* navigation privée */ } },
  };

  // Thème : préférence enregistrée, sinon celle du système.
  const prefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
  root.dataset.theme = store.get('crud-theme') ?? (prefersLight ? 'light' : 'dark');
  document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
    root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
    store.set('crud-theme', root.dataset.theme);
  });

  // Formulaire d'ajout / modification : ouvert par le serveur (?form=…), fermé en revenant à la liste.
  const formDialog = document.getElementById('form-dialog');
  if (formDialog?.hasAttribute('data-autoshow')) {
    formDialog.showModal();
    formDialog.querySelector('input:not([type=hidden]), textarea, select')?.focus();
    formDialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      window.location.href = formDialog.querySelector('a[data-close]')?.href ?? window.location.pathname;
    });
  }

  // Confirmation de suppression.
  const deleteDialog = document.getElementById('delete-dialog');
  document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog && dialog !== formDialog) dialog.close();
    });
  });

  // Ouverture générique des confirmations (« Supprimer la sélection », « Supprimer toutes les données »).
  document.querySelectorAll('[data-dialog]').forEach((button) => {
    button.addEventListener('click', () => document.getElementById(button.dataset.dialog)?.showModal());
  });
  document.querySelectorAll('dialog [data-close]').forEach((button) => {
    if (button.tagName === 'BUTTON') button.addEventListener('click', () => button.closest('dialog')?.close());
  });

  // Cases à cocher : compteur, « tout cocher », bouton de suppression groupée (rebranché après chaque recherche).
  const bindSelection = () => {
    const rowChecks = [...document.querySelectorAll('[data-row-check]')];
    const selectAll = document.querySelector('[data-select-all]');
    const sync = () => {
      const selected = rowChecks.filter((box) => box.checked).length;
      document.querySelectorAll('[data-selected-count]').forEach((el) => { el.textContent = String(selected); });
      document.querySelectorAll('[data-bulk-button]').forEach((btn) => { btn.disabled = selected === 0; });
      if (selectAll) {
        selectAll.checked = selected > 0 && selected === rowChecks.length;
        selectAll.indeterminate = selected > 0 && selected < rowChecks.length;
      }
    };
    rowChecks.forEach((box) => box.addEventListener('change', sync));
    selectAll?.addEventListener('change', () => {
      rowChecks.forEach((box) => { box.checked = selectAll.checked; });
      sync();
    });
    sync();
  };
  const bindDeleteButtons = () => {
    document.querySelectorAll('[data-delete]').forEach((button) => {
      button.addEventListener('click', () => {
        if (!deleteDialog) return;
        deleteDialog.querySelector('[data-delete-id]').value = button.dataset.delete;
        deleteDialog.querySelector('[data-delete-name]').textContent = button.dataset.name || `n° ${button.dataset.delete}`;
        deleteDialog.querySelector('[data-delete-label]').textContent = button.dataset.delete;
        deleteDialog.showModal();
      });
    });
  };
  bindSelection();
  bindDeleteButtons();

  // Recherche instantanée : la liste se met à jour pendant la frappe (et au changement du filtre).
  document.querySelectorAll('form[data-live-search]').forEach((form) => {
    const spinner = form.querySelector('[data-live-spinner]');
    let timer = 0;
    let controller = null;
    const run = async () => {
      const params = new URLSearchParams(new FormData(form));
      [...params.keys()].forEach((key) => { if (params.get(key) === '') params.delete(key); });
      const url = `index.php?${params}`;
      controller?.abort();
      controller = new AbortController();
      if (spinner) spinner.hidden = false;
      try {
        const response = await fetch(url, { signal: controller.signal });
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        document.querySelectorAll('[data-live-region]').forEach((region) => {
          const next = doc.querySelector(`[data-live-region="${region.dataset.liveRegion}"]`);
          if (next) region.replaceWith(next);
        });
        // Le journal des appels API suit aussi.
        const journal = doc.querySelector('.journal');
        if (journal) document.querySelector('.journal')?.replaceWith(journal);
        history.replaceState(null, '', url);
        bindSelection();
        bindDeleteButtons();
      } catch (error) {
        if (error.name !== 'AbortError') form.submit();
      } finally {
        if (spinner) spinner.hidden = true;
      }
    };
    form.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(run, 300);
    });
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      clearTimeout(timer);
      run();
    });
  });

  // « Insérer » : amène au formulaire et place le curseur dans le premier champ.
  document.querySelectorAll('[data-focus-form]').forEach((link) => {
    link.addEventListener('click', (event) => {
      const form = document.getElementById('formulaire');
      if (!form) return;
      event.preventDefault();
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      form.querySelector('input:not([type=hidden]), textarea, select')?.focus({ preventScroll: true });
    });
  });

  // Filtre qui se soumet tout seul.
  document.querySelectorAll('[data-autosubmit]').forEach((field) => {
    field.addEventListener('change', () => field.form?.requestSubmit());
  });

  // Notifications : disparition après 4 secondes (6 pour une erreur), fermeture manuelle.
  document.querySelectorAll('[data-toast]').forEach((toast) => {
    const close = () => {
      toast.classList.add('is-leaving');
      setTimeout(() => toast.remove(), 260);
    };
    toast.querySelector('[data-toast-close]')?.addEventListener('click', close);
    setTimeout(close, toast.classList.contains('toast-error') ? 6000 : 4000);
  });

  // Animation en cascade des étapes du test.
  document.querySelectorAll('.checklist li').forEach((li, i) => li.style.setProperty('--i', String(i)));
})();
