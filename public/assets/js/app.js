// Flashcards — utilidades de todas las páginas. AGPL-3.0-only.
(function () {
  'use strict';

  // Pide confirmación antes de enviar formularios que borran cosas.
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute && ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      ev.preventDefault();
    }
  });

  // En Importar: muestra el campo "nombre del mazo nuevo" solo si se elige "Mazo nuevo…".
  Array.prototype.forEach.call(document.querySelectorAll('select[data-toggle-new]'), function (sel) {
    var box = document.getElementById(sel.getAttribute('data-toggle-new'));
    var input = box ? box.querySelector('input') : null;
    function sync() {
      var isNew = sel.value === '0';
      if (box) box.hidden = !isNew;
      if (input) input.required = isNew;
    }
    sel.addEventListener('change', sync);
    sync();
  });

  // Fórmulas con KaTeX: \( … \) en línea, \[ … \] o $$ … $$ en bloque.
  window.fcRenderMath = function (node) {
    if (!window.renderMathInElement || !node) return;
    try {
      window.renderMathInElement(node, {
        delimiters: [
          { left: '$$', right: '$$', display: true },
          { left: '\\[', right: '\\]', display: true },
          { left: '\\(', right: '\\)', display: false }
        ],
        throwOnError: false
      });
    } catch (e) { /* si una fórmula falla, se deja el texto como está */ }
  };
  window.addEventListener('load', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.math'), window.fcRenderMath);
  });

  // Respuestas de repaso pendientes de guardar (por si se va la señal).
  // Se guardan en el navegador y se envían en orden apenas hay conexión.
  var KEY = 'fc_pendientes_v1';
  var csrf = document.body.getAttribute('data-csrf') || '';
  var listeners = { change: [], conflict: [], error: [], auth: [] };
  var queue = read();
  var busy = false;
  var timer = null;

  function read() {
    try { return JSON.parse(window.localStorage.getItem(KEY) || '[]') || []; } catch (e) { return []; }
  }
  function write() {
    try { window.localStorage.setItem(KEY, JSON.stringify(queue)); } catch (e) { /* sin almacenamiento: queda en memoria */ }
    emit('change', queue.length);
  }
  function emit(type, value) {
    listeners[type].forEach(function (fn) { try { fn(value); } catch (e) { /* nada */ } });
  }
  function retryLater() {
    clearTimeout(timer);
    timer = setTimeout(flush, 15000);
  }
  function flush() {
    if (busy || !queue.length || !csrf) return;
    busy = true;
    var item = queue[0];
    fetch('/api/responder', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(item)
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (body) { return { status: res.status, body: body }; });
    }).then(function (r) {
      busy = false;
      if (r.status === 401 || r.status === 403) {
        emit('auth', r.body.error || 'Tu sesión terminó.');
        return;
      }
      if (r.status >= 500 || r.status === 0) {
        retryLater();
        return;
      }
      if (r.status === 409 && r.body.card) emit('conflict', r.body.card);
      if (r.status === 422 || r.status === 404) emit('error', r.body.error || 'Una respuesta no se pudo guardar.');
      queue.shift();
      write();
      flush();
    }).catch(function () {
      busy = false;
      retryLater();
    });
  }

  window.fcPending = {
    push: function (item) { queue.push(item); write(); flush(); },
    count: function () { return queue.length; },
    flush: flush,
    on: function (type, fn) { if (listeners[type]) listeners[type].push(fn); },
    // Espera a que se vacíe la cola (máximo ms) y luego llama a cb.
    whenEmpty: function (cb, ms) {
      var start = Date.now();
      (function check() {
        if (!queue.length || Date.now() - start > (ms || 5000)) { cb(); return; }
        setTimeout(check, 250);
      })();
    }
  };
  window.addEventListener('online', flush);
  if (queue.length) flush();
})();
