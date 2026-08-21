/**
 * NIP Autofill — fills company name and address from the Polish VAT
 * register after a valid NIP is typed into the vat_number field.
 *
 * Vanilla JS, delegated listeners: survives the AJAX re-renders of the
 * ST Easy Checkout address forms and works on the standard checkout too.
 */
(function () {
  'use strict';

  var cfg = window.w3bNipAutofill;
  if (!cfg || !cfg.ajaxUrl) {
    return;
  }

  var DEBOUNCE_MS = 500;
  var timers = new WeakMap();
  var aborters = new WeakMap();

  function cleanNip(raw) {
    var v = String(raw || '').toUpperCase().replace(/[\s.,\-\/\\]/g, '');
    if (v.indexOf('PL') === 0) {
      v = v.slice(2);
    }
    return v.replace(/\D/g, '');
  }

  function isValidNip(nip) {
    if (!/^\d{10}$/.test(nip) || nip === '0000000000') {
      return false;
    }
    var weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
    var sum = 0;
    for (var i = 0; i < 9; i++) {
      sum += weights[i] * Number(nip.charAt(i));
    }
    return sum % 11 === Number(nip.charAt(9));
  }

  /**
   * ST Easy Checkout has a private/company radio (p_or_c: 0/1); only act
   * in company mode there. Forms without the switch (standard checkout,
   * account address form) always qualify — typing a NIP is intent enough.
   */
  function isCompanyMode(form) {
    if (!form.querySelector('input[name="p_or_c"]')) {
      return true;
    }
    var checked = form.querySelector('input[name="p_or_c"]:checked');
    return !!checked && checked.value === '1';
  }

  function statusEl(field) {
    var wrap = field.closest('.form-group') || field.parentElement;
    var el = wrap.querySelector('.w3b-nip-status');
    if (!el) {
      el = document.createElement('p');
      el.className = 'w3b-nip-status';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      wrap.appendChild(el);
    }
    return el;
  }

  function setStatus(field, state, message) {
    var el = statusEl(field);
    el.textContent = message || '';
    el.className = 'w3b-nip-status' + (state ? ' w3b-nip-status--' + state : '');
  }

  function setField(form, name, value) {
    if (!value) {
      return;
    }
    var input = form.querySelector('input[name="' + name + '"], textarea[name="' + name + '"]');
    if (!input || input.type === 'hidden') {
      return;
    }
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function fillForm(form, data) {
    setField(form, 'company', data.company);
    setField(form, 'address1', data.address1);
    setField(form, 'postcode', data.postcode);
    setField(form, 'city', data.city);
  }

  function lookup(field, form, nip) {
    if (field.dataset.w3bNipDone === nip) {
      return;
    }

    var previous = aborters.get(field);
    if (previous) {
      previous.abort();
    }
    var controller = typeof AbortController === 'function' ? new AbortController() : null;
    if (controller) {
      aborters.set(field, controller);
    }

    setStatus(field, 'loading', cfg.i18n.loading);

    var url = cfg.ajaxUrl + (cfg.ajaxUrl.indexOf('?') === -1 ? '?' : '&')
      + 'ajax=1&nip=' + encodeURIComponent(nip)
      + '&token=' + encodeURIComponent(cfg.token);

    fetch(url, {
      credentials: 'same-origin',
      signal: controller ? controller.signal : undefined
    })
      .then(function (response) { return response.json(); })
      .then(function (result) {
        if (!result || result.ok !== true) {
          var reason = result && result.reason;
          var message = cfg.i18n.error;
          if (reason === 'invalid_nip') {
            message = cfg.i18n.invalid;
          } else if (reason === 'rate_limited') {
            message = cfg.i18n.rateLimited || cfg.i18n.error;
          }
          setStatus(field, 'error', message);
          return;
        }
        if (!result.found) {
          setStatus(field, 'error', cfg.i18n.notFound);
          return;
        }
        field.dataset.w3bNipDone = nip;
        fillForm(form, result.data);
        setStatus(field, 'ok', cfg.i18n.filled);
      })
      .catch(function (error) {
        if (error && error.name === 'AbortError') {
          return;
        }
        setStatus(field, 'error', cfg.i18n.error);
      });
  }

  function onNipInput(field, immediate) {
    var form = field.closest('form');
    if (!form || !isCompanyMode(form)) {
      return;
    }

    var pending = timers.get(field);
    if (pending) {
      clearTimeout(pending);
    }

    var nip = cleanNip(field.value);
    if (nip.length < 10) {
      showHint(field);
      return;
    }
    if (!isValidNip(nip)) {
      setStatus(field, 'error', cfg.i18n.invalid);
      return;
    }

    var run = function () { lookup(field, form, nip); };
    if (immediate) {
      run();
    } else {
      timers.set(field, setTimeout(run, DEBOUNCE_MS));
    }
  }

  // Invite the customer to type a NIP while the field is empty or incomplete.
  function showHint(field) {
    if (field.dataset.w3bNipDone) {
      return;
    }
    setStatus(field, 'hint', cfg.i18n.hint);
  }

  function hintIdleFields(root) {
    var fields = root.querySelectorAll ? root.querySelectorAll('input[name="vat_number"]') : [];
    for (var i = 0; i < fields.length; i++) {
      var field = fields[i];
      var form = field.closest('form');
      if (form && isCompanyMode(form) && cleanNip(field.value).length < 10) {
        showHint(field);
      }
    }
  }

  // Checkout address forms are (re)loaded over AJAX, so watch for the
  // NIP field appearing instead of relying on the initial page load only.
  if (typeof MutationObserver === 'function') {
    new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var added = mutations[i].addedNodes;
        for (var j = 0; j < added.length; j++) {
          if (added[j].nodeType === 1) {
            hintIdleFields(added[j]);
          }
        }
      }
    }).observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      hintIdleFields(document);
    });
  } else {
    hintIdleFields(document);
  }

  document.addEventListener('input', function (event) {
    var target = event.target;
    if (target && target.name === 'vat_number') {
      onNipInput(target, false);
    }
  });

  // On leaving the field: normalize the visible value (strip spaces,
  // dashes, PL prefix) and fetch without the debounce delay.
  document.addEventListener('focusout', function (event) {
    var target = event.target;
    if (!target || target.name !== 'vat_number') {
      return;
    }
    var nip = cleanNip(target.value);
    if (nip.length === 10 && target.value !== nip) {
      target.value = nip;
    }
    if (nip.length === 10) {
      onNipInput(target, true);
    }
  });

  // Switching to "company": fetch right away if a NIP is already typed,
  // otherwise show the hint next to the now-visible NIP field.
  document.addEventListener('change', function (event) {
    var target = event.target;
    if (target && target.name === 'p_or_c' && target.value === '1') {
      var form = target.closest('form');
      var field = form && form.querySelector('input[name="vat_number"]');
      if (!field) {
        return;
      }
      if (cleanNip(field.value).length >= 10) {
        onNipInput(field, true);
      } else {
        showHint(field);
      }
    }
  });
})();
