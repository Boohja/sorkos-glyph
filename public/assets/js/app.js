(function () {
  var storageKey = 'glyph.guest.sprite.v1';
  var tool = document.querySelector('[data-glyph-tool]');

  if (!tool) {
    return;
  }

  var dropzone = tool.querySelector('[data-dropzone]');
  var fileInput = tool.querySelector('[data-file-input]');
  var grid = tool.querySelector('[data-icon-grid]');
  var emptyState = tool.querySelector('[data-empty-state]');
  var statusLine = tool.querySelector('[data-status]');
  var usageSnippet = tool.querySelector('[data-usage-snippet]');
  var modeSelect = tool.querySelector('[data-option="mode"]');
  var actions = {
    download: tool.querySelector('[data-action="download"]'),
    copySprite: tool.querySelector('[data-action="copy-sprite"]'),
    copyUsage: tool.querySelector('[data-action="copy-usage"]'),
    clear: tool.querySelector('[data-action="clear"]')
  };

  var state = loadState();
  render();

  document.addEventListener('click', function (event) {
    if (!tool.contains(event.target)) {
      closeGuestPopovers();
      return;
    }

    if (!event.target.closest('.detail-badge') && !event.target.closest('.icon-popover')) {
      closeGuestPopovers();
    }
  });

  dropzone.addEventListener('dragover', function (event) {
    event.preventDefault();
    dropzone.classList.add('is-dragover');
  });

  dropzone.addEventListener('dragleave', function () {
    dropzone.classList.remove('is-dragover');
  });

  dropzone.addEventListener('drop', function (event) {
    event.preventDefault();
    dropzone.classList.remove('is-dragover');
    uploadFiles(event.dataTransfer.files);
  });

  fileInput.addEventListener('change', function () {
    uploadFiles(fileInput.files);
    fileInput.value = '';
  });

  modeSelect.addEventListener('change', function () {
    state.mode = modeSelect.value;
    saveState();
  });

  actions.download.addEventListener('click', function () {
    buildSprite().then(function (sprite) {
      if (!sprite) {
        return;
      }

      var link = document.createElement('a');
      link.href = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(sprite);
      link.download = 'sprite.svg';
      link.style.display = 'none';
      document.body.appendChild(link);
      link.click();
      window.setTimeout(function () {
        link.remove();
      }, 1000);
      setStatus('');
    });
  });

  actions.copySprite.addEventListener('click', function () {
    buildSprite().then(function (sprite) {
      if (!sprite) {
        return;
      }

      return navigator.clipboard.writeText(sprite).then(function () {
        flashCopyButton(actions.copySprite, 'Copied');
      });
    }).catch(function () {
      setStatus('Copy failed. Your browser may require HTTPS clipboard permission.', true);
    });
  });

  actions.copyUsage.addEventListener('click', function () {
    navigator.clipboard.writeText(usageSnippet.textContent).then(function () {
      flashCopyButton(actions.copyUsage, 'Copied');
    }).catch(function () {
      setStatus('Copy failed. Your browser may require HTTPS clipboard permission.', true);
    });
  });

  actions.clear.addEventListener('click', function () {
    if (!window.confirm('Clear the local guest sprite from this browser?')) {
      return;
    }

    state.icons = [];
    saveState();
    render();
    setStatus('Local sprite cleared.');
  });

  function uploadFiles(fileList) {
    var files = Array.from(fileList || []);
    if (!files.length) {
      return;
    }

    var formData = new FormData();
    files.forEach(function (file) {
      formData.append('icons[]', file);
    });
    setStatus('Cleaning ' + files.length + ' SVG file' + (files.length === 1 ? '' : 's') + '...');

    fetch('/api/sanitize', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) {
          throw new Error(errorMessage(data));
        }
        return data;
      });
    }).then(function (data) {
      var added = 0;
      (data.icons || []).forEach(function (icon) {
        if (icon.ok) {
          state.icons.push(icon);
          added++;
        } else {
          setStatus((icon.filename || 'SVG') + ': ' + (icon.errors || ['Could not clean SVG.']).join(' '), true);
        }
      });

      saveState();
      render();

      if (added > 0) {
        setStatus('Added ' + added + ' cleaned icon' + (added === 1 ? '' : 's') + '.');
      }
    }).catch(function (error) {
      setStatus(error.message || 'Upload failed.', true);
    });
  }

  function render() {
    modeSelect.value = state.mode || 'pretty';
    grid.innerHTML = '';

    var hasIcons = state.icons.length > 0;
    tool.classList.toggle('has-icons', hasIcons);
    emptyState.hidden = hasIcons;
    actions.download.disabled = !hasIcons;
    actions.copySprite.disabled = !hasIcons;
    actions.copyUsage.disabled = false;
    actions.clear.disabled = !hasIcons;

    state.icons.forEach(function (icon, index) {
      grid.appendChild(createGuestIconCard(icon, index));
    });

    updateUsageSnippet();
  }

  function createGuestIconCard(icon, index) {
    var card = document.createElement('article');
    var preview = document.createElement('div');

    card.className = 'saved-icon-card guest-icon-card';
    preview.className = 'icon-preview';
    preview.setAttribute('aria-hidden', 'true');
    preview.innerHTML = '<svg viewBox="' + escapeAttribute(icon.viewBox) + '">' + icon.symbol_markup + '</svg>';

    card.appendChild(preview);
    appendGuestIconDetails(card, icon, index);
    card.appendChild(createGuestIconForm(icon, index));

    return card;
  }

  function appendGuestIconDetails(card, icon, index) {
    var messages = visibleIconMessages(icon);

    if (!messages.length) {
      return;
    }

    var detailsButton = document.createElement('button');
    var popover = document.createElement('div');
    var popoverId = 'guest-icon-details-' + index;

    detailsButton.className = 'detail-badge' + (Array.isArray(icon.warnings) && icon.warnings.length ? ' has-warnings' : '');
    detailsButton.type = 'button';
    detailsButton.textContent = String(messages.length);
    detailsButton.setAttribute('aria-label', messages.length + ' cleanup detail' + (messages.length === 1 ? '' : 's') + ' for ' + (icon.filename || 'icon'));
    detailsButton.setAttribute('aria-expanded', 'false');
    detailsButton.setAttribute('aria-controls', popoverId);

    popover.className = 'icon-popover';
    popover.id = popoverId;
    popover.hidden = true;
    popover.innerHTML = '<strong>Details</strong><ul>' + messages.map(function (message) {
      return '<li>' + escapeHtml(message) + '</li>';
    }).join('') + '</ul>';

    detailsButton.addEventListener('click', function (event) {
      event.stopPropagation();
      var isOpen = !popover.hidden;
      closeGuestPopovers();
      popover.hidden = isOpen;
      detailsButton.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
    });

    card.appendChild(detailsButton);
    card.appendChild(popover);
  }

  function createGuestIconForm(icon, index) {
    var form = document.createElement('form');
    var label = document.createElement('label');
    var labelText = document.createElement('span');
    var input = document.createElement('input');
    var rowActions = document.createElement('div');
    var remove = document.createElement('button');

    form.className = 'saved-icon-form';
    form.addEventListener('submit', function (event) {
      event.preventDefault();
    });

    labelText.className = 'visually-hidden';
    labelText.textContent = 'Symbol ID';

    input.name = 'symbol_id';
    input.value = icon.symbol_id;
    input.required = true;
    input.pattern = '[a-z][a-z0-9_-]{0,119}';
    input.setAttribute('aria-label', 'Symbol ID for ' + (icon.filename || 'icon'));
    input.addEventListener('input', function () {
      icon.symbol_id = slugSymbolId(input.value);
      input.value = icon.symbol_id;
      saveState();
      updateUsageSnippet();
    });

    rowActions.className = 'row-actions';

    remove.className = 'button button-plain icon-delete';
    remove.type = 'button';
    remove.textContent = '\u00d7';
    remove.title = 'Delete';
    remove.setAttribute('aria-label', 'Delete ' + (icon.symbol_id || icon.filename || 'icon'));
    remove.addEventListener('click', function () {
      state.icons.splice(index, 1);
      saveState();
      render();
      setStatus('Icon removed.');
    });

    label.appendChild(labelText);
    label.appendChild(input);
    rowActions.appendChild(remove);
    form.appendChild(label);
    form.appendChild(rowActions);

    return form;
  }

  function buildSprite() {
    if (!state.icons.length) {
      setStatus('Upload at least one icon first.', true);
      return Promise.resolve('');
    }

    var normalizedIcons = state.icons.map(function (icon) {
      return {
        symbol_id: icon.symbol_id,
        viewBox: icon.viewBox || icon.viewbox,
        symbol_markup: icon.symbol_markup
      };
    });

    return fetch('/api/build-sprite', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      credentials: 'same-origin',
      body: JSON.stringify({
        mode: modeSelect.value,
        icons: normalizedIcons
      })
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || !data.ok) {
          throw new Error(errorMessage(data));
        }
        return data.sprite;
      });
    }).catch(function (error) {
      setStatus(error.message || 'Could not build sprite.', true);
      return '';
    });
  }

  function updateUsageSnippet() {
    var firstId = state.icons[0] ? state.icons[0].symbol_id : 'symbol-id';
    usageSnippet.textContent = '<svg class="icon" aria-hidden="true">\n  <use href="/assets/icons.svg#' + firstId + '"></use>\n</svg>';
  }

  function loadState() {
    try {
      var parsed = JSON.parse(localStorage.getItem(storageKey) || '{}');
      var loadedState = {
        mode: parsed.mode || 'pretty',
        icons: Array.isArray(parsed.icons) ? parsed.icons : []
      };
      loadedState.icons = loadedState.icons.map(migrateIconMessages);
      return loadedState;
    } catch (error) {
      return { mode: 'pretty', icons: [] };
    }
  }

  function saveState() {
    localStorage.setItem(storageKey, JSON.stringify(state));
  }

  function setStatus(message, isError) {
    window.clearTimeout(setStatus.clearTimer);
    statusLine.textContent = message || '';
    statusLine.classList.toggle('is-error', Boolean(isError));

    if (message && !isError) {
      setStatus.clearTimer = window.setTimeout(function () {
        statusLine.textContent = '';
      }, 3200);
    }
  }

  function flashCopyButton(button, message) {
    var label = button.getAttribute('data-copy-label') || button.textContent;
    button.textContent = message;
    window.clearTimeout(button._glyphCopyTimer);
    button._glyphCopyTimer = window.setTimeout(function () {
      button.textContent = label;
    }, 2200);
  }

  function errorMessage(data) {
    if (data && Array.isArray(data.errors) && data.errors[0]) {
      return data.errors.map(function (error) {
        return error.message || String(error);
      }).join(' ');
    }
    return 'Request failed.';
  }

  function slugSymbolId(value) {
    var slug = String(value || '')
      .toLowerCase()
      .replace(/[\s_]+/g, '-')
      .replace(/[^a-z0-9_-]/g, '')
      .replace(/^-+|-+$/g, '');

    if (!/^[a-z]/.test(slug)) {
      slug = 'icon-' + slug;
    }

    return slug.slice(0, 120);
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (character) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[character];
    });
  }

  function escapeAttribute(value) {
    return escapeHtml(value).replace(/`/g, '&#096;');
  }

  function closeGuestPopovers() {
    tool.querySelectorAll('.guest-icon-card .icon-popover').forEach(function (popover) {
      popover.hidden = true;
    });

    tool.querySelectorAll('.guest-icon-card .detail-badge').forEach(function (button) {
      button.setAttribute('aria-expanded', 'false');
    });
  }

  function visibleIconMessages(icon) {
    var hiddenMessages = [
      'Removed fixed width and height so the symbol scales through viewBox.',
      'Removed fixed width and height. The icon now scales through viewBox.',
      'Root SVG had no title or desc.',
      'Converted icon colors to currentColor.'
    ];

    return unique([].concat(icon.warnings || [], icon.notes || []).filter(function (message) {
      return hiddenMessages.indexOf(message) === -1;
    }));
  }

  function migrateIconMessages(icon) {
    if (!icon || !Array.isArray(icon.warnings)) {
      return icon;
    }

    var warnings = [];
    var notes = Array.isArray(icon.notes) ? icon.notes.slice() : [];
    icon.symbol_markup = withCurrentColor(icon.symbol_markup || '');

    icon.warnings.forEach(function (message) {
      if (message === 'Root SVG had no title or desc.') {
        return;
      }

      if (
        message === 'Generated viewBox from numeric width and height.' ||
        message === 'Removed fixed width and height. The icon now scales through viewBox.'
      ) {
        notes.push(message === 'Removed fixed width and height. The icon now scales through viewBox.'
          ? 'Removed fixed width and height so the symbol scales through viewBox.'
          : message);
        return;
      }

      warnings.push(message);
    });

    icon.warnings = unique(warnings);
    icon.notes = unique(notes);
    return icon;
  }

  function withCurrentColor(markup) {
    return String(markup || '').replace(/\s(fill|stroke)="([^"]*)"/gi, function (match, name, value) {
      var normalized = String(value || '').trim().toLowerCase();
      if (normalized === 'none' || normalized === 'currentcolor') {
        return ' ' + name + '="' + value + '"';
      }
      return ' ' + name + '="currentColor"';
    });
  }

  function unique(values) {
    return values.filter(function (value, index) {
      return values.indexOf(value) === index;
    });
  }
})();

(function () {
  document.addEventListener('click', function (event) {
    document.querySelectorAll('.account-menu[open]').forEach(function (menu) {
      if (!menu.contains(event.target)) {
        menu.removeAttribute('open');
      }
    });
  });

  document.querySelectorAll('.account-menu').forEach(function (menu) {
    menu.addEventListener('toggle', function () {
      if (!menu.open) {
        return;
      }

      document.querySelectorAll('.account-menu[open]').forEach(function (otherMenu) {
        if (otherMenu !== menu) {
          otherMenu.removeAttribute('open');
        }
      });
    });
  });

  var kofiAnchor = document.querySelector('[data-kofi-anchor]');

  if (!kofiAnchor || !window.kofiWidgetOverlay) {
    return;
  }

  window.kofiWidgetOverlay.draw('boohja', {
    type: 'floating-chat',
    'floating-chat.donateButton.text': 'Support me',
    'floating-chat.donateButton.background-color': '#00b9fe',
    'floating-chat.donateButton.text-color': '#fff'
  });

  dockKofiWidget();

  var observer = new MutationObserver(dockKofiWidget);
  observer.observe(document.body, { childList: true, subtree: true });

  function dockKofiWidget() {
    var widget = document.querySelector('.floatingchat-container-wrap');

    if (!widget || kofiAnchor.contains(widget)) {
      return;
    }

    widget.classList.add('kofi-docked');
    kofiAnchor.appendChild(widget);
    var fallback = kofiAnchor.querySelector('.kofi-fallback');
    if (fallback) {
      fallback.hidden = true;
    }
  }
})();

(function () {
  var editor = document.querySelector('[data-sprite-editor]');

  if (!editor) {
    return;
  }

  var spriteId = editor.getAttribute('data-sprite-id');
  var csrfToken = editor.getAttribute('data-csrf');
  var dropzone = editor.querySelector('[data-saved-dropzone]');
  var fileInput = editor.querySelector('[data-saved-file-input]');
  var statusLine = editor.querySelector('[data-saved-status]');
  var saveIconButtons = editor.querySelectorAll('[data-save-icon-changes]');
  var downloadSprite = editor.querySelector('[data-download-sprite]');

  if (dropzone && fileInput) {
    dropzone.addEventListener('dragover', function (event) {
      event.preventDefault();
      dropzone.classList.add('is-dragover');
    });

    dropzone.addEventListener('dragleave', function () {
      dropzone.classList.remove('is-dragover');
    });

    dropzone.addEventListener('drop', function (event) {
      event.preventDefault();
      dropzone.classList.remove('is-dragover');
      uploadSavedIcons(event.dataTransfer.files);
    });

    fileInput.addEventListener('change', function () {
      uploadSavedIcons(fileInput.files);
      fileInput.value = '';
    });
  }

  editor.querySelectorAll('[data-icon-form]').forEach(function (form) {
    var rowStatus = form.querySelector('[data-row-status]');
    var rowButtons = form.querySelectorAll('button');
    var symbolInput = form.querySelector('input[name="symbol_id"]');

    if (symbolInput) {
      symbolInput.setAttribute('data-original-value', symbolInput.value);
      symbolInput.addEventListener('input', function () {
        var isDirty = symbolInput.value !== symbolInput.getAttribute('data-original-value');
        form.classList.toggle('is-dirty', isDirty);
        setCardDirty(form, isDirty);
        setRowStatus(rowStatus, isDirty ? 'Unsaved' : '');
        updateSaveIconButtons();
      });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      saveIconChanges();
    });
  });

  saveIconButtons.forEach(function (button) {
    button.addEventListener('click', saveIconChanges);
  });

  if (downloadSprite) {
    downloadSprite.addEventListener('click', function (event) {
      if (dirtyIconCount() === 0) {
        return;
      }

      event.preventDefault();
      setSavedStatus('Save ID changes before downloading this sprite.', true);
      var firstDirty = editor.querySelector('[data-icon-form].is-dirty input[name="symbol_id"]');
      if (firstDirty) {
        firstDirty.focus();
      }
    });
  }

  editor.querySelectorAll('[data-details-toggle]').forEach(function (button) {
    button.addEventListener('click', function (event) {
      event.stopPropagation();
      var card = button.closest('[data-icon-row]');
      var popover = card ? card.querySelector('[data-details-popover]') : null;
      if (!popover) {
        return;
      }

      var willOpen = popover.hidden;
      closeIconPopovers();
      popover.hidden = !willOpen;
      button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });

  editor.querySelectorAll('[data-details-popover]').forEach(function (popover) {
    popover.addEventListener('click', function (event) {
      event.stopPropagation();
    });
  });

  document.addEventListener('click', closeIconPopovers);

  editor.querySelectorAll('[data-delete-icon]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (!window.confirm('Delete this icon from the sprite?')) {
        return;
      }

      var formData = new FormData();
      formData.append('csrf_token', csrfToken);
      var row = button.closest('[data-icon-row]');
      var rowStatus = row ? row.querySelector('[data-row-status]') : null;
      button.disabled = true;
      setRowStatus(rowStatus, 'Deleting...');
      postForm('/api/icons/' + button.getAttribute('data-delete-icon') + '/delete', formData).then(function () {
        if (row) {
          row.remove();
        }
        updateSaveIconButtons();
        setSavedStatus('Icon deleted.');
      }).catch(function (error) {
        var message = error.message || 'Could not delete icon.';
        setRowStatus(rowStatus, message, true);
        setSavedStatus(message, true);
        button.disabled = false;
      });
    });
  });

  updateSaveIconButtons();

  function uploadSavedIcons(fileList) {
    var files = Array.from(fileList || []);
    if (!files.length) {
      return;
    }

    var formData = new FormData();
    formData.append('csrf_token', csrfToken);
    files.forEach(function (file) {
      formData.append('icons[]', file);
    });

    setSavedStatus('Cleaning and saving ' + files.length + ' SVG file' + (files.length === 1 ? '' : 's') + '...');

    postForm('/api/sprites/' + spriteId + '/icons', formData).then(function (data) {
      if (data.added > 0) {
        setSavedStatus('Added ' + data.added + ' icon' + (data.added === 1 ? '' : 's') + '. Reloading...');
        window.location.reload();
        return;
      }

      setSavedStatus('No icons were added. Check file errors and try again.', true);
    }).catch(function (error) {
      setSavedStatus(error.message || 'Upload failed.', true);
    });
  }

  function postForm(url, formData) {
    return fetch(url, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || data.ok === false) {
          throw new Error(errorMessage(data));
        }
        return data;
      });
    });
  }

  function saveIconChanges() {
    var dirtyForms = Array.prototype.slice.call(editor.querySelectorAll('[data-icon-form].is-dirty'));
    if (!dirtyForms.length) {
      return;
    }

    var icons = dirtyForms.map(function (form) {
      var input = form.querySelector('input[name="symbol_id"]');
      return {
        id: form.getAttribute('data-icon-form'),
        symbol_id: input.value
      };
    });

    var invalidForm = dirtyForms.find(function (form) {
      var input = form.querySelector('input[name="symbol_id"]');
      return input && !input.checkValidity();
    });
    if (invalidForm) {
      var invalidInput = invalidForm.querySelector('input[name="symbol_id"]');
      invalidInput.reportValidity();
      setRowStatus(invalidForm.querySelector('[data-row-status]'), 'Invalid ID', true);
      return;
    }

    var formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('icons', JSON.stringify(icons));

    dirtyForms.forEach(function (form) {
      form.classList.add('is-saving');
      setRowStatus(form.querySelector('[data-row-status]'), 'Saving...');
      setButtons(form.querySelectorAll('button'), true);
    });
    setButtons(saveIconButtons, true);
    setSavedStatus('Saving ' + dirtyForms.length + ' changed ID' + (dirtyForms.length === 1 ? '' : 's') + '...');

    postForm('/api/sprites/' + spriteId + '/icons/update', formData).then(function (data) {
      (data.icons || []).forEach(function (icon) {
        var form = editor.querySelector('[data-icon-form="' + icon.id + '"]');
        var input = form ? form.querySelector('input[name="symbol_id"]') : null;
        if (!form || !input) {
          return;
        }

        input.value = icon.symbol_id;
        input.setAttribute('data-original-value', icon.symbol_id);
        form.classList.remove('is-dirty');
        setCardDirty(form, false);
        setRowStatus(form.querySelector('[data-row-status]'), 'Saved');
      });

      setSavedStatus('Saved ' + (data.icons || []).length + ' ID change' + ((data.icons || []).length === 1 ? '' : 's') + '.');
    }).catch(function (error) {
      var message = error.message || 'Could not save icon changes.';
      dirtyForms.forEach(function (form) {
        setRowStatus(form.querySelector('[data-row-status]'), message, true);
      });
      setSavedStatus(message, true);
    }).finally(function () {
      dirtyForms.forEach(function (form) {
        form.classList.remove('is-saving');
        setButtons(form.querySelectorAll('button'), false);
      });
      updateSaveIconButtons();
    });
  }

  function updateSaveIconButtons() {
    var count = dirtyIconCount();
    saveIconButtons.forEach(function (button) {
      button.hidden = count === 0;
      button.disabled = count === 0;
      button.textContent = count > 0
        ? 'Save ' + count + ' ID change' + (count === 1 ? '' : 's')
        : 'Save ID changes';
    });
  }

  function dirtyIconCount() {
    return editor.querySelectorAll('[data-icon-form].is-dirty').length;
  }

  function setCardDirty(form, isDirty) {
    var card = form.closest('[data-icon-row]');
    if (card) {
      card.classList.toggle('is-dirty', isDirty);
    }
  }

  function closeIconPopovers() {
    editor.querySelectorAll('[data-details-popover]').forEach(function (popover) {
      popover.hidden = true;
    });
    editor.querySelectorAll('[data-details-toggle]').forEach(function (button) {
      button.setAttribute('aria-expanded', 'false');
    });
  }

  function setSavedStatus(message, isError) {
    if (!statusLine) {
      return;
    }

    statusLine.textContent = message || '';
    statusLine.classList.toggle('is-error', Boolean(isError));
  }

  function setRowStatus(element, message, isError) {
    if (!element) {
      return;
    }

    element.textContent = message || '';
    element.classList.toggle('is-error', Boolean(isError));
  }

  function setButtons(buttons, disabled) {
    Array.prototype.forEach.call(buttons, function (button) {
      button.disabled = disabled;
    });
  }

  function errorMessage(data) {
    if (data && Array.isArray(data.errors) && data.errors[0]) {
      return data.errors.map(function (error) {
        return error.message || String(error);
      }).join(' ');
    }
    return 'Request failed.';
  }
})();
