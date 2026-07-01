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
  var downloadFallback = tool.querySelector('[data-download-fallback]');
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
      showDownloadFallback(sprite);
      setStatus('Sprite ready. If the download did not start, use the download link below.');
    });
  });

  actions.copySprite.addEventListener('click', function () {
    buildSprite().then(function (sprite) {
      if (!sprite) {
        return;
      }

      return navigator.clipboard.writeText(sprite).then(function () {
        setStatus('Sprite copied.');
      });
    }).catch(function () {
      setStatus('Copy failed. Your browser may require HTTPS clipboard permission.', true);
    });
  });

  actions.copyUsage.addEventListener('click', function () {
    navigator.clipboard.writeText(usageSnippet.textContent).then(function () {
      setStatus('Usage snippet copied.');
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
    emptyState.hidden = hasIcons;
    actions.download.disabled = !hasIcons;
    actions.copySprite.disabled = !hasIcons;
    actions.copyUsage.disabled = !hasIcons;
    actions.clear.disabled = !hasIcons;

    state.icons.forEach(function (icon, index) {
      var card = document.createElement('article');
      card.className = 'icon-card';

      var preview = document.createElement('div');
      preview.className = 'icon-preview';
      preview.setAttribute('aria-hidden', 'true');
      preview.innerHTML = '<svg viewBox="' + escapeAttribute(icon.viewBox) + '">' + icon.symbol_markup + '</svg>';

      var meta = document.createElement('div');
      meta.className = 'icon-meta';

      var filename = document.createElement('small');
      filename.textContent = icon.filename || 'icon.svg';

      var input = document.createElement('input');
      input.className = 'symbol-input';
      input.value = icon.symbol_id;
      input.setAttribute('aria-label', 'Symbol ID for ' + (icon.filename || 'icon'));
      input.addEventListener('input', function () {
        icon.symbol_id = slugSymbolId(input.value);
        input.value = icon.symbol_id;
        saveState();
        updateUsageSnippet();
      });

      var remove = document.createElement('button');
      remove.className = 'button button-secondary';
      remove.type = 'button';
      remove.textContent = 'Remove';
      remove.addEventListener('click', function () {
        state.icons.splice(index, 1);
        saveState();
        render();
        setStatus('Icon removed.');
      });

      meta.appendChild(filename);
      meta.appendChild(input);

      card.appendChild(preview);
      card.appendChild(meta);

      if (Array.isArray(icon.notes) && icon.notes.length) {
        var notes = document.createElement('div');
        notes.className = 'notes';
        notes.innerHTML = '<strong>Cleanup notes</strong><ul>' + icon.notes.map(function (note) {
          return '<li>' + escapeHtml(note) + '</li>';
        }).join('') + '</ul>';
        card.appendChild(notes);
      }

      if (Array.isArray(icon.warnings) && icon.warnings.length) {
        var warnings = document.createElement('div');
        warnings.className = 'warnings';
        warnings.innerHTML = '<strong>Warnings</strong><ul>' + icon.warnings.map(function (warning) {
          return '<li>' + escapeHtml(warning) + '</li>';
        }).join('') + '</ul>';
        card.appendChild(warnings);
      }

      card.appendChild(remove);
      grid.appendChild(card);
    });

    updateUsageSnippet();
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
    statusLine.textContent = message || '';
    statusLine.classList.toggle('is-error', Boolean(isError));
  }

  function showDownloadFallback(sprite) {
    var href = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(sprite);
    downloadFallback.hidden = false;
    downloadFallback.innerHTML = '<a class="button button-secondary" download="sprite.svg" href="' + href + '">Download ready sprite</a>';
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
    var count = editor.querySelectorAll('[data-icon-form].is-dirty').length;
    saveIconButtons.forEach(function (button) {
      button.hidden = count === 0;
      button.disabled = count === 0;
      button.textContent = count > 0
        ? 'Save ' + count + ' ID change' + (count === 1 ? '' : 's')
        : 'Save ID changes';
    });
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
