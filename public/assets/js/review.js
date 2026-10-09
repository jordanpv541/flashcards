// Flashcards — pantalla de repaso. Usa ts-fsrs (MIT) para calcular el próximo repaso. AGPL-3.0-only.
(function () {
  'use strict';

  var root = document.getElementById('review');
  if (!root) return;
  if (!window.FSRS) {
    root.querySelector('.review-loading').textContent = 'No se pudo cargar el repaso. Recarga la página.';
    return;
  }

  var csrf = document.body.getAttribute('data-csrf') || '';
  var mode = root.getAttribute('data-mode') || 'hoy';
  var query = root.getAttribute('data-query') || '';
  var backUrl = root.getAttribute('data-back') || '/hoy';
  var shortSize = parseInt(root.getAttribute('data-short') || '10', 10);
  var scheduler = window.FSRS.fsrs(window.FSRS.generatorParameters({ enable_fuzz: true }));
  var LEARN_AHEAD_MS = 20 * 60 * 1000;

  var el = {
    loading: root.querySelector('.review-loading'),
    card: root.querySelector('.flashcard'),
    meta: root.querySelector('.flashcard-meta'),
    front: root.querySelector('.flashcard-front'),
    back: root.querySelector('.flashcard-back'),
    end: root.querySelector('.review-end'),
    actions: root.querySelector('.review-actions'),
    reveal: root.querySelector('.btn-reveal'),
    grades: root.querySelector('.grades'),
    status: root.querySelector('.review-status'),
    bar: root.querySelector('.review-progress .bar'),
    pending: root.querySelector('.pending-note'),
    editBtn: root.querySelector('[data-action="edit"]')
  };
  var dialog = document.getElementById('edit-dialog');
  var editForm = document.getElementById('edit-form');
  var editError = editForm.querySelector('.form-error');

  var queue = [];
  var current = null;
  var revealed = false;
  var shownAt = 0;
  var answered = 0;
  var tally = { 1: 0, 2: 0, 3: 0, 4: 0 };

  var GRADES = mode === 'hoy'
    ? [
        { rating: 1, label: 'Otra vez', cls: 'g-again' },
        { rating: 2, label: 'Difícil', cls: 'g-hard' },
        { rating: 3, label: 'Bien', cls: 'g-good' },
        { rating: 4, label: 'Fácil', cls: 'g-easy' }
      ]
    : [
        { rating: 1, label: 'No lo sabía', cls: 'g-again' },
        { rating: 3, label: 'Lo sabía', cls: 'g-good' }
      ];

  // ---------- utilidades ----------

  function showStatus(text) {
    el.status.textContent = text || '';
  }

  function setText(node, text) {
    node.textContent = text;
    if (window.fcRenderMath) window.fcRenderMath(node);
  }

  function uuid() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 3) | 8).toString(16);
    });
  }

  function toFsrs(c) {
    return {
      due: new Date(c.due),
      stability: c.stability,
      difficulty: c.difficulty,
      elapsed_days: c.elapsed_days,
      scheduled_days: c.scheduled_days,
      learning_steps: c.learning_steps,
      reps: c.reps,
      lapses: c.lapses,
      state: c.state,
      last_review: c.last_review ? new Date(c.last_review) : undefined
    };
  }

  function fromFsrs(c) {
    return {
      due: new Date(c.due).toISOString(),
      stability: c.stability,
      difficulty: c.difficulty,
      elapsed_days: c.elapsed_days,
      scheduled_days: c.scheduled_days,
      learning_steps: c.learning_steps,
      reps: c.reps,
      lapses: c.lapses,
      state: c.state,
      last_review: c.last_review ? new Date(c.last_review).toISOString() : null
    };
  }

  function decimal(n) {
    return (Math.round(n * 10) / 10).toString().replace('.', ',');
  }

  function intervalLabel(ms) {
    var min = Math.round(ms / 60000);
    if (min < 1) return '<1 min';
    if (min < 60) return min + ' min';
    var h = Math.round(min / 60);
    if (h < 24) return h + ' h';
    var d = Math.round(h / 24);
    if (d < 31) return d + ' d';
    var mo = Math.round((d / 30) * 10) / 10;
    if (mo < 12) return decimal(mo) + (mo === 1 ? ' mes' : ' meses');
    var y = Math.round((d / 365) * 10) / 10;
    return decimal(y) + (y === 1 ? ' año' : ' años');
  }

  function stateLabel(s) {
    return ['Nueva', 'Aprendiendo', 'Repaso', 'Reaprendiendo'][s] || '';
  }

  function remaining() {
    return queue.length + (current ? 1 : 0);
  }

  function updateProgress() {
    var left = remaining();
    var total = answered + left;
    el.bar.style.width = total ? Math.round((answered / total) * 100) + '%' : '0%';
    if (current) showStatus(left === 1 ? 'Queda 1' : 'Quedan ' + left);
  }

  // ---------- flujo del repaso ----------

  function showNext() {
    current = queue.shift() || null;
    revealed = false;
    if (!current) {
      finish();
      return;
    }
    el.loading.hidden = true;
    el.card.hidden = false;
    el.end.hidden = true;
    el.actions.hidden = false;
    el.back.hidden = true;
    el.grades.hidden = true;
    el.reveal.hidden = false;
    el.editBtn.hidden = false;
    var meta = current.deck_name;
    if (current.week !== null) meta += ' · S' + current.week;
    if (mode === 'hoy') meta += ' · ' + stateLabel(current.card.state);
    el.meta.textContent = meta;
    setText(el.front, current.front);
    setText(el.back, current.back);
    updateProgress();
    shownAt = Date.now();
    window.scrollTo(0, 0);
  }

  function buildGrades(previews) {
    el.grades.innerHTML = '';
    el.grades.className = 'grades grades-' + GRADES.length;
    GRADES.forEach(function (g) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'grade ' + g.cls;
      var name = document.createElement('span');
      name.className = 'grade-name';
      name.textContent = g.label;
      b.appendChild(name);
      if (previews && previews[g.rating]) {
        var t = document.createElement('span');
        t.className = 'grade-time';
        t.textContent = previews[g.rating];
        b.appendChild(t);
      }
      b.addEventListener('click', function () { answer(g.rating); });
      el.grades.appendChild(b);
    });
  }

  function reveal() {
    if (!current || revealed) return;
    revealed = true;
    el.back.hidden = false;
    el.reveal.hidden = true;
    el.grades.hidden = false;
    var previews = null;
    if (mode === 'hoy') {
      var now = new Date();
      try {
        var preview = scheduler.repeat(toFsrs(current.card), now);
        previews = {};
        [1, 2, 3, 4].forEach(function (r) {
          previews[r] = intervalLabel(preview[r].card.due.getTime() - now.getTime());
        });
      } catch (e) {
        previews = null;
      }
    }
    buildGrades(previews);
  }

  function requeue(item, position) {
    queue.splice(Math.min(position, queue.length), 0, item);
  }

  function answer(rating) {
    if (!current || !revealed) return;
    var item = current;
    var now = new Date();
    var payload = {
      uuid: uuid(),
      card_id: item.id,
      rating: rating,
      mode: mode,
      duration_ms: Math.min(Date.now() - shownAt, 600000),
      now: now.toISOString()
    };
    if (mode === 'hoy') {
      var next;
      try {
        next = scheduler.next(toFsrs(item.card), now, rating);
      } catch (e) {
        showStatus('No se pudo calcular el repaso de esta tarjeta.');
        return;
      }
      payload.reps_before = item.card.reps;
      payload.card = fromFsrs(next.card);
      item.card = payload.card;
      // Si la fallaste y vuelve en menos de 20 minutos, aparece otra vez en esta sesión.
      // Las demás tarjetas en aprendizaje salen en la próxima sesión, así "Repasar 10" no se alarga.
      if (rating === 1 && new Date(next.card.due).getTime() - now.getTime() <= LEARN_AHEAD_MS) {
        requeue(item, 3);
      }
    } else if (rating === 1) {
      requeue(item, 4);
    }
    tally[rating] = (tally[rating] || 0) + 1;
    answered++;
    window.fcPending.push(payload);
    showNext();
  }

  function addButton(parent, href, text, primary) {
    var a = document.createElement('a');
    a.className = 'btn btn-big' + (primary ? ' btn-primary' : '');
    a.href = href;
    a.textContent = text;
    parent.appendChild(a);
  }

  function finish() {
    current = null;
    el.card.hidden = true;
    el.actions.hidden = true;
    el.editBtn.hidden = true;
    el.loading.hidden = true;
    el.end.hidden = false;
    el.end.innerHTML = '';
    el.bar.style.width = answered ? '100%' : '0%';
    showStatus('');

    var h = document.createElement('h2');
    var p = document.createElement('p');
    p.className = 'muted';
    if (answered === 0) {
      var empty = {
        hoy: ['No tienes tarjetas pendientes por ahora.', 'Vuelve más tarde o repasa todo un mazo en modo examen.'],
        examen: ['No hay tarjetas con ese filtro.', 'Prueba con otras semanas o con todo el mazo.'],
        errores: ['Tu cuaderno de errores está vacío.', 'Aquí aparecen las tarjetas que marcas como "Otra vez".']
      }[mode];
      h.textContent = empty[0];
      p.textContent = empty[1];
    } else {
      h.textContent = '¡Listo! Repasaste ' + answered + (answered === 1 ? ' tarjeta.' : ' tarjetas.');
      if (mode === 'hoy') {
        p.textContent = 'Otra vez: ' + tally[1] + ' · Difícil: ' + tally[2] + ' · Bien: ' + tally[3] + ' · Fácil: ' + tally[4];
      } else {
        p.textContent = 'Lo sabías: ' + tally[3] + ' · No lo sabías: ' + tally[1];
      }
    }
    el.end.appendChild(h);
    el.end.appendChild(p);

    var actions = document.createElement('div');
    actions.className = 'stack';
    if (mode === 'hoy' && answered > 0) {
      addButton(actions, '/repasar?limite=' + shortSize, 'Seguir con ' + shortSize + ' más', true);
    }
    if (mode === 'examen' && answered > 0) {
      addButton(actions, window.location.pathname + window.location.search, 'Otra vuelta', true);
    }
    addButton(actions, backUrl, 'Volver', answered === 0);
    el.end.appendChild(actions);
  }

  function load() {
    fetch('/api/sesion' + (query ? '?' + query : ''), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) {
        if (res.status === 401) {
          window.location.href = '/entrar';
          throw new Error('auth');
        }
        return res.json();
      })
      .then(function (j) {
        if (!j.ok) {
          el.loading.textContent = j.error || 'No se pudieron cargar las tarjetas.';
          return;
        }
        queue = j.cards || [];
        showNext();
      })
      .catch(function (e) {
        if (e && e.message === 'auth') return;
        el.loading.textContent = 'No se pudieron cargar las tarjetas. Revisa tu conexión y recarga la página.';
      });
  }

  // ---------- respuestas pendientes ----------

  function updatePendingNote(count) {
    if (!count) {
      el.pending.hidden = true;
      return;
    }
    el.pending.hidden = false;
    el.pending.textContent = count === 1
      ? '1 respuesta sin guardar. Se envía sola cuando vuelva la señal.'
      : count + ' respuestas sin guardar. Se envían solas cuando vuelva la señal.';
  }

  window.fcPending.on('change', updatePendingNote);
  window.fcPending.on('auth', function () {
    showStatus('Tu sesión terminó. Vuelve a entrar para guardar tus respuestas.');
  });
  window.fcPending.on('error', function (msg) { showStatus(msg); });
  window.fcPending.on('conflict', function (serverCard) {
    [current].concat(queue).forEach(function (it) {
      if (it && it.id === serverCard.id) it.card = serverCard.card;
    });
  });
  // Antes de salir con respuestas sin guardar, se avisa.
  window.addEventListener('beforeunload', function (ev) {
    if (window.fcPending.count() > 0) {
      ev.preventDefault();
      ev.returnValue = '';
    }
  });

  // ---------- editar desde el repaso ----------

  function openEdit() {
    if (!current) return;
    editForm.elements.front.value = current.front;
    editForm.elements.back.value = current.back;
    editForm.elements.week.value = current.week === null ? '' : String(current.week);
    editError.hidden = true;
    dialog.showModal();
  }

  editForm.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (!current) return;
    var item = current;
    var body = {
      id: item.id,
      front: editForm.elements.front.value,
      back: editForm.elements.back.value,
      week: editForm.elements.week.value
    };
    fetch('/api/tarjeta', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().catch(function () { return {}; });
    }).then(function (j) {
      if (!j.ok) {
        editError.textContent = j.error || 'No se pudo guardar.';
        editError.hidden = false;
        return;
      }
      item.front = j.card.front;
      item.back = j.card.back;
      item.week = j.card.week;
      if (item === current) {
        setText(el.front, item.front);
        setText(el.back, item.back);
      }
      dialog.close();
      showStatus('Tarjeta guardada.');
    }).catch(function () {
      editError.textContent = 'Sin conexión. Inténtalo otra vez.';
      editError.hidden = false;
    });
  });

  editForm.querySelector('[data-action="cancel-edit"]').addEventListener('click', function () { dialog.close(); });
  el.editBtn.addEventListener('click', openEdit);
  el.reveal.addEventListener('click', reveal);
  el.card.addEventListener('click', function (ev) {
    if (!revealed && !(ev.target.closest && ev.target.closest('a'))) reveal();
  });

  // Teclado (PC o iPad con teclado): espacio muestra, 1-4 califica, E edita.
  document.addEventListener('keydown', function (ev) {
    if (dialog.open) return;
    var tag = ev.target && ev.target.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
    if (ev.key === ' ' || ev.key === 'Enter') {
      if (current && !revealed) {
        ev.preventDefault();
        reveal();
      }
      return;
    }
    if (revealed && /^[1-4]$/.test(ev.key)) {
      var g = GRADES[parseInt(ev.key, 10) - 1];
      if (g) answer(g.rating);
      return;
    }
    if (ev.key === 'e' || ev.key === 'E') openEdit();
  });

  updatePendingNote(window.fcPending.count());
  // Primero se envían respuestas viejas pendientes, para cargar el estado al día.
  window.fcPending.flush();
  window.fcPending.whenEmpty(load, 4000);
})();
